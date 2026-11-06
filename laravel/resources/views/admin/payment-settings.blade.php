{{-- Payment Settings (was admin/payment-settings.php): the refund switch card + one GCash
     account card per venue. Header + sidebar come from the layout. --}}
@extends('layouts.admin')
@section('title', 'Payment Settings')
@section('active', 'Payment Settings')

@push('head')
    <!-- shared VENUSeP modal: the password re-entry for a GCash account change -->
    <link rel="stylesheet" href="{{ asset('assets/css/confirm-modal.css') }}" />
    {{-- in the head: the refund-switch script below the form needs venusepEye --}}
    <script src="{{ asset('assets/js/confirm-modal.js') }}"></script>
@endpush

@push('styles')
    <style>
      .app-content-header { padding: 26px 0 0; }              /* 26px top, same as other pages (this page's [0] block) */
    </style>
    <!-- ============================================================
         [1] PAGE CSS — same tokens and the same neutral-surface look
         as Venue Management (hairlines, no filled tint boxes).
         ============================================================ -->
    <style>
      :root {
        --ps-border: #e5e5e5;
        --ps-muted: #6b675f;
        --ps-text: #1c1b19;
        --ps-dark: #1f1e1e;
        --ps-warn: #8a5a12;
        --ps-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
      }
      .ps-header { align-items: flex-start; display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; margin-bottom: 1.4rem; }
      .ps-heading h1 { font-size: 1.6rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 0.25rem; }
      .ps-heading p { color: var(--ps-muted); font-size: 0.88rem; margin: 0; }

      /* the "why this matters" note — a plain hairline panel, not a tint box */
      .ps-why {
        border: 1px solid var(--ps-border);
        border-radius: 12px;
        color: var(--ps-muted);
        font-size: 0.82rem;
        line-height: 1.6;
        margin-bottom: 1.4rem;
        max-width: 780px;
        padding: 0.85rem 1rem;
      }
      .ps-why strong { color: var(--ps-text); font-weight: 650; }

      .ps-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); }

      .ps-card {
        background: #fff;
        border: 1px solid var(--ps-border);
        border-radius: 14px;
        box-shadow: var(--ps-shadow);
        display: flex;
        flex-direction: column;
        padding: 1.1rem 1.15rem 1rem;
      }
      .ps-venue { align-items: center; display: flex; gap: 0.55rem; margin-bottom: 0.15rem; }
      .ps-venue h2 { font-size: 1rem; font-weight: 680; letter-spacing: -0.01em; margin: 0; }
      .ps-kind {
        border: 1px solid var(--ps-border);
        border-radius: 999px;
        color: var(--ps-muted);
        font-size: 0.68rem;
        font-weight: 600;
        padding: 0.12rem 0.5rem;
      }
      .ps-note { color: var(--ps-muted); font-size: 0.76rem; line-height: 1.55; margin: 0.35rem 0 0.9rem; }

      /* label-over-value, open — no grey filled boxes */
      .ps-kv { border-top: 1px solid #f0efec; padding: 0.6rem 0; }
      .ps-kv .k { color: #a5a19a; font-size: 0.66rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; }
      .ps-kv .v { color: var(--ps-text); font-size: 0.95rem; font-weight: 640; margin-top: 0.15rem; }
      .ps-kv .v.mono { font-variant-numeric: tabular-nums; letter-spacing: 0.02em; }
      .ps-kv .v:empty::before { color: var(--ps-muted); content: 'Not set'; font-weight: 500; }   /* a venue with no account yet; Edit starts from an empty field */

      .ps-control {
        background: #fff;
        border: 1px solid rgba(0, 0, 0, 0.14);
        border-radius: 8px;
        font: inherit;
        font-size: 0.9rem;
        height: 38px;
        margin-top: 0.2rem;
        padding: 0 0.6rem;
        width: 100%;
      }
      .ps-control:focus { outline: 2px solid rgba(31, 42, 68, 0.35); outline-offset: 0; }
      .ps-err { color: #b23a3a; font-size: 0.72rem; margin-top: 0.3rem; }

      .ps-foot { align-items: center; display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 0.9rem; padding-top: 0.8rem; border-top: 1px solid #f0efec; }
      .ps-btn {
        background: #fff;
        border: 1px solid var(--ps-border);
        border-radius: 9px;
        color: var(--ps-text);
        cursor: pointer;
        font: inherit;
        font-size: 0.8rem;
        font-weight: 600;
        padding: 0.45rem 0.85rem;
        text-decoration: none;
      }
      .ps-btn:hover { background: #fbfbfa; }
      .ps-btn-primary { background: var(--ps-dark); border-color: var(--ps-dark); color: #fff; }
      .ps-btn-primary:hover { background: #343333; }

      /* what changing this number actually does — asked, not decorated */
      .ps-effect { align-items: flex-start; display: flex; gap: 0.5rem; font-size: 0.74rem; color: var(--ps-muted); line-height: 1.5; margin-top: 0.55rem; }
      .ps-effect::before { background: var(--ps-warn); border-radius: 50%; content: ''; flex: none; height: 6px; margin-top: 0.35rem; width: 6px; }
      .ps-saved { color: #1c7a4f; font-size: 0.74rem; font-weight: 600; margin-right: auto; }

      /* ---- refund switch card — same hairline look as the GCash cards ---- */
      .rs-card { background: #fff; border: 1px solid var(--ps-border); border-radius: 14px; box-shadow: var(--ps-shadow); margin-bottom: 1.4rem; max-width: 780px; padding: 1.1rem 1.15rem 1rem; }
      .rs-top { align-items: flex-start; display: flex; flex-wrap: wrap; gap: 0.9rem; justify-content: space-between; }
      .rs-title { align-items: center; display: flex; flex-wrap: wrap; gap: 0.55rem; }
      .rs-title h2 { font-size: 1rem; font-weight: 680; letter-spacing: -0.01em; margin: 0; }
      .rs-pill { border-radius: 999px; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.04em; padding: 0.16rem 0.55rem; }
      .rs-pill-on { background: #e7f5ee; color: #1c7a4f; }
      .rs-pill-off { background: #efeeec; color: #4a463f; }
      .rs-state { color: var(--ps-text); font-size: 0.86rem; line-height: 1.55; margin: 0.35rem 0 0; max-width: 58ch; }
      .rs-lock { align-items: center; color: var(--ps-muted); display: inline-flex; font-size: 0.78rem; font-weight: 600; gap: 0.4rem; }
      .rs-btn-on { background: #1c7a4f; border-color: #1c7a4f; color: #fff; }
      .rs-btn-on:hover { background: #166340; }
      .rs-rules { color: var(--ps-muted); font-size: 0.76rem; line-height: 1.55; margin: 0.8rem 0 0; padding-left: 1.05rem; }
      .rs-meta { border-top: 1px solid #f0efec; color: #a5a19a; font-size: 0.74rem; margin-top: 0.8rem; padding-top: 0.6rem; }
      .rs-meta strong { color: var(--ps-muted); font-weight: 600; }
      .rs-flash { align-items: center; color: #1c7a4f; display: flex; font-size: 0.8rem; font-weight: 600; gap: 0.4rem; margin-bottom: 0.6rem; }
      .rs-error { color: #b23a3a; }
      /* one row per office (DB-DECISIONS 28) */
      .rs-office { align-items: flex-start; border-top: 1px solid #f0efec; display: flex; flex-wrap: wrap; gap: 0.9rem; justify-content: space-between; margin-top: 0.85rem; padding-top: 0.85rem; }
      .rs-office h3 { font-size: 0.92rem; font-weight: 650; margin: 0; }
      .rs-office .rs-meta { border-top: 0; margin-top: 0.35rem; padding-top: 0; }

      /* ---- the warning + password window ---- */
      .rs-backdrop { align-items: center; background: rgba(15, 12, 10, 0.5); display: flex; inset: 0; justify-content: center; padding: 16px; position: fixed; z-index: 2000; }
      .rs-backdrop[hidden], .rs-msg[hidden] { display: none; }   /* display:flex would otherwise beat the hidden attribute */
      .rs-modal { background: #fff; border-radius: 16px; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3); max-height: calc(100vh - 32px); max-width: 480px; overflow-y: auto; width: 100%; }
      .rs-modal-head { align-items: flex-start; border-bottom: 1px solid #f0efec; display: flex; gap: 0.7rem; padding: 1.1rem 1.2rem 0.9rem; }
      .rs-modal-head i { color: #c2540a; flex: none; font-size: 1.3rem; line-height: 1.2; }
      .rs-modal-head h3 { font-size: 1.02rem; font-weight: 700; margin: 0; }
      .rs-modal-head p { color: var(--ps-muted); font-size: 0.8rem; margin: 0.2rem 0 0; }
      .rs-modal-body { padding: 0.9rem 1.2rem 0; }
      .rs-list { font-size: 0.84rem; line-height: 1.55; margin: 0 0 1rem; padding-left: 1.1rem; }
      .rs-list li { margin-bottom: 0.45rem; }
      .rs-pw-label { display: block; font-size: 0.78rem; font-weight: 650; margin-bottom: 0.1rem; }
      .rs-msg { border-radius: 8px; font-size: 0.78rem; line-height: 1.45; margin-top: 0.55rem; padding: 0.5rem 0.65rem; }
      .rs-msg-bad { background: #fcecec; color: #b23a3a; }
      .rs-msg-lock { background: #fdf3e6; color: #8a5a12; }
      .rs-modal-foot { border-top: 1px solid #f0efec; display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem; padding: 0.8rem 1.2rem; }
      .rs-modal-foot .ps-btn[disabled] { cursor: not-allowed; opacity: 0.5; }
    </style>
@endpush

@section('content')
<!-- ==========================================================
           [4] PAGE CONTENT — one card per venue. Rendered from PHP so
           the cards and the booking pages read the same array.
           ========================================================== -->
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="ps-header">
              <div class="ps-heading">
                <h1>Payment Settings</h1>
                <p>Whether customers can request refunds, and the GCash account each venue's payments go to.</p>
              </div>
            </div>

            <!-- REFUND REQUESTS — one switch per office, the super admin's only (DB-DECISIONS 28).
                 Everything shown here is re-checked by RefundSwitchController. -->
            @fragment('rs-card')
            <section class="rs-card" id="rsCard" aria-labelledby="rsTitle" data-live="rs-card">
              @if ($rsJustSaved)
                <div class="rs-flash" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Saved. Refund requests at {{ $rsJustSaved['name'] }} are now {{ $rsJustSaved['refunds'] ? 'ON' : 'OFF' }}.</div>
              @endif
              <div class="rs-top">
                <div class="rs-title">
                  <h2 id="rsTitle">Refund Requests</h2>
                </div>
                @if ($rsDbOk && !$rsIsAdmin)
                  <span class="rs-lock"><i class="bi bi-lock-fill" aria-hidden="true"></i>Only the super admin can change this</span>
                @endif
              </div>
              @if (!$rsDbOk)
                <p class="rs-state rs-error">The database cannot be reached, so this setting cannot be read or changed right now. Until it is back, customers are shown the no-refund policy.</p>
              @endif
              @foreach ($rsOffices as $o)
                @php $hostel = $o['type'] === 'hostel'; @endphp
                <div class="rs-office">
                  <div>
                    <div class="rs-title">
                      <h3>{{ $o['name'] }}</h3>
                      <span class="rs-pill {{ $o['refunds'] ? 'rs-pill-on' : 'rs-pill-off' }}">{{ $o['refunds'] ? 'ON' : 'OFF' }}</span>
                    </div>
                    <p class="rs-state">
                      @if ($o['refunds'])
                        <strong>Customers may request refunds</strong> on {{ $hostel ? 'stays' : 'bookings' }} made while this is ON.
                      @else
                        <strong>New {{ $hostel ? 'stays' : 'bookings' }} are non-refundable.</strong> Customers confirm this before they book.
                      @endif
                      <br>
                      @switch (office_payment_policy($o))
                        @case ('pay_to_use') Payment: always before the stay (fixed). @break
                        @case ('prepay') Payment: <strong>before the event</strong>, after approval, by the day before. @break
                        @default Payment: <strong>after the event</strong>, due the day after the last booked day; overdue after {{ $o['grace'] }} days, nothing is released.
                      @endswitch
                    </p>
                    <div class="rs-meta">
                      @if ($o['last'])
                        Last changed by <strong>{{ $o['last']['email'] ?? 'a removed account' }}</strong> on {{ date('M j, Y \a\t g:i A', strtotime($o['last']['changed_at'])) }}
                      @else
                        Not changed since each office got its own switch (default: OFF).
                      @endif
                    </div>
                  </div>
                  @if ($rsIsAdmin)
                    <button type="button" class="ps-btn {{ $o['refunds'] ? 'ps-btn-primary' : 'rs-btn-on' }}" data-rs-open
                            data-venue="{{ $o['id'] }}" data-name="{{ $o['name'] }}" data-on="{{ $o['refunds'] ? 1 : 0 }}" data-hostel="{{ $hostel ? 1 : 0 }}"
                            data-stay="{{ $o['stay'] }}" data-open="{{ $o['open'] }}" data-grace="{{ $o['grace'] }}"
                            aria-label="{{ ($o['refunds'] ? 'Turn refunds OFF at ' : 'Turn refunds ON at ') . $o['name'] }}">
                      {{ $o['refunds'] ? 'Turn refunds OFF' : 'Turn refunds ON' }}
                    </button>
                  @endif
                </div>
              @endforeach
              <ul class="rs-rules">
                <li>A change applies to <strong>new bookings at that office only</strong>. Every booking keeps the policy it was made under.</li>
                <li>With refunds OFF, nothing is refundable through VENUSeP, even when the office closes. Staff handle those cases outside the system.</li>
                <li>Changing it needs your password, and every change is recorded in the office's settings history.</li>
              </ul>
            </section>
            @endfragment

            <div class="ps-why">
              Each number below is used <strong>twice</strong>: the booking page tells the customer where to send the money, and the receipt checker verifies it landed there. Both read the same value, so they cannot drift apart — change it here and both move together. <strong>A wrong number does not fail loudly:</strong> the customer pays exactly what they were shown and every receipt is auto-rejected as “receiver mismatch”, which looks like the scanner is broken.
            </div>
          </div>
        </div>

        <div class="app-content">
          <div class="container-fluid">
            <div class="ps-grid" id="psGrid">
@foreach ($psCards as $venue => $acct)
@php $isHostel = in_array($venue, $psHostelVenues, true); @endphp
              <div class="ps-card" data-venue="{{ $venue }}">
                <div class="ps-venue">
                  <h2>{{ $venue }}</h2>
                  <span class="ps-kind">{{ $isHostel ? 'Staff account' : 'Business account' }}</span>
                </div>
                <p class="ps-note">{{ $acct['note'] }}</p>

                <div class="ps-kv">
                  <div class="k">GCash account name</div>
                  <div class="v" data-f="name">{{ $acct['name'] }}</div>
                </div>
                <div class="ps-kv">
                  <div class="k">GCash number</div>
                  <div class="v mono" data-f="number">{{ gcFormatNumber($acct['number']) }}</div>
                </div>

                <div class="ps-foot">
                  @if ($psCanEdit)
                    <button type="button" class="ps-btn" onclick="psEdit(this)">Edit</button>
                  @else
                    <span class="rs-lock"><i class="bi bi-lock-fill" aria-hidden="true"></i>Only the office's admin can change this</span>
                  @endif
                </div>
              </div>
@endforeach
            </div>

          </div>
        </div>
      </main>
@endsection

@push('body-end')
@if ($rsDbOk && $rsIsAdmin)
<!-- ============================================================
         [5] REFUND MODAL — the warning + password re-entry. Rendered for
         the admin only; staff never get the form.
         ============================================================ -->
    <div class="rs-backdrop" id="rsModal" role="dialog" aria-modal="true" aria-labelledby="rsModalTitle" hidden>
      <div class="rs-modal">
        <div class="rs-modal-head">
          <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
          <div>
            <h3 id="rsModalTitle"></h3>
            <p>Read this before you confirm.</p>
          </div>
        </div>
        <form id="rsForm" novalidate>
          <div class="rs-modal-body">
            <ul class="rs-list" id="rsModalList"></ul>
            <label class="rs-pw-label" for="rsPassword">Enter your admin password to confirm</label>
            <span class="vz-pw"><input class="ps-control" type="password" id="rsPassword" autocomplete="current-password" /></span>
            <div class="rs-msg" id="rsMsg" role="alert" hidden></div>
          </div>
          <div class="rs-modal-foot">
            <button type="button" class="ps-btn" id="rsCancel">Cancel</button>
            <button type="submit" class="ps-btn ps-btn-primary" id="rsConfirm"></button>
          </div>
        </form>
      </div>
    </div>

    <!-- Open refund requests are counted from the `refunds` table (they were
         in the database; the OFF warning counts them from there. -->

<!-- ============================================================
         [7] REFUND SCRIPT — opens the right warning, posts to
         admin/refund-switch.php, runs the lockout countdown. The server
         decides everything; this only reports what it says.
         ============================================================ -->
    <script>
      (function () {
        const CSRF = @json($rsCsrf);
        let lockUntil = Date.now() + {{ (int) $rsLockSeconds }} * 1000;
        let timer = null, busy = false;
        let office = null, openBtn = null;   /* the office whose button opened the window (data-* on it) */

        const modal = document.getElementById('rsModal');
        const title = document.getElementById('rsModalTitle');
        const list = document.getElementById('rsModalList');
        const form = document.getElementById('rsForm');
        const pw = document.getElementById('rsPassword');
        pw.insertAdjacentHTML('afterend', venusepEye);
        const hidePw = function () { if (pw.type !== 'password') pw.nextElementSibling.click(); };
        const msg = document.getElementById('rsMsg');
        const confirmBtn = document.getElementById('rsConfirm');

        const plural = function (n, one, many) { return n + ' ' + (n === 1 ? one : many); };
        /* Per office, counted server-side: refund requests still open (finished by staff even
           after the switch goes off, DB-DECISIONS #16: a warning, not a blocker) and bookings
           made while it was ON, which keep their refund right. */

        function show(kind, text) { msg.className = 'rs-msg ' + (kind === 'lock' ? 'rs-msg-lock' : 'rs-msg-bad'); msg.textContent = text; msg.hidden = false; }
        function hideMsg() { msg.hidden = true; }

        function tick() {
          const left = Math.ceil((lockUntil - Date.now()) / 1000);
          if (left > 0) {
            pw.disabled = true; confirmBtn.disabled = true;
            show('lock', 'Too many wrong passwords. Try again in ' + (left >= 60 ? Math.floor(left / 60) + 'm ' + (left % 60) + 's' : left + 's') + '.');
            return;
          }
          clearInterval(timer); timer = null;
          pw.disabled = false; confirmBtn.disabled = false; hideMsg();
          if (!modal.hidden) pw.focus();
        }
        function startCountdown(seconds) {
          lockUntil = Date.now() + seconds * 1000;
          if (!timer) timer = setInterval(tick, 250);
          tick();
        }

        function open(btn) {
          openBtn = btn;
          office = { id: btn.dataset.venue, name: btn.dataset.name, on: btn.dataset.on === '1', hostel: btn.dataset.hostel === '1',
                     stay: +btn.dataset.stay, open: +btn.dataset.open, grace: +btn.dataset.grace };
          const turningOn = !office.on;
          const at = ' at <strong>' + office.name.replace(/[&<>"]/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }) + '</strong>';
          title.textContent = (turningOn ? 'Enable refund requests at ' : 'Disable refund requests at ') + office.name + '?';
          confirmBtn.textContent = turningOn ? 'Enable refunds' : 'Disable refunds';
          const items = turningOn ? [
            'Customers will be able to request refunds on bookings made' + at + ' <strong>from now on</strong>. The other offices do not change.',
            'Bookings made while refunds were OFF <strong>stay non-refundable</strong>, because their customers agreed to that.',
            'The landing page, FAQ and checkout will stop saying this office\'s bookings are non-refundable.',
            office.hostel
              ? 'Payment does not change: hostel stays are always paid before the stay.'
              : 'New bookings switch to <strong>pre-pay</strong>: paid after approval, by the day before the event; unpaid holds are released.',
            '<strong>Only enable this if the University\'s policy officially allows refunds.</strong>'
          ] : [
            'Customers <strong>can no longer request refunds</strong> on new bookings' + at + ', and must confirm a booking is non-refundable before making it.',
            '<strong>' + plural(office.open, 'open refund request is', 'open refund requests are') + '</strong> still waiting. Staff can still finish them.',
            '<strong>' + plural(office.stay, 'booking', 'bookings') + '</strong> made while refunds were ON <strong>' + (office.stay === 1 ? 'stays' : 'stay') + ' refundable</strong>, because their customers were promised that.',
            office.hostel
              ? 'Payment does not change: hostel stays are always paid before the stay.'
              : 'New bookings switch to <strong>post-pay</strong>: nothing is paid until the event is over, then it is due the day after the event. Unpaid bookings go overdue after ' + office.grace + ' days instead of being released.',
            'With refunds OFF, nothing is refundable through VENUSeP, even when the office closes. Staff handle those cases outside the system.'
          ];
          list.innerHTML = items.map(function (t) { return '<li>' + t + '</li>'; }).join('');
          pw.value = ''; hideMsg();
          modal.hidden = false;
          if (lockUntil > Date.now()) startCountdown(Math.ceil((lockUntil - Date.now()) / 1000));
          else pw.focus();
        }
        function close() {
          if (busy) return;
          modal.hidden = true; pw.value = ''; hidePw(); hideMsg();
          if (openBtn) openBtn.focus();
        }

        /* on the document: the card and its buttons are redrawn after a change */
        document.addEventListener('click', function (e) { const b = e.target.closest('[data-rs-open]'); if (b) open(b); });
        document.getElementById('rsCancel').addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

        form.addEventListener('submit', function (e) {
          e.preventDefault();
          if (busy || lockUntil > Date.now()) return;
          if (!pw.value) { show('bad', 'Enter your admin password to confirm.'); pw.focus(); return; }

          const body = new FormData();
          body.append('_token', CSRF);
          body.append('venue_id', office.id);
          body.append('enable', office.on ? '0' : '1');
          body.append('password', pw.value);

          busy = true; confirmBtn.disabled = true; pw.disabled = true; hideMsg();
          fetch(@json(route('admin.refund-switch')), { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unexpected reply from the server.' }; }); })
            .then(function (res) {
              busy = false;
              if (res.ok) {
                /* the card again, with its Saved note; the address carries the note, as a page load did */
                const url = @json(route('admin.payment-settings', ['refunds' => 'saved'])) + '&office=' + encodeURIComponent(office.id);
                const id = office.id;
                confirmBtn.disabled = false; pw.disabled = false;
                history.replaceState(null, '', url);
                close();
                Live.refresh(url).then(function () { document.querySelector('[data-rs-open][data-venue="' + id + '"]').focus(); });
                return;
              }
              pw.value = '';
              if (res.error === 'locked') { startCountdown(res.seconds || 10); return; }
              confirmBtn.disabled = false; pw.disabled = false;
              if (res.error === 'wrong_password') {
                show('bad', 'Wrong password. ' + plural(res.attemptsLeft, 'attempt', 'attempts') + ' left before a temporary lock.');
              } else if (res.error === 'not_logged_in') {
                show('bad', res.message); setTimeout(function () { location.href = @json(route('admin.admin-login')); }, 1500);
              } else {
                show('bad', res.message || 'The setting was not changed.');
              }
              pw.focus();
            })
            .catch(function () {
              busy = false; confirmBtn.disabled = false; pw.disabled = false;
              show('bad', 'Could not reach the server. The setting was not changed.');
            });
        });
      })();
    </script>
@endif
@endpush

@push('scripts')
<!-- ============================================================
         [6] PAGE SCRIPT — edit in place. Save posts to gcash-account-save.php
         only; nothing persists and no booking page is affected until
         includes/payment-settings.php is backed by a database.
         ============================================================ -->
    <script>
      /* The checker compares DIGITS, never the pretty form — so a number is
         valid on its digits alone. 11 digits starting 09 is the PH mobile
         format GCash uses; anything else would silently fail every receipt. */
      const PS_CSRF = @json($rsCsrf);

      function psDigits(s) { return String(s).replace(/\D+/g, ''); }
      function psPretty(s) { return psDigits(s).replace(/^(\d{4})(\d{3})(\d{4})$/, '$1 $2 $3'); }
      function psValid(s) { const d = psDigits(s); return d.length === 11 && d.startsWith('09'); }
      function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

      function psField(card, f) { return card.querySelector('[data-f="' + f + '"]'); }

      function psEdit(btn) {
        const card = btn.closest('.ps-card');
        if (card.querySelector('.ps-control')) return;          // already editing
        const name = psField(card, 'name'), number = psField(card, 'number');
        card.dataset.oldName = name.textContent;
        card.dataset.oldNumber = number.textContent;

        name.innerHTML = '<input class="ps-control" data-i="name" value="' + esc(card.dataset.oldName) + '" placeholder="Name exactly as it shows on GCash">';
        number.innerHTML = '<input class="ps-control" data-i="number" value="' + esc(card.dataset.oldNumber) + '" placeholder="09xx xxx xxxx" inputmode="numeric">'
          + '<div class="ps-err" data-e hidden></div>';

        card.querySelector('.ps-foot').innerHTML =
          '<span class="ps-saved" data-s hidden>Saved</span>'
          + '<button type="button" class="ps-btn" onclick="psCancel(this)">Cancel</button>'
          + '<button type="button" class="ps-btn ps-btn-primary" onclick="psSave(this)">Save</button>';

        const eff = document.createElement('div');
        eff.className = 'ps-effect';
        eff.dataset.eff = '1';
        eff.textContent = 'Changing this changes what customers are told to pay AND what the checker accepts. Existing unpaid bookings for this venue will expect the new account.';
        card.querySelector('.ps-foot').before(eff);
        card.querySelector('[data-i="name"]').focus();
      }

      function psCancel(btn) {
        const card = btn.closest('.ps-card');
        psRestore(card, card.dataset.oldName, card.dataset.oldNumber, false);
      }

      async function psSave(btn) {
        const card = btn.closest('.ps-card');
        const name = card.querySelector('[data-i="name"]').value.trim();
        const raw = card.querySelector('[data-i="number"]').value;
        const err = card.querySelector('[data-e]');

        /* Refuse to save something the checker could never match. Saving a
           malformed number would not error anywhere — it would just reject
           every receipt for this venue, quietly. */
        if (!name) { err.textContent = 'The account name cannot be empty — the checker matches it when the number is masked on a receipt.'; err.hidden = false; return; }
        if (!psValid(raw)) { err.textContent = 'Needs 11 digits starting with 09. The checker compares digits, so a number in any other shape rejects every receipt.'; err.hidden = false; return; }

        /* This is where the venue's money lands, so the server re-asks for the
           password before it will move — same treatment as the refund switch,
           and for a stronger reason: anyone with a borrowed admin session could
           otherwise point a venue's payments at their own number. The field is
           a real password input — the browser's prompt() showed it as typed. */
        const pw = await venusepPrompt(
          'Every future payment for ' + card.dataset.venue + ' will go to ' + psPretty(raw) + ', and receipts will be checked against it.',
          { title: 'Change where ' + card.dataset.venue + ' is paid?', label: 'Your admin password', inputType: 'password',
            autocomplete: 'current-password', okLabel: 'Confirm change', danger: true });
        if (pw === null) return;                /* cancelled — nothing changes */

        err.hidden = true;
        const body = new URLSearchParams({
          _token: PS_CSRF, venue: card.dataset.venue,
          account_name: name, mobile_number: psDigits(raw), password: pw
        });
        fetch(@json(route('admin.gcash-account-save')), { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
          .then(function (res) {
            if (!res.ok) {
              err.textContent = res.message + (res.attemptsLeft != null ? ' ' + res.attemptsLeft + ' attempt(s) left.' : '');
              err.hidden = false;
              return;
            }
            psRestore(card, name, psPretty(raw), true);
          })
          .catch(function () { err.textContent = 'Could not reach the server, so nothing was changed.'; err.hidden = false; });
      }

      function psRestore(card, name, number, saved) {
        psField(card, 'name').textContent = name;
        psField(card, 'number').textContent = number;
        const eff = card.querySelector('[data-eff]');
        if (eff) eff.remove();
        card.querySelector('.ps-foot').innerHTML =
          (saved ? '<span class="ps-saved">Saved</span>' : '')
          + '<button type="button" class="ps-btn" onclick="psEdit(this)">Edit</button>';
      }
    </script>
@endpush
