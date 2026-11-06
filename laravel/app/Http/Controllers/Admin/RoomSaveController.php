<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\BookingRules;
use App\Queries\RoomQueries;
use App\Queries\StaffHallQueries;
use App\Queries\VenueQueries;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;
use Symfony\Component\HttpFoundation\InputBag;

/* =====================================================================
   ROOM SAVE (was admin/room-save.php) — creates or updates a room, called
   by "Save Changes" on room-form and hostel-room-form.

   POST  _token, room_id (room_code, e.g. "r1"; EMPTY = create), name,
         venue (venue name), room_type ('event' | 'hostel')
   creating also: description, and per type —
         event:  capacity, fee, staff_ids = JSON [user ids]: the office's staff who cover
                 the new hall; at least one (every hall keeps a staff member, Aron 2026-11-05)
         hostel: beds, rate, cr_type (the hostel is one unit: its staff cover every room)
   Replies with JSON.

   ROOM CODES ARE GENERATED, NEVER TYPED. room_code is the photo folder
   name (assets/img/venues/rooms/<code>/), the ?room= URL parameter and
   the key every page joins on, so an admin inventing one could collide
   with a directory already on disk. Creation continues the existing
   series: r9, h6.

   A NEW HOSTEL ROOM GETS ITS BEDS. Availability is counted from
   bed_reservation_nights joined to hostel_beds, so a hostel room with no
   bed rows can never be booked — it reads as permanently full. Creating
   the beds is not optional garnish; it is what makes the room exist.

   room_type is checked against the room's actual rooms.room_type on an
   update, and against the VENUE's type on a create, so neither form can
   be pointed at the wrong thing by tampering with the request.

   EVERY DETAIL ON THE FORM SAVES (2026-10-27), on create and on edit:
   description, the "at a glance" lines, capacity + rate (event), rate +
   beds + CR type + check-in/out times (hostel), the maintenance window and
   amenities. The rules that protect bookings already made:
     · a new rate reaches NEW bookings only (each booking snapshots its price)
     · capacity may go down; upcoming bookings over it are named, not changed
     · beds with upcoming guests cannot be removed (refused, with the bookings)
     · a room moves to another venue only with no upcoming live bookings, and only by an admin
     · a CLOSED maintenance window names the bookings inside it — nothing is
       cancelled automatically
   ===================================================================== */
class RoomSaveController extends Controller
{
    use RepliesJson;

    /* Booking references for a list of booking rows (id, submitted_at). */
    private function refs(array $rows)
    {
        return array_map(function ($b) { return booking_reference($b['id'], $b['submitted_at']); }, $rows);
    }

    /* MAINTENANCE (2026-10-27) — the form's `maintenance` JSON:
       {on, tier:'hard'|'medium', from:'Y-m-d', until:'Y-m-d'|null, reason}.
       null = the form did not send it (leave the windows alone). */
    private function parseMaintenance(InputBag $post)
    {
        if (!$post->has('maintenance')) {
            return null;
        }
        $m = json_decode((string) $post->get('maintenance'), true);
        if (!is_array($m) || empty($m['on'])) {
            return ['on' => false];
        }
        $from   = (string) ($m['from'] ?? '');
        $until  = ($m['until'] ?? null) === null || $m['until'] === '' ? null : (string) $m['until'];
        $reason = trim((string) ($m['reason'] ?? ''));
        /* real calendar dates: 2031-02-30 was stored as 0000-00-00, closing the room for every date up to the end */
        if (!BookingRules::isDate($from) || ($until !== null && (!BookingRules::isDate($until) || $until < $from))) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_maintenance', 'message' => 'Set the maintenance dates: a start date, and an end date on or after it (or tick "no set return date").']);
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_maintenance', 'message' => 'Say why the room is under maintenance — staff and customers read it.']);
        }
        return ['on' => true, 'hard' => ($m['tier'] ?? '') === 'hard', 'from' => $from, 'until' => $until, 'reason' => $reason];
    }

    /* A room carries ONE live closure: the current/future window is replaced, and
       windows that already ended stay as history. Returns the live bookings inside
       a CLOSED (hard) window — staff resolve those; nothing is cancelled here. */
    private function applyMaintenance($roomId, $roomType, array $mt, $actor)
    {
        RoomQueries::clearLiveClosure($roomId);
        if (!$mt['on']) {
            return [];
        }
        RoomQueries::addClosure($roomId, $mt['from'], $mt['until'], $mt['reason'], $mt['hard'], $actor);
        if (!$mt['hard']) {
            return [];
        }
        return $this->refs(RoomQueries::bookingsInClosure($roomId, $roomType, $mt['from'], $mt['until'] ?? '9999-12-31'));
    }

    /* HOSTEL BEDS (2026-10-27): make the room hold $target active beds. Adding
       reactivates retired beds first, then makes new ones. Removing takes the
       highest-numbered beds, and is REFUSED if any of them has an upcoming
       night booked — a guest's bed must never vanish. Beds are retired
       (is_active = 0), never deleted: past stays still point at them. */
    private function setBeds($roomId, $roomCode, $target)
    {
        $all = RoomQueries::beds($roomId);
        $active = array_values(array_filter($all, function ($b) { return (int) $b['is_active'] === 1; }));
        $count = count($active);
        if ($target > $count) {
            $need = $target - $count;
            foreach ($all as $b) {
                if ($need === 0) { break; }
                if ((int) $b['is_active'] === 0) {
                    RoomQueries::reactivateBed((int) $b['id']);
                    $need--;
                }
            }
            $order = $all ? max(array_map(function ($b) { return (int) $b['display_order']; }, $all)) : 0;
            RoomQueries::addBeds($roomId, $roomCode, $order, $need);
        } elseif ($target < $count) {
            $retire = array_map(function ($b) { return (int) $b['id']; }, array_slice(array_reverse($active), 0, $count - $target));
            $held = RoomQueries::bookingsOnBeds($retire);
            if ($held) {
                throw new RoomSaveRefusal('Those beds have upcoming guests (' . implode(', ', $this->refs($held))
                    . '), so the bed count cannot go below ' . $count . ' yet. Move or finish those stays first.');
            }
            RoomQueries::retireBeds($retire);
        }
    }

    public function __invoke(Request $request)
    {
        $this->requirePost($request);
        $this->requireStaffJson();

        extract(legacy('db', 'amenities', 'bookings'));   /* amenity_keys_clean(), $AMENITY_GROUPS*, booking_reference() */
        $post = $request->request;
        $actor = (int) session('user_id');

        /* An empty room_id means CREATE. The Add Room screen has existed all along;
           until now it answered with an alert saying it could not actually create one. */
        $isNewRoom = trim((string) ($post->get('room_id') ?? '')) === '';
        /* Adding a room, hall or hostel, is for the admins of that office's group and the
           super admin (requireVenueScope below); staff edit rooms, they do not add them. */
        if ($isNewRoom && !admin_is_admin()) {
            $this->reply(403, ['ok' => false, 'error' => 'admin_only', 'message' => 'Only an administrator can add a room.']);
        }
        $roomCode = $post->get('room_id') ?? '';
        $name = trim((string) ($post->get('name') ?? ''));
        $venueName = trim((string) ($post->get('venue') ?? ''));
        $roomType = $post->get('room_type') ?? '';

        if (!$isNewRoom && (!is_string($roomCode) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $roomCode))) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_room', 'message' => 'Invalid room.']);
        }
        if ($name === '' || mb_strlen($name) > 150) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_name', 'message' => 'Enter a room name (up to 150 characters).']);
        }
        if ($venueName === '') {
            $this->reply(400, ['ok' => false, 'error' => 'bad_venue', 'message' => 'Choose a location.']);
        }
        if (!in_array($roomType, ['event', 'hostel'], true)) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_type', 'message' => 'Invalid request.']);
        }

        /* WHICH AMENITIES THIS ROOM HAS, as vocabulary KEYS.
           amenity_keys_clean() drops anything the vocabulary does not know, so a
           tampered post cannot put junk in the column.

           `amenities` absent entirely = "this form did not ask", leave the column
           alone. An EMPTY list is a real answer: the admin unticked everything.
           Those two must not collapse into each other, or saving a form that has no
           amenity picker would silently wipe the room's amenities. */
        $amenitiesGiven = $post->has('amenities');
        $amenityKeys = [];
        if ($amenitiesGiven) {
            $decoded = json_decode((string) $post->get('amenities'), true);
            $amenityKeys = amenity_keys_clean(is_array($decoded) ? $decoded : []);

            /* Keep only the groups this ROOM TYPE can use. The picker already filters,
               but the picker is a convenience, not a boundary. */
            $allowedGroups = $roomType === 'hostel' ? $AMENITY_GROUPS_HOSTEL : $AMENITY_GROUPS_EVENT;
            $allowedKeys = [];
            foreach ($allowedGroups as $g) {
                foreach ($AMENITY_GROUPS[$g] as $k) { $allowedKeys[$k] = true; }
            }
            $amenityKeys = array_values(array_filter($amenityKeys, function ($k) use ($allowedKeys) {
                return isset($allowedKeys[$k]);
            }));
        }

        /* EVERY DETAIL ON THE FORM (2026-10-27). Each is optional — absent means
           "this form did not send it", so the column is left alone — and checked
           here, not just in the form. */
        $descGiven = $post->has('description');
        $desc = mb_substr(trim((string) ($post->get('description') ?? '')), 0, 2000);
        $glanceGiven = $post->has('best_for');
        $glance = [];
        foreach (['best_for', 'catering', 'accessibility'] as $g) {
            $glance[$g] = mb_substr(trim((string) ($post->get($g) ?? '')), 0, 255);
        }
        /* A blank or non-numeric field is refused, not cast: (float) '' is 0, which
           silently made a room FREE when the rate box was cleared. Counts are whole. */
        $whole = function ($v) { return is_string($v) && preg_match('/^\s*\d{1,9}\s*$/', $v) === 1 ? (int) $v : -1; };
        $money = function ($v) { return is_string($v) && is_numeric($v) ? (float) $v : -1.0; };
        $capGiven = $post->has('capacity');
        $cap = $whole($post->get('capacity'));
        $feeGiven = $post->has('fee');
        $fee = $money($post->get('fee'));
        if ($capGiven && ($cap < 1 || $cap > 100000)) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_capacity', 'message' => 'Capacity must be at least 1 person.']);
        }
        if ($feeGiven && ($fee < 0 || $fee > 10000000)) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_fee', 'message' => 'Enter a rate of ₱0 or more.']);
        }
        $hostelGiven = $roomType === 'hostel' && $post->has('rate');
        /* A NEW room states its price: leaving the fields out used to create a free one. */
        if ($isNewRoom && ($roomType === 'hostel' ? !$hostelGiven : (!$capGiven || !$feeGiven))) {
            $this->reply(400, ['ok' => false, 'error' => 'missing_price', 'message' => 'A new room needs its capacity and rate.']);
        }
        $hRate  = $money($post->get('rate'));
        $hBeds  = $whole($post->get('beds'));
        $hCr    = ($post->get('cr_type') ?? '') === 'private' ? 'private' : 'communal';
        $hIn    = (string) ($post->get('check_in_time') ?? '');
        $hOut   = (string) ($post->get('check_out_time') ?? '');
        if ($hostelGiven) {
            if ($hRate < 0 || $hRate > 1000000) {
                $this->reply(400, ['ok' => false, 'error' => 'bad_rate', 'message' => 'Enter a rate per bed per night of ₱0 or more.']);
            }
            if ($hBeds < 1 || $hBeds > 20) {
                $this->reply(400, ['ok' => false, 'error' => 'bad_beds', 'message' => 'A hostel room holds 1 to 20 beds.']);
            }
            $clock = '/^([01]\d|2[0-3]):[0-5]\d$/';   /* "25:99" used to reach MySQL and fail as a 500 */
            if (!preg_match($clock, $hIn) || !preg_match($clock, $hOut)) {
                $this->reply(400, ['ok' => false, 'error' => 'bad_times', 'message' => 'Set the check-in and check-out times.']);
            }
        }
        $mt = $this->parseMaintenance($post);

        if (venusep_db() === null) {
            $this->reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so the room was not saved.']);
        }

        try {
            $venueRow = VenueQueries::findByName($venueName);
            if (!$venueRow) {
                $this->reply(400, ['ok' => false, 'error' => 'bad_venue', 'message' => 'Unknown location.']);
            }

            /* the venue the room is (or will be) filed under must be the staff
               member's own (#24); an edit also checks the venue it is in now, below */
            $this->requireVenueScope($venueRow['id']);

            if ($isNewRoom) {
                /* The room must suit the venue (trg_rooms_type_ins enforces the same
                   rule at the database; catching it here gives a sentence). */
                $wantVenueType = $roomType === 'hostel' ? 'hostel' : 'event';
                if ($venueRow['venue_type'] !== $wantVenueType) {
                    $this->reply(409, ['ok' => false, 'error' => 'type_mismatch',
                        'message' => $roomType === 'hostel'
                            ? 'Hostel rooms can only be added to a hostel venue.'
                            : 'Event rooms can only be added to an event venue.']);
                }

                /* A new hall names its staff: active staff of that office, at least one. */
                $hallStaff = [];
                if ($roomType === 'event') {
                    $officeStaff = StaffHallQueries::staffOf((int) $venueRow['id']);
                    $hallStaff = array_values(array_unique(array_map('intval', (array) json_decode((string) ($post->get('staff_ids') ?? '[]'), true))));
                    if (!$officeStaff) {
                        $this->reply(409, ['ok' => false, 'error' => 'no_staff',
                            'message' => 'Create or assign a staff member to ' . $venueName . ' first: every hall needs one.']);
                    }
                    if (!$hallStaff || array_diff($hallStaff, array_keys($officeStaff))) {
                        $this->reply(400, ['ok' => false, 'error' => 'bad_staff',
                            'message' => 'Choose the staff member (or members) of ' . $venueName . ' who look after this hall.']);
                    }
                }

                /* room_code is GENERATED, never typed — "r9", "h6" — continuing the
                   existing series. The prefix follows the TYPE, not the venue. */
                $prefix = $roomType === 'hostel' ? 'h' : 'r';
                $newCode = RoomQueries::nextCode($prefix);

                DB::transaction(function () use ($venueRow, $newCode, $name, $roomType, $post, $amenityKeys, $hallStaff, $actor, $glanceGiven, $glance, $hostelGiven, $hIn, $hOut, $mt) {
                    $newId = RoomQueries::add((int) $venueRow['id'], $newCode, $name, $roomType,
                        mb_substr(trim((string) ($post->get('description') ?? '')), 0, 2000) ?: null,
                        json_encode($amenityKeys, JSON_UNESCAPED_SLASHES));

                    if ($roomType === 'hostel') {
                        /* A hostel room is booked PER BED; the beds ARE the room's capacity. */
                        $beds = (int) ($post->get('beds') ?? 6);
                        $beds = max(1, min(20, $beds));
                        $rate = (float) ($post->get('rate') ?? 0);
                        $crType = ($post->get('cr_type') ?? '') === 'private' ? 'private' : 'communal';
                        RoomQueries::addHostelRoom($newId, $newCode, $crType, $rate, $beds);
                    } else {
                        RoomQueries::saveEventDetails($newId, max(1, (int) ($post->get('capacity') ?? 1)), max(0, (float) ($post->get('fee') ?? 0)));
                        foreach ($hallStaff as $staffId) {
                            StaffHallQueries::assign($newId, $staffId, $actor);
                        }
                    }
                    if ($glanceGiven) {
                        RoomQueries::saveGlance($newId, $glance);
                    }
                    if ($hostelGiven) {
                        RoomQueries::saveStayTimes($newId, $hIn, $hOut);
                    }
                    if ($mt !== null) {
                        $this->applyMaintenance($newId, $roomType, $mt, $actor);
                    }
                });

                $this->reply(200, ['ok' => true, 'created' => true, 'room_id' => $newCode,
                    'name' => $name, 'venue' => $venueName,
                    'message' => 'Room created as ' . $newCode . '. You can add its photos now.']);
            }

            $roomRow = RoomQueries::findForEdit($roomCode);
            if (!$roomRow) {
                $this->reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Room not found.']);
            }
            $this->requireRoomScope($roomRow['id'], $roomRow['venue_id']);
            if ($roomRow['room_type'] !== $roomType) {
                $this->reply(409, ['ok' => false, 'error' => 'wrong_type', 'message' => 'This room does not match the form that submitted it.']);
            }

            /* Staff never move a room: it would reach another office, and a covered hall moved
               to their own office would stay theirs after the cover (trg_rooms_halls_follow_upd). */
            if ((int) $roomRow['venue_id'] !== (int) $venueRow['id'] && !admin_is_admin()) {
                $this->reply(403, ['ok' => false, 'error' => 'staff_move', 'message' => 'Only an administrator can move a room to another venue.']);
            }

            /* The venue was already resolved above — one lookup serves both paths. */
            $venueId = (int) $venueRow['id'];
            $rid = (int) $roomRow['id'];
            $notes = [];

            DB::transaction(function () use (&$notes, $rid, $venueId, $venueRow, $venueName, $roomType, $roomCode, $name, $amenitiesGiven, $amenityKeys, $descGiven, $desc, $glanceGiven, $glance, $capGiven, $cap, $feeGiven, $fee, $hostelGiven, $hRate, $hCr, $hIn, $hOut, $hBeds, $mt, $actor) {
                /* MOVING A ROOM TO ANOTHER VENUE (2026-10-27): only with no upcoming live
                   bookings — the venue decides which GCash account is paid and which staff
                   handle the booking. And only to a venue of the same kind. */
                if (RoomQueries::lockVenueId($rid) !== $venueId) {
                    if ($venueRow['venue_type'] !== ($roomType === 'hostel' ? 'hostel' : 'event')) {
                        throw new RoomSaveRefusal($roomType === 'hostel' ? 'A hostel room can only belong to a hostel venue.' : 'An event room can only belong to an event venue.');
                    }
                    /* the hall goes to the new office's staff (trg_rooms_halls_follow_upd): it needs one */
                    if ($roomType === 'event' && !StaffHallQueries::staffOf($venueId)) {
                        throw new RoomSaveRefusal('Create or assign a staff member to ' . $venueName . ' first: every hall needs one.');
                    }
                    $upcoming = RoomQueries::upcomingBookings($rid);
                    if ($upcoming) {
                        throw new RoomSaveRefusal('This room has upcoming bookings (' . implode(', ', $this->refs($upcoming))
                            . '), so it cannot move to another venue yet. The venue decides who is paid and which staff handle them.');
                    }
                }

                RoomQueries::saveNameAndVenue($rid, $name, $venueId);
                /* amenities only when the form actually sent them — see $amenitiesGiven. */
                if ($amenitiesGiven) {
                    RoomQueries::saveAmenities($rid, json_encode($amenityKeys, JSON_UNESCAPED_SLASHES));
                }
                if ($descGiven) {
                    RoomQueries::saveDescription($rid, $desc !== '' ? $desc : null);
                }
                if ($glanceGiven) {
                    RoomQueries::saveGlance($rid, $glance);
                }

                if ($roomType === 'event' && ($capGiven || $feeGiven)) {
                    $o = RoomQueries::findEventDetails($rid) ?? ['attendee_capacity' => 0, 'fee_per_day' => 0];
                    $newCap = $capGiven ? $cap : (int) $o['attendee_capacity'];
                    $newFee = $feeGiven ? $fee : (float) $o['fee_per_day'];
                    if (abs($newFee - (float) $o['fee_per_day']) > 0.004 && session('account_type') !== 'admin') {
                        $this->reply(403, ['ok' => false, 'error' => 'admin_price', 'message' => 'Only an administrator can change a price.']);
                    }
                    RoomQueries::saveEventDetails($rid, $newCap, $newFee);
                    /* a booking snapshots its price, so a new rate only reaches NEW ones */
                    if (abs($newFee - (float) $o['fee_per_day']) > 0.004) {
                        $notes[] = 'The new rate applies to new bookings; existing bookings keep the price they were made at.';
                    }
                    /* capacity may go down; say which upcoming bookings are now over it */
                    if ($newCap < (int) $o['attendee_capacity']) {
                        $overRows = RoomQueries::bookingsOverCapacity($rid, $newCap);
                        if ($overRows) {
                            $notes[] = 'These upcoming bookings expect more than ' . $newCap . ' people: ' . implode(', ', $this->refs($overRows)) . '. They are kept as booked.';
                        }
                    }
                }

                if ($hostelGiven) {
                    $oldRate = RoomQueries::findHostelRate($rid);
                    RoomQueries::saveHostelDetails($rid, $hRate, $hCr, $hIn, $hOut);
                    if (abs($hRate - $oldRate) > 0.004 && session('account_type') !== 'admin') {
                        $this->reply(403, ['ok' => false, 'error' => 'admin_price', 'message' => 'Only an administrator can change a price.']);
                    }
                    if (abs($hRate - $oldRate) > 0.004) {
                        $notes[] = 'The new rate applies to new bookings; existing stays keep the price they were booked at.';
                    }
                    $this->setBeds($rid, $roomCode, $hBeds);
                }

                if ($mt !== null) {
                    $affected = $this->applyMaintenance($rid, $roomType, $mt, $actor);
                    if ($affected) {
                        $notes[] = 'These bookings fall inside the closure and still need you: ' . implode(', ', $affected) . '. Nothing was cancelled.';
                    }
                }
            });
            $this->reply(200, ['ok' => true, 'name' => $name, 'venue' => $venueName, 'notes' => $notes,
                'message' => 'Room saved.' . ($notes ? "\n\n" . implode("\n\n", $notes) : '')]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (RoomSaveRefusal $e) {
            $this->reply(409, ['ok' => false, 'error' => 'refused', 'message' => $e->getMessage()]);
        } catch (PDOException $e) {
            /* uq_rooms_venue_name: two rooms with the same name in one venue would be
               indistinguishable in every dropdown and every booking reference. */
            if ($e->getCode() === '23000' && stripos((string) ($e->errorInfo[2] ?? ''), 'uq_rooms_venue_name') !== false) {
                $this->reply(409, ['ok' => false, 'error' => 'duplicate',
                    'message' => 'That location already has a room with this name.']);
            }
            sql_failed($e);
            $this->reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the room was not saved.']);
        } catch (\Throwable $e) {
            sql_failed($e);
            $this->reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the room was not saved.']);
        }
    }
}
