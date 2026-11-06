{{-- Venue room booking (was customer/room-reservation.php) — standalone, with the shared
     browse-mode top bar. Also COUNTER MODE (?counter=1, staff session). Data:
     Customer\RoomReservationController. The script is the old one, URLs/CSRF aside. --}}
@use('App\Http\Controllers\Customer\RoomReservationController', 'RR')
{{-- counter mode: every link back into the site keeps it (like the landing cards) --}}
@php $cq = counter_mode() ? '?counter=1' : ''; @endphp
<!DOCTYPE html>
<!-- ==================================================================
  USeP ROOM RESERVATION — customer booking UI (self-contained mockup)
  ==================================================================
  One page, three parts: a small style block, an empty div (id="app"),
  and ONE big script that draws every screen into #app.

  ENTRY: the landing page (venusep_venue_booking.php) links here with
  ?room=r1…r8. Room BROWSING happens on the landing page now — the old
  in-page browse screen was removed in this VENUSeP port.

  HOW IT WORKS: the `state` object holds what the user is doing right
  now; every click updates `state` then calls render(), which redraws
  the current screen from scratch.

  MAP OF THE SCRIPT — Ctrl+F the quoted text to jump to that section:

    "---------- data"                constants + the ROOMS list (edit rooms here)
    "---------- state"               the one object that drives the whole UI
    "---------- helpers"             formatting, icons, date/availability logic
    "---------- actions"             small functions the buttons call
    "---------- custom date-picker"  calendar popup + time dropdowns
    "---------- 360 panorama"        360 lightbox, swipe photo strip, photo-gallery lightbox
    "---------- approve-first flow"  ID upload → pending → staff approval
    "GCASH RECEIPT CHECKER"          OCR + verdict engine (big banner explains it)
    "---------- shared bits"         page header
    "screen: DETAIL"                 room page + booking panel (entry screen)
    "screen: REVIEW"                 booking summary + valid-ID upload
    "screen: PENDING"                waiting-for-staff-approval screen
    "screen: PAYMENT"                GCash / cash step (uses the checker)
    "screen: CONFIRMATION"           done screen
    "---------- modals"              12-hour rule + booked-date warnings
    "---------- render"              redraws the current screen into #app

  [SIM] = simulation-only, so the mockup works with no server. Delete
  or replace these when the real system + database is connected:
    · ROOMS + ACCOUNT     sample rooms and a fake logged-in customer
    · GCASH_ACCOUNTS      one GCash account per venue, from
                          includes/payment-settings.php (edited in admin
                          Payment Settings). gcAccount() picks by room.
    · CASH_PAY            cashier location — placeholder, not decided yet
    · SAMPLE_PANO         demo 360° image used for every room
    · localStorage store  stands in for the receipts database
  ================================================================== -->
<html lang="en">
<head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VENUSeP | Room Reservation</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..800&display=swap" rel="stylesheet">
<!-- Pannellum 360 panorama viewer (same lib as the 360 test) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.css">
<script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.js"></script>
<!-- Tesseract.js — in-browser OCR for the GCash receipt checker (needs internet) -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/room-reservation.css') }}" />
<link rel="stylesheet" href="{{ asset_stamped('assets/css/booking-footer-viewer.css') }}" />
<link rel="stylesheet" href="{{ asset_stamped('assets/css/booking-more-rooms.css') }}" />
<link rel="stylesheet" href="{{ asset_stamped('assets/css/booking-panel.css') }}" />
<link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/room-reservation-panel.css') }}" />
</head>
<body>
<!-- empty shell — render() (bottom of the script) draws the current screen in here -->
{{-- the nav highlights the venue this room belongs to; this page renders its own
     counter bar (with the same link + the staff name) below #app, so the shared
     nav's link would just duplicate it --}}
@include('partials.customer-nav', ['navMode' => 'solid', 'navHere' => $navHere, 'navCounterHasOwnBar' => true])
@include('customer.overdue-notice')
<div id="app"></div>

{{-- legacy pricing.php + gcash-checker.php print their <script> blocks here, as the old includes did --}}
{!! $pricingJs !!}{!! $checkerJs !!}
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="bk-art-hall" viewBox="0 0 400 250"><g stroke="#fff" stroke-opacity=".32" fill="none" stroke-width="1.6"><path d="M80 172V108a30 30 0 0 1 60 0v64Z"/><path d="M170 172V98a30 30 0 0 1 60 0v74Z"/><path d="M260 172V108a30 30 0 0 1 60 0v64Z"/><path d="M24 172h352"/><path d="M150 172v-32h100v32"/></g><g fill="#fff" fill-opacity=".14"><rect x="84" y="196" width="24" height="8" rx="4"/><rect x="120" y="196" width="24" height="8" rx="4"/><rect x="156" y="196" width="24" height="8" rx="4"/><rect x="192" y="196" width="24" height="8" rx="4"/><rect x="228" y="196" width="24" height="8" rx="4"/><rect x="264" y="196" width="24" height="8" rx="4"/><rect x="300" y="196" width="24" height="8" rx="4"/></g></symbol>
  <symbol id="bk-art-gym" viewBox="0 0 400 250"><g stroke="#fff" stroke-opacity=".32" fill="none" stroke-width="1.6"><path d="M24 84c50-30 100-42 176-42s126 12 176 42"/><path d="M24 108c50-28 100-40 176-40s126 12 176 40"/><path d="M64 84v98M134 68v114M200 62v120M266 68v114M336 84v98"/><path d="M14 182h372"/><rect x="128" y="128" width="144" height="54" rx="3"/><path d="M200 128v54M128 155h144" stroke-opacity=".5"/></g></symbol>
  <symbol id="bk-art-bunk" viewBox="0 0 400 250"><g stroke="#fff" stroke-opacity=".38" fill="none" stroke-width="1.7"><rect x="52" y="72" width="140" height="46" rx="6"/><rect x="52" y="146" width="140" height="46" rx="6"/><path d="M62 72V56M182 72V56M62 146v-28M182 146v-28M62 192v22M182 192v22"/><rect x="228" y="72" width="140" height="46" rx="6"/><rect x="228" y="146" width="140" height="46" rx="6"/><path d="M238 72V56M358 72V56M238 146v-28M358 146v-28M238 192v22M358 192v22"/><path d="M14 214h372"/></g><g fill="#fff" fill-opacity=".22"><rect x="64" y="82" width="40" height="16" rx="7"/><rect x="64" y="156" width="40" height="16" rx="7"/><rect x="240" y="82" width="40" height="16" rx="7"/><rect x="240" y="156" width="40" height="16" rx="7"/></g></symbol>
  <symbol id="bk-art-private" viewBox="0 0 400 250"><g stroke="#fff" stroke-opacity=".38" fill="none" stroke-width="1.7"><rect x="44" y="88" width="132" height="42" rx="6"/><rect x="44" y="158" width="132" height="42" rx="6"/><path d="M54 88V72M166 88V72M54 158v-28M166 158v-28M54 200v20M166 200v20"/><path d="M244 52v168M244 52h122M244 220h122"/><path d="M272 132a22 22 0 0 1 44 0v10h-44Z"/><path d="M266 142h56M294 142v22"/><rect x="336" y="96" width="26" height="60" rx="4"/><path d="M14 220h372"/></g><g fill="#fff" fill-opacity=".22"><rect x="56" y="98" width="36" height="15" rx="7"/><rect x="56" y="168" width="36" height="15" rx="7"/></g></symbol>
</svg>
<section class="bk-more">
  <div class="bk-more-wrap">
    <div class="bk-more-head">
      <div><span class="bk-more-eyebrow">Discover</span><h2>More rooms</h2></div>
      <a class="bk-more-all" href="{{ route('customer.venues') . $cq }}#venue-listings">See all spaces <span aria-hidden="true">&rarr;</span></a>
    </div>
    <div class="bk-more-grid">
@foreach ($mrOthers as $r)
@php
        /* the badge on the photo says "Closed · Roof repair"; the phone tile is too narrow for the
           reason, so there the badge keeps only the word and the reason moves to a line in the card body */
        $mt = $r['maintenance']; $covers = $mt && venueMaintCovers($mt, $mrToday); $badge = ''; $status = '';
        if ($covers) {
          $word = $mt['blocks'] ? 'Closed' : 'Notice'; $cls = $mt['blocks'] ? ' is-closed' : '';
          $badge  = '<span class="bk-more-badge' . $cls . '">' . $word . '<span class="bk-more-why"> &middot; ' . htmlspecialchars($mt['reason']) . '</span></span>';
          $status = '<p class="bk-more-status' . $cls . '">' . $word . ' &middot; ' . htmlspecialchars($mt['reason']) . '</p>';
        }
@endphp
      <a class="bk-more-card" href="{{ route('customer.room-reservation') }}?room={{ urlencode($r['id']) }}{{ $cq ? '&counter=1' : '' }}">
        {!! RR::morePic($r['id'], $r['capacity'] >= 500 ? 'gym' : 'hall', $badge) !!}
        <div class="bk-more-body">
          <h3>{{ $r['name'] }}</h3>
          <p class="bk-more-meta">{{ $r['venue'] }} &middot; up to {{ number_format((int) $r['capacity']) }} guests</p>
          {!! $status !!}
          <p class="bk-more-price">{!! usep_price_html((float) $r['fee'], $mrUsep, ' per day', $r['office']['discount']) !!}</p>
        </div>
      </a>
@endforeach
    </div>
    <p class="bk-more-alt">Looking for a bed instead? <a href="{{ route('customer.venues') . $cq }}#hostel-listings">See the hostel rooms</a></p>
  </div>
</section>

@include('partials.booking-footer')
<script>
  /* Values from PHP for assets/js/customer/room-reservation.js */
  /* PAYMENT TIMING follows the same switch (DB-DECISIONS #18). The rule and the
   date math come from includes/refund-policy.php — the same function every
   customer page uses — so this page never computes a deadline of its own.
   refunds ON = pre-pay (pay after approval, 1 day before the event);
   refunds OFF = post-pay (payment opens after the event, due the day after it;
   Overdue only after PAY_POLICY.graceDays — a quiet buffer the customer is not told). */
  /* Every venue's GCash account, from the gcash_accounts table. */
  const GCASH_ACCOUNTS = {!! json_encode($gcAccounts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
  /* This room's office's refund switch (includes/refund-policy.php, venues.refunds_enabled, DB-DECISIONS 28).
  OFF = this booking is non-refundable, and the customer must tick that they
  understand before the request can be submitted. Whatever it is at submit time
  is what the booking keeps (bookings.refunds_allowed) — it never changes later. */
  const REFUNDS_ENABLED = {{ $REFUNDS_ENABLED ? 'true' : 'false' }};
  /* The upload cap for every file (Aron 2026-10-30: 7 MB) — documents.php's DOC_MAX_BYTES, the size the server refuses above. */
  const UPLOAD_MAX_MB = {{ intdiv(DOC_MAX_BYTES, 1048576) }};
  {!! $paymentPolicyJs !!}
  /* The venue rooms come from the ONE shared source (includes/venue-rooms.php),
  so this page, the landing page and admin Venue Management cannot disagree.
  photoUrls/panorama are merged in from includes/room-photos.php — whatever
  was uploaded on admin Venue Management (Edit Room -> Edit photos / 360°). */
  const ROOMS = {!! json_encode($mrRoomsWithPhotos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
  var roomReservationPage = {
    venuesUrl: @json(route('customer.venues') . $cq),
    bookingCreateUrl: @json(route('admin.booking-create')),
    bookingSubmitUrl: @json(route('customer.booking-submit')),
    paymentSubmitUrl: @json(route('customer.payment-submit')),
    bookingHistoryUrl: @json(route('customer.booking-history')),
    faqUrl: @json(route('customer.faq')),
    counterQuery: @json(counter_mode() ? '?counter=1' : ''),
    bookingRequestUrl: @json(route('admin.booking-request')),
    roomReservationUrl: @json(route('customer.room-reservation')),
    dashboardUrl: @json(route('admin.dashboard')),
  };
</script>
{!! $counterBookerJs !!}{{-- legacy counter-booker.php: the counter's "who" step, shared with the hostel page --}}
<script src="{{ asset_stamped('assets/js/booking-panorama.js') }}"></script>
<script src="{{ asset_stamped('assets/js/booking-gallery.js') }}"></script>
<script src="{{ asset_stamped('assets/js/booking-receipt-panel.js') }}"></script>
<script>
  /* Values from PHP for assets/js/customer/room-reservation.js */
  /* THE BOOKING WINDOW (BookingRules::lastBookableDay): no date past LAST_DAY is bookable;
  booking-submit / booking-create refuse it too. One booking covers at most MAX_DAYS days. */
  const LAST_DAY = @json($lastDay);
  const MAX_DAYS = {{ \App\Http\Controllers\Customer\BookingRules::MAX_DAYS }};
  /* WHO IS ACTUALLY BOOKING — the session's own row, not a hard-coded demo
  customer. This line used to name Juan Miguel whoever was signed in, so the
  nav chip (which read the real session) and the booking card on the same
  screen could show two different people, and the booking was submitted under
  the hard-coded name. */
  const ACCOUNT = {!! json_encode([
  'name'  => $customerContact['name'],
  'role'  => $customerContact['universityId'] !== '' ? 'USeP · ' . $customerContact['universityId'] : 'Customer',
  'email' => $customerContact['email'],
  'phone' => $customerContact['phone'],
  /* the profile photo for the "Booking under" card, else '' (initials); never at the counter (a walk-in) */
  'photo' => !counter_mode() && ($customerContact['photo'] ?? '') !== '' ? asset($customerContact['photo']) : '',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
  /* The logged-in customer, from the session. */
  const CSRF = {!! json_encode(csrf_token()) !!};
  /* ---------------------------------------------------------------------
  COUNTER MODE — this same page, driven by staff, for a walk-in.
  
  Deliberately not a separate admin screen: the walk-in screen IS the
  customer screen, so the two can never drift apart. What changes is
  only what has to:
  · a "who is this for" step, because the booker is not the session
  · no valid-ID upload — staff inspect the physical ID instead
  · submits to admin/booking-create.php, which approves it outright
  
  counter_mode() takes the privilege from the STAFF SESSION, never from
  the URL, so a customer adding ?counter=1 gets nothing (includes/auth.php).
  --------------------------------------------------------------------- */
  const COUNTER = {{ counter_mode() ? 'true' : 'false' }};
  const COUNTER_STAFF = {!! json_encode(counter_staff_name(), JSON_UNESCAPED_UNICODE) !!};
  /* Counter: customer id -> their oldest Overdue booking. Online they cannot book until it
  is paid; here staff see the warning (counter-booker.php) and may still book. */
  const COUNTER_OVERDUE = {!! json_encode((object) $counterOverdue, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
  /* Counter: customer id -> true when blocked at this room's office (DB-DECISIONS 28): no booking here. */
  const COUNTER_BLOCKED = {!! json_encode((object) $counterBlocked) !!};
  /* SAME DAY, DIFFERENT TIME (2026-10-27): minutes kept free before and after
  every booking of a room. From system_settings, the same value
  sp_add_venue_slot() enforces. */
  const VENUE_GAP_MIN = {{ (int) (office_page()['gap'] ?? venue_cleanup_gap_minutes()) }};
  const PAGE_OFFICE = {!! json_encode(office_page(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
  {!! office_js() !!}
</script>
<script src="{{ asset_stamped('assets/js/customer/room-reservation.js') }}"></script>
<!-- GSAP — the landing page's motion, on the booking flow. Same CDN as the rest of the project. -->
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>
<script src="{{ asset_stamped('assets/js/booking-motion.js') }}"></script>
<script src="{{ asset_stamped('assets/js/customer/room-reservation-motion.js') }}"></script>
@include('partials.booking-history-nav')
</body>
</html>
