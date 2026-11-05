{{-- Reports: which office the report is about (DB-DECISIONS 28). The choices are the ones
     ReportsController allows the signed-in person; one choice = no toggle, just its name.
     Needs $rpChoices (key => [label, offices]) and $rpOffice (the shown key, null = no office). --}}
@push('styles')
    <style>
      .rp-office { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin-bottom: 14px; font-size: 13.5px; color: #1f1e1e; }
      .rp-office-tabs { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
      .rp-office-tabs strong { margin-right: 6px; }
      .rp-office-tabs a { display: inline-flex; align-items: center; min-height: 36px; padding: 6px 14px; border: 1px solid #d7d7d7; border-radius: 8px; background: #fff; color: #1f1e1e; font-weight: 600; text-decoration: none; touch-action: manipulation; transition: background-color .15s, color .15s, border-color .15s; }
      .rp-office-tabs a:hover { border-color: #1f1e1e; }
      .rp-office-tabs a:focus-visible { outline: 2px solid #1f1e1e; outline-offset: 2px; }
      .rp-office-tabs a[aria-current="page"] { background: #1f1e1e; border-color: #1f1e1e; color: #fff; }
      .rp-office-note { flex-basis: 100%; font-size: 12px; color: #6f675d; }
      .rp-office-name.has-tabs { display: none; }
      @media (prefers-reduced-motion: reduce) { .rp-office-tabs a { transition: none; } }
      @media print { .rp-office-tabs { display: none !important; } .rp-office-name.has-tabs { display: block; } }
      html.rp-print .rp-office { margin: 0 0 6px; font-size: 11.5px; }
      html.rp-print .rp-office-tabs { display: none !important; }
      html.rp-print .rp-office-name.has-tabs { display: block; }
      html.rp-print .rp-office-note { font-size: 10px; }
    </style>
@endpush
@php($rpTabs = count($rpChoices) > 1)
<div class="rp-office">
@if ($rpOffice === null)
    <p>You have no office yet. Ask an admin to assign one.</p>
@else
@if ($rpTabs)
    <nav class="rp-office-tabs" aria-label="Office">
        <strong aria-hidden="true">Office:</strong>
@foreach ($rpChoices as $rpKey => $rpChoice)
        <a href="{{ route('admin.reports', ['office' => $rpKey]) }}"@if ($rpKey === $rpOffice) aria-current="page"@endif>{{ $rpChoice['label'] }}</a>
@endforeach
    </nav>
@endif
    <p class="rp-office-name{{ $rpTabs ? ' has-tabs' : '' }}">Office: <strong>{{ $rpChoices[$rpOffice]['label'] }}</strong></p>
@if (count($rpChoices[$rpOffice]['offices']) > 1)
    <p class="rp-office-note">Customers are counted once in this combined report, so the offices' own customer totals don't add up to it.</p>
@endif
@endif
</div>
