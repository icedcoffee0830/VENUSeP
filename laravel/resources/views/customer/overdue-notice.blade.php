{{-- An Overdue booking blocks new online bookings at its office (2026-11-01):
     booking-submit refuses, and the booking pages say so up front.
     $overdueNotice = BookingRules::overdueNoticeForPage() (null: nothing shown).
     A block at an office (DB-DECISIONS 28) is told the same way, with the office's contact
     info and never the reason: $blockNotices = BookingRules::blockNoticesForPage() (booking pages only). --}}
@if ($overdueNotice)
<div style="padding:{{ $noticeGutter ?? '0 16px' }}">
  <div role="alert" style="max-width:1120px;margin:{{ $noticeMargin ?? '16px auto 0' }};padding:12px 16px;background:#fdf6e7;border:1px solid #efd9ab;border-radius:12px;color:#5c3d0c;font-size:14px;line-height:1.55;text-align:left;box-sizing:border-box">
    {{ $overdueNotice }}
    <a href="{{ route('customer.booking-history') }}" style="color:#8a1222;font-weight:650;text-decoration:underline;white-space:nowrap;display:inline-block;padding:12px 0;margin:-12px 0">Go to My Bookings</a>{{-- padding: a ~40px tap area on phones, same line height --}}
  </div>
</div>
@endif
@foreach ($blockNotices ?? [] as $block)
<div style="padding:{{ $noticeGutter ?? '0 16px' }}">
  <div role="alert" style="max-width:1120px;margin:{{ $noticeMargin ?? '16px auto 0' }};padding:12px 16px;background:#fdf6e7;border:1px solid #efd9ab;border-radius:12px;color:#5c3d0c;font-size:14px;line-height:1.55;text-align:left;box-sizing:border-box;overflow-wrap:anywhere">
    <strong>{{ $block['message'] }}</strong><br>{{ $block['contact'] }}
  </div>
</div>
@endforeach
