@extends('layouts.admin')
@section('title', 'Office Settings')
@section('active', 'Office Settings')

@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/office-settings.css') }}" />
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
                    <div class="os-field" style="grid-column:1 / -1"><label for="location{{ $o['id'] }}">Location</label><input id="location{{ $o['id'] }}" name="location" type="text" maxlength="500" autocomplete="off" value="{{ $o['location'] }}">@fragment('os-hours-' . $o['id'])<small data-live="os-hours-{{ $o['id'] }}">Hours: {{ office_hours_label($o) }} (from the opening times and closed days above).</small>@endfragment</div>
                  </div>
                  <div class="os-field" style="margin-top:.9rem"><label for="reason{{ $o['id'] }}">Reason for this change</label><input id="reason{{ $o['id'] }}" name="reason" type="text" maxlength="500"><small>Required when the discount or grace days change. It goes in the history.</small></div>
                  <div class="os-row"><button class="os-btn" type="submit">Save {{ $o['name'] }}</button><span class="os-msg" role="status"></span></div>
                </form>

                <h3>Closures with dates</h3>
                @fragment('os-closures-' . $o['id'])
                <ul class="os-list" data-live="os-closures-{{ $o['id'] }}">
@forelse ($o['closures'] as $c)
                  <li><span><strong>{{ date('M j, Y', strtotime($c['from'])) }}@if ($c['until'] !== $c['from']) &ndash; {{ date('M j, Y', strtotime($c['until'])) }}@endif</strong> &middot; {{ $c['reason'] }}</span>
                    <button class="os-btn os-btn-line" type="button" onclick="osRemove({{ $o['id'] }}, {{ $c['id'] }}, this)">Remove</button></li>
@empty
                  <li class="os-muted">No closures planned.</li>
@endforelse
                </ul>
                @endfragment
                <form class="os-closure" onsubmit="return osClose(event, this)">
                  <div class="os-grid" style="margin-top:.6rem">
                    <div class="os-field"><label for="from{{ $o['id'] }}">From</label><input id="from{{ $o['id'] }}" name="from" type="date" min="{{ date('Y-m-d') }}"></div>
                    <div class="os-field"><label for="until{{ $o['id'] }}">Until</label><input id="until{{ $o['id'] }}" name="until" type="date" min="{{ date('Y-m-d') }}"></div>
                    <div class="os-field"><label for="why{{ $o['id'] }}">Reason (customers see this)</label><input id="why{{ $o['id'] }}" name="reason" type="text" maxlength="500"></div>
                  </div>
                  <div class="os-row"><button class="os-btn os-btn-line" type="submit">Close this office</button><span class="os-msg" role="status"></span></div>
                </form>

                @fragment('os-history-' . $o['id'])
                <div data-live="os-history-{{ $o['id'] }}">
@if (!empty($historyByOffice[$o['id']]))
                <h3>Change history</h3>
                <ul class="os-list">
@foreach (array_slice($historyByOffice[$o['id']], 0, 8) as $h)
                  <li><span><strong>{{ $h['label'] }}:</strong> {{ $h['old_value'] === '' ? 'none' : $h['old_value'] }} &rarr; {{ $h['new_value'] === '' ? 'none' : $h['new_value'] }}@if ($h['note']) &middot; {{ $h['note'] }}@endif</span>
                    <span class="os-muted">{{ $h['who'] }} &middot; {{ date('M j, Y, g:i A', strtotime($h['changed_at'])) }}</span></li>
@endforeach
                </ul>
@endif
                </div>
                @endfragment
              </section>
@endforeach
            </div>
          </div>
        </div>
      </main>
@endsection

@push('scripts')
    <script>
  /* Values from PHP for assets/js/admin/office-settings.js */
  const OS_URL = {!! json_encode(route('admin.office-save')) !!};
</script>
<script src="{{ asset_stamped('assets/js/admin/office-settings.js') }}"></script>
@endpush
