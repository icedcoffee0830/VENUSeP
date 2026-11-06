{{-- DOCUMENT VIEWER (was valid-id-view.php) — standalone page: the file on a dark stage + the booking beside it. Bytes come from route root.document-view. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>VENUSeP | {{ $viTitle }} · {{ $b['bookingId'] }}</title>
<link rel="icon" href="{{ asset('logo/Logo Header 3.png') }}" type="image/png" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/600.css" crossorigin="anonymous" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/700.css" crossorigin="anonymous" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
<link rel="stylesheet" href="{{ asset_stamped('assets/css/valid-id-view.css') }}" />
</head>
<body>
  <header class="vi-bar">
    <button type="button" class="vi-btn" onclick="viBack()"><i class="bi bi-arrow-left" aria-hidden="true"></i><span class="vi-hide-sm">Back</span></button>
    <img class="vi-logo" src="{{ asset('logo/Logo Header 3.png') }}" alt="VENUSeP">
    <span class="vi-sep" aria-hidden="true"></span>
    <h1>{{ $viTitle }}<small>{{ $b['bookingId'] }} · {{ $b['customerName'] }}</small></h1>
    <span class="vi-grow"></span>
    @if ($viFile)
      <a class="vi-btn vi-light" href="{{ $viSrc }}&amp;download=1"><i class="bi bi-download" aria-hidden="true"></i><span class="vi-hide-sm">Download</span></a>
    @endif
  </header>

  <main class="vi-main">
    <section class="vi-stage" id="viStage" aria-label="{{ $viTitle }}">
      @if (!$viFile)
        <div class="vi-missing"><i class="bi bi-person-vcard" aria-hidden="true"></i><strong>No ID on file</strong><br>No valid ID was recorded for this booking.</div>
      @elseif ($viIsPdf)
        <iframe src="{{ $viSrc }}" title="{{ $viTitle }}"></iframe>
      @else
        <div class="vi-tools">
          <button type="button" class="vi-tool" onclick="viRotate()" title="Rotate" aria-label="Rotate"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>
          <button type="button" class="vi-tool" onclick="viZoom()" title="Actual size / fit to screen" aria-label="Actual size or fit to screen" id="viZoomBtn"><i class="bi bi-arrows-angle-expand" aria-hidden="true"></i></button>
        </div>
        <img id="viImg" src="{{ $viSrc }}" alt="{{ $viTitle . ' for ' . $b['bookingId'] }}" onclick="viZoom()"
             onerror="this.outerHTML='<div class=&quot;vi-missing&quot;><i class=&quot;bi bi-image&quot;></i><strong>The file could not be shown</strong><br>It may have been moved. Try Download, or ask an administrator.</div>'">
      @endif
    </section>

    <aside class="vi-card">
      @if ($viFacts)
      <div class="vi-block">
        <h2>{{ $viTitle }}</h2>
        @foreach ($viFacts as [$k, $v, $tone])
          <div class="vi-row"><span>{{ $k }}</span><span>@if ($tone)<span class="vi-tag {{ $tone }}">{{ $v }}</span>@else{{ $v }}@endif</span></div>
        @endforeach
      </div>
      @endif
      <div class="vi-block">
        <h2>Booking</h2>
        <div class="vi-row"><span>Reference</span><span>{{ $b['bookingId'] }}</span></div>
        <div class="vi-row"><span>Booked by</span><span>{{ $b['customerName'] }}</span></div>
        <div class="vi-row"><span>{{ $b['type'] === 'hostel' ? 'Room' : 'Venue' }}</span><span>{{ $b['roomName'] }}, {{ $b['venueName'] }}</span></div>
        <div class="vi-row"><span>{{ $b['type'] === 'hostel' ? 'Stay' : 'Date' }}</span><span>{{ booking_date_label($b) }}</span></div>
        <div class="vi-row"><span>Total</span><span>{{ $b['amount'] }}</span></div>
        <div class="vi-row"><span>Status</span><span>{{ $viIsStaff ? $b['bookingStatusStaff'] : $b['bookingStatus'] }}</span></div>
      </div>
      <p class="vi-note"><i class="bi bi-shield-lock" aria-hidden="true"></i> Kept privately on the VENUSeP server and shown only to {{ $viIsStaff ? 'staff and the customer who uploaded it' : 'you and the venue staff' }}.</p>
    </aside>
  </main>

<script>
  /* Values from PHP for assets/js/valid-id-view.js */
  var validIdView = {
    viHome: {!! json_encode($viHome) !!},
  };
</script>
<script src="{{ asset_stamped('assets/js/valid-id-view.js') }}"></script>
</body>
</html>
