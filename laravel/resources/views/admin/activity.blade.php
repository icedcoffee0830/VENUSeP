@extends('layouts.admin')
@section('title', 'Activity')
@section('active', 'Activity')

@push('styles')
    <style>
      .ac-page { max-width: 1100px; margin: 0 auto; padding: 1.25rem 0 3rem; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #1f1e1e; }
      .ac-page h1 { font-size: 1.5rem; font-weight: 700; margin: 0 0 .25rem; }
      .ac-page > p { color: #606a75; font-size: .9rem; margin: 0 0 1.25rem; }
      .ac-wrap { overflow-x: auto; background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; }
      .ac-table { width: 100%; border-collapse: collapse; font-size: .84rem; min-width: 680px; }
      .ac-table th { text-align: left; font-size: .74rem; font-weight: 650; color: #606a75; padding: .7rem .9rem; border-bottom: 1px solid #e5e5e5; white-space: nowrap; }
      .ac-table td { padding: .6rem .9rem; border-top: 1px solid #f0efec; vertical-align: top; line-height: 1.45; }
      .ac-nowrap { white-space: nowrap; }
    </style>
@endpush

@section('content')
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="ac-page">
              <h1>Activity</h1>
              <p>The latest 300 actions by staff and admins: booking changes, office settings, who opened a customer's ID, deleted rooms, covers and moves.</p>
              <div class="ac-wrap">
                <table class="ac-table">
                  <thead><tr><th>When</th><th>Who</th><th>On</th><th>What happened</th></tr></thead>
                  <tbody>
@forelse ($rows as $r)
                    <tr>
                      <td class="ac-nowrap">{{ date('M j, Y, g:i A', strtotime($r['at'])) }}</td>
                      <td>{{ $r['who'] }}@if ($r['role'] === 'admin') <span style="color:#606a75">(admin)</span>@endif</td>
                      <td class="ac-nowrap">{{ $r['what'] }}</td>
                      <td>{{ $r['detail'] }}</td>
                    </tr>
@empty
                    <tr><td colspan="4">Nothing yet.</td></tr>
@endforelse
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </main>
@endsection
