{{-- FAQ (was customer/faq.php) — public. Standalone page (its own look), two frames:
     ?in=app keeps the account shell (shared header + sidebar, FAQ highlighted, no footer);
     otherwise the browse-mode top bar + footer. Questions come from legacy faqs.php. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="darkreader-lock">
<meta name="color-scheme" content="dark">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VENUSeP | FAQ</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
@if ($fqApp)
<!-- the shared header + sidebar use Bootstrap Icons; the app-shell pages size themselves from these two variables -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/faq-app.css') }}" />
@endif
<link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/faq.css') }}" />
</head>
<body>
<div class="fq-glow" aria-hidden="true"></div>
<div class="fq-grain" aria-hidden="true"></div>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="fq-i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
  <symbol id="fq-i-back" viewBox="0 0 24 24"><path d="M19 12H5M11 18l-6-6 6-6"/></symbol>
  <symbol id="fq-i-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/></symbol>
  <symbol id="fq-i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
</svg>

@if ($fqApp)
<!-- ACCOUNT SHELL: the shared header + sidebar, like every other sidebar page -->
@include('partials.header', ['portal' => 'customer'])
@include('partials.sidebar', ['portal' => 'customer', 'active' => 'FAQ'])
<div class="app-main">
@else
<!-- STANDALONE: the shared customer nav -->
@include('partials.customer-nav', ['navMode' => 'solid', 'navHere' => 'faq'])
@endif

<main>
  <div class="fq-wrap">
    <div class="fq-head" data-reveal>
      <span class="fq-eyebrow"><svg class="fq-icon" width="13" height="13" style="color:var(--gold)"><use href="#fq-i-help"/></svg>Help</span>
      <h1>Frequently asked questions</h1>
      <p>Everything about booking a venue or a hostel bed, the USeP discount, paying, and what happens after you submit.</p>
      @if (!$fqApp)<a class="fq-back" href="{{ route('customer.venues') . $fqCounterQ }}"><svg class="fq-icon" width="15" height="15"><use href="#fq-i-back"/></svg>Back to venue booking</a>@endif
    </div>

    <div class="fq-body">
      <nav class="fq-toc" data-reveal aria-label="Sections">
        <small>Jump to</small>
        <a href="#booking">Booking a venue</a>
        <a href="#discount">The USeP discount</a>
        <a href="#hostel">Hostel beds</a>
        <a href="#gcash">Paying with GCash</a>
        <a href="#cash">Paying in cash</a>
        <a href="#after">After you book &middot; {{ $fqAnyRefunds ? 'refunds' : 'refund policy' }}</a>
      </nav>

      <div>

        <!-- ==================== BOOKING ==================== -->
        <section class="fq-section" id="booking" data-reveal>
          <h2><span>01</span>Booking a venue</h2>
{!! $fqSections['booking'] !!}
        </section>

        <!-- ==================== DISCOUNT ==================== -->
        <section class="fq-section" id="discount" data-reveal>
          <h2><span>02</span>The USeP discount</h2>
{!! $fqSections['discount'] !!}
        </section>

        <!-- ==================== HOSTEL ==================== -->
        <section class="fq-section" id="hostel" data-reveal>
          <h2><span>03</span>Hostel beds</h2>
{!! $fqSections['hostel'] !!}
        </section>

        <!-- ==================== GCASH ==================== -->
        <section class="fq-section" id="gcash" data-reveal>
          <h2><span>04</span>Paying with GCash</h2>
{!! $fqSections['gcash'] !!}
        </section>

        <!-- ==================== CASH ==================== -->
        <section class="fq-section" id="cash" data-reveal>
          <h2><span>05</span>Paying in cash</h2>
{!! $fqSections['cash'] !!}
        </section>

        <!-- ==================== AFTER / REFUNDS ==================== -->
        <section class="fq-section" id="after" data-reveal>
          <h2><span>06</span>After you book &middot; {{ $fqAnyRefunds ? 'refunds' : 'refund policy' }}</h2>
{!! $fqSections['after'] !!}
        </section>

        <div class="fq-help" data-reveal>
          <div>
            <h3>Still need help?</h3>
            <p>Reach the venue coordination office through your USeP account email, or visit the USeP Cashier &mdash; Venue Reservations Window during office hours. Your booking history shows where every request stands.</p>
          </div>
          @if (!$fqCounterQ)<a class="fq-btn" href="{{ route('customer.booking-history') }}">Open my bookings<svg class="fq-icon" width="15" height="15"><use href="#fq-i-arrow"/></svg></a>@endif
        </div>

      </div>
    </div>
  </div>
</main>
@if ($fqApp)
</div><!-- /.app-main -->
@else

<footer class="fq-footer">
  <div class="fq-wrap">
    <div class="fq-footer-grid">
      <div class="fq-footer-brand">
        <img class="fq-footer-logo" src="{{ asset('logo/Logo Header 3.png') }}" alt="VENUSeP">
        <p class="fq-footer-about">Venue and hostel booking for the University of Southeastern Philippines, Tagum&ndash;Mabini Campus. Run by the campus venue office.</p>
      </div>
      <div>
        <h4>Book</h4>
        <div class="fq-footer-links">
          <a href="{{ route('customer.venues') . $fqCounterQ }}#venue-listings">Browse venues</a>
          <a href="{{ route('customer.venues') . $fqCounterQ }}#hostel-listings">Hostel beds</a>
          @if (!$fqCounterQ)
          <a href="{{ route('customer.calendar') }}">Availability calendar</a>
          <a href="{{ route('customer.booking-history') }}">My bookings</a>
          @endif
        </div>
      </div>
      <div>
        <h4>Help</h4>
        <div class="fq-footer-links">
          <a href="#gcash">How to pay by GCash</a>
          <a href="#after">{{ $fqAnyRefunds ? 'Request a refund' : 'Refund policy' }}</a>
          <a href="#discount">USeP discount rules</a>
          @if (!$fqCounterQ)<a href="{{ route('customer.transaction-history') }}">Transaction history</a>@endif
        </div>
      </div>
    </div>
    <div class="fq-footer-bottom">
      <span>&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</span>
      <span><a href="#after">Booking &amp; cancellation policy</a></span>
    </div>
  </div>
</footer>
@endif

<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>
<script src="{{ asset_stamped('assets/js/customer/faq.js') }}"></script>
</body>
</html>
