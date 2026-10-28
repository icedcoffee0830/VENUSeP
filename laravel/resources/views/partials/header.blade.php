{{-- THE ONE HEADER BAR (was includes/header.php). $portal = admin | customer picks the menu, not the look. --}}
@php
  $hdName   = $portal === 'customer' ? 'Juan Miguel Dela Cruz' : 'Administrator';
  $hdAvatar = $portal === 'customer' ? 'JM' : 'AD';
  if ($portal === 'customer') {
    if (isset($customerContact['name'])) $hdName = $customerContact['name'];   /* pages that load the shared customer record */
    if (session('customer_name') && session('account_type') === 'customer') $hdName = session('customer_name');   /* who actually logged in */
    $hdParts  = preg_split('/\s+/', trim($hdName));   /* initials = first + last name */
    $hdAvatar = strtoupper(substr($hdParts[0], 0, 1) . substr(end($hdParts), 0, 1));
  }
  legacy('demo-mode');
@endphp
<style>
  /* the ONE header — crimson to black, like the landing page's nav (both portals) */
  nav.app-header{position:fixed;top:0;right:0;left:var(--venusep-sidebar-width,235px);z-index:1030;height:58px;min-height:58px;color:#fff;display:flex;align-items:center;margin:0;padding:0;
    background:linear-gradient(100deg,#8a1222 0%,#3a0c14 52%,#120809 100%);border-bottom:1px solid rgba(255,255,255,.12);box-shadow:0 8px 24px rgba(10,4,5,.25);
    transition:left 320ms cubic-bezier(.16,1,.3,1)}
  nav.app-header .hd-wrap{width:100%;padding:0 1.15rem;display:flex;align-items:center;justify-content:space-between}
  nav.app-header .hd-nav{display:flex;align-items:center;gap:.45rem;margin:0;padding:0;list-style:none}
  nav.app-header .hd-nav li{margin:0;padding:0;list-style:none}
  nav.app-header .hd-toggle{width:40px;height:36px;display:inline-flex;align-items:center;justify-content:center;border-radius:10px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.22);color:#fff;font-size:1.15rem;cursor:pointer;transition:background 180ms ease}
  nav.app-header .hd-toggle:hover{background:rgba(255,255,255,.18)}
  nav.app-header .hd-quick{display:inline-flex;align-items:center;gap:.45rem;height:36px;padding:0 .8rem;border-radius:10px;color:#f2d0cb;font-size:.82rem;font-weight:600;text-decoration:none;border:1px solid transparent;transition:background 180ms ease,color 180ms ease}
  nav.app-header .hd-quick i{font-size:1rem;line-height:1}
  nav.app-header .hd-quick:hover{background:rgba(255,255,255,.1);color:#fff}
  nav.app-header .hd-quick.is-here{background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.18);color:#fff}
  nav.app-header .hd-sep{width:1px;height:22px;background:rgba(255,255,255,.18);margin:0 .35rem}
  nav.app-header .hd-user{display:inline-flex;align-items:center;gap:.55rem;padding:.3rem .85rem .3rem .3rem;border-radius:999px;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.1);color:#fff;font-weight:700;font-size:.82rem;text-decoration:none;transition:background 180ms ease}
  nav.app-header .hd-user:hover{background:rgba(255,255,255,.18);color:#fff}
  nav.app-header .hd-avatar{width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#fff;color:#1d1214;font-size:12px;font-weight:700}
  /* the page behind the cards: the same crimson-to-black as the sidebar and
     header, so the whole viewport reads as one surface. Cards stay white —
     each page keeps its own card styles; this only paints what is behind them.
     (Included after the page's own <style>, so these win at equal specificity.) */
  body{background:linear-gradient(162deg,#7d1120 0%,#3c0c14 40%,#120809 100%) fixed}
  .app-main{background:transparent}
  /* the burger's effect: collapse the sidebar and let the page reclaim the width */
  body.sb-closed{--venusep-sidebar-width:0px}
  body.sb-closed aside.sidebar{transform:translateX(-100%)!important}
  .app-main{transition:margin-left 320ms cubic-bezier(.16,1,.3,1)}
  @media (max-width:767.98px){
    nav.app-header{left:0;height:56px;min-height:56px}
    nav.app-header .hd-username{display:none}
    nav.app-header .hd-quick span{display:none}
    nav.app-header .hd-quick{padding:0 .55rem}
    nav.app-header .hd-wrap{flex-direction:row-reverse;justify-content:flex-start;gap:.6rem}      /* phone: everything on the right — burger at the edge, chip + quick links beside it */
    body.sb-open aside.sidebar{transform:none!important}
    /* the page dims behind the open sidebar with a fading layer (opacity animates on the GPU);
       the old way — a 100vw box-shadow — repainted the whole screen every frame and stuttered on phones */
    body::after{content:"";position:fixed;inset:0;z-index:1034;background:rgba(10,4,5,.5);opacity:0;pointer-events:none;transition:opacity 320ms cubic-bezier(.16,1,.3,1)}
    body.sb-open::after{opacity:1;pointer-events:auto}
  }
</style>
{{-- DEMO MODE banner — deliberately the first thing on every admin and customer page. --}}
{!! demo_banner_html() !!}
@if ($portal === 'customer')
<nav class="app-header">
  <div class="hd-wrap">
    <ul class="hd-nav">
      <li><button class="hd-toggle" id="hdBurger" type="button" aria-label="Show or hide the menu" aria-controls="hdSidebar"><i class="bi bi-list"></i></button></li>
    </ul>
    <ul class="hd-nav">
      <li><a class="hd-quick{{ request()->routeIs('customer.faq') ? ' is-here' : '' }}" href="{{ route('customer.faq', ['in' => 'app']) }}"><i class="bi bi-question-circle"></i><span>FAQ</span></a></li>
      <li>@include('partials.notification-bell', ['nbLook' => 'admin'])</li>
      <li><span class="hd-sep" aria-hidden="true"></span></li>
      <li><a class="hd-user" href="{{ route('customer.customer-profile') }}" title="Your profile"><span class="hd-avatar" aria-hidden="true">{{ $hdAvatar }}</span><span class="hd-username">{{ $hdName }}</span></a></li>
    </ul>
  </div>
</nav>
@else
<nav class="app-header">
  <div class="hd-wrap">
    <ul class="hd-nav">
      <li><button class="hd-toggle" id="hdBurger" type="button" aria-label="Show or hide the menu" aria-controls="hdSidebar"><i class="bi bi-list"></i></button></li>
    </ul>
    <ul class="hd-nav">
      <li>@include('partials.notification-bell', ['nbLook' => 'admin'])</li>
      <li><a class="hd-user" href="{{ route('admin.profile') }}" title="Your account"><span class="hd-avatar" aria-hidden="true">{{ $hdAvatar }}</span><span class="hd-username">{{ $hdName }}</span></a></li>
    </ul>
  </div>
</nav>
@php include base_path('legacy/includes/admin-theme.php'); /* the crimson page theme for every admin page */ @endphp
@endif
<script>
  /* The burger. Desktop: collapse/expand the sidebar and remember the choice
     for the next page. Phone: the sidebar is hidden by default and the burger
     slides it in over the page; tapping outside closes it. */
  (function () {
    var KEY = 'venusep_sidebar', body = document.body;
    var phone = window.matchMedia('(max-width: 767.98px)');
    try { if (localStorage.getItem(KEY) === 'closed') body.classList.add('sb-closed'); } catch (e) {}
    document.getElementById('hdBurger').addEventListener('click', function () {
      if (phone.matches) { body.classList.toggle('sb-open'); return; }
      var closed = body.classList.toggle('sb-closed');
      try { localStorage.setItem(KEY, closed ? 'closed' : 'open'); } catch (e) {}
    });
    document.addEventListener('click', function (e) {
      if (phone.matches && body.classList.contains('sb-open') && !e.target.closest('aside.sidebar, #hdBurger')) body.classList.remove('sb-open');
    });
  })();
</script>
