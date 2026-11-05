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
<style>
  .sm-list { width: 100%; border-collapse: collapse; font-size: .84rem; min-width: 640px; }
  .sm-list th { text-align: left; font-size: .74rem; font-weight: 650; color: #606a75; padding: .55rem .7rem; border-bottom: 1px solid var(--venusep-border); }
  .sm-list td { padding: .55rem .7rem; border-top: 1px solid #f0efec; vertical-align: top; line-height: 1.45; }
  .sm-scroll { overflow-x: auto; }
  .sm-empty { margin: 0; padding: .9rem 0; color: #606a75; font-size: .85rem; }
  .sm-note { display: block; color: #606a75; font-size: .78rem; }
  .sm-actions { display: flex; flex-wrap: wrap; gap: .3rem; }
  .sm-btn { min-height: 32px; padding: .3rem .75rem; border: 1px solid #d5d0c8; border-radius: 999px; background: #fff; color: #1f1e1e; font: inherit; font-size: .76rem; font-weight: 600; cursor: pointer; }
  .sm-btn:hover { background: #f8f7f5; }
  .sm-btn:focus-visible, .sm-dlg :focus-visible { outline: 2px solid #1d1b1b; outline-offset: 2px; }
  .sm-btn-no { border-color: #e3c3c3; color: #b23a3a; }
  .sm-btn-main { border-color: #a11626; background: #a11626; color: #fff; }
  .sm-btn-main:hover { background: #7d0f1e; }
  .sm-btn:disabled { opacity: .5; cursor: not-allowed; }
  .sm-section { margin-top: 1.25rem; }
  .sm-dlg { width: min(460px, calc(100vw - 32px)); padding: 1.25rem; border: 0; border-radius: 12px; color: #1f1e1e; }
  .sm-dlg::backdrop { background: rgba(0, 0, 0, .45); }
  .sm-dlg h3 { margin: 0 0 .25rem; font-size: 1.05rem; font-weight: 700; }
  .sm-dlg p { margin: 0 0 .75rem; color: #606a75; font-size: .84rem; }
  .sm-field { display: block; margin-bottom: .75rem; font-size: .84rem; font-weight: 600; }
  .sm-field select, .sm-field input, .sm-field textarea { display: block; width: 100%; min-height: 36px; margin-top: .25rem; padding: .35rem .5rem; border: 1px solid var(--venusep-border); border-radius: 8px; background: #fff; font: inherit; font-weight: 400; }
  .sm-check { display: flex; align-items: center; gap: .5rem; min-height: 32px; font-size: .88rem; cursor: pointer; }
  .sm-foot { display: flex; justify-content: flex-end; gap: .5rem; margin-top: .9rem; }
  .sm-error { color: #b23a3a; font-size: .84rem; font-weight: 600; }
  .sm-error:empty { display: none; }
</style>
@endpush

<section class="staff-panel sm-section" aria-labelledby="smCoversTitle">
  <div class="staff-panel-header">
    <h2 id="smCoversTitle">Covers</h2>
    <div class="staff-panel-tools"><button type="button" class="btn-staff-add" style="border:0;cursor:pointer" id="smCoverOpen"><i class="bi bi-calendar-plus" aria-hidden="true"></i>Set up a cover</button></div>
  </div>
  <div class="staff-panel-body">
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
</section>

<section class="staff-panel sm-section" aria-labelledby="smMovesTitle">
  <div class="staff-panel-header"><h2 id="smMovesTitle">Moves between groups</h2></div>
  <div class="staff-panel-body">
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
    <p class="sm-empty">No moves waiting. Start one with Move group on a staff member's row.</p>
@endif
  </div>
</section>

<dialog class="sm-dlg" id="smHallsDlg" aria-labelledby="smHallsTitle">
  <form method="dialog">
    <h3 id="smHallsTitle">Halls</h3>
    <p>They still see the whole office. Their notifications and My halls list follow these halls.</p>
    <div id="smHallsList"></div>
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
    <label class="sm-field">Reason<textarea name="reason" rows="2" maxlength="500" required></textarea></label>
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
    <label class="sm-field">Reason<textarea name="reason" rows="2" maxlength="500" required></textarea></label>
    <div id="smMoveHalls"></div>
    <div class="sm-error" role="alert"></div>
    <div class="sm-foot">
      <button type="button" class="sm-btn" data-close>Cancel</button>
      <button type="submit" class="sm-btn sm-btn-main">{{ $smCover['super'] ? 'Move now' : 'Send for approval' }}</button>
    </div>
  </form>
</dialog>

<script>
  (function () {
    const COVER = {!! json_encode(['offices' => $smCover['offices'], 'halls' => $smCover['halls'], 'staff' => $smCover['staff'], 'group' => $smCover['group'], 'super' => $smCover['super']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
    const ROWS = {!! json_encode(array_values(array_filter($smRows, fn ($r) => $r['role'] === 'Staff' && $r['canManage'] && $r['status'] === 'Active')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
    const CSRF = {!! json_encode(csrf_token()) !!};
    const COVER_URL = @json(route('admin.staff-cover-save'));
    const STAFF_URL = @json(route('admin.staff-save'));
    const officeOf = (id) => COVER.offices.find((o) => String(o.id) === String(id));

    /* option elements take text, so a typed name stays text */
    function fill(select, items) {
      select.replaceChildren(...items.map(([value, label]) => {
        const opt = document.createElement('option');
        opt.value = value; opt.textContent = label;
        return opt;
      }));
    }
    function post(url, fields) {
      const body = new FormData();
      Object.entries(fields).forEach(([k, v]) => body.append(k, v));
      body.append('_token', CSRF);
      return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
        .then((r) => r.json().catch(() => ({ ok: false, message: 'The server sent an unreadable reply.' })))
        .catch(() => ({ ok: false, message: 'Could not reach the server, so nothing was changed.' }));
    }
    /* a form in a dialog: send, show a refusal inside it, reload on success */
    function submits(dialog, send) {
      const form = dialog.querySelector('form');
      const error = dialog.querySelector('.sm-error');
      const button = form.querySelector('button[type=submit]');
      dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        error.textContent = '';
        button.disabled = true;
        const res = await send(new FormData(form));
        button.disabled = false;
        if (res.ok) { window.location.reload(); return; }
        error.textContent = res.message || 'Nothing was changed.';
      });
    }
    function open(dialog) {
      dialog.querySelector('.sm-error').textContent = '';
      dialog.showModal();
    }

    /* ---- halls of one staff member ---- */
    const hallsDlg = document.getElementById('smHallsDlg');
    let hallsFor = null;
    window.smOpenHalls = function (row) {
      hallsFor = row;
      document.getElementById('smHallsTitle').textContent = 'Halls of ' + row.name;
      const list = document.getElementById('smHallsList');
      list.replaceChildren(...COVER.halls.filter((h) => String(h.venue_id) === String(row.venueId)).map((hall) => {
        const label = document.createElement('label');
        const box = document.createElement('input');
        label.className = 'sm-check';
        box.type = 'checkbox'; box.name = 'hall'; box.value = hall.id;
        box.checked = row.halls.includes(Number(hall.id));
        label.append(box, ' ' + hall.name);
        return label;
      }));
      open(hallsDlg);
    };
    submits(hallsDlg, (data) => post(STAFF_URL, { action: 'set_halls', user_id: hallsFor.id,
      hall_ids: JSON.stringify(data.getAll('hall').map(Number)) }));

    /* ---- a cover ---- */
    const coverDlg = document.getElementById('smCoverDlg');
    const coverForm = coverDlg.querySelector('form');
    function coverChanged() {
      const staff = ROWS.find((r) => String(r.id) === coverForm.user_id.value);
      const office = officeOf(coverForm.venue_id.value);
      const halls = COVER.halls.filter((h) => office && String(h.venue_id) === String(office.id));
      fill(coverForm.room_id, [['', 'The whole office']].concat(halls.map((h) => [h.id, h.name])));
      document.getElementById('smCoverHall').hidden = !halls.length;   // the hostel is one unit
      const mine = staff ? officeOf(staff.venueId) : null;
      document.getElementById('smCoverHint').hidden = COVER.super || !mine || !office || mine.office_group === office.office_group;
    }
    coverForm.user_id.addEventListener('change', coverChanged);
    coverForm.venue_id.addEventListener('change', coverChanged);
    document.getElementById('smCoverOpen').addEventListener('click', () => {
      fill(coverForm.user_id, ROWS.map((r) => [r.id, r.name + ' (' + r.venue + ')']));
      fill(coverForm.venue_id, COVER.offices.map((o) => [o.id, o.name]));
      coverChanged();
      open(coverDlg);
    });
    submits(coverDlg, (data) => post(COVER_URL, Object.assign({ action: 'cover_create' }, Object.fromEntries(data))));

    /* ---- a permanent move to the other group ---- */
    const moveDlg = document.getElementById('smMoveDlg');
    const moveForm = moveDlg.querySelector('form');
    let moveFor = null;
    window.smOpenMove = function (row) {
      moveFor = row;
      document.getElementById('smMoveTitle').textContent = 'Move ' + row.name + ' to the other group';
      const mine = officeOf(row.venueId);
      fill(moveForm.venue_id, COVER.offices.filter((o) => o.office_group !== mine.office_group).map((o) => [o.id, o.name]));
      const others = (COVER.staff[row.venueId] || []).filter((s) => s.id !== row.id);
      const box = document.getElementById('smMoveHalls');
      /* each hall only they cover names who takes it; none to name it: the server says so */
      box.replaceChildren(...(others.length ? row.lastHalls.filter((h) => h.id > 0) : []).map((hall) => {
        const label = document.createElement('label');
        const select = document.createElement('select');
        label.className = 'sm-field';
        select.dataset.hall = hall.id;
        select.required = true;
        fill(select, [['', 'Choose who takes it']].concat(others.map((s) => [s.id, s.name])));
        label.append('Replacement for ' + hall.name, select);
        return label;
      }));
      open(moveDlg);
    };
    submits(moveDlg, (data) => {
      const replacements = {};
      moveDlg.querySelectorAll('[data-hall]').forEach((s) => { replacements[s.dataset.hall] = Number(s.value); });
      return post(COVER_URL, { action: 'move_create', user_id: moveFor.id, venue_id: data.get('venue_id'), reason: data.get('reason'),
        replacements: JSON.stringify(replacements) });
    });

    /* ---- the row buttons of both lists ---- */
    document.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-act]');
      if (!button) return;
      const act = button.dataset.act;
      const fields = { action: act, id: button.dataset.id };
      if (act.endsWith('_reject')) {
        const reason = await venusepPrompt('The other side is told, with your reason.',
          { title: 'Reject this request?', label: 'Reason', okLabel: 'Reject', danger: true });
        if (reason === null) return;
        fields.reason = reason;
      } else if (act === 'cover_end') {
        if (!(await venusepConfirm('It stops now, for this staff member and for both offices.',
          { title: 'End this cover now?', okLabel: 'End cover', danger: true }))) return;
      }
      const res = await post(COVER_URL, fields);
      if (!res.ok) { venusepAlert(res.message || 'Nothing was changed.'); return; }
      window.location.reload();
    });
  })();
</script>
