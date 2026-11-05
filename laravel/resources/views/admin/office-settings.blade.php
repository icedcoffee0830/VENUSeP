@extends('layouts.admin')
@section('title', 'Office Settings')
@section('active', 'Office Settings')

@push('styles')
    <style>
      .os-page { max-width: 980px; margin: 0 auto; padding: 1.25rem 0 3rem; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #1f1e1e; }
      .os-title { font-size: 1.5rem; font-weight: 700; margin: 0 0 .25rem; }
      .os-sub { color: #606a75; font-size: .9rem; margin: 0 0 1.25rem; }
      .os-card { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 1.1rem 1.2rem; margin-bottom: 1.25rem; }
      .os-card h2 { font-size: 1.1rem; font-weight: 680; margin: 0 0 .9rem; }
      .os-card h3 { font-size: .85rem; font-weight: 650; margin: 1.1rem 0 .5rem; }
      .os-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: .8rem; }
      .os-field label { display: block; font-size: .78rem; font-weight: 600; margin-bottom: .25rem; }
      .os-field small { display: block; color: #606a75; font-size: .72rem; margin-top: .2rem; line-height: 1.4; }
      .os-field input { width: 100%; height: 40px; border: 1px solid #d8d4cc; border-radius: 8px; padding: 0 .6rem; font: inherit; font-size: .88rem; }
      .os-days { display: flex; flex-wrap: wrap; gap: .4rem .9rem; }
      .os-days label { display: inline-flex; align-items: center; gap: .35rem; font-size: .84rem; min-height: 32px; cursor: pointer; }
      .os-row { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; margin-top: .9rem; }
      .os-btn { height: 40px; padding: 0 1rem; border-radius: 8px; border: 1px solid #1f1e1e; background: #1f1e1e; color: #fff; font: inherit; font-size: .84rem; font-weight: 600; cursor: pointer; }
      .os-btn-line { background: #fff; color: #1f1e1e; }
      .os-btn[disabled] { opacity: .5; cursor: not-allowed; }
      .os-msg { font-size: .8rem; line-height: 1.5; }
      .os-msg.bad { color: #b23a3a; }
      .os-msg.ok { color: #1c7a4f; }
      .os-list { list-style: none; margin: 0; padding: 0; font-size: .82rem; }
      .os-list li { display: flex; gap: .7rem; align-items: center; justify-content: space-between; padding: .45rem 0; border-top: 1px solid #f0efec; flex-wrap: wrap; }
      .os-muted { color: #606a75; font-size: .78rem; }
    </style>
@endpush

@section('content')
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="os-page">
              <h1 class="os-title">Office Settings</h1>
              <p class="os-sub">Each office runs separately: changing one never affects another. Changes apply to new bookings; existing bookings stay as they are.</p>

@foreach ($offices as $o)
@php $isHostel = $o['type'] === 'hostel'; @endphp
              <section class="os-card" data-office="{{ $o['id'] }}">
                <h2>{{ $o['name'] }}</h2>
                <form class="os-form" onsubmit="return osSave(event, this)">
                  <div class="os-grid">
@unless ($isHostel)
                    <div class="os-field"><label for="opens{{ $o['id'] }}">Opens</label><input id="opens{{ $o['id'] }}" name="opens" type="time" step="1800" value="{{ $o['opens'] }}"></div>
                    <div class="os-field"><label for="closes{{ $o['id'] }}">Closes</label><input id="closes{{ $o['id'] }}" name="closes" type="time" step="1800" value="{{ $o['closes'] }}"></div>
                    <div class="os-field"><label for="lead{{ $o['id'] }}">Minimum notice (hours)</label><input id="lead{{ $o['id'] }}" name="lead" type="number" min="0" max="720" value="{{ $o['lead'] }}"></div>
                    <div class="os-field"><label for="gap{{ $o['id'] }}">Cleanup gap (minutes)</label><input id="gap{{ $o['id'] }}" name="gap" type="number" min="0" max="240" step="15" value="{{ $o['gap'] }}"></div>
@endunless
                    <div class="os-field"><label for="discount{{ $o['id'] }}">USeP discount (%)</label><input id="discount{{ $o['id'] }}" name="discount" type="number" min="0" max="100" value="{{ $o['discount'] }}"></div>
                    <div class="os-field"><label for="grace{{ $o['id'] }}">Grace days</label><input id="grace{{ $o['id'] }}" name="grace" type="number" min="0" max="30" value="{{ $o['grace'] }}"><small>Days after the event before an unpaid booking is Overdue.</small></div>
                  </div>
                  <h3>Regular closed days</h3>
                  <div class="os-days">
@foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $i => $day)
                    <label><input type="checkbox" name="days[]" value="{{ $i }}" @checked(in_array($i, $o['closedDays'], true))> {{ $day }}</label>
@endforeach
                  </div>
                  <h3>Contact info <span class="os-muted">(customers see this on the public page, in emails and when they cannot book)</span></h3>
                  <div class="os-grid">
                    <div class="os-field"><label for="phone{{ $o['id'] }}">Phone</label><input id="phone{{ $o['id'] }}" name="phone" type="tel" maxlength="30" autocomplete="off" value="{{ $o['phone'] }}"></div>
                    <div class="os-field"><label for="email{{ $o['id'] }}">Email</label><input id="email{{ $o['id'] }}" name="email" type="email" maxlength="190" autocomplete="off" spellcheck="false" value="{{ $o['email'] }}"></div>
                    <div class="os-field" style="grid-column:1 / -1"><label for="location{{ $o['id'] }}">Location</label><input id="location{{ $o['id'] }}" name="location" type="text" maxlength="500" autocomplete="off" value="{{ $o['location'] }}"><small>Hours: {{ office_hours_label($o) }} (from the opening times and closed days above).</small></div>
                  </div>
                  <div class="os-field" style="margin-top:.9rem"><label for="reason{{ $o['id'] }}">Reason for this change</label><input id="reason{{ $o['id'] }}" name="reason" type="text" maxlength="500"><small>Required when the discount or grace days change. It goes in the history.</small></div>
                  <div class="os-row"><button class="os-btn" type="submit">Save {{ $o['name'] }}</button><span class="os-msg" role="status"></span></div>
                </form>

                <h3>Closures with dates</h3>
                <ul class="os-list">
@forelse ($o['closures'] as $c)
                  <li><span><strong>{{ date('M j, Y', strtotime($c['from'])) }}@if ($c['until'] !== $c['from']) &ndash; {{ date('M j, Y', strtotime($c['until'])) }}@endif</strong> &middot; {{ $c['reason'] }}</span>
                    <button class="os-btn os-btn-line" type="button" onclick="osRemove({{ $o['id'] }}, {{ $c['id'] }}, this)">Remove</button></li>
@empty
                  <li class="os-muted">No closures planned.</li>
@endforelse
                </ul>
                <form class="os-closure" onsubmit="return osClose(event, this)">
                  <div class="os-grid" style="margin-top:.6rem">
                    <div class="os-field"><label for="from{{ $o['id'] }}">From</label><input id="from{{ $o['id'] }}" name="from" type="date" min="{{ date('Y-m-d') }}"></div>
                    <div class="os-field"><label for="until{{ $o['id'] }}">Until</label><input id="until{{ $o['id'] }}" name="until" type="date" min="{{ date('Y-m-d') }}"></div>
                    <div class="os-field"><label for="why{{ $o['id'] }}">Reason (customers see this)</label><input id="why{{ $o['id'] }}" name="reason" type="text" maxlength="500"></div>
                  </div>
                  <div class="os-row"><button class="os-btn os-btn-line" type="submit">Close this office</button><span class="os-msg" role="status"></span></div>
                </form>

@if (!empty($historyByOffice[$o['id']]))
                <h3>Change history</h3>
                <ul class="os-list">
@foreach (array_slice($historyByOffice[$o['id']], 0, 8) as $h)
                  <li><span><strong>{{ $h['label'] }}:</strong> {{ $h['old_value'] === '' ? 'none' : $h['old_value'] }} &rarr; {{ $h['new_value'] === '' ? 'none' : $h['new_value'] }}@if ($h['note']) &middot; {{ $h['note'] }}@endif</span>
                    <span class="os-muted">{{ $h['who'] }} &middot; {{ date('M j, Y, g:i A', strtotime($h['changed_at'])) }}</span></li>
@endforeach
                </ul>
@endif
              </section>
@endforeach
            </div>
          </div>
        </div>
      </main>
@endsection

@push('scripts')
    <script>
      const OS_URL = {!! json_encode(route('admin.office-save')) !!};
      const OS_CSRF = {!! json_encode(csrf_token()) !!};
      async function osPost(body) {
        body.append('_token', OS_CSRF);
        return fetch(OS_URL, { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .catch(function () { return { ok: false, message: 'Could not reach the server, so nothing was changed.' }; });
      }
      async function osSend(form, action, keepOpen) {
        const msg = form.querySelector('.os-msg');
        const btn = form.querySelector('button[type=submit]');
        const body = new FormData(form);
        body.append('venue_id', form.closest('[data-office]').dataset.office);
        body.append('action', action);
        btn.disabled = true;
        const out = await osPost(body);
        btn.disabled = false;
        msg.className = 'os-msg ' + (out.ok ? 'ok' : 'bad');
        msg.textContent = out.message || 'Nothing was changed.';
        if (out.ok && !(out.affected && out.affected.length)) setTimeout(function () { location.reload(); }, 700);
      }
      function osSave(e, form) { e.preventDefault(); osSend(form, 'save'); return false; }
      function osClose(e, form) { e.preventDefault(); osSend(form, 'closure_add'); return false; }
      async function osRemove(venue, id, btn) {
        if (!(await venusepConfirm('Customers can book these dates again.', { title: 'Remove this closure?', okLabel: 'Remove' }))) return;
        const body = new FormData();
        body.append('venue_id', venue); body.append('action', 'closure_remove'); body.append('closure_id', id);
        btn.disabled = true;
        const out = await osPost(body);
        if (out.ok) { location.reload(); return; }
        btn.disabled = false;
        venusepAlert(out.message || 'Nothing was changed.');
      }
    </script>
@endpush
