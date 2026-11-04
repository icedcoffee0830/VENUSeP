<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\BookingRules;
use Illuminate\Http\Request;
use PDO;

/* OFFICE SETTINGS (Aron, 2026-11-01): each office's hours, closed days, closures,
   USeP discount, grace days, lead time and cleanup gap. Admins only: a normal admin
   its own group's offices, the super admin every office (DB-DECISIONS 28).
   POST _token, venue_id, action=save|closure_add|closure_remove. Replies with JSON.
   A change applies to NEW bookings; existing ones keep what they were made under. */
class OfficeSettingsController extends Controller
{
    use RepliesJson;

    /* field => [label, column], logged in venue_settings_history */
    private const FIELDS = [
        'opens'    => ['Opening time', 'opens_at'],
        'closes'   => ['Closing time', 'closes_at'],
        'days'     => ['Closed days', 'closed_weekdays'],
        'discount' => ['USeP discount', 'discount_percent'],
        'grace'    => ['Grace days', 'postpay_grace_days'],
        'lead'     => ['Lead time (hours)', 'lead_hours'],
        'gap'      => ['Cleanup gap (minutes)', 'cleanup_gap_minutes'],
    ];

    public function index()
    {
        legacy('offices', 'auth');
        $offices = array_filter(office_all(), fn ($o) => staff_may_handle_venue($o['id']));
        $history = venusep_db_or_fail()->query(
            "SELECT h.venue_id, h.field, h.old_value, h.new_value, h.note, h.changed_at, COALESCE(u.username, u.email) AS who
               FROM venue_settings_history h LEFT JOIN users u ON u.id = h.changed_by_user_id
              ORDER BY h.id DESC LIMIT 60"
        )->fetchAll();
        $byOffice = [];
        foreach ($history as $h) {
            $byOffice[(int) $h['venue_id']][] = $h + ['label' => self::FIELDS[$h['field']][0] ?? $h['field']];
        }

        return view('admin.office-settings', ['offices' => $offices, 'historyByOffice' => $byOffice]);
    }

    public function save(Request $request)
    {
        legacy('auth', 'offices', 'bookings');
        $this->requireStaffJson();
        if (session('account_type') !== 'admin') {
            $this->reply(403, ['ok' => false, 'error' => 'not_admin', 'message' => 'Only an administrator can change office settings.']);
        }
        $pdo = venusep_db();
        if ($pdo === null) {
            $this->reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was changed.']);
        }
        $venueId = (int) $request->post('venue_id');
        $office = office_by_id($venueId);
        if ($office === null) {
            $this->reply(404, ['ok' => false, 'message' => 'That office no longer exists.']);
        }
        if (!staff_may_handle_venue($venueId)) {
            $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'That office belongs to another group. Only its admin or the super admin can change it.']);
        }
        $actor = (int) session('user_id');

        switch ((string) $request->post('action')) {
            case 'save':
                $this->saveSettings($request, $pdo, $office, $actor);
                $this->reply(200, ['ok' => true, 'message' => 'Saved. New bookings use these settings.']);
            case 'closure_add':
                $this->addClosure($request, $pdo, $venueId, $actor);
            case 'closure_remove':
                $pdo->prepare('DELETE FROM venue_closures WHERE id = :i AND venue_id = :v')
                    ->execute([':i' => (int) $request->post('closure_id'), ':v' => $venueId]);
                $this->reply(200, ['ok' => true, 'message' => 'Closure removed.']);
        }
        $this->reply(400, ['ok' => false, 'message' => 'Unknown action.']);
    }

    private function saveSettings(Request $request, PDO $pdo, array $office, int $actor): void
    {
        $isHostel = $office['type'] === 'hostel';
        $int = function (string $field, int $max, string $what) use ($request) {
            $raw = trim((string) $request->post($field));
            if (!preg_match('/^\d{1,4}$/', $raw) || (int) $raw > $max) {
                $this->reply(400, ['ok' => false, 'message' => $what . ' must be a whole number from 0 to ' . $max . '.']);
            }
            return (int) $raw;
        };
        $days = array_values(array_unique(array_filter((array) $request->post('days', []), fn ($d) => is_string($d) && preg_match('/^[0-6]$/', $d))));
        sort($days);
        $new = [
            'days'     => implode(',', $days),
            'discount' => $int('discount', 100, 'The discount'),
            'grace'    => $int('grace', 30, 'Grace days'),
        ];
        if (count($days) === 7) {
            $this->reply(400, ['ok' => false, 'message' => 'An office cannot be closed every day. Add a closure with dates instead.']);
        }
        /* The hostel books by the night: it has no opening hours, lead time or gap. */
        if (!$isHostel) {
            $opens = (string) $request->post('opens');
            $closes = (string) $request->post('closes');
            if (!preg_match('/^([01]\d|2[0-3]):(00|30)$/', $opens) || !preg_match('/^([01]\d|2[0-3]):(00|30)$/', $closes) || $closes <= $opens) {
                $this->reply(400, ['ok' => false, 'message' => 'Pick an opening and a closing time on the half hour, with closing after opening.']);
            }
            $new += ['opens' => $opens, 'closes' => $closes,
                     'lead' => $int('lead', 720, 'The lead time'), 'gap' => $int('gap', 240, 'The cleanup gap')];
        }

        $old = ['days' => implode(',', $office['closedDays']), 'discount' => $office['discount'], 'grace' => $office['grace'],
                'opens' => $office['opens'], 'closes' => $office['closes'], 'lead' => $office['lead'], 'gap' => $office['gap']];
        $changed = array_filter($new, fn ($v, $k) => (string) $v !== (string) $old[$k], ARRAY_FILTER_USE_BOTH);
        if (!$changed) {
            $this->reply(400, ['ok' => false, 'message' => 'Nothing has changed.']);
        }
        $reason = mb_substr(trim((string) $request->post('reason')), 0, 500);
        if ($reason === '' && (isset($changed['discount']) || isset($changed['grace']))) {
            $this->reply(400, ['ok' => false, 'message' => 'A reason is required when the discount or grace days change.']);
        }

        $pdo->beginTransaction();
        $log = $pdo->prepare('INSERT INTO venue_settings_history (venue_id, field, old_value, new_value, note, changed_by_user_id) VALUES (:v, :f, :o, :n, :w, :u)');
        foreach ($changed as $field => $value) {
            $column = self::FIELDS[$field][1];
            $pdo->prepare("UPDATE venues SET $column = :x WHERE id = :v")->execute([':x' => $value, ':v' => $office['id']]);
            $log->execute([':v' => $office['id'], ':f' => $field, ':o' => (string) $old[$field], ':n' => (string) $value,
                           ':w' => $reason !== '' ? $reason : null, ':u' => $actor]);
        }
        $pdo->commit();
    }

    /* Nothing is cancelled: the reply names the bookings inside the dates, for staff to sort out. */
    private function addClosure(Request $request, PDO $pdo, int $venueId, int $actor): never
    {
        $from = (string) $request->post('from');
        $until = (string) $request->post('until');
        $reason = mb_substr(trim((string) $request->post('reason')), 0, 500);
        if (!BookingRules::isDate($from) || !BookingRules::isDate($until) || $until < $from) {
            $this->reply(400, ['ok' => false, 'message' => 'Pick a start date and an end date, with the end on or after the start.']);
        }
        if ($until < date('Y-m-d')) {
            $this->reply(400, ['ok' => false, 'message' => 'Those dates have already passed.']);
        }
        if ($reason === '') {
            $this->reply(400, ['ok' => false, 'message' => 'Write the reason. Customers see it on the booking page.']);
        }
        $pdo->prepare('INSERT INTO venue_closures (venue_id, from_date, until_date, reason, created_by_user_id) VALUES (:v, :f, :u, :r, :a)')
            ->execute([':v' => $venueId, ':f' => $from, ':u' => $until, ':r' => $reason, ':a' => $actor]);

        $st = $pdo->prepare(
            "SELECT DISTINCT b.id, b.submitted_at FROM bookings b JOIN rooms r ON r.id = b.room_id
               LEFT JOIN venue_booking_slots s ON s.booking_id = b.id AND s.released_at IS NULL
               LEFT JOIN bed_reservation_nights n ON n.booking_id = b.id AND n.released_at IS NULL
              WHERE r.venue_id = :v AND b.reservation_status IN ('pending', 'approved')
                AND ((s.slot_date BETWEEN :f AND :u) OR (n.night_date BETWEEN :f2 AND :u2))"
        );
        $st->execute([':v' => $venueId, ':f' => $from, ':u' => $until, ':f2' => $from, ':u2' => $until]);
        $refs = array_map(fn ($b) => booking_reference($b['id'], $b['submitted_at']), $st->fetchAll());
        $this->reply(200, ['ok' => true, 'affected' => $refs, 'message' => $refs
            ? 'Closure saved. These bookings fall inside it and were not changed, so staff need to contact the customers: ' . implode(', ', $refs) . '.'
            : 'Closure saved. No bookings fall inside it.']);
    }
}
