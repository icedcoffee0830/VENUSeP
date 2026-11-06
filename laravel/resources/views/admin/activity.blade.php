@extends('layouts.admin')
@section('title', 'Activity')
@section('active', 'Activity')

@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/activity.css') }}" />
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
