@extends('layouts.customer')
{{-- RECEIPT (was customer/receipt.php). Receipt lines come from receipt_view() — the same ones the PDF draws. --}}
@section('title', 'System Receipt ' . $rcView['number'])
@section('active', 'Booking History')
@section('body-class', 'receipt-page')
@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/600.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/700.css" crossorigin="anonymous" />
@endpush
@push('styles')
    <style>
      @media (max-width: 767.98px) { .container-fluid { padding-inline: 16px; } }
    </style>
    </style>

    <!-- [1] PAGE CSS — the approved "Paper slip" receipt (_preview/email/receipt-view.html) -->
    <style>
      :root { --crimson: #a11626; --crimson-deep: #7d0f1e; --ink: #1d1214; --soft: #6e6a64; --line: #efe0db; --head: #faf9f7; --ease: cubic-bezier(.16,1,.3,1); }
      /* white page, like Booking History (this page is its sub-page) — not the crimson account backdrop */
      body { background: #fff !important; }
      .app-main { background: #fff !important; }
      ::selection { background: rgba(161,22,38,.16); }
      .rc-wrap { max-width: 860px; margin: 0 auto; padding-top: 22px; }
      .rc-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
      .rc-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: #4a4440; text-decoration: none; }
      .rc-back:hover { color: var(--crimson); }
      .rc-tools { display: inline-flex; gap: 8px; flex-wrap: wrap; }
      .rc-btn { display: inline-flex; align-items: center; gap: 7px; height: 40px; padding: 0 16px; border-radius: 999px; border: 1px solid #d7d7d7; background: #fff; color: var(--black); font: 600 13px Inter, system-ui, sans-serif; text-decoration: none; cursor: pointer; transition: background 220ms var(--ease), border-color 220ms var(--ease); }
      .rc-btn:hover { background: #f4f2ee; border-color: #c9c2b6; }
      .rc-btn.is-primary { background: var(--crimson); border-color: var(--crimson); color: #fff; }
      .rc-btn.is-primary:hover { background: var(--crimson-deep); }
      .rc-btn:focus-visible, .rc-back:focus-visible { outline: 2px solid var(--crimson); outline-offset: 2px; }

      .rc-strip { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 12px 14px; border-radius: 12px; margin-bottom: 14px; font-size: 13.5px; }
      .rc-strip p { margin: 0; flex: 1 1 260px; }
      .rc-strip form { margin: 0; display: inline-flex; gap: 8px; }
      .rc-strip.is-ask { background: #fff; border: 1px solid var(--border); color: #4a4440; }
      .rc-strip.is-ask strong { color: var(--ink); }
      .rc-strip.is-ok { background: #eaf6ef; border: 1px solid #cfe7d9; color: #17573a; }
      .rc-strip.is-ok i { color: #1c7a4f; font-size: 16px; }
      .rc-strip.is-warn { background: #fdf3e6; border: 1px solid #f0dcc0; color: #5b3c0c; }
      .rc-strip.is-warn i { color: #8a5a12; font-size: 16px; }

      .rc-paper { background: #fff; border: 1px solid var(--border); border-radius: 4px; overflow: hidden; color: var(--ink); }
      .rc-paper .rc-rule { height: 5px; background: var(--crimson); }
      .rc-paper .rc-in { padding: 28px clamp(18px, 5vw, 48px) 32px; }
      .rc-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
      .rc-top img { width: 130px; height: auto; display: block; }
      .rc-doc { text-align: right; }
      .rc-doc h1 { margin: 0; font-size: 20px; font-weight: 700; letter-spacing: -.01em; }
      .rc-doc div { margin-top: 4px; font-size: 13px; color: var(--soft); }
      .rc-doc b { color: var(--ink); }
      .rc-cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px 32px; margin-top: 26px; padding-top: 16px; border-top: 1px solid var(--line); }
      .rc-cols h2 { margin: 0 0 6px; font-size: 12px; font-weight: 600; color: var(--soft); }
      .rc-cols p { margin: 0; font-size: 13.5px; line-height: 1.65; overflow-wrap: anywhere; }
      .rc-table { width: 100%; border-collapse: collapse; margin-top: 24px; font-size: 13.5px; }
      .rc-table th { text-align: left; background: var(--head); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); padding: 9px 12px; font-size: 12px; font-weight: 600; color: var(--soft); }
      .rc-table th.r, .rc-table td.r { text-align: right; white-space: nowrap; }
      .rc-table td { padding: 11px 12px; border-bottom: 1px solid var(--line); vertical-align: top; font-variant-numeric: tabular-nums; }
      .rc-table td small { display: block; color: var(--soft); font-size: 12px; margin-top: 2px; }
      .rc-totals { max-width: 300px; margin: 14px 0 0 auto; font-size: 13.5px; }
      .rc-totals div { display: flex; justify-content: space-between; gap: 16px; padding: 5px 12px; font-variant-numeric: tabular-nums; }
      .rc-totals .rc-paid { border-top: 2px solid var(--ink); margin-top: 6px; padding-top: 10px; font-weight: 700; font-size: 15px; }
      .rc-pay { margin-top: 24px; font-size: 13.5px; line-height: 1.75; padding: 12px 16px; border: 1px solid var(--line); }
      .rc-pay span { display: inline-block; min-width: 130px; color: var(--soft); }
      .rc-foot { margin: 26px 0 0; padding-top: 12px; border-top: 1px solid var(--line); font-size: 12px; color: var(--soft); line-height: 1.6; }
      @media (max-width: 520px) { .rc-doc { text-align: left; } }
    </style>
  </head>
  <body class="receipt-page">
@endpush
@section('content')
        <div class="app-content">
          <div class="container-fluid">
            <div class="rc-wrap">
              <div class="rc-bar">
                <a class="rc-back" href="{{ route('customer.booking-history') }}" onclick="if (document.referrer.indexOf(location.origin) === 0 && history.length > 1) { history.back(); return false; }"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back</a>
                <div class="rc-tools">
                  <a class="rc-btn" href="{{ route('root.receipt-pdf', ['booking' => $rcRef]) }}"><i class="bi bi-download" aria-hidden="true"></i>Download PDF</a>
                  @if ($rcFlash !== 'sent')
                    <a class="rc-btn is-primary" href="{{ $rcSelf }}&amp;confirm=1"><i class="bi bi-envelope" aria-hidden="true"></i>Email me this receipt</a>
                  @endif
                </div>
              </div>

              @if ($rcConfirm && $rcMe['email'] !== '')
                <div class="rc-strip is-ask" role="group" aria-label="Email the receipt">
                  <p>Send this receipt as a PDF to <strong>{{ $rcMe['email'] }}</strong>? Wrong address? <a href="{{ route('customer.customer-profile') }}">Change it in Profile</a> first.</p>
                  <form method="post" action="{{ $rcSelf }}">
                    @csrf
                    <input type="hidden" name="action" value="email">
                    <button class="rc-btn is-primary" type="submit">Send</button>
                    <a class="rc-btn" href="{{ $rcSelf }}">Cancel</a>
                  </form>
                </div>
              @elseif ($rcFlash === 'sent')
                <div class="rc-strip is-ok" role="status"><i class="bi bi-envelope-check" aria-hidden="true"></i>Sent to {{ $rcMe['email'] }}. Check your inbox, and your spam folder if it isn&rsquo;t there in a minute.</div>
              @elseif ($rcFlash === 'recent')
                <div class="rc-strip is-ok" role="status"><i class="bi bi-envelope-check" aria-hidden="true"></i>Already sent a few minutes ago. Check your inbox, and your spam folder.</div>
              @elseif ($rcFlash === 'failed')
                <div class="rc-strip is-warn" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>We couldn&rsquo;t send it right now. Try again in a few minutes, or use Download PDF.</div>
              @endif

              <article class="rc-paper" aria-label="System Receipt {{ $rcView['number'] }}">
                <div class="rc-rule"></div>
                <div class="rc-in">
                  <div class="rc-top">
                    <img src="{{ asset('assets/img/receipt-logo.png') }}" alt="VENUSeP" width="130" height="37">
                    <div class="rc-doc">
                      <h1>System Receipt</h1>
                      <div>No. <b>{{ $rcView['number'] }}</b></div>
                      <div>Issued {{ $rcView['issued'] }}</div>
                    </div>
                  </div>
                  <div class="rc-cols">
                    <div><h2>Received from</h2><p>{!! implode('<br>', array_map('bh_e', $rcView['from'])) !!}</p></div>
                    <div><h2>Booking</h2><p>{!! implode('<br>', array_map('bh_e', $rcView['booking'])) !!}</p></div>
                  </div>
                  <table class="rc-table">
                    <thead><tr><th scope="col">Description</th><th scope="col" class="r">Amount</th></tr></thead>
                    <tbody>
                      @foreach ($rcView['items'] as $rcItem)
                        <tr><td>{{ $rcItem['label'] }}<small>{{ $rcItem['sub'] }}</small></td><td class="r">{{ $rcItem['amount'] }}</td></tr>
                      @endforeach
                    </tbody>
                  </table>
                  <div class="rc-totals">
                    <div><span>Total due</span><span>{{ $rcView['total'] }}</span></div>
                    <div class="rc-paid"><span>Amount paid</span><span>{{ $rcView['paid'] }}</span></div>
                  </div>
                  <div class="rc-pay">
                    @foreach ($rcView['paid_by'] as [$rcK, $rcV])
                      <div><span>{{ $rcK }}</span>{{ $rcV }}</div>
                    @endforeach
                  </div>
                  <p class="rc-foot">{{ $rcView['footer'] }} A refund request needs this receipt.</p>
                </div>
              </article>
            </div>
          </div>
        </div>
@endsection
