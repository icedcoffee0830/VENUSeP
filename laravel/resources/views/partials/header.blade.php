{{-- THE ONE HEADER BAR (was includes/header.php). $portal = admin | customer picks the menu, not the look. --}}
@php
  $hdName   = $portal === 'customer' ? 'Juan Miguel Dela Cruz' : 'Administrator';
  $hdRole   = '';
  $hdPhoto  = '';   /* the photo the person chose on their profile page (profile-save action=photo), else initials */
  if ($portal === 'customer') {
    if (isset($customerContact['name'])) $hdName = $customerContact['name'];   /* pages that load the shared customer record */
    if (session('customer_name') && session('account_type') === 'customer') $hdName = session('customer_name');   /* who actually logged in */
    $hdPhoto = (string) (\Illuminate\Support\Facades\DB::selectOne('SELECT photo_path FROM customers WHERE user_id = ?', [(int) session('user_id')])->photo_path ?? '');
  } else {
    /* who actually signed in (Bug 5) — the profile page's name rule: the staff
       row's full name, else the username (the seeded admin has no staff row) */
    $hdRole = session('account_type') === 'admin' ? 'Administrator' : 'Staff';
    $hdWho = \Illuminate\Support\Facades\DB::selectOne(
      'SELECT COALESCE(s.full_name, u.username, u.email) AS who, s.photo_path FROM users u LEFT JOIN staff s ON s.user_id = u.id WHERE u.id = ?',
      [(int) session('user_id')]);
    $hdName = (string) ($hdWho->who ?? $hdRole);
    $hdPhoto = (string) ($hdWho->photo_path ?? '');
  }
  $hdParts  = preg_split('/\s+/', trim($hdName));   /* initials = first + last name */
  $hdAvatar = strtoupper(mb_substr($hdParts[0], 0, 1) . (count($hdParts) > 1 ? mb_substr(end($hdParts), 0, 1) : ''));
@endphp
<link rel="stylesheet" href="{{ asset_stamped('assets/css/header.css') }}" />
@if ($portal === 'customer')
<nav class="app-header">
  <div class="hd-wrap">
    <ul class="hd-nav">
      <li><button class="hd-toggle" id="hdBurger" type="button" aria-label="Show or hide the menu" aria-controls="hdSidebar"><i class="bi bi-list"></i></button></li>
    </ul>
    <ul class="hd-nav">
      <li><a class="hd-quick{{ request()->routeIs('customer.faq') ? ' is-here' : '' }}" aria-label="FAQ" href="{{ route('customer.faq', ['in' => 'app']) }}"><i class="bi bi-question-circle"></i><span>FAQ</span></a></li>
      <li>@include('partials.notification-bell', ['nbLook' => 'admin'])</li>
      <li><span class="hd-sep" aria-hidden="true"></span></li>
      <li>@fragment('hd-user')<a class="hd-user" data-live="hd-user" href="{{ route('customer.customer-profile') }}" title="Your profile">@if ($hdPhoto !== '')<img class="hd-avatar" src="{{ asset($hdPhoto) }}" alt="" width="30" height="30">@else<span class="hd-avatar" aria-hidden="true">{{ $hdAvatar }}</span>@endif<span class="hd-username">{{ $hdName }}</span></a>@endfragment</li>
    </ul>
    @include('partials.customer-tabs')
  </div>
</nav>
@else
<nav class="app-header">
  <div class="hd-wrap">
    <ul class="hd-nav">
      <li><button class="hd-toggle" id="hdBurger" type="button" aria-label="Show or hide the menu" aria-controls="hdSidebar"><i class="bi bi-list"></i></button></li>
    </ul>
    <ul class="hd-nav">
      <li><a class="hd-counter" href="{{ route('customer.venues', ['counter' => 1]) }}" aria-label="New counter booking"><i class="bi bi-plus-lg" aria-hidden="true"></i><span>Counter booking</span></a></li>
      <li>@include('partials.notification-bell', ['nbLook' => 'admin'])</li>
      <li>@fragment('hd-user')<a class="hd-user" data-live="hd-user" href="{{ route('admin.profile') }}" title="Your account · {{ $hdRole }}">@if ($hdPhoto !== '')<img class="hd-avatar" src="{{ asset($hdPhoto) }}" alt="" width="30" height="30">@else<span class="hd-avatar" aria-hidden="true">{{ $hdAvatar }}</span>@endif<span class="hd-username">{{ $hdName }}</span></a>@endfragment</li>
    </ul>
  </div>
</nav>
@php include base_path('legacy/includes/admin-theme.php'); /* the crimson page theme for every admin page */ @endphp
@endif
<script src="{{ asset_stamped('assets/js/header.js') }}"></script>
