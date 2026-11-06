{{-- RECEIPT (STAFF VIEW) (was admin/receipt.php) — the same System Receipt slip customer/receipt shows; any booking, any staff member. --}}
@extends('layouts.admin')
@section('title', 'Transaction Receipt ' . $arView['number'])
@section('active', 'Transaction History')
@section('body-class', 'receipt-page')
@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/600.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/700.css" crossorigin="anonymous" />
@endpush
@push('styles')
    {{-- this page's old [0] block differs from the admin shell (customer-style tokens, 16px phone inset): kept whole --}}
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/receipt.css') }}" />

    <!-- [1] PAGE CSS — the same "paper slip" receipt as customer/receipt.php, so staff
         and customers are never shown two different drawings of one receipt -->
    
@endpush
@section('content')
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="rc-wrap">
              <div class="rc-bar">
                <a class="rc-back" href="{{ route('admin.transaction-history') }}" onclick="if (document.referrer.indexOf(location.origin) === 0 && history.length > 1) { history.back(); return false; }"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back</a>
                <div class="rc-tools">
                  <a class="rc-btn" href="{{ route('root.receipt-pdf', ['booking' => $arRef]) }}"><i class="bi bi-download" aria-hidden="true"></i>Download PDF</a>
                </div>
              </div>

              <article class="rc-paper" aria-label="Transaction Receipt {{ $arView['number'] }}">
                <div class="rc-rule"></div>
                <div class="rc-in">
                  <div class="rc-top">
                    <img src="{{ asset('assets/img/receipt-logo.png') }}" alt="VENUSeP" width="130" height="37">
                    <div class="rc-doc">
                      <h1>Transaction Receipt</h1>
                      <div>No. <b>{{ $arView['number'] }}</b></div>
                      <div>Issued {{ $arView['issued'] }}</div>
                    </div>
                  </div>
                  <div class="rc-cols">
                    <div><h2>Received from</h2><p>{!! implode('<br>', array_map('e', $arView['from'])) !!}</p></div>
                    <div><h2>Booking</h2><p>{!! implode('<br>', array_map('e', $arView['booking'])) !!}</p></div>
                  </div>
                  <table class="rc-table">
                    <thead><tr><th scope="col">Description</th><th scope="col" class="r">Amount</th></tr></thead>
                    <tbody>
                      @foreach ($arView['items'] as $arItem)
                        <tr><td>{{ $arItem['label'] }}<small>{{ $arItem['sub'] }}</small></td><td class="r">{{ $arItem['amount'] }}</td></tr>
                      @endforeach
                    </tbody>
                  </table>
                  <div class="rc-totals">
                    <div><span>Total due</span><span>{{ $arView['total'] }}</span></div>
                    <div class="rc-paid"><span>Amount paid</span><span>{{ $arView['paid'] }}</span></div>
                  </div>
                  <div class="rc-pay">
                    @foreach ($arView['paid_by'] as [$arK, $arV])
                      <div><span>{{ $arK }}</span>{{ $arV }}</div>
                    @endforeach
                  </div>
                  <p class="rc-foot">{{ $arView['slip_note'] }}</p>
                </div>
              </article>
            </div>
          </div>
        </div>
      </main>
@endsection
