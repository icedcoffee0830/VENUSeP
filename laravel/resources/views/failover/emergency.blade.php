{{-- The emergency way in (ServerController::emergency): the database can't be reached or is locked,
     so this page reads nothing from it. Signed out: the super admin's sign-in (the locked copy).
     Signed in: the Server panel and nothing else. --}}
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>VENUSeP | Switch the database server</title>
<link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/server.css') }}" />
<link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}" />
<script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
<style>
*{box-sizing:border-box}
body{margin:0;color:#1f1e1e;font:15px/1.55 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;background:#f7f5f4}
.em{min-height:100vh;background:linear-gradient(160deg,#8f1424,#140708);display:grid;place-items:center;padding:20px}
.em .box{background:#fff;border-radius:18px;max-width:520px;width:100%;padding:26px}
.logo{color:#a11626;font:800 22px/1 "Segoe UI",sans-serif;letter-spacing:1px;margin:0 0 14px}
.em h1{font-size:23px;line-height:1.25;margin:4px 0 8px;text-wrap:balance}
.em label{display:block;font-weight:600;font-size:14px;margin:12px 0 4px}
.em input{width:100%;border:1.5px solid #e5e1df;border-radius:9px;padding:9px 12px;font:inherit;min-height:42px}
.em input:focus-visible{outline:2px solid #a11626;outline-offset:1px}
.em hr{border:0;border-top:1px solid #e5e1df;margin:18px 0}
.small{font-size:13px;color:#6e6a64}
.err{background:#fbecec;border:1px solid #efc9c9;color:#7a2424;border-radius:10px;padding:9px 12px;margin:12px 0 0;font-size:14px}
.go{margin-top:16px;background:#a11626;color:#fff;border:0;border-radius:9px;min-height:42px;padding:0 18px;font:700 15px Inter,system-ui,sans-serif;cursor:pointer}
.go:focus-visible{outline:2px solid #1f1e1e;outline-offset:2px}
.bar{background:linear-gradient(90deg,#7d0f1e,#2a0a0e);color:#fff;padding:12px 20px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
.bar strong{letter-spacing:1px}
.wrap{max-width:1020px;margin:0 auto;padding:24px 16px 60px}
</style></head><body>
@if (!$emSignedIn)
<main class="em"><div class="box">
  <div class="logo">VENUSEP</div>
  <h1>Can’t reach the database server</h1>
  <p class="small">The main database ({{ $emMain }}) is not answering, or it is locked after a restart. Sign in as the super admin to switch to a copy or unlock it.</p>
  @if (!$emHasCopy)
    <p class="err" role="alert">This laptop has no emergency sign-in yet. Is the 1-minute task running (SETUP.md)? Use another laptop, or wait for the database.</p>
  @endif
  <form method="post" action="{{ route('failover.emergency') }}" autocomplete="on">
    @csrf
    <label for="emEmail">Email</label>
    <input id="emEmail" name="email" type="email" autocomplete="username" spellcheck="false" value="{{ $emEmail }}" required>
    <label for="emPassword">Password</label>
    <input id="emPassword" name="password" type="password" autocomplete="current-password" required>
    <label for="emCode">Code from your authenticator app</label>
    <input id="emCode" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="6 digits…" spellcheck="false" required>
    @if ($emError)<p class="err" role="alert">{{ $emError }}</p>@endif
    <button type="submit" class="go">Sign in</button>
  </form>
  <hr>
  <p class="small"><strong>After signing in:</strong> the same list as the Server page (Find, Test, Switch). Recovery codes don’t work here while the database is down.</p>
</div></main>
@else
<div class="bar"><strong>VENUSEP</strong><span class="small" style="color:#f3d9dc">Emergency server panel · signed in for {{ \App\Failover\SuperAdminCopy::MINUTES }} minutes</span></div>
<main class="wrap">
  @include('failover.panel')
</main>
<script>
  var serverPage = { csrf: @json($svCsrf), actionUrl: @json(route('failover.emergency-action')) };
</script>
<script src="{{ asset_stamped('assets/js/vendor/qrcode-generator-1.4.4.js') }}"></script>
<script src="{{ asset_stamped('assets/js/admin/server.js') }}"></script>
@endif
</body></html>
