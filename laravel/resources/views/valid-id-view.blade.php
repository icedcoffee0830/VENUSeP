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
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; min-height: 100%; }
  body { background: #f4f2ef; color: #1c1b19; font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; }
  /* top bar — the app's crimson-to-black */
  .vi-bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 14px; padding: 0 20px; height: 60px;
            background: linear-gradient(100deg, #8a1222 0%, #3a0c14 52%, #120809 100%); color: #fff; box-shadow: 0 6px 20px rgba(10,4,5,.2); }
  .vi-bar .vi-logo { height: 22px; display: block; filter: invert(1); }   /* white, as in the other top bars */
  .vi-sep { width: 1px; height: 24px; background: rgba(255,255,255,.22); }
  .vi-bar h1 { margin: 0; font-size: 15px; font-weight: 700; line-height: 1.2; }
  .vi-bar h1 small { display: block; font-size: 12px; font-weight: 500; color: #f2d0cb; margin-top: 1px; }
  .vi-bar .vi-grow { flex: 1; }
  .vi-btn { display: inline-flex; align-items: center; gap: 7px; height: 36px; padding: 0 14px; border-radius: 10px; border: 1px solid rgba(255,255,255,.25);
            background: rgba(255,255,255,.1); color: #fff; font: 600 13px Inter, system-ui, sans-serif; text-decoration: none; cursor: pointer; white-space: nowrap; }
  .vi-btn:hover { background: rgba(255,255,255,.2); }
  .vi-btn.vi-light { background: #fff; color: #1d1214; border-color: #fff; }
  .vi-btn.vi-light:hover { background: #f2ece9; }
  /* page */
  .vi-main { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 18px; padding: 18px 20px 28px; max-width: 1400px; margin: 0 auto; }
  .vi-stage { position: relative; background: #1d1a19; border-radius: 16px; min-height: calc(100vh - 106px); overflow: auto; display: flex; align-items: center; justify-content: center; padding: 22px; }
  .vi-stage img { display: block; max-width: 100%; max-height: calc(100vh - 150px); border-radius: 6px; box-shadow: 0 18px 50px rgba(0,0,0,.45); transition: transform .25s ease; cursor: zoom-in; background: #fff; }
  .vi-stage.is-actual { align-items: flex-start; justify-content: flex-start; }
  .vi-stage.is-actual img { max-width: none; max-height: none; cursor: zoom-out; }
  .vi-stage iframe { width: 100%; height: calc(100vh - 150px); border: 0; border-radius: 8px; background: #fff; }
  .vi-tools { position: absolute; top: 12px; right: 12px; display: flex; gap: 6px; z-index: 2; }
  .vi-tool { width: 38px; height: 38px; border-radius: 10px; border: 1px solid rgba(255,255,255,.18); background: rgba(20,18,17,.7); color: #fff; font-size: 16px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
  .vi-tool:hover { background: rgba(60,55,52,.85); }
  .vi-missing { color: #d9d3cc; text-align: center; font-size: 14px; line-height: 1.6; max-width: 340px; }
  .vi-missing i { display: block; font-size: 34px; margin-bottom: 10px; color: #8a857d; }
  /* side card */
  .vi-card { background: #fff; border: 1px solid #ebe7e2; border-radius: 16px; padding: 18px; align-self: start; position: sticky; top: 78px; }
  .vi-card h2 { margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #8a857d; }
  .vi-card h2 + .vi-row { border-top: 0; }
  .vi-row { display: flex; justify-content: space-between; gap: 14px; padding: 9px 0; border-top: 1px solid #f2efeb; font-size: 13px; }
  .vi-row span:first-child { color: #8a857d; flex: none; }
  .vi-row span:last-child { font-weight: 600; text-align: right; overflow-wrap: anywhere; }
  .vi-tag { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 650; }
  .vi-tag.ok { background: #e8f2ec; color: #1c7a4f; } .vi-tag.warn { background: #fdf3e6; color: #8a5a12; } .vi-tag.bad { background: #fcecec; color: #b23a3a; }
  .vi-block + .vi-block { margin-top: 18px; padding-top: 16px; border-top: 1px solid #ebe7e2; }
  .vi-note { margin: 14px 0 0; font-size: 12px; color: #8a857d; line-height: 1.55; }
  @media (max-width: 860px) {
    .vi-main { grid-template-columns: 1fr; padding: 12px 12px 22px; }
    .vi-stage { min-height: 60vh; }
    .vi-stage img { max-height: 70vh; }
    .vi-card { position: static; }
    .vi-bar { padding: 0 12px; gap: 10px; }
    .vi-bar .vi-logo, .vi-sep, .vi-hide-sm { display: none; }
  }
</style>
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
  /* Back to wherever this was opened from — it opens in the same tab now — or,
     opened directly, to the booking's own page. */
  function viBack() {
    var cameFromHere = document.referrer && document.referrer.indexOf(location.origin) === 0;
    if (cameFromHere && history.length > 1) { history.back(); return; }
    location.href = {!! json_encode($viHome) !!};
  }
  var viTurn = 0;
  function viRotate() {
    var img = document.getElementById('viImg');
    if (!img) return;
    viTurn = (viTurn + 90) % 360;
    img.style.transform = 'rotate(' + viTurn + 'deg)';
  }
  function viZoom() {
    var stage = document.getElementById('viStage');
    var on = stage.classList.toggle('is-actual');
    var btn = document.getElementById('viZoomBtn');
    if (btn) btn.innerHTML = '<i class="bi ' + (on ? 'bi-arrows-angle-contract' : 'bi-arrows-angle-expand') + '" aria-hidden="true"></i>';
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') viBack(); });
</script>
</body>
</html>
