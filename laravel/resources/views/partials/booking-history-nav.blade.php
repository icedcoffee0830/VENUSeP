<script>
  /* Values from PHP for assets/js/booking-history-nav.js */
  var bookingHistoryNav = {
    venuesUrl: @json(route('customer.venues') . $cq),
  };
</script>
<script src="{{ asset_stamped('assets/js/booking-history-nav.js') }}"></script>
