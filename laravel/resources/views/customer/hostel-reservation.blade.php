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
    · demoGoPay()        jump to payment once "approved" (demo mode only)
    · demoIssueOr()      fake "cashier issued the Official Receipt"
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
<link rel="stylesheet" href="{{ asset('assets/css/confirm-modal.css') }}">
<script src="{{ asset('assets/js/confirm-modal.js') }}"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.css">
<script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.js"></script>
<!-- Tesseract.js — in-browser OCR for the GCash receipt checker (needs internet) -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<style>
  *{box-sizing:border-box}
  body{margin:0;background:#f4f3f0;color:#1c1b19;font-family:'Inter',system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
  a{color:#a11626;text-decoration:none}
  a:hover{color:#7d0f1e}
  input,select,button,textarea{font-family:inherit}
  input:focus,select:focus,textarea:focus{outline:2px solid rgba(161,22,38,.35);outline-offset:0}
  ::placeholder{color:#a5a19a}
  button{appearance:none}
  /* custom date-picker dropdown (booking panel) — same look as the venue page */
  .cal-pop{position:absolute;top:calc(100% + 6px);z-index:70;background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;box-shadow:0 12px 36px rgba(0,0,0,.14);padding:12px;width:274px}
  .cal-nav{width:28px;height:28px;border:none;background:none;border-radius:8px;cursor:pointer;color:#5c584f;font-size:15px;line-height:1}
  .cal-nav:hover{background:#f0eeea}
  .cal-day{width:32px;height:32px;border:none;background:none;border-radius:999px;display:flex;align-items:center;justify-content:center;font:500 12.5px 'Inter',system-ui,sans-serif;color:#1c1b19;cursor:pointer;padding:0}
  .cal-day:hover:not(:disabled){background:#eef0f4}
  .cal-day:disabled{color:#c6c2ba;text-decoration:line-through;cursor:default;background:none}
  .cal-day.out{color:#c6c2ba}
  .cal-day.today:not(.sel){box-shadow:inset 0 0 0 1.5px #b9c0d0}
  .cal-day.sel,.cal-day.sel:hover{background:#a11626;color:#fff;font-weight:650}
  /* bed roster rows */
  .bed-row{display:grid;grid-template-columns:22px 1fr 92px;gap:8px;align-items:center;padding:7px 0;border-top:1px solid rgba(0,0,0,.06)}
  .bed-no{font-size:12.5px;font-weight:650;color:#a5a19a;text-align:center}
  .bed-name{height:36px;border:1px solid rgba(0,0,0,.14);border-radius:9px;padding:0 10px;font-size:13px;background:#fff;width:100%}
  .g-seg{display:flex;border:1px solid rgba(0,0,0,.14);border-radius:9px;overflow:hidden;height:36px;background:#fff}
  .g-seg button{flex:1;border:none;background:none;font-size:12px;font-weight:600;color:#8a857d;cursor:pointer;padding:0}
  .g-seg button.on{background:#a11626;color:#fff}
@include('partials.booking-css-footer-viewer')
@include('partials.booking-css-more-rooms')
  .bk-panel input{border-color:transparent!important;background:#fff}
@include('partials.booking-css-panel')
    .bk-panel .g-seg{height:46px}
  }
  /* hostel roster on the crimson card */
  .bk-panel .bed-row{border-top-color:rgba(255,255,255,.16)}
  .bk-panel .bed-no{color:#d5b8b5}
  .bk-panel .bed-name{height:42px}
  .bk-panel .g-seg{height:42px;border-color:transparent}
  .bk-more-free{margin-top:13px;display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:999px;font-size:12.5px;font-weight:600;background:rgba(255,209,102,.16);border:1px solid rgba(255,209,102,.42);color:#ffd166}
  .bk-more-free i{width:6px;height:6px;border-radius:50%;background:#ffd166;display:block}
  .bk-more-free.is-none{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.17);color:#bda4a2}
  .bk-more-free.is-none i{background:#96807e}
  .bk-more-free.is-closed{background:rgba(178,58,58,.26);border-color:rgba(255,123,138,.4);color:#ff9aa6}
  .bk-more-free.is-closed i{background:#ff7b8a}
  .bk-more-alt{margin:22px 0 0;font-size:13px;color:#d5b8b5}
  .bk-more-alt a{color:#fff;font-weight:600}
  .bk-footer{border-top:1px solid rgba(255,255,255,.12)}
  @media (max-width:1080px){.bk-more-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media (max-width:720px){.bk-more-wrap{padding:40px 18px 36px}.bk-more-head{flex-direction:column;align-items:flex-start}.bk-more-grid{grid-template-columns:1fr;gap:10px}.bk-more h2{font-size:26px}
    /* phone: compact "More rooms" cards — photo left, text right (same as the landing page) */
    .bk-more-card{display:grid;grid-template-columns:112px minmax(0,1fr);border-radius:16px}
    .bk-more-ph{aspect-ratio:auto;height:100%;min-height:118px}
    .bk-more-tag{display:none}
    .bk-more-body{padding:14px 16px;min-width:0;display:flex;flex-direction:column;justify-content:center;align-items:flex-start;gap:3px}
    .bk-more-body h3{font-size:15px;line-height:1.3}
    .bk-more-meta{margin:0}
    .bk-more-free{margin-top:6px;padding:5px 11px;line-height:1.35}
    /* phone: a sticky bar so the booking form (below the description) is one tap away */
    .bk-cta{position:fixed;left:0;right:0;bottom:0;z-index:50;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px calc(10px + env(safe-area-inset-bottom));
      background:rgba(255,255,255,.96);backdrop-filter:blur(10px);border-top:1px solid rgba(0,0,0,.1);box-shadow:0 -8px 24px rgba(0,0,0,.08);transform:translateY(110%);transition:transform 260ms cubic-bezier(.16,1,.3,1)}
    .bk-cta.show{transform:none}
    .bk-cta-price{font-size:13px;color:#4a463f;line-height:1.3;min-width:0}
    .bk-cta-price strong{display:block;font-family:Archivo,Inter,sans-serif;font-size:17px;font-weight:800;color:#1c1b19}
    .bk-cta-btn{flex:none;height:44px;padding:0 18px;border:0;border-radius:12px;background:#a11626;color:#fff;font:inherit;font-size:14px;font-weight:700;cursor:pointer}
    body.bk-cta-on #app{padding-bottom:72px}
  }
</style>
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
          <p class="bk-more-meta">{{ (int) $r['beds'] }} beds &middot; {!! usep_price_html((float) $r['rate'], $mrUsep, ' per head, per night') !!}</p>
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
/* Unlike room-reservation.php (which serves two venues), every room on this page
   belongs to the hostel — so gcAccount() below is constant. It is still a
   function because that is the checker's contract. */
const GCASH_ACCOUNTS = {!! json_encode($gcAccounts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
/* The admin refund switch (includes/refund-policy.php, system_settings.refunds_enabled).
   OFF = the stay is non-refundable and the guest must tick that they understand
   before submitting. ON leaves the hostel's refund text as it was — a stay booked
   with refunds on can be refunded like a venue booking (Aron, 2026-10-30). */
const REFUNDS_ENABLED = {{ $REFUNDS_ENABLED ? 'true' : 'false' }};
/* The upload cap for every file (Aron 2026-10-30: 7 MB) — documents.php's DOC_MAX_BYTES, the size the server refuses above. */
const UPLOAD_MAX_MB = {{ intdiv(DOC_MAX_BYTES, 1048576) }};
/* PAYMENT TIMING: the hostel is PAY TO USE (decided 2026-10-27, DB-DECISIONS
   #26), whatever the refund switch says — venues still follow it (#18). Payment
   opens at approval (GCash from Pay now, or cash at the front desk) and is due
   by the END of the check-in day; unpaid by then, the beds are released. The
   POS and the OR are paperwork recorded later; neither holds anything up. */
</script>
{!! $counterBookerJs !!}{{-- legacy counter-booker.php: the counter's "who" step, shared with the venue page --}}
<script>
/* ---------- data ---------- */
/* COUNTER MODE (2026-10-27): this same page, driven by staff, for a guest at the
   front desk — the hostel twin of the venue page's counter. counter_mode()
   takes the privilege from the STAFF SESSION, never from the URL
   (includes/auth.php); it submits to admin/booking-create.php, which approves
   the stay outright. */
const COUNTER = {{ counter_mode() ? 'true' : 'false' }};
const COUNTER_STAFF = {!! json_encode(counter_staff_name(), JSON_UNESCAPED_UNICODE) !!};
/* Counter: customer id -> their oldest Overdue booking. Online they cannot book until it
   is paid; here staff see the warning (counter-booker.php) and may still book. */
const COUNTER_OVERDUE = {!! json_encode((object) $counterOverdue, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
const NOW = new Date();
const TODAY = isoOf(NOW);
/* THE ADVANCE LIMIT (system_settings.hostel_advance_booking_max_days, 2026-10-30): a
   stay can be booked at most this many days before check-in; later check-ins are
   greyed out, and booking-submit / booking-create refuse them. null = no limit. */
const ADVANCE_DAYS = {{ json_encode($HOSTEL_ADVANCE_DAYS) }};
const LAST_CHECKIN = ADVANCE_DAYS==null ? null : (function(){ const d=new Date(NOW); d.setDate(d.getDate()+ADVANCE_DAYS); return isoOf(d); })();

/* Rooms come from the ONE PHP source, so this page, the landing page and admin
   Venue Management can never disagree about what exists. photoUrls/panorama
   are merged in from includes/room-photos.php — whatever was uploaded on
   admin Venue Management (Edit Room -> Edit photos / 360°). */
const ROOMS    = {!! json_encode($mrRoomsWithPhotos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
const RATES    = {!! json_encode($HOSTEL_RATES) !!};
const CR_LABEL = {!! json_encode($HOSTEL_CR_LABEL) !!};
const HOSTEL   = {!! json_encode($HOSTEL_VENUE) !!};

/* The logged-in customer, from the session. */
/* WHO IS ACTUALLY BOOKING — the session's own row. This named Juan Miguel
   whoever was signed in, so the nav chip and the booking card on the same
   screen could show two different people. */
const ACCOUNT = {!! json_encode([
  'name'  => $customerContact['name'],
  'role'  => $customerContact['universityId'] !== '' ? 'USeP · ' . $customerContact['universityId'] : 'Customer',
  'email' => $customerContact['email'],
  'phone' => $customerContact['phone'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
const CSRF = {!! json_encode(csrf_token()) !!};

/* Is this a showcase? The "Demo only" panels below stand in for people and
   offices this page cannot reach — a coordinator approving an ID, CEDU handing
   over a POS, the cashier issuing an OR. They belong to a demonstration, and a
   real customer must never be shown a button that claims to approve their own
   booking. So they render ONLY while demo mode is on (includes/demo-mode.php,
   switched on admin/venusep_profile.php).
   Nothing here could ever have changed the database — these functions only move
   the screen, and the server refuses a payment on a booking it has not approved
   — but a customer reading "Simulate staff approval" has no way to know that. */
const DEMO_MODE = {{ demo_mode_on() ? 'true' : 'false' }};

/* [SIM] where the customer hands cash to the hostel staff. */
const CASH_PAY = { where:'USeP Hostel front desk', hours:'Mon–Sat · 8:00 AM – 5:00 PM' };

/* [SIM] the office the staff walks to for the POS, and the one that issues the OR. */
const CEDU     = { name:'CEDU', full:'College of Engineering & Development Unit' };
const CASHIER  = { name:'University Cashier', full:'USeP Cashier’s Office' };

/* [SIM] Sample equirectangular panorama — same stand-in the venue page uses.
   Swap for real per-room images (room.panorama) later. */
const SAMPLE_PANO = 'https://pannellum.org/images/alma.jpg';

/* ---------- state ---------- */
const ROOM_PARAM = new URLSearchParams(location.search).get('room');
const ROOM_OK = ROOMS.some(r=>r.id===ROOM_PARAM);
if(!ROOM_OK) location.replace('{{ route('customer.venues') . $cq }}');

let state = {
  screen: 'detail',                       // detail | who (counter) | review | pending | payment | done
  booker: Object.assign({}, booker0),     // counter only: who the stay is for (includes/counter-booker.php)
  counterResult: null,                    // counter only: what booking-create.php answered
  roomId: ROOM_OK ? ROOM_PARAM : ROOMS[0].id,
  tab: 'overview',                        // overview | availability | policies
  /* NOTE: there is no `beds` field. beds = occupants.length, always. The bed
     stepper grows/shrinks this list, so the count and the roster are one thing. */
  booking: { checkIn:'', checkOut:'', occupants:[ {name:'', gender:'F'} ] },
  payMethod: 'gcash',                     // 'gcash' (staff account) | 'cash' (to staff)
  affiliated: isUsepAccount(ACCOUNT.email) ? true : null,   // pre-selected for a USeP account (preview — the ID decides)
  idFile: null,                           // required to submit
  approved: false,                        // staff approved the ID + booking -> payment open until the end of check-in day
  orNumber: null,                         // null until the cashier issues it -> post-confirmation only
  agreeExact: false,
  agreeNoRefund: false,                   // "non-refundable" ticked? (required only while refunds are OFF)
  ocr: null,
  reference: 'USEP-H-' + Math.floor(100000 + Math.random()*900000),
  cal: null,
  modal: null,
};

/* ---------- helpers ---------- */
function isoOf(dt){ return dt.getFullYear()+'-'+String(dt.getMonth()+1).padStart(2,'0')+'-'+String(dt.getDate()).padStart(2,'0'); }
/* "1,500" for whole pesos, "1,500.50" with centavos — never "1,500.5" (= usep_peso() in pricing.php) */
function peso(n){ const v=Math.round(Number(n)*100)/100; return '₱' + v.toLocaleString('en-PH', v%1 ? {minimumFractionDigits:2, maximumFractionDigits:2} : {maximumFractionDigits:0}); }
function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtDate(d){ if(!d) return '—'; const dt=new Date(d+'T00:00:00'); return dt.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'}); }
function fmtShort(d){ if(!d) return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
function getRoom(){ return ROOMS.find(r=>r.id===state.roomId) || null; }
/* each room's OWN rate (2026-10-27): rates are edited per room now, and the
   server charges the room's own — a per-CR-type table could show another price */
function roomRate(r){ return r.rate; }
function initials(n){ return n.split(' ').map(w=>w[0]).slice(0,2).join(''); }

/* NIGHTS ARE EXCLUSIVE of the check-out date: Aug 1 -> Aug 4 occupies the nights
   of Aug 1, 2 and 3 = 3 nights. This is NOT the venue's inclusive day count, and
   reusing that here would over-bill every stay by one night. */
function nightsOf(ci,co){
  const out=[];
  if(!ci||!co||co<=ci) return out;
  let cur=new Date(ci+'T00:00:00'); const end=new Date(co+'T00:00:00');
  while(cur<end && out.length<366){ out.push(isoOf(cur)); cur.setDate(cur.getDate()+1); }
  return out;
}

/* ---------- maintenance (same window shape as every venue room) ---------- */
function maintCovers(m,ds){ return !!m && ds>=m.from && (m.until==null || ds<=m.until); }
function maintLive(m){ return !!m && (m.until==null || m.until>=TODAY); }

/* A night is BLOCKED only by HARD maintenance. Beds being full is a different
   thing: it limits HOW MANY beds you can take, it does not close the room. */
function nightBlocked(ns){
  const r=getRoom();
  return !!r && maintCovers(r.maintenance,ns) && r.maintenance.blocks;
}
function firstBlockedNight(nights){ return nights.find(nightBlocked) || null; }

/* ---------- occupancy: every number here is COUNTED, never stored ---------- */
function occupantsOn(r,ns){ return (r.occupied && r.occupied[ns]) || []; }
function bedsTaken(r,ns){ return occupantsOn(r,ns).length; }      // the load-bearing rule
function bedsFree(r,ns){ return Math.max(0, r.beds - bedsTaken(r,ns)); }

/* A bed must be free EVERY night of the stay, so the limit is the worst night. */
function maxBedsFree(r,nights){
  if(!nights.length) return r.beds;
  return nights.reduce((min,ns)=>Math.min(min,bedsFree(r,ns)), r.beds);
}
/* Gender mix is DISPLAY ONLY — a guest may want to know the room's mix. Nothing
   is enforced, no bed is gender-locked, and the bed counter stays a plain number. */
function genderMix(r,ns){
  let F=0,M=0;
  for(const o of occupantsOn(r,ns)) (o.gender==='F') ? F++ : M++;
  return {F,M};
}
function mixLabel(mix){
  const p=[]; if(mix.F) p.push(mix.F+' female'); if(mix.M) p.push(mix.M+' male');
  return p.length?p.join(', '):'empty';
}

/* beds = occupants.length. Never read a stored count. */
function bedCount(){ return state.booking.occupants.length; }

/* ---------- the one derivation everything reads ---------- */
function derive(){
  const R=getRoom(); const b=state.booking;
  const nights=nightsOf(b.checkIn,b.checkOut);
  const beds=bedCount();
  const rate=R?roomRate(R):0;
  const maxFree=R?maxBedsFree(R,nights):0;
  const blocked=firstBlockedNight(nights);
  /* Pricing. `total` is the DISCOUNTED total, same as the venue page: the checker
     validates the stored discounted figure (DB-DECISIONS #2), so every screen and
     gcExpectedCentavos() below stays right without knowing a discount exists.
     Hostel maths differs — beds x rate x NIGHTS — but the discount lands the same. */
  const roomPrice=R?(beds*rate*nights.length):0;
  const hpr=priceWithDiscount(roomPrice, state.affiliated);
  const total=hpr.total, discountPercent=hpr.discountPercent, discountAmount=hpr.discountAmount;
  const named=b.occupants.every(o=>o.name.trim().length>0);
  const badRange=!!(b.checkIn && b.checkOut && b.checkOut<=b.checkIn);
  const tooFar=!!(b.checkIn && LAST_CHECKIN && b.checkIn>LAST_CHECKIN);

  /* the status card: neutral surface + a small dot, same as the venue page */
  let slot={show:false};
  if(b.checkIn && b.checkOut){
    if(tooFar){
      slot={show:true,dot:'#d9930d',title:'Check-in is too far ahead',
            detail:'Hostel stays can be booked at most '+ADVANCE_DAYS+' days ahead — pick a check-in on or before '+fmtShort(LAST_CHECKIN)+'.'};
    }else if(badRange){
      slot={show:true,dot:'#d9930d',title:'Check-out must be after check-in',
            detail: b.checkOut===b.checkIn ? 'A stay is at least one night — pick the next day or later for check-out.'
                                           : 'Adjust your dates to see availability.'};
    }else if(blocked){
      /* A hostel stay is ONE CONTIGUOUS thing. The venue books around a closed
         day; a guest cannot leave and come back, so the whole range is refused. */
      slot={show:true,dot:'#d9930d',title:'The room is closed for part of your stay',
            detail:'Closed for maintenance on '+fmtShort(blocked)+' ('+esc(R.maintenance.reason)+'). A stay cannot be split around a closure — please pick different dates.'};
    }else if(maxFree===0){
      slot={show:true,dot:'#d9930d',title:'No beds left for these dates',
            detail:'Every bed in this room is taken on at least one of your nights. Try other dates or another room.'};
    }else if(beds>maxFree){
      slot={show:true,dot:'#d9930d',title:'Only '+maxFree+' bed'+(maxFree>1?'s':'')+' free for these dates',
            detail:'A bed has to be free every night of your stay. Reduce the number of beds or shorten the stay.'};
    }else if(!named){
      slot={show:true,dot:'#d9930d',title:'Name every guest',
            detail:'Each bed is booked for a named person — the staff use these at check-in.'};
    }else{
      slot={show:true,dot:'#2f9e63',title:nights.length+' night'+(nights.length>1?'s':'')+' · '+beds+' bed'+(beds>1?'s':''),
            detail:fmtShort(b.checkIn)+' → '+fmtShort(b.checkOut)+' · '+maxFree+' of '+R.beds+' beds free'};
    }
  }

  const ready = !!(R && nights.length>0 && !tooFar && !blocked && beds>0 && beds<=maxFree && named);

  let hint='';
  if(!b.checkIn||!b.checkOut) hint='Pick your check-in and check-out dates';
  else if(tooFar) hint='Check-in can be at most '+ADVANCE_DAYS+' days ahead';
  else if(badRange) hint='Check-out must be after check-in';
  else if(blocked) hint='Closed for maintenance during your stay — pick different dates';
  else if(maxFree===0) hint='No beds available for these dates';
  else if(beds>maxFree) hint='Only '+maxFree+' bed'+(maxFree>1?'s':'')+' free — reduce your booking';
  else if(!named) hint='Enter a name for every bed';

  return { R, b, nights, beds, rate, maxFree, blocked, total, roomPrice, discountPercent, discountAmount, named, badRange, slot, ready, hint };
}

/* ---------- actions ---------- */
function setBooking(key,val){
  state.booking[key]=val;
  /* keep check-out after check-in — a stay is at least one night */
  if(key==='checkIn' && state.booking.checkOut && state.booking.checkOut<=val){
    const nx=new Date(val+'T00:00:00'); nx.setDate(nx.getDate()+1);
    state.booking.checkOut=isoOf(nx);
  }
  render();
}
/* The bed stepper IS the roster editor — that is what makes beds = occupants.length
   true by construction rather than by discipline. */
function setBeds(n){
  const R=getRoom(); const o=state.booking.occupants;
  n=Math.max(1, Math.min(n, R?R.beds:6));
  while(o.length<n) o.push({name:'', gender:'F'});
  while(o.length>n) o.pop();
  render();
}
function setOccupant(i,key,val){
  const o=state.booking.occupants[i]; if(!o) return;
  o[key]=val;
  if(key==='gender') render();            // name typing must not steal focus
  else updateReady();
}
/* Typing a name should not re-render the whole screen (it would drop the caret),
   so patch only the two things a name can change. */
function updateReady(){
  const d=derive();
  const btn=document.getElementById('hbGo'); const hint=document.getElementById('hbHint');
  if(btn){ btn.disabled=!d.ready; btn.className='bk-go '+(d.ready?'on':'off'); }
  if(hint) hint.textContent=d.hint;
}
function setTab(k){ state.tab=k; render(); }
function goHome(){ location.href='{{ route('customer.venues') . $cq }}'; }
/* At the counter the room, dates and guests are settled first, then we ask who
   the stay is for — the "who" step sits between detail and review. */
function goReview(){ if(derive().ready){ state.screen = COUNTER ? 'who' : 'review'; window.scrollTo(0,0); render(); } }
function backToDetail(){ state.screen='detail'; window.scrollTo(0,0); render(); }
function setPayMethod(m){ state.payMethod=m; render(); }
function toggleAgreeExact(el){ state.agreeExact=!!el.checked; render(); }
function closeModal(){ state.modal=null; render(); }
function restart(){ location.href='{{ route('customer.venues') . $cq }}'; }

/* ---------- date-picker ---------- */
function openCal(field){
  const cur=state.booking[field]||state.booking.checkIn||TODAY;
  state.cal = state.cal && state.cal.field===field ? null : { field, month:cur.slice(0,7) };
  render();
}
function closeCal(){ if(state.cal){ state.cal=null; render(); } }
function calNav(dir){
  if(!state.cal) return;
  const [y,m]=state.cal.month.split('-').map(Number);
  const d=new Date(y,m-1+dir,1);
  state.cal.month=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
  render();
}
function calPick(iso){
  if(!state.cal) return;
  const f=state.cal.field; state.cal=null;
  setBooking(f,iso);
}
function calHtml(field){
  if(!state.cal || state.cal.field!==field) return '';
  const [y,m]=state.cal.month.split('-').map(Number);
  const first=new Date(y,m-1,1), start=first.getDay(), dim=new Date(y,m,0).getDate();
  const sel=state.booking[field];
  /* check-out must be at least the night after check-in */
  const min = field==='checkOut' ? nextDay(state.booking.checkIn||TODAY) : TODAY;
  const cells=[];
  for(let i=0;i<start;i++) cells.push('<span></span>');
  for(let d=1;d<=dim;d++){
    const iso=y+'-'+String(m).padStart(2,'0')+'-'+String(d).padStart(2,'0');
    const off = iso<min || (field==='checkIn' && LAST_CHECKIN!==null && iso>LAST_CHECKIN);
    const cls='cal-day'+(iso===sel?' sel':'')+(iso===TODAY?' today':'');
    cells.push(`<button class="${cls}" ${off?'disabled':''} onclick="event.stopPropagation();calPick('${iso}')">${d}</button>`);
  }
  const label=first.toLocaleDateString('en-US',{month:'long',year:'numeric'});
  return `
  <div class="cal-pop" style="${field==='checkOut'?'right:0':'left:0'}" onclick="event.stopPropagation()">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
      <button class="cal-nav" onclick="calNav(-1)">‹</button>
      <span style="font-size:13px;font-weight:650">${label}</span>
      <button class="cal-nav" onclick="calNav(1)">›</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;justify-items:center">
      ${['S','M','T','W','T','F','S'].map(d=>`<span style="font-size:12px;font-weight:600;color:#a5a19a;padding:4px 0">${d}</span>`).join('')}
      ${cells.join('')}
    </div>
    ${field==='checkIn' && LAST_CHECKIN ? `<div style="font-size:12px;color:#77736c;margin-top:6px;text-align:center">Check-in up to ${ADVANCE_DAYS} days ahead</div>` : ''}
  </div>`;
}
function nextDay(iso){ const d=new Date(iso+'T00:00:00'); d.setDate(d.getDate()+1); return isoOf(d); }

/* ---------- CEDU flow ----------
   The hostel does NOT use the venue's payment path. Money moves:
       customer -> staff -> university cashier
   PAY TO USE (2026-10-27): the guest pays BEFORE using the bed — from approval,
   by GCash to the staff account or in cash at the front desk, by the end of the
   check-in day. Two documents come from other offices: the POS (CEDU) and the
   OR (the cashier). Neither is a gate any more; both are paperwork staff
   record whenever they arrive, so nobody thinks a paid stay is unpaid. */
function uploadId(input){
  const f=input.files && input.files[0]; if(!f) return;
  /* over the cap: refused here with the same words, no shrinking (2026-10-30) */
  if(f.size > UPLOAD_MAX_MB*1048576){ state.submitError='This file is over '+UPLOAD_MAX_MB+' MB. Choose a smaller photo or a screenshot.'; input.value=''; render(); return; }
  state.submitError=null;
  if(state.idFile && state.idFile.url){ try{ URL.revokeObjectURL(state.idFile.url); }catch(e){} }
  /* keep the File itself — submitRequest() has to upload it, not just preview it */
  state.idFile={ name:f.name, url:URL.createObjectURL(f), file:f };
  input.value=''; render();
}
function setAffiliation(v){ state.affiliated = v; render(); }   /* re-prices instantly */
function removeId(){
  if(state.idFile && state.idFile.url){ try{ URL.revokeObjectURL(state.idFile.url); }catch(e){} }
  state.idFile=null; render();
}
/* While refunds are OFF, the guest must acknowledge the stay is final before
   the booking is made — the policy is agreed at booking time, not at payment. */
/* COUNTER: no ID file — staff saw the ID — and affiliation was answered on the
   "who" step, so what must be true is that the booker is settled. */
function canSubmitRequest(){
  if(COUNTER) return counterBookerReady() && (REFUNDS_ENABLED || state.agreeNoRefund);
  return !!state.idFile && state.affiliated !== null && (REFUNDS_ENABLED || state.agreeNoRefund);
}
function toggleAgreeNoRefund(el){ state.agreeNoRefund=!!el.checked; render(); }
/* Submit FOR REAL. The screen advances when the server has a row, so a guest is
   never shown a reference for a stay that does not exist. Beds are assigned by
   sp_assign_bed() against the UNIQUE(bed, night) guard — this page cannot claim
   a bed, it can only ask for one. */
let submitting = false;
async function submitRequest(){
  /* affiliation is a required choice, like the ID — the price depends on it */
  if(submitting || !derive().ready || !canSubmitRequest() || state.affiliated===null) return;
  const b = state.booking;
  const fd = new FormData();
  fd.append('csrf', CSRF);
  fd.append('_token', CSRF);   /* Laravel's CSRF field */
  fd.append('type', 'hostel');
  fd.append('room', state.roomId);
  fd.append('check_in', b.checkIn);
  fd.append('check_out', b.checkOut);
  /* The page uses F/M for display; the database stores male/female/other. */
  fd.append('occupants', JSON.stringify(b.occupants.map(function(o){
    return { name:o.name, gender: o.gender === 'F' ? 'female' : (o.gender === 'M' ? 'male' : 'other') };
  })));
  fd.append('affiliated', state.affiliated ? '1' : '0');
  fd.append('agree_no_refund', state.agreeNoRefund ? '1' : '0');
  /* COUNTER: who it is for travels with the stay, and no ID file does — the
     server re-checks every one of these (admin/booking-create.php).
     PAY TO USE: the stay is only recorded once the cash is in hand, so staff
     confirm it first; cancelling saves nothing. Online, no method is sent —
     the guest chooses GCash or cash when they pay. */
  if(COUNTER){
    if(!(await venusepConfirm('Take '+peso(derive().total)+' in cash from the guest now. The stay is booked and marked paid only after you confirm.',
          { title:'Cash received?', okLabel:'Cash received — book it' }))) return;
    fd.append('method', 'cash');
    fd.append('cash_received', '1');
    const k = state.booker;
    fd.append('booker_mode', k.mode);
    if(k.mode==='account') fd.append('customer_id', k.customerId);
    else { fd.append('walkin_name', k.name.trim()); fd.append('walkin_phone', cleanPhone(k.phone) || ''); fd.append('walkin_address', k.address.trim()); fd.append('walkin_email', k.noEmail ? '' : k.email.trim()); fd.append('walkin_no_email', k.noEmail ? '1' : '0'); }
    fd.append('id_checked', k.idChecked ? '1' : '0');
    fd.append('usep_id', k.usepId ? '1' : '0');
  } else {
    fd.append('id_document', state.idFile.file, state.idFile.name);
  }

  submitting = true; render();
  try{
    const res = await fetch(COUNTER ? '{{ route('admin.booking-create') }}' : '{{ route('customer.booking-submit') }}', { method:'POST', body:fd, credentials:'same-origin' });
    const out = await res.json().catch(function(){ return { ok:false, message:'The server sent an unreadable reply.' }; });
    if(!out.ok){ submitting=false; state.submitError = out.message || 'Your booking was not submitted.'; render(); return; }
    state.reference = out.reference;          /* the database owns booking identity */
    state.bookingDbId = out.bookingId;
    state.submitError = null;
    state.submitted = true;                   // the form is gone for good
    /* COUNTER: staff ARE the approver — the stay came back approved */
    state.screen = COUNTER ? 'done' : 'pending'; window.scrollTo(0,0);
    if(COUNTER) state.counterResult = out;
  }catch(e){
    state.submitError = 'Could not reach the server, so your booking was not submitted.';
  }
  submitting = false; render();
}
/* [SIM] the staff side is not connected — these stand in for the hostel staff
   approving the ID + booking, after which payment is open. */
function demoApprove(){ if(!DEMO_MODE) return; state.approved=true; window.scrollTo(0,0); render(); }
function demoGoPay(){
  if(!DEMO_MODE || !state.approved) return;  /* live system: the guest pays from My Bookings (Pay now) */
  state.screen='payment'; window.scrollTo(0,0); render();
}
/* PAY TO USE: due by the END of the check-in day (fn_payment_deadline). */
function payByInfo(){ return { label: state.booking.checkIn ? fmtDate(state.booking.checkIn) : '—' }; }
function receiptGate(){ return state.payMethod==='cash' ? true : receiptOk(); }
/* Record the payment FOR REAL — only once staff approved the stay (the server
   refuses it otherwise). The verdict is recomputed server-side against this
   booking's total and the HOSTEL's own GCash account — which is a designated
   staff account, not a business one, so a receipt paid to a venue account is
   correctly refused here. */
let paying = false;
async function confirmBooking(){
  if(paying) return;
  if(!state.approved) return;               // payment opens at approval
  if(!receiptGate()) return;
  const cash = state.payMethod === 'cash';

  const fd = new FormData();
  fd.append('csrf', CSRF);
  fd.append('_token', CSRF);   /* Laravel's CSRF field */
  fd.append('booking', state.reference);
  fd.append('method', cash ? 'cash' : 'gcash');
  if(!cash && state.ocr && state.ocr.rec){
    const rec = state.ocr.rec, p = rec.parsed || {};
    fd.append('receipt', state.ocr.file, state.ocr.fileName || 'receipt.jpg');
    fd.append('ref', p.ref || '');
    fd.append('receiver_name', p.receiverNameMasked || p.receiverNameShort || '');
    fd.append('receiver_number', p.receiverNumber || '');
    fd.append('amount_centavos', p.effAmountC != null ? p.effAmountC : '');
    fd.append('receipt_datetime', p.datetime || '');
    fd.append('confidence', rec.confidence != null ? rec.confidence : '');
    fd.append('flags', JSON.stringify(rec.flags || []));
    fd.append('text_excerpt', rec.textExcerpt || '');
  }

  paying = true; render();
  try{
    const res = await fetch('{{ route('customer.payment-submit') }}', { method:'POST', body:fd, credentials:'same-origin' });
    const out = await res.json().catch(function(){ return { ok:false, message:'The server sent an unreadable reply.' }; });
    if(!out.ok){
      paying = false;
      state.payError = out.message || 'Your payment was not recorded.';
      if(out.verdict === 'rejected' && state.ocr && state.ocr.rec){ state.ocr.rec.status = 'rejected'; }
      render(); return;
    }
    state.payError = null;
    state.paymentVerdict = out.verdict || null;
    state.screen='done'; window.scrollTo(0,0);
  }catch(e){
    state.payError = 'Could not reach the server, so your payment was not recorded.';
  }
  paying = false; render();
}
/* [SIM] the cashier issuing the OR, which happens AFTER the booking is already
   confirmed — this is why the OR is a document flag and not a payment status. */
function demoIssueOr(){
  if(!DEMO_MODE) return;     /* live system: the cashier issues the real OR */
  state.orNumber='OR-'+Math.floor(100000 + Math.random()*900000);
  render();
}

/* the shared GCash checker calls back into these three — the only things this
   page knows that the engine does not: which account, what is owed, and for
   which booking. */
function gcAccount(){ return GCASH_ACCOUNTS[HOSTEL] || { name:'', number:'' }; }
function gcExpectedCentavos(){ return Math.round(derive().total*100); }   // hostel: beds x rate x nights
function gcBookingRef(){ return state.reference; }

/* ---------- shared bits ---------- */
/* The SAME header as the venue page (room-reservation.php's header()): real logo,
   sticky translucent bar, account chip linking to the profile. Kept byte-for-byte
   identical so the two pages read as one system. */
function pageHeader(){ return ''; }   /* the nav bar is static now: includes/customer-nav.php, above #app */

/* the maintenance chip — DERIVED from the window, never stored. Identical rule
   to the venue page: no window = no chip, because a room with nothing wrong has
   nothing to say. */
function maintChip(R){
  const m=R.maintenance; if(!maintLive(m)) return '';
  const started=TODAY>=m.from;
  const tone=m.blocks?{bg:'#f6e4e4',fg:'#b23a3a'}:{bg:'#f4ecd6',fg:'#8a6d1f'};
  const head=m.blocks?'Closed for maintenance':'Partial maintenance';
  let when;
  if(!started)     when = m.until ? fmtShort(m.from)+' – '+fmtShort(m.until) : 'from '+fmtShort(m.from);
  else if(m.until) when = 'until '+fmtShort(m.until);
  else             when = 'no set return date';
  return `<span title="${esc(m.reason)}" style="padding:4px 11px;border-radius:999px;font-size:12.5px;background:${tone.bg};color:${tone.fg}">${esc(head+' · '+when)}</span>`;
}
/* MEDIUM maintenance never blocks, so this notice is its ONLY route to the
   guest — and it has to land before they pay. */
function maintDisclosure(R){
  const m=R.maintenance;
  if(!maintLive(m) || m.blocks) return '';
  const when = TODAY>=m.from ? (m.until?'until '+fmtShort(m.until):'until further notice')
                             : (m.until?fmtShort(m.from)+' – '+fmtShort(m.until):'from '+fmtShort(m.from));
  return `
    <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:12px;margin-bottom:12px;background:#fff;border:1px solid rgba(0,0,0,.09)">
      <span style="width:8px;height:8px;border-radius:999px;background:#d9930d;margin-top:5px;flex:none"></span>
      <div>
        <div style="font-size:13.5px;font-weight:650;color:#1c1b19">Partial maintenance ${esc(when)}</div>
        <div style="font-size:12.5px;color:#7a766f;margin-top:1px">${esc(m.reason)}. The room is still open and bookable — we just want you to know before you reserve.</div>
      </div>
    </div>`;
}
const PHOTO_TILE = 'background:#e9e7e2;background-image:repeating-linear-gradient(45deg,rgba(0,0,0,.035) 0 11px,transparent 11px 22px);display:flex;align-items:center;justify-content:center';

/* A hero gallery tile: a real uploaded photo (R.photoUrls[i]) if one exists
   at that index, else the placeholder pattern + "room photo" label. `inner`
   is extra HTML appended inside the tile (e.g. the "+N photos" overlay). */
function heroTile(R,i,extraStyle,inner){
  const url=(R.photoUrls||[])[i];
  extraStyle=extraStyle||''; inner=inner||'';
  if(url) return `<div onclick="openGallery()" style="cursor:pointer;background-size:cover;background-position:center;background-image:url('${url}');${extraStyle}">${inner}</div>`;
  return `<div onclick="openGallery()" style="cursor:pointer;${PHOTO_TILE};${extraStyle}"><span style="font:500 11px/1 ui-monospace,Menlo,monospace;color:#9a958c">room photo</span>${inner}</div>`;
}
function svgBed(s,c){ return `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="${c||'currentColor'}" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M2 18v-6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v6"/><path d="M2 18h20M2 18v2M22 18v2"/><path d="M6 10V8a2 2 0 0 1 2-2h3v4"/></svg>`; }
function svgUsers(size){ return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`; }

/* ---------- 360 panorama (opened from the photo strip's first slide) + photo gallery lightbox ---------- */
@include('partials.booking-panorama')
/* Photo gallery lightbox — view-only, mounted outside #app. Shows the
   room's real uploaded photos (R.photoUrls); falls back to the numbered
   gradient placeholders when the room has none yet. */
@include('partials.booking-gallery')

/* ---------- amenity icons ----------
   Same idea as the venue page's amenityIcon(), but matched to the HOSTEL
   catalog: a bed, a locker and a shower — not projectors and stages. */
const AI=(function(){
  const sw='fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
  const S=p=>`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}>${p}</svg>`;
  return {
    bunk:  S('<path d="M2 18v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5"/><path d="M2 18h20M2 18v3M22 18v3"/><path d="M2 9V4M22 9V4M2 6h20"/>'),
    shower:S('<path d="M4 20V7a3 3 0 0 1 6 0v1"/><line x1="10" y1="8" x2="20" y2="8"/><line x1="13" y1="12" x2="13" y2="13"/><line x1="16" y1="12" x2="16" y2="14"/><line x1="19" y1="12" x2="19" y2="13"/>'),
    door:  S('<path d="M3 21h18"/><path d="M6 21V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v17"/><circle cx="15" cy="12" r="1"/>'),
    lock:  S('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'),
    bulb:  S('<path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5.76.76 1.23 1.52 1.41 2.5"/>'),
    wind:  S('<path d="M9.59 4.59A2 2 0 1 1 11 8H2"/><path d="M12.59 19.41A2 2 0 1 0 14 16H2"/><path d="M17.73 7.73A2.5 2.5 0 1 1 19.5 12H2"/>'),
    wifi:  S('<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>'),
    table: S('<path d="M3 10h18"/><path d="M5 10V6h14v4"/><path d="M6 10v10M18 10v10"/>'),
    power: S('<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'),
    coffee:S('<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>'),
    stairs:S('<path d="M3 20h4v-4h4v-4h4V8h4V4"/>'),
    window:S('<rect x="3" y="3" width="18" height="18" rx="1"/><line x1="12" y1="3" x2="12" y2="21"/><line x1="3" y1="12" x2="21" y2="12"/>'),
    check: S('<polyline points="20 6 9 17 4 12"/>'),
  };
})();
function amenityIcon(label){
  const t=String(label).toLowerCase();
  const has=(...ks)=>ks.some(k=>t.includes(k));
  let k='check';
  if(has('bunk','bed')) k='bunk';
  else if(has('shower','hot')) k='shower';
  else if(has('bathroom','cr','toilet')) k='door';
  else if(has('locker','safe')) k='lock';
  else if(has('light','lamp','reading')) k='bulb';
  else if(has('air-condition','aircon','conditioned','fan','ventil')) k='wind';
  else if(has('wi-fi','wifi','internet')) k='wifi';
  else if(has('table','desk','study')) k='table';
  else if(has('outlet','power','socket','charg')) k='power';
  else if(has('lounge','water','drinking','coffee','pantry')) k='coffee';
  else if(has('step-free','ground','floor','stair','access')) k='stairs';
  else if(has('window','courtyard','view','quiet')) k='window';
  return AI[k];
}

/* a small "n of 6" bed strip — the occupancy fact that replaces a status label */
function bedStrip(taken,total,size){
  const w=size||9;
  let out='<span style="display:inline-flex;gap:3px;vertical-align:middle">';
  for(let i=0;i<total;i++) out+=`<span style="width:${w}px;height:${w}px;border-radius:2px;background:${i<taken?'#a11626':'#dcd9d3'}"></span>`;
  return out+'</span>';
}

/* ---------- screen: DETAIL ---------- */
function detailScreen(){
  const d=derive(); const R=d.R; if(!R) return '';
  const b=state.booking;
  const rate=roomRate(R);

  const tabDefs=[['overview','Overview'],['availability','Availability'],['policies','Policies']];
  const tabs=tabDefs.map(([k,label])=>`
    <button onclick="setTab('${k}')" style="background:none;border:none;padding:12px 14px;font-size:14px;font-weight:${state.tab===k?680:550};color:${state.tab===k?'#1c1b19':'#8a857d'};border-bottom:2px solid ${state.tab===k?'#a11626':'transparent'};cursor:pointer;margin-bottom:-1px">${label}</button>`).join('');

  let content='';
  if(state.tab==='overview'){
    /* same "At a glance" treatment as the venue page — but the FACTS are the
       ones a bed has. No catering, no bookable hours, no "best for". */
    const sw='fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
    const I={
      tag:`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.4"/></svg>`,
      moon:`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>`,
      cr:`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}><path d="M4 20V7a3 3 0 0 1 6 0v1"/><line x1="10" y1="8" x2="20" y2="8"/><line x1="13" y1="12" x2="13" y2="13"/><line x1="16" y1="12" x2="16" y2="14"/></svg>`,
      users:`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>`,
    };
    const facts=[
      { icon:I.tag,   text:'Rate: '+peso(rate)+' per head, per night — paid in full' },
      { icon:I.moon,  text:'Charged per night; check-out day is not a night' },
      { icon:I.cr,    text:R.cr_type==='private' ? 'Private CR inside the room' : 'Communal CR — shared, outside the room' },
      { icon:I.users, text:'You book beds, not the room — book all '+R.beds+' and it is yours' },
    ];
    const row=(icon,label)=>`<div style="display:flex;align-items:flex-start;gap:11px;font-size:14px;line-height:1.45;color:#1c1b19"><span style="color:#3a372f;flex:none;display:flex;margin-top:1px">${icon}</span>${esc(label)}</div>`;
    content=`
      <p style="margin:0 0 28px;font-size:15.5px;line-height:1.7;color:#3a372f;max-width:70ch">${esc(R.description)}</p>
      <h3 style="margin:0 0 16px;font-size:16px;font-weight:660">At a glance</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px 28px;margin-bottom:34px">${facts.map(f=>row(f.icon,f.text)).join('')}</div>
      <h3 style="margin:0 0 16px;font-size:16px;font-weight:660">In this room</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:16px 28px">${R.amenities.map(a=>row(amenityIcon(a),a)).join('')}</div>`;
  }else if(state.tab==='availability'){
    /* Occupancy is a room x NIGHT fact, so it can only be shown per night —
       there is no single label that is true of the room. */
    const upcoming=[];
    let cur=new Date(TODAY+'T00:00:00');
    for(let i=0;i<14;i++){ upcoming.push(isoOf(cur)); cur.setDate(cur.getDate()+1); }
    content=`
      <div style="font-size:13px;color:#7a766f;margin-bottom:12px">Beds are booked one at a time, so a room can be partly full. Here are the next 14 nights.</div>
      <div style="border:1px solid rgba(0,0,0,.08);border-radius:12px;overflow:hidden;background:#fff">
        ${upcoming.map((ns,i)=>{
          const taken=bedsTaken(R,ns), free=bedsFree(R,ns), mix=genderMix(R,ns);
          const closed=nightBlocked(ns);
          const line=i?'border-top:1px solid rgba(0,0,0,.06);':'';
          return `<div style="display:flex;align-items:center;gap:12px;padding:10px 14px;${line}">
            <span style="font-size:13px;color:#4a463f;min-width:118px">${esc(fmtShort(ns))}</span>
            ${closed
              ? `<span style="font-size:12.5px;color:#b23a3a">Closed for maintenance</span>`
              : `${bedStrip(taken,R.beds)}
                 <span style="font-size:12.5px;color:${free?'#4a463f':'#b23a3a'}">${free?free+' of '+R.beds+' free':'full'}</span>
                 <span style="font-size:12px;color:#a5a19a;margin-left:auto">${taken?esc(mixLabel(mix)):''}</span>`}
          </div>`;
        }).join('')}
      </div>`;
  }else{
    /* same policy layout as the venue page — hairline-separated blocks */
    const policy=(title,body)=>`<div style="padding:18px 0;border-top:1px solid rgba(0,0,0,.08)"><div style="font-weight:640;font-size:14px;margin-bottom:5px">${title}</div><p style="margin:0;font-size:13.5px;line-height:1.6;color:#4a463f;max-width:70ch">${body}</p></div>`;
    content=`
      <h3 style="margin:0 0 4px;font-size:16px;font-weight:660">Booking &amp; stay policies</h3>
      ${policy('Booking is per bed','You reserve <strong>beds</strong>, not the room. Other guests may book the remaining beds in the same room — unless you book all '+R.beds+', which gives you the whole room. A bed has to be free on <strong>every night</strong> of your stay.')}
      ${policy('Every bed is named','Give the name of the person sleeping in each bed. Staff match these against your valid ID at check-in. The gender mix of the room is shown so you know who you are sharing with — <strong>no bed is reserved by gender</strong>.')}
      ${policy('Nights, not days','A stay is counted in <strong>nights</strong>: check in on the 1st and out on the 4th and that is 3 nights. Your check-out day is not charged. A stay must be <strong>continuous</strong> — if the room is closed for maintenance on any night in your range, those dates cannot be booked at all.')}
      ${policy('Pay before you check in','The hostel is <strong>pay to use</strong>: your stay must be paid before you get your bed. Once staff approve your booking, pay any time up to your check-in day — <strong>GCash</strong> to the designated staff account from My Bookings (send the <strong>exact amount</strong> — a different amount is not confirmed automatically and has to be reviewed by staff), or <strong>cash at the front desk</strong> when you arrive. A stay not paid by the <strong>end of its check-in day</strong> is released.')}
      ${policy('One full payment','The whole stay is paid at once, up front. There is no per-night billing and no partial payment.')}
      ${policy('Your receipts','You get a <strong>VENUSeP System Receipt</strong> once your payment is confirmed, a <strong>GCash Payment Receipt</strong> if you paid online, and an <strong>Official Receipt</strong> from the '+CASHIER.name+' once the staff hand over your payment. The OR arrives after your booking is already paid — the booking is not waiting on it.')}
      ${policy('At check-in','Show the staff the valid ID you submitted. Check-in needs a <strong>paid</strong> stay: a GCash receipt still being reviewed is confirmed by staff first. Each guest sleeps in the bed booked under their name.')}
      ${!REFUNDS_ENABLED
        ? policy('Non-refundable','All bookings are <strong>final and non-refundable</strong> once paid. Please check your dates and guests before you submit and pay. If USeP has to close the room, the hostel office will offer you a <strong>replacement room or new dates</strong> instead; if you cannot accept either, your payment is returned.')
        : policy('Refunds','A refund requires the VENUSeP System Receipt, the GCash Payment Receipt (if you paid by GCash), <strong>and</strong> the Official Receipt. Requests missing any of these cannot be processed.')}`;
  }

  /* --- booking panel --- */
  const dateField=(field,label,val)=>`
    <div style="position:relative;flex:1;min-width:0">
      <div style="font-size:12px;font-weight:600;color:#f2d0cb;margin-bottom:5px">${label}</div>
      <button onclick="event.stopPropagation();openCal('${field}')" style="width:100%;height:42px;border:1px solid transparent;border-radius:10px;background:#fff;padding:0 11px;font-size:13.5px;color:${val?'#1c1b19':'#a5a19a'};text-align:left;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:6px">
        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${val?esc(fmtShort(val)):'Select'}</span>
      </button>
      ${calHtml(field)}
    </div>`;

  const beds=d.beds;
  const cap=R.beds;
  const roster=state.booking.occupants.map((o,i)=>`
    <div class="bed-row">
      <span class="bed-no">${i+1}</span>
      <input class="bed-name" type="text" value="${esc(o.name)}" placeholder="Full name of guest ${i+1}" oninput="setOccupant(${i},'name',this.value)">
      <span class="g-seg">
        <button class="${o.gender==='F'?'on':''}" onclick="setOccupant(${i},'gender','F')">Female</button>
        <button class="${o.gender==='M'?'on':''}" onclick="setOccupant(${i},'gender','M')">Male</button>
      </span>
    </div>`).join('');

  const firstNight=d.nights[0];
  const mixNow=firstNight?genderMix(R,firstNight):null;
  const takenNow=firstNight?bedsTaken(R,firstNight):0;

  const slot = d.slot.show ? `
    <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:12px;margin-bottom:12px;background:#fff;border:1px solid rgba(0,0,0,.09)">
      <span style="width:8px;height:8px;border-radius:999px;background:${d.slot.dot};margin-top:5px;flex:none"></span>
      <div>
        <div style="font-size:13.5px;font-weight:650;color:#1c1b19">${d.slot.title}</div>
        <div style="font-size:12.5px;color:#7a766f;margin-top:1px">${d.slot.detail}</div>
      </div>
    </div>` : '';

  /* photo strip — the 360° tour first, then the room's photos; swipe / drag only */
  const photos=(R.photoUrls||[]).filter(Boolean);
  const total=photos.length+1;
  const solo=total===1?' solo':'';
  const tour=`<button type="button" class="bk-gal-slide bk-gal-360s${solo}" onclick="openRoomPano()" aria-label="Open the 360° tour of ${esc(R.name)}" style="background-image:url('${esc(R.panorama||SAMPLE_PANO)}')">
      <span class="bk-360-ico"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/></svg></span>
      <strong>360° tour</strong><small>Tap to look around</small></button>`;
  const slides = tour + photos.map((u,i)=>`<div class="bk-gal-slide" role="img" aria-label="${esc(R.name)} — photo ${i+1} of ${photos.length}" style="background-image:url('${esc(u)}')"></div>`).join('');
  const gallery = `
      <div class="bk-gal">
        <div class="bk-gal-track" tabindex="0" aria-label="${esc(R.name)} — 360° tour and photos, swipe to browse">${slides}</div>
        ${total>1?`<span class="bk-gal-pill bk-gal-count"><span class="bk-gal-idx">1</span>&nbsp;/&nbsp;${total}</span><div class="bk-gal-dots">${Array.from({length:total},(_,i)=>`<i${i?'':' class="on"'}></i>`).join('')}</div>`:''}
      </div>`;

  return `
  ${pageHeader()}
  <main onclick="closeCal()" style="max-width:1180px;margin:0 auto;padding:20px 24px 72px">
    <a onclick="goHome()" style="display:inline-flex;align-items:center;gap:7px;font-size:13.5px;font-weight:600;cursor:pointer;margin-bottom:16px">← All rooms</a>

    ${gallery}

    <div style="display:flex;flex-wrap:wrap;align-items:flex-start;gap:14px;margin:0 2px 26px">
      <div style="flex:1;min-width:240px">
        <h1 style="margin:0 0 6px;font-size:27px;font-weight:700;letter-spacing:-.015em">${esc(R.name)}</h1>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;font-size:14px;color:#4a463f">
          <span>${esc(HOSTEL)}</span>
          <span style="display:inline-flex;align-items:center;gap:6px" title="${esc(CR_LABEL[R.cr_type])}">${amenityIcon(R.cr_type==='private'?'private bathroom':'shared bathroom')}${esc(CR_LABEL[R.cr_type])}</span>
          <span style="display:inline-flex;align-items:center;gap:6px" title="${R.beds} beds in this room">${svgBed(17,'currentColor')}${R.beds} beds</span>
          ${maintChip(R)}
        </div>
      </div>
    </div>

    <div class="bk-cols">
    <!-- LEFT -->
    <div style="min-width:0">
      <div style="display:flex;gap:4px;border-bottom:1px solid rgba(0,0,0,.1);overflow-x:auto;scrollbar-width:none">${tabs}</div>
      <div style="margin-top:24px">${content}</div>
    </div>

      <aside class="bk-aside">
        <div class="bk-panel">
          <span class="bk-eyebrow">Book a bed</span>
          <div style="font-size:20px;font-weight:750;letter-spacing:-.01em;margin-bottom:4px">Reserve a bed</div>
          <div style="font-size:12.5px;line-height:1.55;color:#e9d0cd;margin-bottom:18px">You book <strong>beds</strong>, not the room. Book all ${cap} and the room is yours.</div>
          ${maintDisclosure(R)}

          <div style="display:flex;gap:9px;margin-bottom:14px">
            ${dateField('checkIn','Check-in',b.checkIn)}
            ${dateField('checkOut','Check-out',b.checkOut)}
          </div>

          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:4px">
            <div>
              <div style="font-size:12px;font-weight:600;color:#f2d0cb">Beds</div>
              <div style="font-size:12.5px;color:#d5b8b5">${d.nights.length?d.maxFree+' free for your dates':'up to '+cap}</div>
            </div>
            <div style="display:flex;align-items:center;gap:0;border:1px solid transparent;border-radius:10px;overflow:hidden;height:38px;background:#fff">
              <button onclick="setBeds(${beds-1})" ${beds<=1?'disabled':''} style="width:36px;height:100%;border:none;background:none;font-size:17px;color:${beds<=1?'#c6c2ba':'#4a463f'};cursor:${beds<=1?'default':'pointer'}">−</button>
              <span style="min-width:26px;text-align:center;font-size:14px;font-weight:680;color:#1c1b19" aria-live="polite">${beds}</span>
              <button onclick="setBeds(${beds+1})" ${beds>=cap?'disabled':''} style="width:36px;height:100%;border:none;background:none;font-size:17px;color:${beds>=cap?'#c6c2ba':'#4a463f'};cursor:${beds>=cap?'default':'pointer'}">+</button>
            </div>
          </div>

          <div style="margin:10px 0 14px">
            <div style="font-size:12px;font-weight:600;color:#f2d0cb;margin-bottom:2px">Who is sleeping in each bed?</div>
            ${roster}
            ${beds===cap?`<div style="font-size:12.5px;color:#e9d0cd;margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,.16)">Booking all ${cap} beds gives you the whole room to yourselves.</div>`:''}
          </div>

          ${firstNight&&takenNow?`
          <div style="font-size:12px;color:#e9d0cd;margin-bottom:12px;padding:9px 11px;border:1px solid rgba(255,255,255,.22);border-radius:10px">
            Already in this room on ${esc(fmtShort(firstNight))}: <strong style="font-weight:640;color:#fff">${esc(mixLabel(mixNow))}</strong>
            <div style="font-size:12px;color:#d5b8b5;margin-top:2px">Shown so you know the room's mix — beds are not reserved by gender.</div>
          </div>`:''}

          ${slot}

          <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;padding-top:12px;border-top:1px solid rgba(255,255,255,.16)">
            <div>
              <div style="font-size:12.5px;color:#d5b8b5">${d.nights.length?`${beds} bed${beds>1?'s':''} × ${peso(rate)} × ${d.nights.length} night${d.nights.length>1?'s':''}`:'Total'}</div>
              <div style="font-size:24px;font-weight:750;letter-spacing:-.01em;color:#fff">${peso(d.total)}</div>
            </div>
          </div>
          <div style="font-size:12.5px;color:#d5b8b5;margin-top:4px">${COUNTER?'Paid in full, in cash, before the stay is booked.':'Paid in full before you check in.'}</div>

          <button id="hbGo" class="bk-go ${d.ready?'on':'off'}" onclick="goReview()" ${d.ready?'':'disabled'} style="margin-top:14px">Continue</button>
          <div id="hbHint" style="font-size:12.5px;color:#d5b8b5;text-align:center;margin-top:10px;min-height:16px">${esc(d.hint)}</div>
        </div>
      </aside>
    </div>
  </main>`;
}

/* rows shared by review / pending / payment / done */
function bookingRows(){
  const d=derive(); const R=d.R;
  return { d, R,
    stayLabel: fmtShort(d.b.checkIn)+' → '+fmtShort(d.b.checkOut),
    nightsLabel: d.nights.length+' night'+(d.nights.length>1?'s':''),
    bedsLabel: d.beds+' bed'+(d.beds>1?'s':''),
    mathLabel: `${d.beds} × ${peso(d.rate)} × ${d.nights.length} night${d.nights.length>1?'s':''}`,
  };
}
const blk=(label,value,span)=>`<div style="min-width:0;${span?'grid-column:1 / -1;':''}"><div style="font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">${label}</div><div style="font-size:13.5px;font-weight:640;color:#1c1b19;overflow-wrap:anywhere">${value}</div></div>`;

function rosterHtml(occupants){
  return `
    <div style="margin:12px 0 2px">
      <div style="font-size:12px;font-weight:600;color:#a5a19a;text-transform:uppercase;letter-spacing:.06em;margin-bottom:7px">Guests (one per bed)</div>
      <div>
        ${occupants.map((o,i)=>`
          <div style="display:flex;justify-content:space-between;gap:16px;padding:9px 0;${i?'border-top:1px solid rgba(0,0,0,.06);':''}font-size:13px">
            <span style="color:#5c584f">Bed ${i+1} · ${esc(o.name||'—')}</span>
            <span style="font-weight:600;color:#1c1b19">${o.gender==='F'?'Female':'Male'}</span>
          </div>`).join('')}
      </div>
    </div>`;
}

/* ---------- screen: REVIEW ---------- */
function reviewScreen(){
  const x=bookingRows(); const R=x.R; if(!R) return '';
  const idOk=!!state.idFile;
  return `
  ${pageHeader()}
  <main style="max-width:620px;margin:0 auto;padding:34px 24px 72px">
    <button onclick="backToDetail()" style="background:none;border:none;color:#8a857d;font-size:13px;font-weight:600;cursor:pointer;padding:0;margin-bottom:14px">← Back</button>
    <h1 style="margin:0 0 6px;font-size:25px;font-weight:720;letter-spacing:-.01em">Review your booking</h1>
    <p style="margin:0 0 22px;font-size:14px;color:#7a766f">${COUNTER?'Check the details with the guest, then take the payment.':'Check the details, then attach a valid ID. Once staff approve it, you pay before you check in.'}</p>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px">
        ${blk('Room', esc(R.name)+' <span style="font-weight:400;color:#8a857d">· '+esc(CR_LABEL[R.cr_type])+'</span>')}
        ${blk('Booked by', esc(COUNTER ? state.booker.name : ACCOUNT.name))}
        ${blk('Check-in', esc(fmtDate(x.d.b.checkIn)))}
        ${blk('Check-out', esc(fmtDate(x.d.b.checkOut)))}
        ${blk('Stay', esc(x.nightsLabel))}
        ${blk('Beds', esc(x.bedsLabel))}
      </div>
      ${rosterHtml(state.booking.occupants)}
      <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-top:14px;padding-top:13px;border-top:1px solid rgba(0,0,0,.08)">
        <span style="font-size:12.5px;color:#8a857d">${esc(x.mathLabel)}</span>
        <span style="font-size:21px;font-weight:720">${peso(x.d.total)}</span>
      </div>
      ${x.d.discountAmount>0?`
      <div style="display:flex;justify-content:space-between;gap:10px;font-size:12px;color:#8a857d;margin-top:4px">
        <span>${peso(x.d.roomPrice)} less USeP discount &minus;${x.d.discountPercent}%</span>
        <span style="color:#1c7a4f;font-weight:640">&minus;${peso(x.d.discountAmount)}</span>
      </div>
      <div style="font-size:12.5px;color:#a5a19a;line-height:1.5;margin-top:4px">Provisional &mdash; staff confirm the discount from your USeP ID when they approve the booking, before payment opens.</div>`:''}
    </div>

    ${COUNTER ? `
    <!-- COUNTER: no affiliation question and no upload — the staff member looked
         at the ID on the "who" step, and that judgement goes in the booking's
         history with their name (admin/booking-create.php). -->
    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:18px;margin-top:14px;font-size:13px;line-height:1.7;color:#1c1b19">
      &check; Valid ID checked in person by <strong>${esc(COUNTER_STAFF)}</strong>${state.booker.usepId?' &middot; <strong>USeP ID</strong>, discount applied':''}<br>
      ${state.booker.mode==='account'
        ? '&check; Account confirmed by the guest&rsquo;s password &mdash; <strong>'+esc(state.booker.name)+'</strong>'
        : '&middot; Walk-in with no account &mdash; <strong>'+esc(state.booker.name)+'</strong> &middot; '+esc(state.booker.phone)+' &middot; pays at the counter'}
    </div>` : `
    <!-- USeP AFFILIATION — same rule as the venue page: a CLAIM the customer
         makes, proved by a USeP ID and confirmed by staff. The usep.edu.ph
         account is evidence shown to staff, never a grant (includes/pricing.php).
         DB-DECISIONS #2 applies the discount to hostel bookings too. -->
    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:18px;margin-top:14px">
      <div style="font-size:14.5px;font-weight:680;margin-bottom:3px">USeP affiliation <span style="color:#b23a3a">*</span></div>
      <div style="font-size:12.5px;color:#8a857d;margin-bottom:12px">USeP students, faculty and employees get <strong>${DISCOUNT_PERCENT}% off</strong> the whole stay. Staff confirm it from your ID.</div>
      ${[[true,'I am USeP-affiliated','Student, faculty or employee &mdash; upload your <strong>USeP ID</strong> below'],
         [false,'Not affiliated','Upload any valid government-issued ID below']].map(function(o){
        const on = state.affiliated === o[0];
        return `<div onclick="setAffiliation(${o[0]})" style="display:flex;gap:11px;align-items:flex-start;border:1.5px solid ${on?'#a11626':'rgba(0,0,0,.14)'};background:${on?'#f4f7fc':'#fff'};border-radius:11px;padding:12px 13px;margin-bottom:8px;cursor:pointer">
            <span style="flex:none;width:17px;height:17px;border-radius:999px;border:2px solid ${on?'#a11626':'#c3bfb8'};margin-top:1px;display:flex;align-items:center;justify-content:center">
              ${on?`<span style="width:8px;height:8px;border-radius:999px;background:#a11626"></span>`:''}</span>
            <span style="min-width:0">
              <span style="display:block;font-size:13.5px;font-weight:640;color:#1c1b19">${o[1]}</span>
              <span style="display:block;font-size:12.5px;color:#8a857d;margin-top:2px">${o[2]}</span>
            </span>
          </div>`;
      }).join('')}
      ${isUsepAccount(ACCOUNT.email)?`
      <div style="display:flex;gap:8px;align-items:flex-start;font-size:12.5px;color:#4f7a63;background:#f2faf5;border:1px solid #d4ebdd;border-radius:9px;padding:9px 11px">
        <span>&#10003;</span><span>You are signed in with a USeP address (<strong>${esc(ACCOUNT.email)}</strong>). Staff see this, but the <strong>ID</strong> is what decides the discount.</span>
      </div>`:''}
    </div>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:18px;margin-top:14px">
      <div style="font-size:14.5px;font-weight:680;margin-bottom:3px">Valid ID <span style="color:#b23a3a">*</span></div>
      <div style="font-size:12.5px;color:#8a857d;margin-bottom:12px">One ID for the person booking. Staff check it before approving, and match it at check-in.</div>
      <input type="file" id="idFile" accept="image/png,image/jpeg,image/webp,application/pdf" style="display:none" onchange="uploadId(this)">
      ${idOk?`
        <div style="display:flex;align-items:center;gap:11px;border:1px solid rgba(0,0,0,.12);border-radius:11px;padding:11px 13px;background:#fff">
          <span style="width:8px;height:8px;border-radius:999px;background:#2f9e63;flex:none"></span>
          <span style="flex:1;font-size:13px;font-weight:600;color:#4a463f;overflow-wrap:anywhere">${esc(state.idFile.name)}</span>
          <button onclick="removeId()" style="flex:none;background:#fff;border:1px solid rgba(0,0,0,.14);border-radius:8px;padding:6px 11px;font-size:12px;font-weight:600;color:#4a463f;cursor:pointer">Remove</button>
        </div>`:`
        <div onclick="document.getElementById('idFile').click()" style="border:1.5px dashed rgba(0,0,0,.18);border-radius:11px;padding:24px 14px;text-align:center;background:#fff;cursor:pointer">
          <div style="font-size:13.5px;font-weight:600;color:#4a463f">Tap to upload your valid ID</div>
          <div style="font-size:12.5px;color:#a5a19a;margin-top:3px">PNG, JPG or PDF · up to ${UPLOAD_MAX_MB} MB · school or government ID</div>
        </div>`}
    </div>`}

    ${REFUNDS_ENABLED?'':`
    <div style="background:#fff;border:1px solid ${state.agreeNoRefund?'#d4ebdd':'#f0d9b8'};border-radius:16px;padding:18px;margin-top:14px">
      <div style="font-size:14.5px;font-weight:680;margin-bottom:3px">Non-refundable booking <span style="color:#b23a3a">*</span></div>
      <div style="font-size:12.5px;color:#8a857d;margin-bottom:12px;line-height:1.55">USeP does not give refunds. Once you pay, this stay is final. If USeP has to close the room, you will be offered a replacement room or new dates instead.</div>
      <label style="display:flex;gap:9px;align-items:flex-start;font-size:13px;line-height:1.55;color:#1c1b19;cursor:pointer">
        <input type="checkbox" id="agreeNoRefund" ${state.agreeNoRefund?'checked':''} onchange="toggleAgreeNoRefund(this)" style="margin-top:2px;width:15px;height:15px;accent-color:#a11626;flex:none">
        <span>I understand this booking is <strong>non-refundable</strong> once paid.</span>
      </label>
    </div>`}

    <!-- the server's refusal (beds just taken, ID not accepted...) — it used to be set and never drawn -->
    ${state.submitError?`<div role="alert" style="margin-top:14px;background:#fdf2f2;border:1px solid #f2d8d8;color:#b23a3a;border-radius:11px;padding:11px 13px;font-size:13px;line-height:1.5">${esc(state.submitError)}</div>`:''}
    <button onclick="submitRequest()" ${(canSubmitRequest()&&!submitting)?'':'disabled'} style="width:100%;height:48px;margin-top:16px;border:none;border-radius:12px;background:#a11626;color:#fff;font-size:14.5px;font-weight:650;cursor:${(canSubmitRequest()&&!submitting)?'pointer':'not-allowed'};opacity:${canSubmitRequest()?1:.45}">${submitting?'Submitting&hellip;':(COUNTER?'Cash received &mdash; book it ('+peso(x.d.total)+')':'Submit booking request')}</button>
    <div style="font-size:12px;color:#a5a19a;text-align:center;margin-top:8px">${COUNTER?(canSubmitRequest()?'Take the cash first. Nothing is booked until you confirm it is received.':'Tick that the guest understands the booking is non-refundable to continue'):!idOk?'Attach a valid ID to continue':!canSubmitRequest()?'Tick that you understand the booking is non-refundable to continue':'Once staff approve it, pay before you check in &mdash; GCash from My Bookings, or cash at the front desk &mdash; by the end of '+esc(payByInfo().label)+'.'}</div>
  </main>`;
}

/* ---------- screen: PENDING (approval, then pay before check-in) ----------
   PAY TO USE (2026-10-27): no POS to wait for. Staff approve the ID + booking;
   from then on the guest pays — GCash from My Bookings, or cash at the front
   desk — by the end of the check-in day, and gets the bed once it is paid. */
function pendingScreen(){
  const x=bookingRows(); const R=x.R; if(!R) return '';
  const due=payByInfo().label;
  const st=(label,status,bg,fg)=>`
    <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:13px 0;border-bottom:1px solid rgba(0,0,0,.06)">
      <span style="font-size:13.5px;color:#4a463f">${label}</span>
      <span style="padding:4px 11px;border-radius:999px;font-size:12px;background:${bg};color:${fg}">${status}</span>
    </div>`;
  return `
  ${pageHeader()}
  <main style="max-width:600px;margin:0 auto;padding:44px 24px 72px;text-align:center">
    <div style="width:46px;height:46px;border-radius:999px;background:${state.approved?'#e9f5ef':'#fbeee0'};display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
      ${state.approved
        ? '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1c7a4f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
        : '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#8a5a12" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'}
    </div>
    <h1 style="margin:0 0 8px;font-size:25px;font-weight:720;letter-spacing:-.01em">${state.approved?'Approved — pay before you check in':'Submitted — awaiting approval'}</h1>
    <p style="margin:0 auto;max-width:460px;font-size:14.5px;line-height:1.6;color:#4a463f">${state.approved
      ? 'Your beds are held. Pay <strong>'+peso(x.d.total)+'</strong> before you check in: GCash from My Bookings, or cash at the hostel front desk when you arrive. Unpaid by the end of <strong>'+esc(due)+'</strong>, the beds are released.'
      : 'Hostel staff review your <strong>ID and booking</strong>. Once approved, you pay <strong>'+peso(x.d.total)+'</strong> before you check in — GCash from My Bookings, or cash at the front desk when you arrive.'}</p>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:4px 18px;margin-top:24px;text-align:left;box-shadow:0 1px 2px rgba(0,0,0,.04),0 12px 32px rgba(0,0,0,.05)">
      ${st('Booking request',state.approved?'Approved':'Received','#e8f2ec','#1c7a4f')}
      ${state.approved?st('Valid ID ('+esc(state.idFile?state.idFile.name:'submitted')+')','Approved','#e8f2ec','#1c7a4f'):st('Valid ID ('+esc(state.idFile?state.idFile.name:'submitted')+')','Under review','#fdf3e6','#8a5a12')}
      <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:13px 0">
        <span style="font-size:13.5px;color:#4a463f">Payment</span>
        <span style="padding:4px 11px;border-radius:999px;font-size:12px;background:${state.approved?'#fdf3e6':'#fff'};border:1px solid ${state.approved?'#fdf3e6':'rgba(0,0,0,.14)'};color:${state.approved?'#8a5a12':'#6b675f'}">${state.approved?'Due by the end of '+esc(due):'Opens once approved'}</span>
      </div>
    </div>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:16px;margin-top:14px;text-align:left">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px">
        ${blk('Reference', esc(state.reference))}
        ${blk('Room', esc(R.name))}
        ${blk('Stay', esc(x.stayLabel)+' <span style="font-weight:400;color:#8a857d">· '+esc(x.nightsLabel)+'</span>')}
        ${blk('Amount (before check-in)', peso(x.d.total))}
      </div>
      <div style="font-size:12px;color:#8a857d;line-height:1.6;margin-top:14px;border-top:1px solid rgba(0,0,0,.06);padding-top:12px">The hostel is <strong>pay to use</strong>: staff check you in once the stay is paid. A GCash receipt still being reviewed is confirmed first.</div>
    </div>

    ${DEMO_MODE ? `
    <div style="border:1.5px dashed rgba(0,0,0,.16);border-radius:12px;padding:14px 16px;margin-top:18px;text-align:left">
      <div style="font-size:12px;font-weight:650;letter-spacing:.06em;text-transform:uppercase;color:#a5a19a;margin-bottom:6px">Demo only</div>
      ${!state.approved ? `
      <div style="font-size:12.5px;color:#8a857d;line-height:1.5;margin-bottom:10px">Demo mode is on, so the staff side is standing in — use this to simulate the hostel staff approving your ID and booking.</div>
      <button onclick="demoApprove()" style="height:42px;padding:0 18px;border:1px solid rgba(0,0,0,.16);border-radius:10px;background:#fff;font-size:13px;font-weight:640;cursor:pointer">Simulate staff approval</button>` : `
      <div style="font-size:12.5px;color:#8a857d;line-height:1.5;margin-bottom:10px">Payment is open. Use this to go to the payment step, as Pay now in My Bookings would.</div>
      <button onclick="demoGoPay()" style="height:42px;padding:0 18px;border:1px solid rgba(0,0,0,.16);border-radius:10px;background:#fff;font-size:13px;font-weight:640;cursor:pointer">Pay now</button>`}
    </div>` : `
    <!-- Live system: real people do these steps. Say who has it and where the
         answer will appear, rather than ending the screen on nothing. -->
    <div style="border:1px solid rgba(0,0,0,.08);border-radius:12px;padding:14px 16px;margin-top:18px;text-align:left;background:#fff">
      <div style="font-size:12.5px;color:#6b675f;line-height:1.6">Your request is with the hostel staff now. You will see the decision in <a href="{{ route('customer.booking-history') }}" style="color:#1f1e1e;font-weight:640">My Bookings</a> &mdash; pay there with <strong>Pay now</strong>, or bring the cash and your valid ID to the front desk.</div>
    </div>`}

    <button onclick="restart()" style="background:none;border:none;color:#8a857d;font-size:13px;font-weight:600;cursor:pointer;margin-top:18px;text-decoration:underline">Browse other rooms</button>
  </main>`;
}

/* ---------- screen: PAYMENT ---------- */
/* Reuses the SHARED receipt checker (includes/gcash-checker.php) — same engine as
   the venue, pointed at the hostel staff's account via $gcAccount. */
@include('partials.booking-receipt-panel')

function paymentScreen(){
  const x=bookingRows(); const R=x.R; if(!R) return '';
  const cash=state.payMethod==='cash';
  const paid=receiptGate();

  const methodCard=(m,title,sub)=>{
    const on=state.payMethod===m;
    return `
    <button onclick="setPayMethod('${m}')" style="flex:1;text-align:left;border:1px solid ${on?'#a11626':'rgba(0,0,0,.14)'};border-radius:12px;padding:12px 13px;background:${on?'#f7f8fa':'#fff'};cursor:pointer">
      <div style="display:flex;align-items:center;gap:8px">
        <span style="width:14px;height:14px;border-radius:999px;border:1.5px solid ${on?'#a11626':'#c6c2ba'};display:flex;align-items:center;justify-content:center;flex:none">${on?'<span style="width:7px;height:7px;border-radius:999px;background:#a11626"></span>':''}</span>
        <span style="font-size:13.5px;font-weight:650">${title}</span>
      </div>
      <div style="font-size:12.5px;color:#8a857d;margin-top:4px;line-height:1.45">${sub}</div>
    </button>`;
  };

  const gcashBody=`
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;padding:14px;border:1px solid rgba(0,0,0,.08);border-radius:12px;background:#fff;margin-bottom:14px">
      ${blk('Send to (GCash name)', esc(gcAccount().name))}
      ${blk('GCash number', esc(gcAccount().number.replace(/^(\d{4})(\d{3})(\d{4})$/,'$1 $2 $3')))}
      ${blk('Reference to include', esc(state.reference))}
      ${blk('Exact amount', '<span style="font-size:16px">'+peso(x.d.total)+'</span>')}
      <div style="grid-column:1 / -1;font-size:12.5px;color:#8a857d;line-height:1.5;border-top:1px solid rgba(0,0,0,.06);padding-top:11px">
        This is the hostel staff's designated GCash account — <strong>not</strong> the same account as the event venues. The staff cash this out and hand it to the ${esc(CASHIER.name)}, who issues your Official Receipt.
      </div>
    </div>
    <label style="display:flex;align-items:flex-start;gap:9px;font-size:12.5px;color:#4a463f;line-height:1.5;margin-bottom:12px;cursor:pointer">
      <input type="checkbox" ${state.agreeExact?'checked':''} onchange="toggleAgreeExact(this)" style="margin-top:2px;width:15px;height:15px;accent-color:#a11626;flex:none">
      <span>I sent <strong>exactly ${peso(x.d.total)}</strong> to the account above. A different amount is not confirmed automatically &mdash; a coordinator has to review it, which delays my booking.</span>
    </label>
    ${receiptPanelHtml()}`;

  const cashBody=`
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;padding:14px;border:1px solid rgba(0,0,0,.08);border-radius:12px;background:#fff;margin-bottom:14px">
      ${blk('Pay at', esc(CASH_PAY.where))}
      ${blk('Open', esc(CASH_PAY.hours))}
      ${blk('Quote your reference', esc(state.reference))}
      ${blk('Exact amount', '<span style="font-size:16px">'+peso(x.d.total)+'</span>')}
      <div style="grid-column:1 / -1;font-size:12.5px;color:#8a857d;line-height:1.5;border-top:1px solid rgba(0,0,0,.06);padding-top:11px">
        Pay the hostel staff when you arrive, by the end of ${esc(payByInfo().label)} &mdash; you get your bed once it is paid. Your Official Receipt comes back from the ${esc(CASHIER.name)} later.
      </div>
    </div>`;

  return `
  ${pageHeader()}
  <main style="max-width:620px;margin:0 auto;padding:34px 24px 72px">
    <h1 style="margin:0 0 6px;font-size:25px;font-weight:720;letter-spacing:-.01em">Payment</h1>
    <p style="margin:0 0 18px;font-size:14px;color:#7a766f">Your booking is approved. Pay before you check in, by the end of <strong>${esc(payByInfo().label)}</strong> &mdash; one full payment for the whole stay. Unpaid by then, the beds are released.</p>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:18px;margin-bottom:14px">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px">
        ${blk('Room', esc(R.name)+' <span style="font-weight:400;color:#8a857d">· '+esc(CR_LABEL[R.cr_type])+'</span>')}
        ${blk('Stay', esc(x.stayLabel)+' <span style="font-weight:400;color:#8a857d">· '+esc(x.nightsLabel)+'</span>')}
        ${blk('Beds', esc(x.bedsLabel))}
        ${blk('Amount due', '<span style="font-size:17px">'+peso(x.d.total)+'</span> <span style="font-weight:400;color:#8a857d;font-size:12px">'+esc(x.mathLabel)+'</span>')}
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-bottom:16px">
      ${methodCard('gcash','GCash','Send to the designated staff account — checked instantly.')}
      ${methodCard('cash','Cash at the front desk','Pay the hostel staff when you arrive.')}
    </div>

    ${cash?cashBody:gcashBody}

    <button onclick="confirmBooking()" ${paid?'':'disabled'} style="width:100%;height:48px;margin-top:4px;border:none;border-radius:12px;background:#a11626;color:#fff;font-size:14.5px;font-weight:650;cursor:${paid?'pointer':'not-allowed'};opacity:${paid?1:.45}">${cash?'I will pay at the front desk':'Confirm payment'}</button>
    <div style="font-size:12px;color:#a5a19a;text-align:center;margin-top:8px">${cash?'Your beds are held until the end of '+esc(payByInfo().label)+' — pay at the front desk before then.':(paid?'':'Upload a receipt that passes the check to continue')}</div>
  </main>`;
}

/* ---------- screen: CONFIRMATION ---------- */
function doneScreen(){
  const x=bookingRows(); const R=x.R; if(!R) return '';
  const cash=state.payMethod==='cash';
  const or=state.orNumber;
  const st=(label,status,bg,fg)=>`
    <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:13px 0;border-bottom:1px solid rgba(0,0,0,.06)">
      <span style="font-size:13.5px;color:#4a463f">${label}</span>
      <span style="padding:4px 11px;border-radius:999px;font-size:12px;background:${bg};color:${fg}">${status}</span>
    </div>`;
  return `
  ${pageHeader()}
  <main style="max-width:600px;margin:0 auto;padding:44px 24px 72px;text-align:center">
    <div style="width:46px;height:46px;border-radius:999px;background:#e8f2ec;display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1c7a4f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <h1 style="margin:0 0 8px;font-size:25px;font-weight:720;letter-spacing:-.01em">${cash?'Beds held — pay at the front desk':'Payment sent'}</h1>
    <p style="margin:0 auto;max-width:460px;font-size:14.5px;line-height:1.6;color:#4a463f">${cash
      ? 'Your beds are held until the end of <strong>'+esc(payByInfo().label)+'</strong>. Hand the staff '+peso(x.d.total)+' at the hostel front desk when you arrive — you get your bed once it is paid.'
      : 'Staff confirm your GCash payment against the hostel account. Once it is confirmed, your stay is paid and you can check in. One document is still on its way — see below.'}</p>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:4px 18px;margin-top:24px;text-align:left;box-shadow:0 1px 2px rgba(0,0,0,.04),0 12px 32px rgba(0,0,0,.05)">
      ${st('Payment', cash?'Awaiting cash at the front desk':'Sent — staff confirm it','#fdf3e6','#8a5a12')}
      ${st('VENUSeP System Receipt','In My Bookings once confirmed','#fff','#6b675f')}
      ${!cash?st('GCash payment receipt','Yours to keep','#e8f2ec','#1c7a4f'):''}
      <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:13px 0">
        <span style="font-size:13.5px;color:#4a463f">Official Receipt (${esc(CASHIER.name)})</span>
        <span style="padding:4px 11px;border-radius:999px;font-size:12px;background:${or?'#e8f2ec':'#fdf3e6'};color:${or?'#1c7a4f':'#8a5a12'}">${or?esc(or):'Not issued yet'}</span>
      </div>
    </div>

    ${!or?`
    <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:12px;margin-top:14px;background:#fff;border:1px solid rgba(0,0,0,.09);text-align:left">
      <span style="width:8px;height:8px;border-radius:999px;background:#d9930d;margin-top:5px;flex:none"></span>
      <div>
        <div style="font-size:13.5px;font-weight:650;color:#1c1b19">Your Official Receipt is still coming</div>
        <div style="font-size:12.5px;color:#7a766f;margin-top:1px">The staff bring your payment to the ${esc(CASHIER.name)}, who issues the OR. It does not hold up your check-in &mdash; keep it as your proof of payment.</div>
      </div>
    </div>`:''}

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:16px;margin-top:14px;text-align:left">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px">
        ${blk('Reference', esc(state.reference))}
        ${blk('Pay by', 'end of '+esc(payByInfo().label))}
        ${blk('Room', esc(R.name)+' <span style="font-weight:400;color:#8a857d">· '+esc(CR_LABEL[R.cr_type])+'</span>')}
        ${blk('Stay', esc(x.stayLabel)+' <span style="font-weight:400;color:#8a857d">· '+esc(x.nightsLabel)+'</span>')}
        ${blk('Beds', esc(x.bedsLabel))}
        ${blk('Total', peso(x.d.total))}
      </div>
      ${rosterHtml(state.booking.occupants)}
      <div style="font-size:12px;color:#8a857d;line-height:1.6;margin-top:12px;border-top:1px solid rgba(0,0,0,.06);padding-top:12px">
        <strong style="font-weight:640;color:#4a463f">At check-in:</strong> show the staff the valid ID you submitted. You get your bed once the stay is paid. Each guest sleeps in the bed booked under their name.
      </div>
    </div>

    ${!or ? (DEMO_MODE ? `
    <div style="border:1.5px dashed rgba(0,0,0,.16);border-radius:12px;padding:14px 16px;margin-top:18px;text-align:left">
      <div style="font-size:12px;font-weight:650;letter-spacing:.06em;text-transform:uppercase;color:#a5a19a;margin-bottom:6px">Demo only</div>
      <div style="font-size:12.5px;color:#8a857d;line-height:1.5;margin-bottom:10px">Demo mode is on, so the cashier is standing in. Use this to simulate the ${esc(CASHIER.name)} issuing the Official Receipt after the staff hand over the money.</div>
      <button onclick="demoIssueOr()" style="height:42px;padding:0 18px;border:1px solid rgba(0,0,0,.16);border-radius:10px;background:#fff;font-size:13px;font-weight:640;cursor:pointer">Simulate OR issued by the cashier</button>
    </div>` : `
    <div style="border:1px solid rgba(0,0,0,.08);border-radius:12px;padding:14px 16px;margin-top:18px;text-align:left;background:#fff">
      <div style="font-size:12.5px;color:#6b675f;line-height:1.6">The ${esc(CASHIER.name)} issues the Official Receipt once the staff hand the money over. It will appear on this stay in <a href="{{ route('customer.booking-history') }}" style="color:#1f1e1e;font-weight:640">My Bookings</a>.</div>
    </div>`) : ''}

    <button onclick="restart()" style="background:none;border:none;color:#8a857d;font-size:13px;font-weight:600;cursor:pointer;margin-top:18px;text-decoration:underline">Back to venues</button>
  </main>`;
}

/* ---------- render ---------- */
function currentScreen(){
  switch(state.screen){
    case 'detail':  return detailScreen();
    case 'who':     return whoScreen();          // counter only — includes/counter-booker.php
    case 'review':  return reviewScreen();
    case 'pending': return pendingScreen();
    case 'payment': return paymentScreen();
    case 'done':    return COUNTER ? counterDoneScreen() : doneScreen();
  }
  return '';
}

/* ---------- screen: DONE at the counter ----------
   PAY TO USE (2026-10-27): the stay was recorded only because the cash was
   taken — booking-create.php wrote it approved AND paid, with its System
   Receipt. The guest can check in on the check-in day; the POS and OR are
   recorded on the booking whenever CEDU and the cashier issue them. */
function counterDoneScreen(){
  const x=bookingRows(); const R=x.R; if(!R) return '';
  const r = state.counterResult || {};
  const blk2=(label,value)=>`<div style="min-width:0"><div style="font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">${label}</div><div style="font-size:13.5px;font-weight:640;color:#1c1b19;overflow-wrap:anywhere">${value}</div></div>`;
  const next = state.booking.checkIn===TODAY
    ? 'Paid. Check the guest in from the booking when they are ready.'
    : 'Paid. The guest checks in on <strong>'+esc(fmtDate(state.booking.checkIn))+'</strong> with the valid ID you saw.';
  return `
  <main style="max-width:620px;margin:0 auto;padding:40px 18px 80px;text-align:center">
    <div style="width:54px;height:54px;border-radius:999px;background:#eaf6ef;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;color:#1c7a4f">&check;</div>
    <h1 style="font-size:22px;font-weight:700;letter-spacing:-.02em;margin:0 0 6px">Stay booked and paid</h1>
    <p style="margin:0 auto;max-width:440px;font-size:14.5px;line-height:1.6;color:#4a463f">Read the reference out to the guest. ${r.demo?'<strong>Demo mode &mdash; nothing was saved.</strong>':'The beds are held in their name.'}</p>
    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:18px;margin-top:22px;text-align:left;box-shadow:0 1px 2px rgba(0,0,0,.04),0 12px 32px rgba(0,0,0,.05)">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px">
        ${blk2('Reference', esc(state.reference))}
        ${blk2('Booked for', esc(state.booker.name))}
        ${blk2('Room', esc(R.name))}
        ${blk2('Stay', esc(x.stayLabel)+' &middot; '+esc(x.nightsLabel))}
        ${blk2('Beds', esc(x.bedsLabel))}
        ${blk2('Paid in cash', peso(x.d.total))}
      </div>
      <div style="border-top:1px solid #f0efec;margin-top:14px;padding-top:12px;font-size:12.5px;color:#4a463f;line-height:1.6">${next} The System Receipt is on the booking${state.booker.mode==='walkin'&&!state.booker.noEmail?' and was emailed to the guest':''}.</div>
      <div style="font-size:12.5px;color:#8a857d;line-height:1.6;margin-top:8px">
        ${state.booker.mode==='walkin'
          ? 'No account, so any refund is handled by staff at the counter.'
          : 'Linked to <strong>'+esc(state.booker.name)+'</strong>&rsquo;s account &mdash; they also see it in My Bookings.'}
      </div>
    </div>
    <div style="display:flex;gap:12px;margin-top:20px;flex-wrap:wrap">
      <a href="{{ route('admin.booking-request') }}?id=${encodeURIComponent(state.reference)}" style="flex:1;min-width:180px;height:48px;display:flex;align-items:center;justify-content:center;border-radius:11px;background:#a11626;color:#fff;font-size:14.5px;font-weight:680;text-decoration:none">Open the booking</a>
      <a href="{{ route('customer.hostel-reservation') }}?room=${encodeURIComponent(state.roomId)}&counter=1" style="flex:none;height:48px;padding:0 20px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(0,0,0,.16);border-radius:11px;background:#fff;color:#1c1b19;font-size:14px;font-weight:640;text-decoration:none">Another booking</a>
    </div>
    <a href="{{ route('admin.dashboard') }}" style="display:inline-block;margin-top:16px;color:#8a857d;font-size:13px;font-weight:600">Back to the dashboard</a>
  </main>`;
}
/* Redraw, then put the caret back where it was — the bed-name inputs and the
   counter's "who" fields re-render on every keystroke, and a freshly focused
   input puts the caret at the START, so typing would come out backwards. */
function render(){
  const ae=document.activeElement;
  const id=ae&&ae.id?ae.id:null;
  const cls=ae&&ae.className==='bed-name'?[...document.querySelectorAll('.bed-name')].indexOf(ae):-1;
  let pos=null, end=null;
  try{ pos=ae?ae.selectionStart:null; end=ae?ae.selectionEnd:null; }catch(e){}
  document.getElementById('app').innerHTML=`<div style="min-height:100vh;background:#fff">${COUNTER?counterBar():''}${currentScreen()}</div>`;
  /* re-attach the live 360 node after the innerHTML swap — it is moved, not
     rebuilt, so typing a guest name never reloads the panorama */
  mountGallery();   /* put the photo strip back where the swipe left it */
  if(cls>-1){
    const back=document.querySelectorAll('.bed-name')[cls];
    if(back){ back.focus(); try{ if(pos!=null) back.setSelectionRange(pos,end); }catch(e){} }
  }else if(id){
    const back=document.getElementById(id);
    if(back && back.focus){
      back.focus();
      try{ if(pos!=null) back.setSelectionRange(pos,end); }catch(e){}
    }
  }
}
render();
</script>
<!-- GSAP — the landing page's motion, on the booking flow. Same CDN as the rest of the project. -->
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>
<script>
  /* Motion for the booking flow — two parts, both skipped when GSAP is missing
     or the user prefers reduced motion.

     (1) Each SCREEN pops in when you move to it (detail -> review -> pending ->
         payment -> done): its blocks rise and fade in with a stagger, and each
         block keeps a scroll trigger after that, so scrolling up and down
         replays the pop. render() also fires on every keystroke and date pick;
         those re-arm the blocks silently — nothing moves under your hands.
     (2) The static parts below the flow (More rooms, footer) reveal on scroll
         exactly as on the landing page, and replay up and down. */
  (function () {
@include('partials.booking-motion')
    (function () {
      if (!window.matchMedia || !window.matchMedia('(max-width: 720px)').matches) return;
      var bar = document.createElement('div'); bar.className = 'bk-cta'; bar.setAttribute('aria-hidden', 'true');
      bar.innerHTML = '<div class="bk-cta-price"></div><button type="button" class="bk-cta-btn">Reserve a bed</button>';
      document.body.appendChild(bar);
      bar.querySelector('.bk-cta-btn').addEventListener('click', function () {
        var aside = document.querySelector('#app aside'); if (!aside) return;
        aside.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var first = aside.querySelector('input, select, button'); if (first) setTimeout(function () { first.focus({ preventScroll: true }); }, 500);
      });
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { if (en.target === watched) formSeen = en.isIntersecting; });   /* ignore late reports about a form that was re-rendered away */
        paintBar();
      }, { threshold: 0.15 });
      var formSeen = false, watched = null;
      function paintBar() {
        var onDetail = typeof state !== 'undefined' && state.screen === 'detail';
        var aside = document.querySelector('#app aside');
        if (aside !== watched) { if (watched) io.unobserve(watched); watched = aside; if (aside) io.observe(aside); formSeen = false; }
        var show = onDetail && aside && !formSeen;
        if (show) { try { bar.querySelector('.bk-cta-price').innerHTML = '<strong>' + peso(roomRate(getRoom())) + '</strong>per head, per night'; } catch (e) {} }
        bar.classList.toggle('show', !!show); document.body.classList.toggle('bk-cta-on', !!show);
      }
      var r0 = render; render = function () { r0.apply(this, arguments); paintBar(); };
      paintBar();
    })();

    var lastScreen = (typeof state !== 'undefined' && state.screen) || '';
    var pageRender = render;                          /* the page's own render() */
    render = function () {
      pageRender.apply(this, arguments);
      var now = (typeof state !== 'undefined' && state.screen) || '';
      var changed = now !== lastScreen; lastScreen = now;
      armScreen(changed);
    };
    armScreen(true);                                  /* the first screen, already drawn */

    /* ---- (2) scroll reveals for the static parts ---- */
    function reveal(targets, trigger, opts) {
      opts = opts || {};
      gsap.set(targets, { opacity: 0, y: opts.rise || 24 });
      function paint(on) {
        gsap.to(targets, { opacity: on ? 1 : 0, y: on ? 0 : (opts.rise || 24), duration: on ? 1 : .4, ease: EASE,
          overwrite: 'auto', stagger: on ? (opts.stagger || 0) : 0, clearProps: on ? 'transform' : '' });
      }
      ScrollTrigger.create({ trigger: trigger, start: 'top 88%', end: 'bottom top',
        onToggle: function (s) { paint(s.isActive); }, onRefresh: function (s) { paint(s.isActive); } });
    }
    var head = document.querySelector('.bk-more-head'); if (head) reveal(head, head);
    var grid = document.querySelector('.bk-more-grid'); if (grid) reveal(grid.children, grid, { stagger: .09 });
    var alt  = document.querySelector('.bk-more-alt');  if (alt)  reveal(alt, alt);
    var foot = document.querySelector('.bk-footer .bk-footer-grid'); if (foot) reveal(foot.children, foot, { stagger: .08, rise: 16 });
  })();
</script>
@include('partials.booking-history-nav')
</body>
</html>
