<?php

namespace App\Http\Controllers\Customer;

use App\Queries\BookingCreateQueries;
use App\Queries\BookingQueries;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PDOException;
use RuntimeException;

/* What a customer can change on their own booking (2026-11-01):
   cancel a request nobody has approved yet, or send a better ID after a rejection.
   POST _token, booking=<VB-reference>; fix also FILES[id_document]. JSON replies. */
class BookingChangeController
{
    private static function reply(int $status, array $data)
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store']);
    }

    /* The session customer's own booking, or null. */
    private static function own(Request $request): ?array
    {
        $id = booking_id_from_reference((string) $request->input('booking', ''));
        if ($id === null) {
            return null;
        }
        return BookingCreateQueries::findOwn($id, (int) session('customer_id'));
    }

    private static function begin(): array
    {
        legacy('auth', 'db', 'documents', 'bookings', 'notifications');
        if (!customer_logged_in()) {
            return [null, self::reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.'])];
        }
        $pdo = venusep_db();
        return $pdo === null
            ? [null, self::reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was changed.'])]
            : [$pdo, null];
    }

    /* Free until staff approve; after that it goes through the office (force cancel). */
    public function cancel(Request $request)
    {
        [, $refused] = self::begin();
        if ($refused) {
            return $refused;
        }
        venusep_expire_due_bookings();
        $b = self::own($request);
        if (!$b) {
            return self::reply(404, ['ok' => false, 'message' => 'That booking was not found.']);
        }
        $user = (int) session('user_id');
        if (!BookingCreateQueries::cancelPending((int) $b['id'], $user)) {
            return self::reply(409, ['ok' => false, 'message' => 'Only a request that has not been approved can be cancelled here. Please contact the office.']);
        }
        return self::reply(200, ['ok' => true, 'message' => 'Your request was cancelled.']);
    }

    /* A rejected request comes back with a new ID, if its dates are still free. */
    public function fix(Request $request)
    {
        [$pdo, $refused] = self::begin();
        if ($refused) {
            return $refused;
        }
        $b = self::own($request);
        if (!$b) {
            return self::reply(404, ['ok' => false, 'message' => 'That booking was not found.']);
        }
        if ($b['reservation_status'] !== 'rejected' || !$b['first_day'] || $b['first_day'] <= date('Y-m-d')) {
            return self::reply(409, ['ok' => false, 'message' => 'This request can no longer be fixed. Please make a new booking.']);
        }
        $file = $request->file('id_document');
        $err = 'Attach your ID.';
        if (!$file instanceof UploadedFile || !$file->isValid() || doc_validate($file->getPathname(), $file->getClientOriginalName(), $err) === null) {
            return self::reply(400, ['ok' => false, 'message' => $err]);
        }

        $taken = 'Those dates have been taken since. Please make a new booking.';
        $id = (int) $b['id'];
        $user = (int) session('user_id');
        try {
            BookingCreateQueries::reopenRejected($id, $b['booking_type'] === 'venue', $user);
        } catch (PDOException $e) {
            sql_failed($e);
            return self::reply(409, ['ok' => false, 'message' => $taken]);
        }

        if ($b['booking_type'] === 'hostel') {
            BookingCreateQueries::clearBeds($id);
            $stay = BookingQueries::findHostelStay($id);
            try {
                BookingRules::assignBeds($pdo, (int) $b['room_id'], $id, BookingQueries::findOccupantIds($id), $stay['check_in_date'], $stay['check_out_date']);
            } catch (RuntimeException | PDOException $e) {
                BookingCreateQueries::putBackRejected($id);
                if ($e instanceof PDOException) {
                    sql_failed($e);
                }
                return self::reply(409, ['ok' => false, 'message' => $taken]);
            }
        }

        $docError = '';
        doc_store($id, 'customer_id', $file->getPathname(), $file->getClientOriginalName(), $user, $docError);
        try {
            notify_new_request($id, $user);
        } catch (PDOException $e) {
            sql_failed($e, optional: true);
        }
        return self::reply(200, ['ok' => true, 'message' => 'Your new ID was sent. The request is back with staff.']);
    }
}
