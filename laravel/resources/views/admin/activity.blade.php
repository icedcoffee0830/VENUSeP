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
              <p>Every recorded action, newest first: sign-ins, passwords and accounts, booking changes by staff, admins and customers, office settings, who opened a customer's ID, backups, deleted rooms, covers and moves.</p>
              <div class="ac-wrap">
                <table class="ac-table">
                  <thead><tr><th>When</th><th>Who</th><th>On</th><th>What happened</th></tr></thead>
                  <tbody>
@forelse ($rows as $r)
                    <tr>
                      <td class="ac-nowrap">{{ date('M j, Y, g:i A', strtotime($r['at'])) }}</td>
                      <td>{{ $r['who'] }}@if (in_array($r['role'], ['admin', 'customer'], true)) <span class="ac-role">({{ $r['role'] }})</span>@endif</td>
                      <td class="ac-nowrap">{{ $r['what'] }}</td>
                      <td>{{ $r['detail'] }}</td>
                    </tr>
@empty
                    <tr><td colspan="4">Nothing yet.</td></tr>
@endforelse
                  </tbody>
                </table>
              </div>
@if ($pages > 1)
              <nav class="ac-pager" aria-label="Activity pages">
                <span class="ac-shown">Page {{ $page }} of {{ $pages }} · {{ number_format($total) }} actions</span>
                <span class="ac-pages">
@if ($page > 1)
                  <a class="ac-pbtn" href="{{ route('admin.activity', ['page' => $page - 1]) }}" rel="prev">Newer</a>
@else
                  <span class="ac-pbtn" aria-disabled="true">Newer</span>
@endif
@if ($page < $pages)
                  <a class="ac-pbtn" href="{{ route('admin.activity', ['page' => $page + 1]) }}" rel="next">Older</a>
@else
                  <span class="ac-pbtn" aria-disabled="true">Older</span>
@endif
                </span>
              </nav>
@endif
            </div>
          </div>
        </div>
      </main>
@endsection
