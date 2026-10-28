<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use PDO;
use PDOException;
use Symfony\Component\HttpFoundation\InputBag;

/* =====================================================================
   ROOM SAVE (was admin/room-save.php) — creates or updates a room, called
   by "Save Changes" on room-form and hostel-room-form.

   POST  _token, room_id (room_code, e.g. "r1"; EMPTY = create), name,
         venue (venue name), room_type ('event' | 'hostel')
   creating also: description, and per type —
         event:  capacity, fee
         hostel: beds, rate, cr_type
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
     · a room moves to another venue only with no upcoming live bookings
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
        $iso = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($iso, $from) || ($until !== null && (!preg_match($iso, $until) || $until < $from))) {
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
    private function applyMaintenance(PDO $pdo, $roomId, $roomType, array $mt, $actor)
    {
        $pdo->prepare('DELETE FROM maintenance_windows WHERE room_id = :r AND (until_date IS NULL OR until_date >= CURDATE())')
            ->execute([':r' => $roomId]);
        if (!$mt['on']) {
            return [];
        }
        $pdo->prepare(
            'INSERT INTO maintenance_windows (room_id, from_date, until_date, reason, blocks_booking, created_by_user_id)
             VALUES (:r, :f, :u, :why, :b, :a)'
        )->execute([':r' => $roomId, ':f' => $mt['from'], ':u' => $mt['until'], ':why' => $mt['reason'],
                    ':b' => $mt['hard'] ? 1 : 0, ':a' => $actor]);
        if (!$mt['hard']) {
            return [];
        }
        $until = $mt['until'] ?? '9999-12-31';
        $sql = $roomType === 'hostel'
            ? "SELECT DISTINCT b.id, b.submitted_at FROM bed_reservation_nights n
                 JOIN hostel_beds hb ON hb.id = n.bed_id JOIN bookings b ON b.id = n.booking_id
                WHERE hb.room_id = :r AND n.released_at IS NULL AND b.reservation_status IN ('pending','approved')
                  AND n.night_date BETWEEN :f AND :u ORDER BY b.id"
            : "SELECT DISTINCT b.id, b.submitted_at FROM venue_booking_slots s JOIN bookings b ON b.id = s.booking_id
                WHERE s.room_id = :r AND s.released_at IS NULL AND b.reservation_status IN ('pending','approved')
                  AND s.slot_date BETWEEN :f AND :u ORDER BY b.id";
        $st = $pdo->prepare($sql);
        $st->execute([':r' => $roomId, ':f' => $mt['from'], ':u' => $until]);
        return $this->refs($st->fetchAll());
    }

    /* HOSTEL BEDS (2026-10-27): make the room hold $target active beds. Adding
       reactivates retired beds first, then makes new ones. Removing takes the
       highest-numbered beds, and is REFUSED if any of them has an upcoming
       night booked — a guest's bed must never vanish. Beds are retired
       (is_active = 0), never deleted: past stays still point at them. */
    private function setBeds(PDO $pdo, $roomId, $roomCode, $target)
    {
        $beds = $pdo->prepare('SELECT id, bed_code, display_order, is_active FROM hostel_beds WHERE room_id = :r ORDER BY display_order, id');
        $beds->execute([':r' => $roomId]);
        $all = $beds->fetchAll();
        $active = array_values(array_filter($all, function ($b) { return (int) $b['is_active'] === 1; }));
        $count = count($active);
        if ($target > $count) {
            $need = $target - $count;
            foreach ($all as $b) {
                if ($need === 0) { break; }
                if ((int) $b['is_active'] === 0) {
                    $pdo->prepare('UPDATE hostel_beds SET is_active = 1 WHERE id = :id')->execute([':id' => $b['id']]);
                    $need--;
                }
            }
            $order = $all ? max(array_map(function ($b) { return (int) $b['display_order']; }, $all)) : 0;
            $add = $pdo->prepare('INSERT INTO hostel_beds (room_id, bed_code, bed_label, display_order) VALUES (:r, :c, :l, :o)');
            while ($need-- > 0) {
                $order++;
                $add->execute([':r' => $roomId, ':c' => $roomCode . '-b' . $order, ':l' => 'Bed ' . $order, ':o' => $order]);
            }
        } elseif ($target < $count) {
            $retire = array_slice(array_reverse($active), 0, $count - $target);
            $ids = implode(',', array_map(function ($b) { return (int) $b['id']; }, $retire));
            $held = $pdo->query(
                "SELECT DISTINCT b.id, b.submitted_at FROM bed_reservation_nights n JOIN bookings b ON b.id = n.booking_id
                  WHERE n.bed_id IN ($ids) AND n.released_at IS NULL AND n.night_date >= CURDATE() ORDER BY b.id"
            )->fetchAll();
            if ($held) {
                throw new RoomSaveRefusal('Those beds have upcoming guests (' . implode(', ', $this->refs($held))
                    . '), so the bed count cannot go below ' . $count . ' yet. Move or finish those stays first.');
            }
            $pdo->exec("UPDATE hostel_beds SET is_active = 0 WHERE id IN ($ids)");
        }
    }

    public function __invoke(Request $request)
    {
        $this->requirePost($request);
        $this->requireStaffJson();
        $this->requireCsrfJson($request);

        extract(legacy('db', 'amenities', 'bookings'));   /* amenity_keys_clean(), $AMENITY_GROUPS*, booking_reference() */
        $post = $request->request;
        $actor = (int) session('user_id');

        /* An empty room_id means CREATE. The Add Room screen has existed all along;
           until now it answered with an alert saying it could not actually create one. */
        $isNewRoom = trim((string) ($post->get('room_id') ?? '')) === '';
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
        $capGiven = $post->has('capacity');
        $cap = (int) ($post->get('capacity') ?? 0);
        $feeGiven = $post->has('fee');
        $fee = (float) ($post->get('fee') ?? -1);
        if ($capGiven && ($cap < 1 || $cap > 100000)) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_capacity', 'message' => 'Capacity must be at least 1 person.']);
        }
        if ($feeGiven && ($fee < 0 || $fee > 10000000)) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_fee', 'message' => 'Enter a rate of ₱0 or more.']);
        }
        $hostelGiven = $roomType === 'hostel' && $post->has('rate');
        $hRate  = (float) ($post->get('rate') ?? -1);
        $hBeds  = (int) ($post->get('beds') ?? 0);
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
            if (!preg_match('/^\d{2}:\d{2}$/', $hIn) || !preg_match('/^\d{2}:\d{2}$/', $hOut)) {
                $this->reply(400, ['ok' => false, 'error' => 'bad_times', 'message' => 'Set the check-in and check-out times.']);
            }
        }
        $mt = $this->parseMaintenance($post);

        $pdo = venusep_db();
        if ($pdo === null) {
            $this->reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so the room was not saved.']);
        }

        try {
            $venue = $pdo->prepare('SELECT id, venue_type FROM venues WHERE name = :name');
            $venue->execute([':name' => $venueName]);
            $venueRow = $venue->fetch();
            if (!$venueRow) {
                $this->reply(400, ['ok' => false, 'error' => 'bad_venue', 'message' => 'Unknown location.']);
            }

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

                /* room_code is GENERATED, never typed — "r9", "h6" — continuing the
                   existing series. The prefix follows the TYPE, not the venue. */
                $prefix = $roomType === 'hostel' ? 'h' : 'r';
                $next = $pdo->prepare(
                    "SELECT COALESCE(MAX(CAST(SUBSTRING(room_code, 2) AS UNSIGNED)), 0) + 1
                       FROM rooms
                      WHERE room_code REGEXP CONCAT('^', :p, '[0-9]+$')"
                );
                $next->execute([':p' => $prefix]);
                $newCode = $prefix . (int) $next->fetchColumn();

                $pdo->beginTransaction();
                $pdo->prepare(
                    'INSERT INTO rooms (venue_id, room_code, name, room_type, description, amenities)
                     VALUES (:v, :c, :n, :t, :d, :a)'
                )->execute([
                    ':v' => $venueRow['id'], ':c' => $newCode, ':n' => $name, ':t' => $roomType,
                    ':d' => mb_substr(trim((string) ($post->get('description') ?? '')), 0, 2000) ?: null,
                    ':a' => json_encode($amenityKeys, JSON_UNESCAPED_SLASHES),
                ]);
                $newId = (int) $pdo->lastInsertId();

                if ($roomType === 'hostel') {
                    /* A hostel room is booked PER BED; the beds ARE the room's capacity. */
                    $beds = (int) ($post->get('beds') ?? 6);
                    $beds = max(1, min(20, $beds));
                    $rate = (float) ($post->get('rate') ?? 0);
                    $crType = ($post->get('cr_type') ?? '') === 'private' ? 'private' : 'communal';

                    $pdo->prepare(
                        'INSERT INTO hostel_room_details (room_id, cr_type, rate_per_head_per_night)
                         VALUES (:r, :c, :p)'
                    )->execute([':r' => $newId, ':c' => $crType, ':p' => $rate]);

                    $bed = $pdo->prepare(
                        'INSERT INTO hostel_beds (room_id, bed_code, bed_label, display_order)
                         VALUES (:r, :c, :l, :o)'
                    );
                    for ($i = 1; $i <= $beds; $i++) {
                        $bed->execute([':r' => $newId, ':c' => $newCode . '-b' . $i, ':l' => 'Bed ' . $i, ':o' => $i]);
                    }
                } else {
                    $pdo->prepare(
                        'INSERT INTO event_room_details (room_id, attendee_capacity, fee_per_day)
                         VALUES (:r, :c, :f)'
                    )->execute([
                        ':r' => $newId,
                        ':c' => max(1, (int) ($post->get('capacity') ?? 1)),
                        ':f' => max(0, (float) ($post->get('fee') ?? 0)),
                    ]);
                }
                if ($glanceGiven) {
                    $pdo->prepare('UPDATE rooms SET best_for = :b, catering = :c, accessibility = :a WHERE id = :id')
                        ->execute([':b' => $glance['best_for'] ?: null, ':c' => $glance['catering'] ?: null,
                                   ':a' => $glance['accessibility'] ?: null, ':id' => $newId]);
                }
                if ($hostelGiven) {
                    $pdo->prepare('UPDATE hostel_room_details SET check_in_time = :i, check_out_time = :o WHERE room_id = :r')
                        ->execute([':i' => $hIn . ':00', ':o' => $hOut . ':00', ':r' => $newId]);
                }
                if ($mt !== null) {
                    $this->applyMaintenance($pdo, $newId, $roomType, $mt, $actor);
                }
                $pdo->commit();

                $this->reply(200, ['ok' => true, 'created' => true, 'room_id' => $newCode,
                    'name' => $name, 'venue' => $venueName,
                    'message' => 'Room created as ' . $newCode . '. You can add its photos now.']);
            }

            $room = $pdo->prepare('SELECT id, room_type FROM rooms WHERE room_code = :code');
            $room->execute([':code' => $roomCode]);
            $roomRow = $room->fetch();
            if (!$roomRow) {
                $this->reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Room not found.']);
            }
            if ($roomRow['room_type'] !== $roomType) {
                $this->reply(409, ['ok' => false, 'error' => 'wrong_type', 'message' => 'This room does not match the form that submitted it.']);
            }

            /* The venue was already resolved above — one lookup serves both paths. */
            $venueId = (int) $venueRow['id'];
            $rid = (int) $roomRow['id'];
            $notes = [];

            $pdo->beginTransaction();

            /* MOVING A ROOM TO ANOTHER VENUE (2026-10-27): only with no upcoming live
               bookings — the venue decides which GCash account is paid and which staff
               handle the booking. And only to a venue of the same kind. */
            $cur = $pdo->prepare('SELECT venue_id FROM rooms WHERE id = :id FOR UPDATE');
            $cur->execute([':id' => $rid]);
            if ((int) $cur->fetchColumn() !== $venueId) {
                if ($venueRow['venue_type'] !== ($roomType === 'hostel' ? 'hostel' : 'event')) {
                    throw new RoomSaveRefusal($roomType === 'hostel' ? 'A hostel room can only belong to a hostel venue.' : 'An event room can only belong to an event venue.');
                }
                $up = $pdo->prepare(
                    "SELECT b.id, b.submitted_at FROM bookings b
                      WHERE b.room_id = :r AND b.reservation_status IN ('pending','approved')
                        AND fn_booking_last_day(b.id) >= CURDATE() ORDER BY b.id"
                );
                $up->execute([':r' => $rid]);
                $upcoming = $up->fetchAll();
                if ($upcoming) {
                    throw new RoomSaveRefusal('This room has upcoming bookings (' . implode(', ', $this->refs($upcoming))
                        . '), so it cannot move to another venue yet. The venue decides who is paid and which staff handle them.');
                }
            }

            $pdo->prepare('UPDATE rooms SET name = :name, venue_id = :venue_id WHERE id = :id')
                ->execute([':name' => $name, ':venue_id' => $venueId, ':id' => $rid]);
            /* amenities only when the form actually sent them — see $amenitiesGiven. */
            if ($amenitiesGiven) {
                $pdo->prepare('UPDATE rooms SET amenities = :a WHERE id = :id')
                    ->execute([':a' => json_encode($amenityKeys, JSON_UNESCAPED_SLASHES), ':id' => $rid]);
            }
            if ($descGiven) {
                $pdo->prepare('UPDATE rooms SET description = :d WHERE id = :id')->execute([':d' => $desc !== '' ? $desc : null, ':id' => $rid]);
            }
            if ($glanceGiven) {
                $pdo->prepare('UPDATE rooms SET best_for = :b, catering = :c, accessibility = :a WHERE id = :id')
                    ->execute([':b' => $glance['best_for'] ?: null, ':c' => $glance['catering'] ?: null,
                               ':a' => $glance['accessibility'] ?: null, ':id' => $rid]);
            }

            if ($roomType === 'event' && ($capGiven || $feeGiven)) {
                $old = $pdo->prepare('SELECT attendee_capacity, fee_per_day FROM event_room_details WHERE room_id = :r');
                $old->execute([':r' => $rid]);
                $o = $old->fetch() ?: ['attendee_capacity' => 0, 'fee_per_day' => 0];
                $newCap = $capGiven ? $cap : (int) $o['attendee_capacity'];
                $newFee = $feeGiven ? $fee : (float) $o['fee_per_day'];
                $pdo->prepare(
                    'INSERT INTO event_room_details (room_id, attendee_capacity, fee_per_day) VALUES (:r, :c, :f)
                     ON DUPLICATE KEY UPDATE attendee_capacity = VALUES(attendee_capacity), fee_per_day = VALUES(fee_per_day)'
                )->execute([':r' => $rid, ':c' => $newCap, ':f' => $newFee]);
                /* a booking snapshots its price, so a new rate only reaches NEW ones */
                if (abs($newFee - (float) $o['fee_per_day']) > 0.004) {
                    $notes[] = 'The new rate applies to new bookings; existing bookings keep the price they were made at.';
                }
                /* capacity may go down; say which upcoming bookings are now over it */
                if ($newCap < (int) $o['attendee_capacity']) {
                    $over = $pdo->prepare(
                        "SELECT b.id, b.submitted_at FROM bookings b JOIN venue_booking_details v ON v.booking_id = b.id
                          WHERE b.room_id = :r AND b.reservation_status IN ('pending','approved')
                            AND v.end_date >= CURDATE() AND v.attendee_count > :c ORDER BY b.id"
                    );
                    $over->execute([':r' => $rid, ':c' => $newCap]);
                    $overRows = $over->fetchAll();
                    if ($overRows) {
                        $notes[] = 'These upcoming bookings expect more than ' . $newCap . ' people: ' . implode(', ', $this->refs($overRows)) . '. They are kept as booked.';
                    }
                }
            }

            if ($hostelGiven) {
                $old = $pdo->prepare('SELECT rate_per_head_per_night FROM hostel_room_details WHERE room_id = :r');
                $old->execute([':r' => $rid]);
                $oldRate = (float) $old->fetchColumn();
                $pdo->prepare(
                    'UPDATE hostel_room_details SET rate_per_head_per_night = :p, cr_type = :c, check_in_time = :i, check_out_time = :o WHERE room_id = :r'
                )->execute([':p' => $hRate, ':c' => $hCr, ':i' => $hIn . ':00', ':o' => $hOut . ':00', ':r' => $rid]);
                if (abs($hRate - $oldRate) > 0.004) {
                    $notes[] = 'The new rate applies to new bookings; existing stays keep the price they were booked at.';
                }
                $this->setBeds($pdo, $rid, $roomCode, $hBeds);
            }

            if ($mt !== null) {
                $affected = $this->applyMaintenance($pdo, $rid, $roomType, $mt, $actor);
                if ($affected) {
                    $notes[] = 'These bookings fall inside the closure and still need you: ' . implode(', ', $affected) . '. Nothing was cancelled.';
                }
            }

            $pdo->commit();
            $this->reply(200, ['ok' => true, 'name' => $name, 'venue' => $venueName, 'notes' => $notes,
                'message' => 'Room saved.' . ($notes ? "\n\n" . implode("\n\n", $notes) : '')]);
        } catch (HttpResponseException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        } catch (RoomSaveRefusal $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $this->reply(409, ['ok' => false, 'error' => 'refused', 'message' => $e->getMessage()]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            /* uq_rooms_venue_name: two rooms with the same name in one venue would be
               indistinguishable in every dropdown and every booking reference. */
            if ($e->getCode() === '23000') {
                $this->reply(409, ['ok' => false, 'error' => 'duplicate',
                    'message' => 'That location already has a room with this name.']);
            }
            error_log('VENUSeP room-save: ' . $e->getMessage());
            $this->reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the room was not saved.']);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('VENUSeP room-save: ' . $e->getMessage());
            $this->reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the room was not saved.']);
        }
    }
}
