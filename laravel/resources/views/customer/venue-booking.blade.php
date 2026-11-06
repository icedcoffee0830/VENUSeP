{{-- The PUBLIC landing page (was customer/venusep_venue_booking.php) — standalone, with the
     shared browse-mode top bar. Data: Customer\VenueBookingController. --}}
@use('App\Http\Controllers\Customer\VenueBookingController', 'LP')
<!DOCTYPE html>
<html lang="en">
<head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Venues in USEP | Booking Landing Page</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/venue-booking.css') }}" />
</head>
<body>
<script>document.body.classList.add('js');</script>{{-- stays here: it must run before the first paint --}}

<!-- The three drawn placeholders, defined once and reused by <use>. A real
     photo in assets/img/venues/ replaces the drawing for that room. -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="lp-art-hall" viewBox="0 0 400 250">
    <g stroke="#fff" stroke-opacity=".32" fill="none" stroke-width="1.6">
      <path d="M80 172V108a30 30 0 0 1 60 0v64Z"/><path d="M170 172V98a30 30 0 0 1 60 0v74Z"/><path d="M260 172V108a30 30 0 0 1 60 0v64Z"/>
      <path d="M24 172h352"/><path d="M150 172v-32h100v32"/>
    </g>
    <g fill="#fff" fill-opacity=".14"><rect x="84" y="196" width="24" height="8" rx="4"/><rect x="120" y="196" width="24" height="8" rx="4"/><rect x="156" y="196" width="24" height="8" rx="4"/><rect x="192" y="196" width="24" height="8" rx="4"/><rect x="228" y="196" width="24" height="8" rx="4"/><rect x="264" y="196" width="24" height="8" rx="4"/><rect x="300" y="196" width="24" height="8" rx="4"/></g>
  </symbol>
  <symbol id="lp-art-gym" viewBox="0 0 400 250">
    <g stroke="#fff" stroke-opacity=".32" fill="none" stroke-width="1.6">
      <path d="M24 84c50-30 100-42 176-42s126 12 176 42"/><path d="M24 108c50-28 100-40 176-40s126 12 176 40"/>
      <path d="M64 84v98M134 68v114M200 62v120M266 68v114M336 84v98"/><path d="M14 182h372"/>
      <rect x="128" y="128" width="144" height="54" rx="3"/><path d="M200 128v54M128 155h144" stroke-opacity=".5"/>
    </g>
  </symbol>
  <symbol id="lp-art-bunk" viewBox="0 0 400 250">
    <g stroke="#fff" stroke-opacity=".38" fill="none" stroke-width="1.7">
      <rect x="52" y="72" width="140" height="46" rx="6"/><rect x="52" y="146" width="140" height="46" rx="6"/>
      <path d="M62 72V56M182 72V56M62 146v-28M182 146v-28M62 192v22M182 192v22"/>
      <rect x="228" y="72" width="140" height="46" rx="6"/><rect x="228" y="146" width="140" height="46" rx="6"/>
      <path d="M238 72V56M358 72V56M238 146v-28M358 146v-28M238 192v22M358 192v22"/><path d="M14 214h372"/>
    </g>
    <g fill="#fff" fill-opacity=".22"><rect x="64" y="82" width="40" height="16" rx="7"/><rect x="64" y="156" width="40" height="16" rx="7"/><rect x="240" y="82" width="40" height="16" rx="7"/><rect x="240" y="156" width="40" height="16" rx="7"/></g>
  </symbol>
  <symbol id="lp-art-private" viewBox="0 0 400 250">
    <g stroke="#fff" stroke-opacity=".38" fill="none" stroke-width="1.7">
      <rect x="44" y="88" width="132" height="42" rx="6"/><rect x="44" y="158" width="132" height="42" rx="6"/>
      <path d="M54 88V72M166 88V72M54 158v-28M166 158v-28M54 200v20M166 200v20"/>
      <path d="M244 52v168M244 52h122M244 220h122"/><path d="M272 132a22 22 0 0 1 44 0v10h-44Z"/><path d="M266 142h56M294 142v22"/>
      <rect x="336" y="96" width="26" height="60" rx="4"/><path d="M14 220h372"/>
    </g>
    <g fill="#fff" fill-opacity=".22"><rect x="56" y="98" width="36" height="15" rx="7"/><rect x="56" y="168" width="36" height="15" rx="7"/></g>
  </symbol>
  <symbol id="lp-i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
  <symbol id="lp-i-pin" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="2.6"/></symbol>
  <symbol id="lp-i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
  <symbol id="lp-art-colonnade" viewBox="0 0 1200 760">
    <g stroke="#fff" fill="none" stroke-width="1.6">
      <path d="M120 250 600 96l480 154"/><path d="M120 250h960"/><path d="M150 250h900" stroke-opacity=".5"/>
      <path d="M196 250v368M330 250v368M464 250v368M598 250v368M732 250v368M866 250v368M1000 250v368"/>
      <path d="M180 618h32M314 618h32M448 618h32M582 618h32M716 618h32M850 618h32M984 618h32"/>
      <path d="M232 618V438a31 31 0 0 1 62 0v180M366 618V438a31 31 0 0 1 62 0v180M500 618V438a31 31 0 0 1 62 0v180M634 618V438a31 31 0 0 1 62 0v180M768 618V438a31 31 0 0 1 62 0v180M902 618V438a31 31 0 0 1 62 0v180" stroke-opacity=".62"/>
      <path d="M60 618h1100M90 650h1040M120 682h980" stroke-opacity=".42"/>
    </g>
  </symbol>
</svg>

<!-- ==================== NAV — the shared customer nav, transparent over the hero ==================== -->
@include('partials.customer-nav', ['navMode' => 'hero'])

<main>

  <!-- ==================== HERO ==================== -->
  <section class="lp-hero lp-grain">
    <div class="lp-glow lp-hero-glow"></div>
    <svg class="lp-hero-art" viewBox="0 0 1200 760" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><use href="#lp-art-colonnade"/></svg>
    <div class="lp-glow lp-hero-vignette"></div>

    <div class="lp-scroll-cue" id="lpCue" aria-hidden="true">
      Scroll
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
    </div>

    <div class="lp-wrap">
      <div class="lp-hero-copy" data-reveal data-hero>
        @include('customer.overdue-notice', ['noticeMargin' => '0 auto 22px', 'noticeGutter' => '0'])
@if ($lpCounterNotice) {{-- counter mode: no office yet, or another office's booking page was refused --}}
        <div role="alert" style="max-width:1120px;margin:0 auto 22px;padding:12px 16px;background:#fdf6e7;border:1px solid #efd9ab;border-radius:12px;color:#5c3d0c;font-size:14px;line-height:1.55;text-align:left;box-sizing:border-box">{{ $lpCounterNotice }}</div>
@endif
        <span class="lp-pill lp-eyebrow" data-hero-item>
          <svg class="lp-i" width="13" height="13" style="color:var(--gold)" aria-hidden="true"><use href="#lp-i-pin"/></svg>
          USeP Tagum&ndash;Mabini Campus
        </span>
        <h1 data-hero-item>Reserve a campus venue in <br>minutes, not visits.</h1>
@if ($lpWhat) {{-- none: a counter with no office --}}
        <p class="lp-hero-sub" data-hero-item>{{ $lpWhat }} &mdash; check real availability, reserve online, and pay by GCash or cash.</p>
@endif

@if ($lpVenueCount) {{-- counter mode shows the session's own offices only (DB-DECISIONS 28) --}}
        <div class="lp-hero-actions" data-hero-item>
          <a class="lp-btn lp-btn-white" href="#{{ $lpVenueNames ? 'venue-listings' : 'hostel-listings' }}">
            Browse the spaces
            <svg class="lp-i" width="16" height="16" aria-hidden="true"><use href="#lp-i-arrow"/></svg>
          </a>
@if ($lpShowHostel && $lpVenueNames)
          <a class="lp-btn lp-btn-ghost" href="#hostel-listings">Hostel beds</a>
@endif
        </div>

        <!-- real numbers, computed from the shared lists -->
        <div class="lp-stats" data-hero-item>
          <div class="lp-stat"><b data-count="{{ (int) $lpVenueCount }}">{{ (int) $lpVenueCount }}</b><span>venues</span></div>
          <div class="lp-stat"><b data-count="{{ (int) $lpSpaceCount }}">{{ (int) $lpSpaceCount }}</b><span>bookable spaces</span></div>
          <div class="lp-stat"><b class="gold" data-count="{{ (int) $DISCOUNT_PERCENT }}" data-suffix="%">{{ (int) $DISCOUNT_PERCENT }}%</b><span>off for USeP</span></div>
        </div>
@endif
      </div>
    </div>
  </section>

  <!-- ==================== VENUES ==================== -->
@if ($lpVenueCount)
  <section class="lp-venues" id="venues">
    <div class="lp-wrap">
      <div class="lp-section-head" data-reveal>
        <div>
          <span class="lp-eyebrow">Where you can book</span>
          <h2 class="lp-h2">{{ $lpPlaces }}</h2>
        </div>
        <a href="#{{ $lpVenueNames ? 'venue-listings' : 'hostel-listings' }}">See every space
          <svg class="lp-i" width="15" height="15" aria-hidden="true"><use href="#lp-i-arrow"/></svg>
        </a>
      </div>

      <div class="lp-grid-3" style="margin-top:32px" data-stagger>
@foreach ($lpVenueNames as $venueName)
@php
        $rooms  = venueRoomsFor($venueRooms, $venueName);
        if (!$rooms) continue;
        $caps   = array_column($rooms, 'capacity');
        $slug   = $lpVenueSlug[$venueName] ?? 'venue';
        $anchor = 'venue-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($venueName));
@endphp
        <a class="lp-card lp-venue-card lp-lift" href="#{{ $anchor }}">
          {!! LP::photoBlock($slug, $lpVenueArt[$venueName] ?? 'hall', '', $venueName) !!}
          <div class="lp-card-body">
            <h3>{{ $venueName }}</h3>
            <p class="lp-card-desc">{{ $lpVenueBlurb[$venueName] ?? '' }}</p>
            <div class="lp-venue-foot">
              <span class="lp-count">{{ count($rooms) }} spaces &middot; {{ (int) min($caps) }}&ndash;{{ number_format(max($caps)) }} seats</span>
            </div>
          </div>
        </a>
@endforeach
@if ($lpShowHostel)
        <a class="lp-card lp-venue-card lp-lift" href="#hostel-listings">
          {!! LP::photoBlock('venue-usep-hostel', 'bunk', '', $HOSTEL_VENUE) !!}
          <div class="lp-card-body">
            <h3>{{ $HOSTEL_VENUE }}</h3>
            <p class="lp-card-desc">Bunk rooms booked by the bed, with communal or private bathrooms.</p>
            <div class="lp-venue-foot">
              <span class="lp-count">{{ count($hostelRooms) }} rooms &middot; {{ (int) $lpBedCount }} beds</span>
            </div>
          </div>
        </a>
@endif
      </div>
    </div>
  </section>
@endif

  <!-- ==================== HOW IT WORKS ==================== -->
  <section class="lp-how lp-grain" id="how">
    <div class="lp-glow lp-how-glow"></div>
    <div class="lp-wrap">
      <div data-reveal>
        <span class="lp-eyebrow">How it works</span>
        <h2 class="lp-h2">Four steps, and you know where you stand at each one.</h2>
      </div>
      <div class="lp-steps" data-stagger>
        <div class="lp-step">
          <span class="lp-step-n">1</span>
          <h3>Pick a space and time</h3>
          <p>Live availability, so you never request a slot that is already taken. Bahay Alumni reservations must start at least 12 hours ahead; USeP Venues can be booked the same day, if there&rsquo;s time left.</p>
        </div>
        <div class="lp-step">
          <span class="lp-step-n">2</span>
          <h3>Upload a valid ID</h3>
          <p>Say whether you are USeP-affiliated. Staff confirm it from your ID &mdash; that is what unlocks the {{ (int) $DISCOUNT_PERCENT }}% rate.</p>
        </div>
        <div class="lp-step">
          <span class="lp-step-n">3</span>
          <h3>Staff review your request</h3>
          <p>You will see the decision, and the reason behind it, in your booking history.</p>
        </div>
        <div class="lp-step">
          <span class="lp-step-n">4</span>
          {{-- each office's own rule: its refund switch also sets a venue's payment timing (DB-DECISIONS #18, 28) --}}
          <h3>Pay when your office asks</h3>
          <p>GCash or over the counter.@foreach ($lpOffices as $o)<br><strong>{{ $o['name'] }}:</strong> {{ ['pay_to_use' => 'before your stay, by your check-in day', 'prepay' => 'before your event, at least one day ahead', 'postpay' => 'after your event, by the day after it'][office_payment_policy($o)] }}.@endforeach</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ==================== SPACES ==================== -->
@if ($lpVenueNames)
  <section class="lp-spaces" id="venue-listings">
    <div class="lp-wrap">
      <div data-reveal>
        <span class="lp-eyebrow">The spaces</span>
        <h2 class="lp-h2">Pick one and open its booking page</h2>
        <p class="lp-lead" style="max-width:560px">Dates, times, ID upload and payment all happen there.</p>
      </div>

      @if ($isUsep)
        <!-- USeP account: prices below are shown discounted with the full price
             crossed out. A preview, not a grant — staff confirm from the ID. -->
        <div class="lp-notice lp-notice-usep" data-reveal>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 13 4.5 4.5L19 7"/></svg>
          <span>You are signed in with a USeP account, so prices show your <strong>{{ $lpDiscountLabel }} USeP rate</strong> with the full price crossed out. It is confirmed from your USeP ID when staff approve the booking.</span>
        </div>
      @else
        <!-- everyone else: full price, plus the nudge — a USeP student on a Gmail
             account should still find out the discount exists. -->
        <div class="lp-notice lp-notice-nudge" data-reveal>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#a11626" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
          <span><strong>USeP student, faculty, staff or alumni?</strong> You get {{ $lpDiscountLabel }} off &mdash; choose "USeP-affiliated" and upload your USeP ID when you book.</span>
        </div>
      @endif

      <!-- Venue rooms come from the ONE shared source (legacy venue-rooms),
           the same data the booking page and admin Venue Management read.
           ONE BLOCK PER VENUE, in the order the data lists them: the venue cards
           above link to these anchors, so "Bahay Alumni" lands on Bahay Alumni. -->
@foreach ($lpVenueNames as $venueName)
@php
        $rooms  = venueRoomsFor($venueRooms, $venueName);
        if (!$rooms) continue;
        $anchor = 'venue-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($venueName));   /* Bahay Alumni -> venue-bahay-alumni */
@endphp
      <div class="lp-group" id="{{ $anchor }}" data-reveal>
        <h3>{{ $venueName }}</h3>
        <span>{{ count($rooms) }} space{{ count($rooms) === 1 ? '' : 's' }}</span>
      </div>
      <div class="lp-grid-3" data-stagger>
@foreach ($rooms as $room)
@php
          $mt     = $room['maintenance'];
          $covers = $mt && venueMaintCovers($mt, $lpToday);
          /* the badge on the photo: "Closed · Roof repair". The phone tile is too
             narrow for the reason, so there the badge keeps only the word and the
             reason shows as a line in the card body (.lp-card-status) instead. */
          $badge = ''; $status = '';
          if ($covers) {
            $word  = $mt['blocks'] ? 'Closed' : 'Notice';
            $cls   = $mt['blocks'] ? 'closed' : 'note';
            $badge  = '<span class="lp-badge lp-badge-' . $cls . '">' . $word . '<span class="lp-badge-why"> &middot; ' . htmlspecialchars($mt['reason']) . '</span></span>';
            $status = '<p class="lp-card-status lp-card-status-' . $cls . '">' . $word . ' &middot; ' . htmlspecialchars($mt['reason']) . '</p>';
          }
@endphp
        <!-- `counter=1` is carried through so staff picking a room for a walk-in
             stay in counter mode. It grants nothing on its own — the privileges
             come from the staff session (legacy auth.php). -->
        <a class="lp-card lp-lift" href="{{ route('customer.room-reservation') }}?room={{ urlencode($room['id']) }}{{ counter_mode() ? '&counter=1' : '' }}">
          {!! LP::photoBlock($room['id'], LP::roomKind($room), $badge) !!}
          <div class="lp-card-body">
            <h3>{{ $room['name'] }}</h3>
            <p class="lp-card-meta">up to {{ number_format((int) $room['capacity']) }} guests</p>
            {!! $status !!}
            <p class="lp-card-desc">{{ $room['description'] }}</p>
            <div class="lp-card-price">{!! usep_price_html((float) $room['fee'], $isUsep, ' per day', $room['office']['discount']) !!}</div>
          </div>
        </a>
@endforeach
      </div>
@endforeach
    </div>
  </section>
@endif

  <!-- ==========================================================
       USeP HOSTEL — a separate section on purpose. The hostel is a
       venue in the data model, but you book a BED here, not a room,
       and it is priced per head per night. Its two CR types are shown
       apart because that is the choice a guest actually makes.
       Rooms come from legacy hostel-rooms.php (one source).
       ========================================================== -->
@if ($lpShowHostel)
  <section class="lp-hostel lp-grain" id="hostel-listings">
    <div class="lp-glow lp-hostel-glow"></div>
    <svg class="lp-hostel-art" viewBox="0 0 400 250" preserveAspectRatio="xMaxYMin slice" aria-hidden="true"><use href="#lp-art-bunk"/></svg>
    <div class="lp-wrap">
      <div data-reveal>
        <span class="lp-eyebrow">{{ $HOSTEL_VENUE }}</span>
        <h2 class="lp-h2">Book a bed, not the room.</h2>
        <p class="lp-lead">You reserve <strong>beds</strong>, not rooms. Others may book the remaining beds in the same room &mdash; book all six and it is yours. Priced per head, per night.</p>
      </div>

@foreach (['communal', 'private'] as $crType)
@php
        $rooms = hostelRoomsByType($hostelRooms, $crType);
        if (!$rooms) continue;
@endphp
      <div class="lp-cr-head" data-reveal>
        <h3>{{ $HOSTEL_CR_LABEL[$crType] }}</h3>
        <span>{{ $crType === 'private' ? 'Bathroom inside the room' : 'Shared bathroom outside the room' }}</span>
      </div>
      <div class="lp-grid-3" data-stagger>
@foreach ($rooms as $room)
@php
          /* Tonight's free beds — COUNTED from the roster, never a stored number.
             It is a room x night fact, so the card says which night it means. */
          $tonight = date('Y-m-d');
          $free    = hostelBedsFree($room, $tonight);
          $mt      = $room['maintenance'];
          $closed  = $mt && $mt['blocks'] && hostelMaintCovers($mt, $tonight);
@endphp
        <a class="lp-card lp-hs-card lp-lift" href="{{ route('customer.hostel-reservation') }}?room={{ urlencode($room['id']) }}{{ $lpCounter ? '&counter=1' : '' }}">
          {!! LP::photoBlock($room['id'], $crType === 'private' ? 'private' : 'bunk') !!}
          <div class="lp-card-body">
            <h3>{{ $room['name'] }}</h3>
            <p class="lp-card-meta">{{ (int) $room['beds'] }} beds &middot; {!! usep_price_html((float) $room['rate'], $isUsep, ' per head, per night', $room['office']['discount']) !!}</p>
            <span class="lp-free {{ $closed ? 'lp-free-closed' : (!$free ? 'lp-free-no' : 'lp-free-yes') }}">
              <i></i>
              {{ $closed ? 'Closed for maintenance tonight' : (!$free ? 'No beds free tonight' : $free . ' of ' . (int) $room['beds'] . ' beds free tonight') }}
            </span>
          </div>
        </a>
@endforeach
      </div>
@endforeach
    </div>
  </section>
@endif

  <!-- ==================== REFUND ====================
       Follows each office's refund switch (DB-DECISIONS 28). OFF is the
       USeP default: every new booking is final. -->
  <section class="lp-refund">
    <div class="lp-wrap">
      <div class="lp-refund-card" data-reveal>
        <div class="lp-refund-left">
          <span class="lp-refund-icon">
@if ($lpRefundOn)
            <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10h13a5 5 0 0 1 0 10h-6"/><path d="m7 6-4 4 4 4"/></svg>
@else
            <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
@endif
          </span>
          <div>
@if ($lpRefundOn && $lpRefundOff)
            <h3>Refunds depend on the office.</h3>
            <p>At {{ \Illuminate\Support\Arr::join($lpRefundOn, ', ', ' and ') }} you can ask for a refund on an approved booking that is paid and has not been held yet, from your booking history. Bookings at {{ \Illuminate\Support\Arr::join($lpRefundOff, ', ', ' and ') }} are non-refundable: check your date, room and details before you pay.</p>
@elseif ($lpRefundOn)
            <h3>Plans change &mdash; you can ask for a refund.</h3>
            <p>Approved bookings that are paid and have not been held yet are eligible. Request it from your booking history; staff make the final call and you will see the reason either way.</p>
@else
            <h3>All bookings are non-refundable.</h3>
            <p>Please check your date, room and details before you pay &mdash; once paid, a booking cannot be refunded. If USeP has to close or cancel your venue, the venue office will offer you a replacement room or a new date.</p>
@endif
          </div>
        </div>
        <a class="lp-btn" href="{{ route('customer.faq') }}{{ $lpQ }}#after">Read the FAQ</a>
      </div>
    </div>
  </section>

</main>

<!-- ==================== FOOTER ==================== -->
<footer class="lp-footer lp-grain">
  <div class="lp-glow lp-footer-glow"></div>
  <div class="lp-wrap">
    <div class="lp-footer-grid">
      <div class="lp-footer-brand">
        <img class="lp-footer-logo" src="{{ asset('logo/Logo Header 3.png') }}" alt="VENUSeP">
        <p class="lp-footer-about">Venue and hostel booking for the University of Southeastern Philippines, Tagum&ndash;Mabini Campus. Run by the campus venue office.</p>
        <span class="lp-footer-disc"><i></i>{{ $lpDiscountLabel }} off for USeP students, staff &amp; alumni</span>
      </div>
      <div>
        <h4>Book</h4>
        <div class="lp-footer-links">
@if ($lpVenueNames)
          <a href="#venue-listings">Browse venues</a>
@endif
@if ($lpShowHostel)
          <a href="#hostel-listings">Hostel beds</a>
@endif
@if ($lpCounter)
          <a href="{{ route('admin.calendar') }}">Availability calendar</a>
@else
          <a href="{{ route('customer.calendar') }}">Availability calendar</a>
          <a href="{{ route('customer.booking-history') }}">My bookings</a>
          <a href="{{ route('customer.transaction-history') }}">Transaction history</a>
@endif
        </div>
      </div>
      <div>
        <h4>Help</h4>
        <div class="lp-footer-links">
          <a href="{{ route('customer.faq') }}{{ $lpQ }}">Frequently asked questions</a>
          <a href="{{ route('customer.faq') }}{{ $lpQ }}">How to pay by GCash</a>
@if ($lpRefundOn && !$lpCounter)
          <a href="{{ route('customer.booking-history') }}">Request a refund</a>
@else
          <a href="{{ route('customer.faq') }}{{ $lpQ }}#after">Refund policy</a>
@endif
          <a href="{{ route('customer.faq') }}{{ $lpQ }}">USeP discount rules</a>
        </div>
      </div>
      <div class="lp-footer-office">
        <h4>{{ count($lpOffices) === 1 ? 'Office' : 'Offices' }}</h4>
        <div class="lp-footer-rows">
@foreach ($lpOffices as $o)
          <div class="lp-footer-row">
            <svg class="lp-i" width="15" height="15" style="color:#96807e" aria-hidden="true"><use href="#lp-i-pin"/></svg>
            <span><strong class="lp-footer-oname">{{ $o['name'] }}</strong><br>{{ $o['location'] }}<br>{{ office_hours_label($o) }}<br><a href="tel:{{ preg_replace('/[^\d+]/', '', $o['phone']) }}">{{ $o['phone'] }}</a> &middot; <a href="mailto:{{ $o['email'] }}">{{ $o['email'] }}</a></span>
          </div>
@endforeach
        </div>
      </div>
    </div>
    <div class="lp-footer-bottom">
      <span>&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</span>
      <span><a href="{{ route('customer.faq') }}{{ $lpQ }}#after">Booking &amp; cancellation policy</a></span>
    </div>
  </div>
</footer>


<!-- GSAP — motion the CSS cannot do. Two effects only (see below).
     Same CDN as the rest of the project. If it fails to load, the page
     is complete without it: numbers already show their final value. -->
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>
<script src="{{ asset_stamped('assets/js/customer/venue-booking.js') }}"></script>
</body>
</html>
