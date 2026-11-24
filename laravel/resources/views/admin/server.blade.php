{{-- Server (failover): the super admin only. Every button posts to admin.server-action, which re-checks who is asking. --}}
@extends('layouts.admin')
@section('title', 'Server')
@section('active', 'Server')

@push('head')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}" />
    <script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/server.css') }}" />
@endpush

@section('content')
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            @include('failover.panel')
          </div>
        </div>
      </main>
@endsection

@push('scripts')
    <script>
  /* Values from PHP for assets/js/admin/server.js */
  var serverPage = { csrf: @json($svCsrf), actionUrl: @json(route('admin.server-action')) };
</script>
<script src="{{ asset_stamped('assets/js/vendor/qrcode-generator-1.4.4.js') }}"></script>
<script src="{{ asset_stamped('assets/js/admin/server.js') }}"></script>
@endpush
