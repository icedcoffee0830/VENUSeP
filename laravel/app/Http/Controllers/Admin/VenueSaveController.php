<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use App\Queries\StaffHallQueries;
use App\Queries\VenueQueries;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDOException;

/* =====================================================================
   VENUE SAVE (was admin/venue-save.php) — updates venues.name +
   venues.description, called by "Save Changes" on venue-form. Same shape
   as room-save.

   POST  _token, venue_id, name, description[, move_staff = JSON [user ids]]
   Replies with JSON.

   Assigned Staff (2026-10-27): move_staff moves those staff accounts to
   this venue (staff.venue_id), admin only. Staff Management sets the same
   column one person at a time.
   ===================================================================== */
class VenueSaveController extends Controller
{
    use RepliesJson;

    public function __invoke(Request $request)
    {
        $this->requirePost($request);
        $this->requireStaffJson();
        legacy('db', 'auth');   /* admin_is_admin() */

        $post = $request->request;
        $venueId = (string) ($post->get('venue_id') ?? '');
        $name = trim((string) ($post->get('name') ?? ''));
        $description = trim((string) ($post->get('description') ?? ''));

        /* venue_id 0 (or absent) used to mean create. The three offices are fixed
           (DB-DECISIONS 28): nobody adds one, the super admin included. */
        if ($venueId === '' || $venueId === '0') {
            $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'The offices are fixed: no office can be added.']);
        }
        if (!ctype_digit($venueId)) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_venue', 'message' => 'Invalid venue.']);
        }
        $venueId = (int) $venueId;
        if ($name === '' || mb_strlen($name) > 150) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_name', 'message' => 'Enter a venue name (up to 150 characters).']);
        }

        if (venusep_db() === null) {
            $this->reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so the venue was not saved.']);
        }

        /* ASSIGNED STAFF (2026-10-27): staff to MOVE to this venue. Every staff member
           handles exactly one venue, so this only ever moves people HERE. Nobody can
           be taken off into "no venue" (trg_staff_venue_required_upd refuses that
           too). Which venue someone handles decides whose bookings they may act on
           (staff_may_handle_venue()); only an admin gets this far (requireOfficeAdmin). */
        $moveRaw = json_decode((string) ($post->get('move_staff') ?? '[]'), true);
        if (!is_array($moveRaw)) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_staff', 'message' => 'Invalid staff selection.']);
        }
        $moveIds = [];
        foreach ($moveRaw as $m) {
            if (!is_scalar($m) || !ctype_digit((string) $m)) {
                $this->reply(400, ['ok' => false, 'error' => 'bad_staff', 'message' => 'Invalid staff selection.']);
            }
            $moveIds[] = (int) $m;
        }
        $moveIds = array_values(array_unique($moveIds));

        try {
            if (!VenueQueries::exists($venueId)) {
                $this->reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Venue not found.']);
            }
            $this->requireOfficeAdmin($venueId);

            $movedEmails = [];
            $moved = DB::transaction(function () use ($venueId, $name, $description, $moveIds, &$movedEmails) {
                VenueQueries::saveNameAndDescription($venueId, $name, $description);
                return $this->moveStaff($venueId, $moveIds, $movedEmails);
            });
            $this->notifyMoves($movedEmails, $name, (int) $venueId);

            $this->reply(200, ['ok' => true, 'created' => false, 'name' => $name, 'description' => $description, 'moved' => $moved]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (RoomSaveRefusal $e) {
            $this->reply(409, ['ok' => false, 'error' => 'last_staff', 'message' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_staff', 'message' => 'One of the selected people is not a staff member you manage, so nothing was saved.']);
        } catch (PDOException $e) {
            /* uq_venues_name — two venues sharing a name would make every "Location"
               dropdown ambiguous, which is exactly what the constraint is for. */
            if ($e->getCode() === '23000') {
                $this->reply(409, ['ok' => false, 'error' => 'duplicate',
                    'message' => 'A venue with that name already exists.']);
            }
            sql_failed($e);
            $this->reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the venue was not saved.']);
        } catch (\Throwable $e) {
            sql_failed($e);
            $this->reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the venue was not saved.']);
        }
    }

    /* Move the chosen staff accounts here; returns their names. Every id must be
       a STAFF account, never an admin's or a customer's row. */
    private function moveStaff($venueId, array $ids, array &$movedEmails = [])
    {
        if (!$ids) {
            return [];
        }
        $rows = VenueQueries::findStaff($ids);
        /* only staff this admin manages: a normal admin moves its own offices' staff (DB-DECISIONS 28) */
        if (count($rows) !== count($ids) || array_filter($rows, fn ($r) => !staff_may_handle_venue($r['venue_id']))) {
            throw new InvalidArgumentException('not_staff');
        }
        /* every hall keeps a staff member (Aron 2026-11-05): the last one of a hall stays */
        foreach ($rows as $r) {
            if ((int) $r['venue_id'] !== (int) $venueId && ($halls = StaffHallQueries::lastStaffOf((int) $r['user_id']))) {
                throw new RoomSaveRefusal($r['full_name'] . ': ' . StaffHallQueries::lastStaffMessage($halls));
            }
        }
        /* their new office's halls come with them (trg_staff_halls_follow_upd) */
        VenueQueries::moveStaff($ids, (int) $venueId);
        /* who actually changed venue — for the admins' notifications */
        foreach ($rows as $r) {
            if ((int) $r['venue_id'] !== (int) $venueId) {
                $movedEmails[$r['email']] = (int) $r['venue_id'];   // email => the office they left
            }
        }
        return array_column($rows, 'full_name');
    }

    /* Tell the admins of the offices each one left and joined who was moved (2026-10-28;
       DB-DECISIONS 28). A failure costs a notification, never the save. */
    private function notifyMoves(array $emails, $venueName, int $venueId)
    {
        if (!$emails) {
            return;
        }
        legacy('notifications');
        try {
            foreach ($emails as $email => $from) {
                notify_account_event('staff_moved', (int) session('user_id'), ['email' => $email, 'venue' => $venueName], [$from, $venueId]);
            }
        } catch (PDOException $e) {
            sql_failed($e, optional: true);
        }
    }
}
