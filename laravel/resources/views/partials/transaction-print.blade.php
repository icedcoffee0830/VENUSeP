{{-- THE TRANSACTION HISTORY PRINTOUT, printed only: USeP's header on the first page, the
     footer on every page (assets/css/print-letterhead.css, assets/css/transaction-print.css).
     assets/js/transaction-print.js moves it to the end of <body> and fills in the rows.
     $letterhead: App\Support\Letterhead's [image, alt]. $office: the admin's office line;
     a customer's own printout has none. $printedBy: who is printing. --}}
<div class="th-print">
  <div class="pl-head"><img src="{{ asset($letterhead[0]) }}" alt="{{ $letterhead[1] }}"></div>
  <div class="pl-body">
    <div class="th-print-title">
      <h1>Transaction History</h1>
      <p>@isset($office)<span>Office: <strong>{{ $office }}</strong></span>@endisset<span data-print="filters"></span><span data-print="rows"></span></p>
      <p><span data-print="printed"></span><span>Printed by <strong>{{ $printedBy }}</strong></span></p>
    </div>
    <div data-print="table"></div>
  </div>
  <div class="pl-foot th-print-foot">
    <img src="{{ asset('assets/img/reports/print-footer-text.png') }}" alt="USeP vision: Premier research university transforming communities in the ASEAN and beyond. University of Southeastern Philippines, Iñigo St., Bo. Obrero, Davao City, Philippines 8000 · (082) 227-8192 local 255 · www.usep.edu.ph · president@usep.edu.ph">
    <img src="{{ asset('assets/img/reports/print-footer-logos.png') }}" alt="Bagong Pilipinas · Philippine Quality Award, PQA Level III Awardee · TÜV Rheinland certified, ISO 9001:2015">
  </div>
</div>
