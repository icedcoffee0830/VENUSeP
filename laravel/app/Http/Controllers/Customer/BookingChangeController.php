<?php

namespace App\Http\Controllers\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PDO;
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
    private static function own(PDO $pdo, Request $request): ?array
    {
        $id = booking_id_from_reference((string) $request->input('booking', ''));
        if ($id === null) {
            return null;
        }
        $st = $pdo->prepare('SELECT b.id, b.room_id, b.booking_type, b.reservation_status, fn_booking_first_day(b.id) AS first_day
                               FROM bookings b WHERE b.id = :b AND b.customer_id = :c');
        $st->execute([':b' => $id, ':c' => (int) session('customer_id')]);
        return $st->fetch() ?: null;
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
        [$pdo, $refused] = self::begin();
        if ($refused) {
            return $refused;
        }
        venusep_expire_due_bookings();
        $b = self::own($pdo, $request);
        if (!$b) {
            return self::reply(404, ['ok' => false, 'message' => 'That booking was not found.']);
        }
        $user = (int) session('user_id');
        $pdo->beginTransaction();
        $pdo->prepare('SET @venusep_actor_user_id = :a, @venusep_action_note = :n')
            ->execute([':a' => $user, ':n' => 'Request cancelled by the customer.']);
        $done = $pdo->prepare("UPDATE bookings SET reservation_status = 'cancelled', cancelled_at = NOW() WHERE id = :b AND reservation_status = 'pending'");
        $done->execute([':b' => $b['id']]);
        $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
        if ($done->rowCount() === 0) {
            $pdo->rollBack();
            return self::reply(409, ['ok' => false, 'message' => 'Only a request that has not been approved can be cancelled here. Please contact the office.']);
        }
        $pdo->commit();
        return self::reply(200, ['ok' => true, 'message' => 'Your request was cancelled.']);
    }

    /* A rejected request comes back with a new ID, if its dates are still free. */
    public function fix(Request $request)
    {
        [$pdo, $refused] = self::begin();
        if ($refused) {
            return $refused;
        }
        $b = self::own($pdo, $request);
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
            BookingRules::beginBooking($pdo);
            $pdo->prepare('SET @venusep_actor_user_id = :a, @venusep_action_note = :n')
                ->execute([':a' => $user, ':n' => 'A new ID was sent; the request is back with staff.']);
            $pdo->prepare("UPDATE bookings SET reservation_status = 'pending', payment_status = 'locked' WHERE id = :b")->execute([':b' => $id]);
            $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
            if ($b['booking_type'] === 'venue') {
                $slots = $pdo->prepare('SELECT slot_date, start_time, end_time FROM venue_booking_slots WHERE booking_id = :b');
                $slots->execute([':b' => $id]);
                $rows = $slots->fetchAll();
                $pdo->prepare('DELETE FROM venue_booking_slots WHERE booking_id = :b')->execute([':b' => $id]);
                $add = $pdo->prepare('CALL sp_add_venue_slot(:b, :d, :s, :e)');
                foreach ($rows as $r) {
                    $add->execute([':b' => $id, ':d' => $r['slot_date'], ':s' => $r['start_time'], ':e' => $r['end_time']]);
                    $add->closeCursor();
                }
            }
            $pdo->prepare('UPDATE bookings SET current_deadline_at = fn_payment_deadline(:b) WHERE id = :b2')->execute([':b' => $id, ':b2' => $id]);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            sql_failed($e);
            return self::reply(409, ['ok' => false, 'message' => $taken]);
        }

        if ($b['booking_type'] === 'hostel') {
            $pdo->prepare('DELETE FROM bed_reservation_nights WHERE booking_id = :b')->execute([':b' => $id]);
            $pdo->prepare('DELETE FROM bed_reservations WHERE booking_id = :b')->execute([':b' => $id]);
            $stay = $pdo->prepare('SELECT check_in_date, check_out_date FROM hostel_booking_details WHERE booking_id = :b');
            $stay->execute([':b' => $id]);
            $dates = $stay->fetch();
            $occ = $pdo->prepare('SELECT id FROM hostel_occupants WHERE booking_id = :b ORDER BY id');
            $occ->execute([':b' => $id]);
            try {
                BookingRules::assignBeds($pdo, (int) $b['room_id'], $id, array_map('intval', $occ->fetchAll(PDO::FETCH_COLUMN)), $dates['check_in_date'], $dates['check_out_date']);
            } catch (RuntimeException | PDOException $e) {
                $pdo->prepare("UPDATE bookings SET reservation_status = 'rejected', payment_status = 'locked' WHERE id = :b")->execute([':b' => $id]);
                if ($e instanceof PDOException) {
                    sql_failed($e);
                }
                return self::reply(409, ['ok' => false, 'message' => $taken]);
            }
        }

        $docError = '';
        doc_store($id, 'customer_id', $file->getPathname(), $file->getClientOriginalName(), $user, $docError);
        try {
            notify_new_request($pdo, $id, $user);
        } catch (PDOException $e) {
            sql_failed($e, optional: true);
        }
        return self::reply(200, ['ok' => true, 'message' => 'Your new ID was sent. The request is back with staff.']);
    }
}
