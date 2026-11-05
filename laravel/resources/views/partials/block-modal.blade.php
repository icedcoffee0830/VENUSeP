{{-- Blocked at an office (Aron 2026-11-05, option A): told once per session, and on a direct
     link to that office. The contact details only: the reason stays internal.
     A native <dialog>: it keeps focus inside, and Esc closes it. --}}
@php $blockModal = \App\Http\Controllers\Customer\BookingRules::blockModalNotices(request()->query('room')); @endphp
@if ($blockModal)
<style>#blockModal::backdrop { background: rgba(28, 27, 25, .55); }</style>
<dialog id="blockModal" aria-labelledby="blockModalTitle" style="width:min(440px,calc(100vw - 32px));padding:22px;border:0;border-radius:16px;background:#fff;color:#1c1b19;font-family:Inter,system-ui,sans-serif;box-shadow:0 18px 48px rgba(0,0,0,.28)">
  <h2 id="blockModalTitle" style="margin:0 0 10px;font-size:18px;font-weight:700;color:#1c1b19">Booking Not Available</h2>
  @foreach ($blockModal as $block)
    <p style="margin:0 0 12px;font-size:14px;line-height:1.55;overflow-wrap:anywhere"><strong>{{ $block['message'] }}</strong><br>{{ $block['contact'] }}</p>
  @endforeach
  <form method="dialog" style="display:flex;justify-content:flex-end;margin:6px 0 0">
    <button type="submit" style="min-width:88px;height:44px;padding:0 18px;border:0;border-radius:10px;background:#a11626;color:#fff;font:inherit;font-size:14px;font-weight:650;cursor:pointer">OK</button>
  </form>
</dialog>
<script>document.getElementById('blockModal').showModal();</script>
@endif
