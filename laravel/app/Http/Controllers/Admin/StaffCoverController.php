<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use App\Queries\CoverQueries;
use App\Queries\StaffHallQueries;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/* =====================================================================
   COVERS AND MOVES BETWEEN GROUPS (Aron 2026-11-05) — admins only, JSON.
   Staff ask in person: there is no staff side to this.

   POST action=cover_create  user_id, venue_id, room_id ('' = the whole office), from, to, reason
        action=cover_approve id          action=cover_reject id, reason
        action=cover_end     id
        action=move_create   user_id, venue_id, reason, replacements = JSON {hall id: user id}
        action=move_approve  id          action=move_reject  id, reason

   COVER: the staff member also handles that office's bookings and gets its news, 7 days
   at most; it ends by itself. Set up by the staff member's office admin (or the super
   admin). Inside one group it counts at once; across groups the destination's admin
   approves or rejects, and unanswered it expires at its start time. A group with no
   normal admin: the super admin answers. The super admin alone is always enough. Either
   side's admin may end a cover early.
   MOVE: a permanent move to the other group, started the same way, approved the same way,
   unanswered it expires after 3 days. It must name a replacement for each hall it would
   leave with nobody. A move inside a group stays on the staff list (StaffController).
   ===================================================================== */
class StaffCoverController extends Controller
{
    use RepliesJson;

    public function __invoke(Request $request)
    {
        $this->requirePost($request);
        $this->requireStaffJson();
        legacy('auth', 'notifications');
        if (!admin_is_admin()) {
            $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'Only an administrator can set up covers and moves.']);
        }
        $actor = (int) session('user_id');
        match ((string) $request->input('action', '')) {
            'cover_create'  => $this->coverCreate($request, $actor),
            'cover_approve' => $this->coverAnswer($request, $actor, true),
            'cover_reject'  => $this->coverAnswer($request, $actor, false),
            'cover_end'     => $this->coverEnd($request, $actor),
            'move_create'   => $this->moveCreate($request, $actor),
            'move_approve'  => $this->moveAnswer($request, $actor, true),
            'move_reject'   => $this->moveAnswer($request, $actor, false),
            default         => $this->reply(400, ['ok' => false, 'error' => 'bad_action', 'message' => 'Unknown action.']),
        };
    }

    /* What Staff Management shows: the lists, and what this admin may do with each row. */
    public static function pageData(): array
    {
        $super = admin_is_super();
        $group = self::myGroup();
        $covers = array_map(fn ($c) => $c + [
            'place'     => self::place($c),
            'canAnswer' => $c['state'] === 'waiting' && ($super || $group === $c['dest_group']),
            'canEnd'    => in_array($c['state'], ['waiting', 'scheduled', 'on'], true)
                           && ($super || in_array($group, [$c['staff_group'], $c['dest_group']], true)),
        ], CoverQueries::listCovers($super ? null : (string) $group));
        $moves = array_map(fn ($m) => $m + [
            'canAnswer' => $m['state'] === 'waiting' && ($super || $group === $m['to_group']),
        ], CoverQueries::listMoves($super ? null : (string) $group));
        $offices = CoverQueries::offices();
        $staff = [];
        foreach ($offices as $office) {
            foreach (StaffHallQueries::staffOf((int) $office['id']) as $id => $name) {
                $staff[(int) $office['id']][] = ['id' => $id, 'name' => $name];
            }
        }

        return ['covers' => $covers, 'moves' => $moves, 'offices' => $offices, 'staff' => $staff,
                'halls' => StaffHallQueries::hallsOf(array_column($offices, 'id')), 'group' => $group, 'super' => $super];
    }

    private function coverCreate(Request $request, int $actor): never
    {
        $staff = $this->managedStaff($request);
        $office = $this->office($request);
        $roomId = (string) $request->input('room_id', '') === '' ? null : (int) $request->input('room_id');
        if ($roomId !== null && !in_array($roomId, array_map('intval', array_column(StaffHallQueries::hallsOf([(int) $office['id']]), 'id')), true)) {
            $this->reply(400, ['ok' => false, 'field' => 'room_id', 'message' => 'That hall is not part of ' . $office['name'] . '.']);
        }
        $reason = $this->reason($request);
        $from = $this->when($request, 'from', 'start');
        $to = $this->when($request, 'to', 'end');
        $now = new DateTimeImmutable();
        if ($to <= $from || $to <= $now) {
            $this->reply(400, ['ok' => false, 'field' => 'to', 'message' => 'The cover must end after it starts, and later than now.']);
        }
        if ($to > $from->modify('+7 days')) {
            $this->reply(400, ['ok' => false, 'field' => 'to', 'message' => 'A cover lasts 7 days at most.']);
        }
        $atOnce = admin_is_super() || $staff['office_group'] === $office['office_group'];
        if (!$atOnce && $from <= $now) {
            $this->reply(400, ['ok' => false, 'field' => 'from', 'message' => 'This cover needs the other group\'s approval, so it must start later than now.']);
        }

        $id = CoverQueries::createCover((int) $staff['id'], (int) $office['id'], $roomId, $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'), $reason, $actor, $atOnce);
        $cover = CoverQueries::findCover($id);
        if ($atOnce) {
            $this->tell([$cover['staff_group'], $cover['dest_group']], 'cover_set', $actor, self::coverDetail($cover));
            $this->reply(200, ['ok' => true, 'id' => $id, 'waiting' => false, 'message' => 'Cover set.']);
        }
        $this->tell([$cover['dest_group']], 'cover_request', $actor, self::coverDetail($cover));
        $this->reply(200, ['ok' => true, 'id' => $id, 'waiting' => true,
            'message' => 'Waiting for approval from ' . (OFFICE_GROUPS[$cover['dest_group']] ?? 'the other group') . '.']);
    }

    private function coverAnswer(Request $request, int $actor, bool $approve): never
    {
        $cover = $this->cover($request);
        if (!admin_is_super() && self::myGroup() !== $cover['dest_group']) {
            $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'Only the admin of ' . $cover['office'] . ' can answer this cover.']);
        }
        $reason = $approve ? null : $this->reason($request);
        $done = $approve ? CoverQueries::approveCover((int) $cover['id'], $actor) : CoverQueries::rejectCover((int) $cover['id'], $reason, $actor);
        if (!$done) {
            $this->reply(409, ['ok' => false, 'error' => 'closed', 'message' => 'This cover is no longer waiting for an answer.']);
        }
        $this->tell([$cover['staff_group'], $cover['dest_group']], $approve ? 'cover_approved' : 'cover_rejected', $actor,
            self::coverDetail($cover) + ['reason' => $reason]);
        $this->reply(200, ['ok' => true, 'message' => $approve ? 'Cover approved.' : 'Cover rejected.']);
    }

    private function coverEnd(Request $request, int $actor): never
    {
        $cover = $this->cover($request);
        if (!admin_is_super() && !in_array(self::myGroup(), [$cover['staff_group'], $cover['dest_group']], true)) {
            $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'Only an admin of either office can end this cover.']);
        }
        if (!CoverQueries::endCover((int) $cover['id'], $actor)) {
            $this->reply(409, ['ok' => false, 'error' => 'closed', 'message' => 'This cover has already ended.']);
        }
        $this->tell([$cover['staff_group'], $cover['dest_group']], 'cover_ended', $actor, self::coverDetail($cover));
        $this->reply(200, ['ok' => true, 'message' => 'Cover ended.']);
    }

    private function moveCreate(Request $request, int $actor): never
    {
        $staff = $this->managedStaff($request);
        $office = $this->office($request);
        if ($staff['office_group'] === $office['office_group']) {
            $this->reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'Inside a group, change the office on the staff list.']);
        }
        $reason = $this->reason($request);
        if (CoverQueries::hasWaitingMove((int) $staff['id'])) {
            $this->reply(409, ['ok' => false, 'error' => 'waiting', 'message' => $staff['full_name'] . ' already has a move waiting for an answer.']);
        }
        $replacements = $this->replacements($request, $staff);

        if (!admin_is_super()) {
            $id = CoverQueries::createMove((int) $staff['id'], (int) $staff['venue_id'], (int) $office['id'], $reason, $replacements, $actor);
            $this->tell([$office['office_group']], 'move_request', $actor, self::moveDetail(CoverQueries::findMove($id)));
            $this->reply(200, ['ok' => true, 'id' => $id, 'waiting' => true,
                'message' => 'Waiting for approval from ' . (OFFICE_GROUPS[$office['office_group']] ?? 'the other group') . '.']);
        }
        $id = DB::transaction(function () use ($staff, $office, $reason, $replacements, $actor) {
            $id = CoverQueries::createMove((int) $staff['id'], (int) $staff['venue_id'], (int) $office['id'], $reason, $replacements, $actor);
            $this->applyMove(CoverQueries::findMove($id), $actor);
            return $id;
        });
        $move = CoverQueries::findMove($id);
        $this->tell([$move['from_group'], $move['to_group']], 'move_approved', $actor, self::moveDetail($move));
        $this->reply(200, ['ok' => true, 'id' => $id, 'waiting' => false, 'message' => $staff['full_name'] . ' moved to ' . $office['name'] . '.']);
    }

    private function moveAnswer(Request $request, int $actor, bool $approve): never
    {
        $move = CoverQueries::findMove((int) $request->input('id'));
        if ($move === null) {
            $this->reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'That move no longer exists.']);
        }
        if (!admin_is_super() && self::myGroup() !== $move['to_group']) {
            $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'Only the admin of ' . $move['to_office'] . ' can answer this move.']);
        }
        if ($move['state'] !== 'waiting') {
            $this->reply(409, ['ok' => false, 'error' => 'closed', 'message' => 'This move is no longer waiting for an answer.']);
        }
        $reason = null;
        if ($approve) {
            DB::transaction(fn () => $this->applyMove($move, $actor));
        } else {
            $reason = $this->reason($request);
            CoverQueries::decideMove((int) $move['id'], false, $reason, $actor);
        }
        $this->tell([$move['from_group'], $move['to_group']], $approve ? 'move_approved' : 'move_rejected', $actor,
            self::moveDetail($move) + ['reason' => $reason]);
        $this->reply(200, ['ok' => true, 'message' => $approve ? $move['staff_name'] . ' moved to ' . $move['to_office'] . '.' : 'Move rejected.']);
    }

    /* The move itself, in the caller's transaction. Each replacement takes their hall at
       this same moment; anything changed since it was asked for refuses the whole move
       (reply() throws, so the transaction rolls back). */
    private function applyMove(array $move, int $actor): void
    {
        $userId = (int) $move['user_id'];
        $office = StaffHallQueries::staffOf((int) $move['from_venue_id']);
        foreach (json_decode((string) $move['replacements'], true) ?: [] as $hallId => $replacement) {
            if (!isset($office[(int) $replacement]) || (int) $replacement === $userId) {
                $this->reply(409, ['ok' => false, 'error' => 'replacement', 'message' => 'A replacement named in this move is no longer available. Reject it and ask for a new one.']);
            }
            StaffHallQueries::assign((int) $hallId, (int) $replacement, $actor);
        }
        if ($halls = StaffHallQueries::lastStaffOf($userId)) {
            $this->reply(409, ['ok' => false, 'error' => 'last_staff', 'message' => StaffHallQueries::lastStaffMessage($halls)]);
        }
        if (!CoverQueries::moveStaff($userId, (int) $move['from_venue_id'], (int) $move['to_venue_id'])
            || !CoverQueries::decideMove((int) $move['id'], true, null, $actor)) {
            $this->reply(409, ['ok' => false, 'error' => 'closed', 'message' => 'This staff member has changed office since the move was asked for.']);
        }
    }

    /* Each hall the move would leave with nobody needs a replacement of the same office:
       hall id => user id. No other staff there (the hostel always, as it is one unit): it
       cannot be sent. */
    private function replacements(Request $request, array $staff): array
    {
        $empty = StaffHallQueries::lastStaffOf((int) $staff['id']);
        $others = StaffHallQueries::staffOf((int) $staff['venue_id']);
        unset($others[(int) $staff['id']]);
        if ($empty && (isset($empty[0]) || !$others)) {
            $this->reply(409, ['ok' => false, 'error' => 'no_replacement', 'message' => StaffHallQueries::lastStaffMessage($empty, 'Create or assign')]);
        }
        $given = json_decode((string) $request->input('replacements', ''), true);
        $out = [];
        foreach ($empty as $hallId => $hall) {
            $who = is_array($given) ? (int) ($given[$hallId] ?? 0) : 0;
            if (!isset($others[$who])) {
                $this->reply(400, ['ok' => false, 'error' => 'replacement', 'message' => 'Name a replacement staff member for ' . $hall . '.']);
            }
            $out[$hallId] = $who;
        }
        return $out;
    }

    /* The staff member in the request: active, staff, and this admin's to manage. */
    private function managedStaff(Request $request): array
    {
        $staff = CoverQueries::staffAccount((int) $request->input('user_id'));
        if ($staff === null || $staff['account_type'] !== 'staff' || !$staff['is_active'] || $staff['venue_id'] === null) {
            $this->reply(400, ['ok' => false, 'field' => 'user_id', 'message' => 'Choose an active staff member.']);
        }
        if (!staff_may_manage_account($staff)) {
            $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'Only the admin of their office can set this up.']);
        }
        return $staff;
    }

    private function office(Request $request): array
    {
        $office = CoverQueries::office((int) $request->input('venue_id'));
        if ($office === null) {
            $this->reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'Choose an office.']);
        }
        return $office;
    }

    private function cover(Request $request): array
    {
        $cover = CoverQueries::findCover((int) $request->input('id'));
        if ($cover === null) {
            $this->reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'That cover no longer exists.']);
        }
        return $cover;
    }

    private function reason(Request $request): string
    {
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '' || mb_strlen($reason) > 500) {
            $this->reply(400, ['ok' => false, 'field' => 'reason', 'message' => 'Write the reason (up to 500 characters).']);
        }
        return $reason;
    }

    /* A datetime-local value. A date that does not exist (Feb 30) is refused, not rolled over. */
    private function when(Request $request, string $field, string $word): DateTimeImmutable
    {
        $raw = (string) $request->input($field, '');
        $at = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw);
        if ($at === false || $at->format('Y-m-d\TH:i') !== $raw) {
            $this->reply(400, ['ok' => false, 'field' => $field, 'message' => 'Set the ' . $word . ' date and time.']);
        }
        return $at;
    }

    /* A normal admin's group; null for the super admin. */
    private static function myGroup(): ?string
    {
        return DB::selectOne('SELECT office_group FROM users WHERE id = ?', [(int) session('user_id')])?->office_group;
    }

    private static function place(array $cover): string
    {
        return ($cover['hall'] !== null ? $cover['hall'] . ', ' : '') . $cover['office'];
    }

    private static function coverDetail(array $cover): array
    {
        return ['staff' => $cover['staff_name'], 'place' => self::place($cover), 'from' => $cover['starts_at'], 'to' => $cover['ends_at']];
    }

    private static function moveDetail(array $move): array
    {
        return ['staff' => $move['staff_name'], 'from' => $move['from_office'], 'to' => $move['to_office']];
    }

    /* Tell each side once. A failure costs a notification, never the change itself. */
    private function tell(array $groups, string $kind, int $actor, array $detail): void
    {
        try {
            foreach (array_unique(array_filter($groups)) as $group) {
                CoverQueries::notifyGroup($group, $kind, $actor, $detail);
            }
        } catch (\Throwable $e) {
            sql_failed($e, optional: true);
        }
    }
}
