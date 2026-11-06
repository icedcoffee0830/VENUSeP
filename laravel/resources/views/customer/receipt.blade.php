@extends('layouts.customer')
{{-- RECEIPT (was customer/receipt.php). Receipt lines come from receipt_view() — the same ones the PDF draws. --}}
@section('title', 'Transaction Receipt ' . $rcView['number'])
@section('active', 'Booking History')
@section('body-class', 'receipt-page')
@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/600.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/700.css" crossorigin="anonymous" />
@endpush
@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/receipt.css') }}" />

    <!-- [1] PAGE CSS — the approved "Paper slip" receipt (_preview/email/receipt-view.html) -->
    
@endpush
@section('content')
      {{-- app-main keeps the page below the fixed header and beside the sidebar --}}
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="rc-wrap">
              <div class="rc-bar">
                <a class="rc-back" href="{{ route('customer.booking-history') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to My bookings</a>
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
              @elseif ($rcFlash === 'notsent')
                <div class="rc-strip is-warn" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>Not sent: email isn&rsquo;t set up here yet. Use Download PDF to keep your receipt.</div>
              @endif

              @if ($rcView['void'])
                <div role="alert" style="max-width:720px;margin:0 auto 12px;padding:10px 14px;border-radius:8px;background:#fcecec;color:#b23a3a;font-weight:650">VOID since {{ $rcView['void'] }}: this payment was reversed.</div>
              @endif
              <article class="rc-paper" aria-label="Transaction Receipt {{ $rcView['number'] }}">
                <div class="rc-rule"></div>
                <div class="rc-in">
                  <div class="rc-top">
                    <img src="{{ asset('assets/img/receipt-logo.png') }}" alt="VENUSeP" width="130" height="37">
                    <div class="rc-doc">
                      <h1>Transaction Receipt</h1>
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
                  <p class="rc-foot">{{ $rcView['slip_note'] }} A refund request needs this receipt.</p>
                </div>
              </article>
            </div>
          </div>
        </div>
      </main>
@endsection
