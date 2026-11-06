{{-- COVERS AND MOVES on Staff Management (Aron 2026-11-05): the lists, the three forms and
     their script. Every rule is checked again by StaffCoverController / staff-save. Errors
     show inside the form: a <dialog> sits above the shared alert, which would be hidden.
     $smCover: StaffCoverController::pageData(). $smRows: the roster. --}}
@php
  $smCoverLabels = ['waiting' => 'Waiting for approval', 'scheduled' => 'Starts later', 'on' => 'On now', 'finished' => 'Finished',
                    'ended' => 'Ended early', 'rejected' => 'Rejected', 'expired' => 'Expired, no answer', 'moved' => 'Moved'];
  $smWhen = fn ($at) => date('M j, g:i A', strtotime($at));
@endphp
@push('styles')
<link rel="stylesheet" href="{{ asset_stamped('assets/css/staff-covers.css') }}" />
@endpush

<section class="staff-panel" aria-labelledby="smCoversTitle" data-tab-panel="covers"@if ($smTab !== 'covers') hidden @endif>
  <div class="staff-panel-header">
    <h2 id="smCoversTitle">Covers</h2>
    <div class="staff-panel-tools"><button type="button" class="btn-staff-add" style="border:0;cursor:pointer" id="smCoverOpen"><i class="bi bi-calendar-plus" aria-hidden="true"></i>Set up a cover</button></div>
  </div>
  @fragment('sm-covers')
  <div class="staff-panel-body" data-live="sm-covers">
@if ($smCover['covers'])
    <div class="sm-scroll">
      <table class="sm-list">
        <thead><tr><th>Staff</th><th>Covering</th><th>From – to</th><th>Reason</th><th>Status</th><th></th></tr></thead>
        <tbody>
@foreach ($smCover['covers'] as $c)
          <tr data-state="{{ $c['state'] }}">
            <td>{{ $c['staff_name'] }}<span class="sm-note">{{ $c['staff_office'] }}</span></td>
            <td>{{ $c['place'] }}</td>
            <td>{{ $smWhen($c['starts_at']) }} – {{ $smWhen($c['ends_at']) }}</td>
            <td>{{ $c['reason'] }}@if ($c['reject_reason'])<span class="sm-note">Rejected: {{ $c['reject_reason'] }}</span>@endif</td>
            <td>{{ $smCoverLabels[$c['state']] }}<span class="sm-note">Set up by {{ $c['created_by'] }}</span></td>
            <td><div class="sm-actions">
@if ($c['canAnswer'])
              <button type="button" class="sm-btn" data-act="cover_approve" data-id="{{ $c['id'] }}">Approve</button>
              <button type="button" class="sm-btn sm-btn-no" data-act="cover_reject" data-id="{{ $c['id'] }}">Reject</button>
@endif
@if ($c['canEnd'])
              <button type="button" class="sm-btn sm-btn-no" data-act="cover_end" data-id="{{ $c['id'] }}">End now</button>
@endif
            </div></td>
          </tr>
@endforeach
        </tbody>
      </table>
    </div>
@else
    <p class="sm-empty">No covers right now.</p>
@endif
  </div>
  @endfragment
</section>

<section class="staff-panel" aria-labelledby="smMovesTitle" data-tab-panel="moves"@if ($smTab !== 'moves') hidden @endif>
  <div class="staff-panel-header"><h2 id="smMovesTitle">Moves between groups</h2></div>
  @fragment('sm-moves')
  <div class="staff-panel-body" data-live="sm-moves">
@if ($smCover['moves'])
    <div class="sm-scroll">
      <table class="sm-list">
        <thead><tr><th>Staff</th><th>Move</th><th>Reason</th><th>Status</th><th></th></tr></thead>
        <tbody>
@foreach ($smCover['moves'] as $m)
          <tr data-state="{{ $m['state'] }}">
            <td>{{ $m['staff_name'] }}</td>
            <td>{{ $m['from_office'] }} to {{ $m['to_office'] }}</td>
            <td>{{ $m['reason'] }}@if ($m['reject_reason'])<span class="sm-note">Rejected: {{ $m['reject_reason'] }}</span>@endif</td>
            <td>{{ $smCoverLabels[$m['state']] }}<span class="sm-note">Started by {{ $m['requested_by'] }}</span></td>
            <td><div class="sm-actions">
@if ($m['canAnswer'])
              <button type="button" class="sm-btn" data-act="move_approve" data-id="{{ $m['id'] }}">Approve</button>
              <button type="button" class="sm-btn sm-btn-no" data-act="move_reject" data-id="{{ $m['id'] }}">Reject</button>
@endif
            </div></td>
          </tr>
@endforeach
        </tbody>
      </table>
    </div>
@else
    <p class="sm-empty">No moves waiting. Start one with Move group on a staff member's row under People.</p>
@endif
  </div>
  @endfragment
</section>

<dialog class="sm-dlg" id="smHallsDlg" aria-labelledby="smHallsTitle">
  <form method="dialog">
    <h3 id="smHallsTitle">Halls</h3>
    <p>They see and handle only these halls, plus any hall they cover. Every hall needs at least one staff member.</p>
    <div id="smHallsList"></div>
    <p class="sm-warn" id="smHallsWarn" role="alert"></p>
    <div class="sm-error" role="alert"></div>
    <div class="sm-foot">
      <button type="button" class="sm-btn" data-close>Cancel</button>
      <button type="submit" class="sm-btn sm-btn-main">Save halls</button>
    </div>
  </form>
</dialog>

<dialog class="sm-dlg" id="smCoverDlg" aria-labelledby="smCoverTitle">
  <form method="dialog">
    <h3 id="smCoverTitle">Set up a cover</h3>
    <p>The staff member also handles that office's bookings and notifications until the end time, 7 days at most.</p>
    <label class="sm-field">Staff member<select name="user_id" required></select></label>
    <label class="sm-field">Office<select name="venue_id" required></select></label>
    <label class="sm-field" id="smCoverHall">Hall<select name="room_id"></select></label>
    <label class="sm-field">From<input type="datetime-local" name="from" required/></label>
    <label class="sm-field">To<input type="datetime-local" name="to" required/></label>
    <label class="sm-field">Reason<textarea name="reason" rows="2" maxlength="500" autocomplete="off" required></textarea></label>
    <p id="smCoverHint" hidden>This office is in the other group, so its admin has to approve. An unanswered request expires at the start time.</p>
    <div class="sm-error" role="alert"></div>
    <div class="sm-foot">
      <button type="button" class="sm-btn" data-close>Cancel</button>
      <button type="submit" class="sm-btn sm-btn-main">Set up cover</button>
    </div>
  </form>
</dialog>

<dialog class="sm-dlg" id="smMoveDlg" aria-labelledby="smMoveTitle">
  <form method="dialog">
    <h3 id="smMoveTitle">Move to the other group</h3>
    <p>A permanent move. @if (!$smCover['super'])The other group's admin has 3 days to approve it.@endif</p>
    <label class="sm-field">New office<select name="venue_id" required></select></label>
    <label class="sm-field">Reason<textarea name="reason" rows="2" maxlength="500" autocomplete="off" required></textarea></label>
    <div id="smMoveHalls"></div>
    <div class="sm-error" role="alert"></div>
    <div class="sm-foot">
      <button type="button" class="sm-btn" data-close>Cancel</button>
      <button type="submit" class="sm-btn sm-btn-main">{{ $smCover['super'] ? 'Move now' : 'Send for approval' }}</button>
    </div>
  </form>
</dialog>

@fragment('sm-cover-data')<script type="application/json" data-live="sm-cover-data">{!! json_encode(['offices' => $smCover['offices'], 'halls' => $smCover['halls'], 'staff' => $smCover['staff'], 'group' => $smCover['group'], 'super' => $smCover['super'],
  'rows' => array_values(array_filter($smRows, fn ($r) => $r['role'] === 'Staff' && $r['canManage'] && $r['status'] === 'Active'))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>@endfragment
<script>
  /* Values from PHP for assets/js/staff-covers.js */
  var staffCovers = {
    staffCoverSaveUrl: @json(route('admin.staff-cover-save')),
    staffSaveUrl: @json(route('admin.staff-save')),
  };
</script>
<script src="{{ asset_stamped('assets/js/staff-covers.js') }}"></script>
