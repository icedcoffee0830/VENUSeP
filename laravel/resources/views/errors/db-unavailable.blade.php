{{-- The one "database down" page (App\Failover\Outage::page). No database, no layout, no session: everything is inline. --}}
@php $readOnly = $readOnly ?? false; @endphp
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>VENUSeP | Temporarily unavailable</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:linear-gradient(160deg,#8f1424,#140708);color:#1f1e1e;font:15px/1.55 Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
.box{background:#fff;border-radius:18px;max-width:520px;width:100%;padding:26px}
.logo{color:#a11626;font:800 22px/1 "Segoe UI",sans-serif;letter-spacing:1px;margin:0 0 14px}
h1{font-size:23px;line-height:1.25;margin:4px 0 8px;text-wrap:balance}
p{margin:0}
.wait{display:flex;gap:10px;align-items:center;color:#6e6a64;font-size:14px;margin-top:14px}
.spin{width:18px;height:18px;border:3px solid #ecd7da;border-top-color:#a11626;border-radius:50%;animation:s 1s linear infinite;flex:none}
@keyframes s{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.spin{animation:none}}
hr{border:0;border-top:1px solid #e5e1df;margin:18px 0}
.small{font-size:13px;color:#6e6a64}
a{color:#a11626;font-weight:600}
a:focus-visible{outline:2px solid #a11626;outline-offset:2px;border-radius:3px}
</style></head><body>
<main class="box">
  <div class="logo">VENUSEP</div>
  <h1>The system is switching servers</h1>
  @if ($readOnly)
    <p>Your last change was <strong>not</strong> saved. Please wait a moment, then do it again. This page tries again by itself.</p>
  @else
    <p>The database can’t be reached right now. Nothing you saved is lost. Please wait a moment; this page tries again by itself.</p>
  @endif
  <div class="wait"><span class="spin" aria-hidden="true"></span><span id="again">Trying again in 10 seconds…</span></div>
  <hr>
  <p class="small">Super admin? <a href="{{ url('/server-emergency') }}">Sign in to switch servers</a>.</p>
</main>
<script>
(function () {
  var left = 10, el = document.getElementById('again');
  var t = setInterval(function () {
    left--;
    if (left <= 0) { clearInterval(t); el.textContent = 'Trying again…'; location.replace(location.href); return; }
    el.textContent = 'Trying again in ' + left + (left === 1 ? ' second…' : ' seconds…');
  }, 1000);
})();
</script>
</body></html>
