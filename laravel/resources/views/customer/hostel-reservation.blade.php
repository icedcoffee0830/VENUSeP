{{-- Hostel bed booking (was customer/hostel-reservation.php) — standalone, with the shared
     browse-mode top bar. Also COUNTER MODE (?counter=1, staff session). Data:
     Customer\HostelReservationController. The script is the old one, URLs/CSRF aside. --}}
@use('App\Http\Controllers\Customer\RoomReservationController', 'RR')
{{-- counter mode: every link back into the site keeps it (like the landing cards) --}}
@php $cq = counter_mode() ? '?counter=1' : ''; @endphp
<!DOCTYPE html>
<!-- ==================================================================
  USeP HOSTEL RESERVATION — customer booking UI (self-contained mockup)
  ==================================================================
  A SEPARATE page from room-reservation.php on purpose. The hostel is a
  venue in the data model, but almost nothing a customer types is the
  same, and its payment goes through CEDU. Splitting where the DATA
  differs; sharing where the MACHINERY is the same (the GCash receipt
  checker is one engine, included by both — see includes/gcash-checker.php).

  ENTRY: venusep_venue_booking.php links here with ?room=h1…h5.

  WHAT IS DIFFERENT FROM A VENUE BOOKING — all of it falls out of
  "booking is per BED, priced per NIGHT":

    Venue                         Hostel
    ---------------------------   -------------------------------------
    Event name                    (gone)
    Date + start/end time         Check-in / check-out -> nights
    Attendees (a number)          Named occupants, one per bed
    days INCLUSIVE (Aug1-3 = 3)   nights EXCLUSIVE (Aug1->4 = 3)
    Aug 1 -> Aug 1 is valid       Aug 1 -> Aug 1 is 0 nights, rejected
    unavailable days are          a blocked night rejects the WHOLE stay
      excluded, booking is          (a guest cannot leave and come back)
      built AROUND them
    fee x days                    beds x rate/head x nights
    GCash -> business account     PAY TO USE (2026-10-27): paid before the
                                    bed is used — GCash to the STAFF's account
                                    or cash at the front desk; the POS and OR
                                    are paperwork that hold nothing up

  THE LOAD-BEARING RULE: `beds` is NEVER stored. beds = occupants.length.
  Storing both lets them disagree (beds=3 but 2 people named) and nothing
  would say which is right. The bed selector grows/shrinks the roster.

  MAP OF THE SCRIPT — Ctrl+F the quoted text:
    "---------- data"            rooms (from PHP) + constants
    "---------- state"           the one object that drives the UI
    "---------- helpers"         nights, beds-free, gender mix, maintenance
    "---------- actions"         what the buttons call
    "---------- date-picker"     calendar popup
    "---------- CEDU flow"       ID -> approval -> payment -> check-in
    "screen: DETAIL"             room page + booking panel (entry)
    "screen: REVIEW"             summary + valid-ID upload
    "screen: PENDING"            waiting for approval, then pay before check-in
    "screen: PAYMENT"            cash to staff / GCash to staff account
    "screen: CONFIRMATION"       confirmed + what still has to arrive
    "---------- render"

  [SIM] = simulation-only so the mockup runs with no server:
    · $hostelRooms       sample rooms (includes/hostel-rooms.php)
    · ACCOUNT            fake logged-in customer
    · $gcAccount         the hostel STAFF's designated GCash account
    · localStorage       stands in for the receipts database
  ================================================================== -->
<html lang="en">
<head>
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VENUSeP | Hostel Reservation</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..800&display=swap" rel="stylesheet">
<!-- Pannellum 360 panorama viewer — same lib and same hero treatment as the
     venue page. A hostel room needs it MORE than a hall does: the bunk layout
     and where the CR sits are exactly what a guest wants to look around at. -->
<link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}">
<script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.css">
<script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.js"></script>
<!-- Tesseract.js — in-browser OCR for the GCash receipt checker (needs internet) -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/hostel-reservation.css') }}" />
<link rel="stylesheet" href="{{ asset_stamped('assets/css/booking-footer-viewer.css') }}" />
<link rel="stylesheet" href="{{ asset_stamped('assets/css/booking-more-rooms.css') }}" />
<link rel="stylesheet" href="{{ asset_stamped('assets/css/booking-panel.css') }}" />
<link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/hostel-reservation-panel.css') }}" />
</head>
<body>
{{-- counter mode draws its own bar (legacy counter-booker.php), like the venue page --}}
@include('partials.customer-nav', ['navMode' => 'solid', 'navHere' => 'hostel', 'navCounterHasOwnBar' => true])
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
      <div><span class="bk-more-eyebrow">Discover</span><h2>More hostel rooms</h2></div>
      <a class="bk-more-all" href="{{ route('customer.venues') . $cq }}#hostel-listings">See all rooms <span aria-hidden="true">&rarr;</span></a>
    </div>
    <div class="bk-more-grid">
@foreach ($mrOthers as $r)
@php
        /* tonight's free beds — counted from the roster, same as the landing page */
        $free = hostelBedsFree($r, $mrToday); $mt = $r['maintenance'];
        $closed = $mt && $mt['blocks'] && hostelMaintCovers($mt, $mrToday);
@endphp
      <a class="bk-more-card" href="{{ route('customer.hostel-reservation') }}?room={{ urlencode($r['id']) }}{{ $cq ? '&counter=1' : '' }}">
        {!! RR::morePic($r['id'], $r['cr_type'] === 'private' ? 'private' : 'bunk') !!}
        <div class="bk-more-body">
          <h3>{{ $r['name'] }}</h3>
          <p class="bk-more-meta">{{ (int) $r['beds'] }} beds &middot; {!! usep_price_html((float) $r['rate'], $mrUsep, ' per head, per night', $r['office']['discount']) !!}</p>
          <span class="bk-more-free {{ $closed ? 'is-closed' : (!$free ? 'is-none' : '') }}"><i></i>{{ $closed ? 'Closed for maintenance tonight' : (!$free ? 'No beds free tonight' : $free . ' of ' . (int) $r['beds'] . ' beds free tonight') }}</span>
        </div>
      </a>
@endforeach
    </div>
    <p class="bk-more-alt">Need an event space instead? <a href="{{ route('customer.venues') . $cq }}#venue-listings">See the venues</a></p>
  </div>
</section>

@include('partials.booking-footer')
<script>
  /* Values from PHP for assets/js/customer/hostel-reservation.js */
  /* PAYMENT TIMING: the hostel is PAY TO USE (decided 2026-10-27, DB-DECISIONS
   #26), whatever the refund switch says — venues still follow it (#18). Payment
   opens at approval (GCash from Pay now, or cash at the front desk) and is due
   by the END of the check-in day; unpaid by then, the beds are released. The
   POS and the OR are paperwork recorded later; neither holds anything up. */
  /* Unlike room-reservation.php (which serves two venues), every room on this page
  belongs to the hostel — so gcAccount() below is constant. It is still a
  function because that is the checker's contract. */
  const GCASH_ACCOUNTS = {!! json_encode($gcAccounts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
  /* This room's office's refund switch (includes/refund-policy.php, venues.refunds_enabled, DB-DECISIONS 28).
  OFF = the stay is non-refundable and the guest must tick that they understand
  before submitting. ON leaves the hostel's refund text as it was — a stay booked
  with refunds on can be refunded like a venue booking (Aron, 2026-10-30). */
  const REFUNDS_ENABLED = {{ $REFUNDS_ENABLED ? 'true' : 'false' }};
  /* The upload cap for every file (Aron 2026-10-30: 7 MB) — documents.php's DOC_MAX_BYTES, the size the server refuses above. */
  const UPLOAD_MAX_MB = {{ intdiv(DOC_MAX_BYTES, 1048576) }};
  var hostelReservationPage = {
    venuesUrl: @json(route('customer.venues') . $cq),
    bookingCreateUrl: @json(route('admin.booking-create')),
    bookingSubmitUrl: @json(route('customer.booking-submit')),
    paymentSubmitUrl: @json(route('customer.payment-submit')),
    bookingHistoryUrl: @json(route('customer.booking-history')),
    bookingRequestUrl: @json(route('admin.booking-request')),
    hostelReservationUrl: @json(route('customer.hostel-reservation')),
    dashboardUrl: @json(route('admin.dashboard')),
  };
</script>
{!! $counterBookerJs !!}{{-- legacy counter-booker.php: the counter's "who" step, shared with the venue page --}}
<script src="{{ asset_stamped('assets/js/booking-panorama.js') }}"></script>
<script src="{{ asset_stamped('assets/js/booking-gallery.js') }}"></script>
<script src="{{ asset_stamped('assets/js/booking-receipt-panel.js') }}"></script>
<script>
  /* Values from PHP for assets/js/customer/hostel-reservation.js */
  /* COUNTER MODE (2026-10-27): this same page, driven by staff, for a guest at the
  front desk — the hostel twin of the venue page's counter. counter_mode()
  takes the privilege from the STAFF SESSION, never from the URL
  (includes/auth.php); it submits to admin/booking-create.php, which approves
  the stay outright. */
  const COUNTER = {{ counter_mode() ? 'true' : 'false' }};
  /* ---------- data ---------- */
  const COUNTER_STAFF = {!! json_encode(counter_staff_name(), JSON_UNESCAPED_UNICODE) !!};
  /* Counter: customer id -> their oldest Overdue booking. Online they cannot book until it
  is paid; here staff see the warning (counter-booker.php) and may still book. */
  const COUNTER_OVERDUE = {!! json_encode((object) $counterOverdue, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
  /* Counter: customer id -> true when blocked at this room's office (DB-DECISIONS 28): no booking here. */
  const COUNTER_BLOCKED = {!! json_encode((object) $counterBlocked) !!};
  /* THE BOOKING WINDOW (BookingRules::lastBookableDay): no date past LAST_DAY is bookable,
  so the last check-in is the day before it; booking-submit / booking-create refuse the rest. */
  const LAST_DAY = @json($lastDay);
  const MAX_STAY = {{ \App\Http\Controllers\Customer\BookingRules::MAX_DAYS }};
  /* Rooms come from the ONE PHP source, so this page, the landing page and admin
  Venue Management can never disagree about what exists. photoUrls/panorama
  are merged in from includes/room-photos.php — whatever was uploaded on
  admin Venue Management (Edit Room -> Edit photos / 360°). */
  const ROOMS    = {!! json_encode($mrRoomsWithPhotos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
  const RATES    = {!! json_encode($HOSTEL_RATES) !!};
  const CR_LABEL = {!! json_encode($HOSTEL_CR_LABEL) !!};
  const HOSTEL   = {!! json_encode($HOSTEL_VENUE) !!};
  /* WHO IS ACTUALLY BOOKING — the session's own row. This named Juan Miguel
  whoever was signed in, so the nav chip and the booking card on the same
  screen could show two different people. */
  const ACCOUNT = {!! json_encode([
  'name'  => $customerContact['name'],
  'role'  => $customerContact['universityId'] !== '' ? 'USeP · ' . $customerContact['universityId'] : 'Customer',
  'email' => $customerContact['email'],
  'phone' => $customerContact['phone'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
  /* The logged-in customer, from the session. */
  const CSRF = {!! json_encode(csrf_token()) !!};
  {!! office_js() !!}
</script>
<script src="{{ asset_stamped('assets/js/customer/hostel-reservation.js') }}"></script>
<!-- GSAP — the landing page's motion, on the booking flow. Same CDN as the rest of the project. -->
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>
<script src="{{ asset_stamped('assets/js/booking-motion.js') }}"></script>
<script src="{{ asset_stamped('assets/js/customer/hostel-reservation-motion.js') }}"></script>
@include('partials.booking-history-nav')
</body>
</html>
