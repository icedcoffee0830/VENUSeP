<!-- ==================== FOOTER — the landing page's, compact. Static: it sits
     below #app in the DOM, so it is there on every screen the script draws. -->
<footer class="bk-footer">
  <div class="bk-footer-wrap">
    <div class="bk-footer-grid">
      <div class="bk-footer-brand">
        <img class="bk-footer-logo" src="{{ asset('logo/Logo Header 3.png') }}" alt="VENUSeP">
        <p class="bk-footer-about">Venue and hostel booking for the University of Southeastern Philippines, Tagum&ndash;Mabini Campus. Run by the campus venue office.</p>
        <span class="bk-footer-disc"><i></i>{{ (int) $DISCOUNT_PERCENT }}% off for USeP students &amp; staff</span>
      </div>
      <div>
        <h4>Book</h4>
        <div class="bk-footer-links">
          <a href="{{ route('customer.venues') . $cq }}#venue-listings">Browse venues</a>
          <a href="{{ route('customer.venues') . $cq }}#hostel-listings">Hostel beds</a>
@if (counter_mode()) {{-- counter mode: staff calendar; no customer-only links (2026-10-27) --}}
          <a href="{{ route('admin.calendar') }}">Availability calendar</a>
@else
          <a href="{{ route('customer.calendar') }}">Availability calendar</a>
          <a href="{{ route('customer.booking-history') }}">My bookings</a>
@endif
        </div>
      </div>
      <div>
        <h4>Help</h4>
        <div class="bk-footer-links">
          <a href="{{ route('customer.faq') }}{{ counter_mode() ? '?counter=1' : '' }}">Frequently asked questions</a>
          <a href="{{ route('customer.faq') }}{{ counter_mode() ? '?counter=1' : '' }}#gcash">How to pay by GCash</a>
          <a href="{{ route('customer.faq') }}{{ counter_mode() ? '?counter=1' : '' }}#after">{{ $REFUNDS_ENABLED ? 'Request a refund' : 'Refund policy' }}</a>
          <a href="{{ route('customer.faq') }}{{ counter_mode() ? '?counter=1' : '' }}#discount">USeP discount rules</a>
        </div>
      </div>
    </div>
    <div class="bk-footer-bottom">
      <span>&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</span>
      <span><a href="{{ route('customer.faq') }}{{ counter_mode() ? '?counter=1' : '' }}#after">Booking &amp; cancellation policy</a><a href="{{ route('customer.faq') }}{{ counter_mode() ? '?counter=1' : '' }}">Privacy</a></span>
    </div>
  </div>
</footer>
