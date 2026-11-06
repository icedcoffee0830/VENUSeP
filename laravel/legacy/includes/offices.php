<?php
/* Holds: each office's own settings: hours, closures, discount, grace days, refund switch. */
/* =====================================================================
   THE OFFICES (Aron, 2026-11-01). Each `venues` row runs itself: its own
   hours, closed days, closures, USeP discount, grace days, lead time and
   cleanup gap, set by an admin on Office Settings. One read, one shape.
   ===================================================================== */
require_once __DIR__ . '/db.php';

/* venue id => the office's settings; closures that have not ended only. */
function office_all(): array
{
    static $all = null;
    if ($all !== null) {
        return $all;
    }
    $all = [];
    if (venusep_db() === null) {
        return $all;
    }
    foreach (\App\Queries\CatalogQueries::offices() as $v) {
        $all[(int) $v['id']] = [
            'id'         => (int) $v['id'],
            'name'       => (string) $v['name'],
            'type'       => (string) $v['venue_type'],
            'opens'      => substr((string) $v['opens_at'], 0, 5),
            'closes'     => substr((string) $v['closes_at'], 0, 5),
            'closedDays' => array_map('intval', array_values(array_filter(explode(',', (string) $v['closed_weekdays']), 'strlen'))),
            'closures'   => [],
            'discount'   => (int) $v['discount_percent'],
            'grace'      => (int) $v['postpay_grace_days'],
            'lead'       => (int) $v['lead_hours'],
            'gap'        => (int) $v['cleanup_gap_minutes'],
            /* contact info (DB-DECISIONS 28), edited on Office Settings */
            'phone'      => (string) $v['contact_phone'],
            'email'      => (string) $v['contact_email'],
            'location'   => (string) $v['address'],
            /* refund requests on NEW bookings (DB-DECISIONS 28): the super admin's switch, Payment Settings */
            'refunds'    => (bool) $v['refunds_enabled'],
        ];
    }
    foreach (\App\Queries\CatalogQueries::officeClosures() as $c) {
        if (isset($all[(int) $c['venue_id']])) {
            $all[(int) $c['venue_id']]['closures'][] = ['id' => (int) $c['id'], 'from' => $c['from_date'], 'until' => $c['until_date'], 'reason' => (string) $c['reason']];
        }
    }
    return $all;
}

function office_by_id($venueId): ?array
{
    return office_all()[(int) $venueId] ?? null;
}

function office_of_room($roomCode): ?array
{
    if (venusep_db() === null || !is_string($roomCode)) {
        return null;
    }
    $id = \App\Queries\CatalogQueries::officeIdOfRoom($roomCode);
    return $id === null ? null : office_by_id($id);
}

function office_of_booking(int $bookingId): ?array
{
    if (venusep_db() === null) {
        return null;
    }
    $id = \App\Queries\CatalogQueries::officeIdOfBooking($bookingId);
    return $id === null ? null : office_by_id($id);
}

/* "Monday to Friday, 7:00 AM – 10:00 PM", from the office's closed days and hours.
   The hostel has no opening hours (it books by the night): its open days only. */
function office_hours_label(array $office): string
{
    $week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];   /* Monday first: index i is weekday (i + 1) % 7 */
    $open = array_keys(array_filter($week, fn ($name, $i) => !in_array(($i + 1) % 7, $office['closedDays'], true), ARRAY_FILTER_USE_BOTH));
    $run = count($open) === 7 ? 'Every day'
        : (count($open) > 2 && end($open) - $open[0] === count($open) - 1
            ? $week[$open[0]] . ' to ' . $week[end($open)]
            : implode(', ', array_map(fn ($i) => $week[$i], $open)));
    if ($office['type'] === 'hostel') {
        return $run;
    }
    return $run . ', ' . date('g:i A', strtotime($office['opens'])) . ' – ' . date('g:i A', strtotime($office['closes']));
}

/* "USeP Venues: 0900 000 0000 · usep.venues@example.com · <location> · <hours>" (plain text). */
function office_contact_line(array $office): string
{
    return $office['name'] . ': ' . implode(' · ', array_filter([$office['phone'], $office['email'], $office['location'], office_hours_label($office)], 'strlen'));
}

/* The office of the room this page is about (?room=), or null on a page about none. */
function office_page(): ?array
{
    static $done = false, $office = null;
    if (!$done) {
        $done = true;
        $office = office_of_room(request()->query('room'));
    }
    return $office;
}

/* Why the office is closed on this date, or null when it is open. */
function office_closed_reason(array $office, string $iso): ?string
{
    if (in_array((int) date('w', strtotime($iso)), $office['closedDays'], true)) {
        return 'The office is closed on ' . date('l', strtotime($iso)) . 's';
    }
    foreach ($office['closures'] as $c) {
        if ($iso >= $c['from'] && $iso <= $c['until']) {
            return $c['reason'];
        }
    }
    return null;
}

/* "20%", or "up to 25%" where the offices' rates differ: for pages about no one room. */
function office_discount_label(): string
{
    $rates = array_unique(array_column(office_all(), 'discount')) ?: [20];
    return (count($rates) > 1 ? 'up to ' : '') . max($rates) . '%';
}

/* Do this session's lists include the hostel? Staff and admins see their own offices only
   (staff_sees_venue, auth.php); customers and guests always do. */
function office_hostel_shown(): bool
{
    return (bool) array_filter(office_all(), fn ($o) => $o['type'] === 'hostel' && staff_sees_venue($o['id']));
}

/* The JS twin of office_closed_reason(), printed once on each booking page. */
function office_js(): string
{
    return <<<'JS'
function officeClosure(r,ds){
  const o=r&&r.office; if(!o) return null;
  if(o.closedDays.includes(new Date(ds+'T00:00:00').getDay())) return 'The office is closed on this day';
  const c=o.closures.find(x=>ds>=x.from&&ds<=x.until);
  return c?c.reason:null;
}
JS;
}
