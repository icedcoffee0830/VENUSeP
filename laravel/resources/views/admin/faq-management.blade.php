@extends('layouts.admin')
@section('title', 'FAQ Management')
@section('active', 'FAQ Management')

@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/faq-management.css') }}" />

    <!-- [1] PAGE CSS -->
    
@endpush

@section('content')
      <!-- [4] PAGE CONTENT -->
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="fq-header">
              <div class="fq-heading">
                <h1>FAQ Management</h1>
                <p>Every question customers read on the FAQ page, section by section. <strong>Built-in</strong> questions ship with the system — edit them, hide them, or restore the original wording. Questions you <strong>add</strong> can also be deleted.</p>
              </div>
            </div>
          </div>
        </div>

        <div class="app-content">
          <div class="container-fluid">
            @if ($fqNote)
              <div class="fq-flash {!! $fqNote[0] !!}" role="status"><i class="bi {!! $fqNote[0] === 'ok' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' !!}" aria-hidden="true"></i>{{ $fqNote[1] }}</div>
            @endif

            @if (!$fqDbOk)
              <div class="fq-off">The database cannot be reached, so the FAQ list cannot be read or changed right now.</div>
            @else

            <!-- PREVIEW SWITCH: which refund-policy state to show the list for -->
            <section class="fq-card" aria-label="Preview">
              <div class="fq-preview">
                <div class="fq-preview-label">Show the FAQ as customers see it when refunds are<small>Some answers change with each office's refund switch (payment timing does too). This preview changes nothing. Live now: @foreach ($fqSwitches as $name => $on){{ $name }} <strong>{{ $on ? 'ON' : 'OFF' }}</strong>{{ $loop->last ? '.' : ' · ' }}@endforeach</small></div>
                <div class="fq-seg" role="group" aria-label="Preview refund policy">
                  <button type="button" data-preview="refunds_on"{!! $fqLive === 'refunds_on' ? ' class="active"' : '' !!}>Refunds ON{!! $fqLive === 'refunds_on' ? '<span class="fq-live">· live</span>' : '' !!}</button>
                  <button type="button" data-preview="refunds_off"{!! $fqLive === 'refunds_off' ? ' class="active"' : '' !!}>Refunds OFF{!! $fqLive === 'refunds_off' ? '<span class="fq-live">· live</span>' : '' !!}</button>
                  <button type="button" data-preview="all"{!! $fqLive === 'all' ? ' class="active"' : '' !!}>Everything</button>
                </div>
              </div>
            </section>

            <!-- ADD -->
            <section class="fq-card" aria-labelledby="fqAddTitle">
              <div class="fq-card-head"><h2 id="fqAddTitle">Add a question @fragment('fq-counts')<small data-live="fq-counts">{!! $fqTotal !!} in total · {!! $fqAdded !!} added{!! $fqChanged ? ' · ' . $fqChanged . ' built-in edited' : '' !!}{!! $fqHidden ? ' · ' . $fqHidden . ' hidden' : '' !!}</small>@endfragment</h2></div>
              <form class="fq-form" method="post" action="{{ route('admin.faq-save') }}">
                @csrf
                <input type="hidden" name="action" value="add" />
                <div>
                  <label for="fqSection">Section</label>
                  <select class="fq-select" id="fqSection" name="section" required>
                    @foreach ($fqSections as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                  </select>
                </div>
                <div>
                  <label for="fqPolicy">Shown when</label>
                  <select class="fq-select" id="fqPolicy" name="policy">
                    @foreach ($fqPolicies as $k => $l)<option value="{!! $k !!}">{!! $l !!}</option>@endforeach
                  </select>
                </div>
                <div>
                  <label for="fqQuestion">Question</label>
                  <input class="fq-input" id="fqQuestion" name="question" maxlength="255" required placeholder="e.g. Can I book for a whole week?" />
                </div>
                <div class="fq-full">
                  <label for="fqAnswer">Answer</label>
                  <div class="fq-toolbar" role="toolbar" aria-label="Formatting">
                    <button type="button" class="fq-tb" data-fmt="bold" title="Bold"><i class="bi bi-type-bold" aria-hidden="true"></i>Bold</button>
                    <button type="button" class="fq-tb" data-fmt="list" title="Bulleted list"><i class="bi bi-list-ul" aria-hidden="true"></i>List</button>
                    <span class="fq-tb-menu"><button type="button" class="fq-tb" data-menu title="Link to a page on this site"><i class="bi bi-link-45deg" aria-hidden="true"></i>Link to a page <i class="bi bi-chevron-down fq-tb-caret" aria-hidden="true"></i></button>
                      <div class="fq-tb-list">
                        <button type="button" data-link="venusep_venue_booking.php">Venues &amp; rooms (home)</button>
                        <button type="button" data-link="../customer/booking-history.php">Booking history</button>
                        <button type="button" data-link="transaction-history.php">Transaction history</button>
                        <button type="button" data-link="customer-profile.php">Profile</button>
                        <button type="button" data-link="faq.php#gcash">FAQ &middot; paying with GCash</button>
                        <button type="button" data-link="faq.php#after">FAQ &middot; after you book</button>
                      </div></span>
                    <span class="fq-tb-menu"><button type="button" class="fq-tb" data-menu title="Insert a value that updates itself"><i class="bi bi-123" aria-hidden="true"></i>Insert a current value <i class="bi bi-chevron-down fq-tb-caret" aria-hidden="true"></i></button>
                      <div class="fq-tb-list">
                        <button type="button" data-value="{discount}">USeP discount<small>{{ (int) $DISCOUNT_PERCENT }}% &mdash; updates by itself</small></button>
                        <button type="button" data-value="{grace_days}">Days to pay after the event<small>{{ (int) $POSTPAY_GRACE_DAYS }} days &mdash; updates by itself</small></button>
                        <button type="button" data-value="{rate_communal}">Hostel rate &middot; communal CR<small>{!! $fqValues['{rate_communal}'] !!} per head, per night</small></button>
                        <button type="button" data-value="{rate_private}">Hostel rate &middot; private CR<small>{!! $fqValues['{rate_private}'] !!} per head, per night</small></button>
                        <button type="button" data-value="{cr_communal}">Room label &middot; communal CR<small>{{ $HOSTEL_CR_LABEL['communal'] }}</small></button>
                        <button type="button" data-value="{cr_private}">Room label &middot; private CR<small>{{ $HOSTEL_CR_LABEL['private'] }}</small></button>
                      </div></span>
                  </div>
                  <div class="fq-editor" id="fqAnswerEditor" contenteditable="true" data-placeholder="Type the answer here. Press Enter for a new paragraph." aria-label="Answer"></div>
                  <textarea class="fq-textarea" id="fqAnswer" name="answer" maxlength="4000" hidden></textarea>
                </div>
                <div class="fq-editor-err">Please type an answer first.</div>
                <div class="fq-help"><i class="bi bi-info-circle" aria-hidden="true"></i> Just type normally &mdash; press Enter twice for a new paragraph. The buttons above the box add bold text, a list, a link to a page, or one of the live values (the discount, the pay-by days, the hostel rates) &mdash; shown as a small chip that updates by itself.</div>
                <div class="fq-actions">
                  <button class="fq-btn fq-btn-primary" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i>Add question</button>
                  <span class="fq-hint">Goes to the end of its section. Use the arrows to move it.</span>
                </div>
              </form>
            </section>

            <!-- ONE CARD PER SECTION -->
            @fragment('fq-list')
            <div data-live="fq-list">
            @php $n = 0; @endphp
            @foreach ($fqSections as $key => $label) @php $n++; $rows = isset($fqRows[$key]) ? $fqRows[$key] : []; @endphp
            <section class="fq-card" id="{{ $key }}" aria-labelledby="fqSec{!! $n !!}">
              <div class="fq-card-head">
                <h2 id="fqSec{!! $n !!}"><span class="fq-num">{!! str_pad($n, 2, '0', STR_PAD_LEFT) !!}</span>{{ $label }} <small>{!! count($rows) !!} question{!! count($rows) === 1 ? '' : 's' !!}</small></h2>
              </div>
              @if (!$rows)<div class="fq-empty">Nothing in this section yet.</div>@endif
              @foreach ($rows as $i => $r)
              <div class="fq-row{!! $r['is_active'] ? '' : ' is-hidden' !!}" id="faq-{{ (int) $r['id'] }}" data-policy="{!! $r['policy'] !!}" data-question="{{ $r['question'] }}">
                <div>
                  <div class="fq-q">{!! faq_question_html($r['question']) !!}
                    @if ($r['is_builtin'])<span class="fq-badge builtin"><i class="bi bi-box-seam" aria-hidden="true"></i>Built-in</span>@else<span class="fq-badge added"><i class="bi bi-plus-circle" aria-hidden="true"></i>Added</span>@endif
                    @if ($r['policy'] === 'refunds_on')<span class="fq-badge on">Refunds ON only</span>@elseif ($r['policy'] === 'refunds_off')<span class="fq-badge off">Refunds OFF only</span>@endif
                    @if ($r['is_changed'])<span class="fq-badge changed">Edited from original</span>@endif
                    @if (!$r['is_active'])<span class="fq-badge">Hidden</span>@endif
                  </div>
                  <div class="fq-a">{!! $fqAnswer($r['answer']) !!}</div>
                  <div class="fq-meta">Last edited {{ date('M j, Y · g:i A', strtotime($r['updated_at'])) }}</div>
                </div>
                <div class="fq-tools">
                  <form method="post" action="{{ route('admin.faq-save') }}">@csrf<input type="hidden" name="action" value="move" /><input type="hidden" name="id" value="{{ (int) $r['id'] }}" /><input type="hidden" name="dir" value="up" /><button class="fq-btn fq-btn-sm" type="submit" title="Move up" aria-label="Move up"{!! $i === 0 ? ' disabled' : '' !!}><i class="bi bi-arrow-up" aria-hidden="true"></i></button></form>
                  <form method="post" action="{{ route('admin.faq-save') }}">@csrf<input type="hidden" name="action" value="move" /><input type="hidden" name="id" value="{{ (int) $r['id'] }}" /><input type="hidden" name="dir" value="down" /><button class="fq-btn fq-btn-sm" type="submit" title="Move down" aria-label="Move down"{!! $i === count($rows) - 1 ? ' disabled' : '' !!}><i class="bi bi-arrow-down" aria-hidden="true"></i></button></form>
                  <button class="fq-btn fq-btn-sm" type="button" data-edit="faq-{{ (int) $r['id'] }}"><i class="bi bi-pencil" aria-hidden="true"></i>Edit</button>
                  <form method="post" action="{{ route('admin.faq-save') }}">@csrf<input type="hidden" name="action" value="toggle" /><input type="hidden" name="id" value="{{ (int) $r['id'] }}" /><button class="fq-btn fq-btn-sm" type="submit"><i class="bi {!! $r['is_active'] ? 'bi-eye-slash' : 'bi-eye' !!}" aria-hidden="true"></i>{!! $r['is_active'] ? 'Hide' : 'Show' !!}</button></form>
                  @if ($r['is_builtin'])
                    <form method="post" action="{{ route('admin.faq-save') }}">@csrf<input type="hidden" name="action" value="restore" /><input type="hidden" name="id" value="{{ (int) $r['id'] }}" /><button class="fq-btn fq-btn-sm" type="submit" title="Back to the shipped wording"{!! $r['is_changed'] ? '' : ' disabled' !!}><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Restore original</button></form>
                  @else
                    <button class="fq-btn fq-btn-sm fq-btn-danger" type="button" data-delete="{{ (int) $r['id'] }}"><i class="bi bi-trash" aria-hidden="true"></i>Delete</button>
                  @endif
                </div>
                <!-- inline edit form (opened by the Edit button) -->
                <form class="fq-edit fq-form" method="post" action="{{ route('admin.faq-save') }}">
                  @csrf
                  <input type="hidden" name="action" value="edit" />
                  <input type="hidden" name="id" value="{{ (int) $r['id'] }}" />
                  <div>
                    <label>Section</label>
                    <select class="fq-select" name="section" required>
                      @foreach ($fqSections as $k2 => $l2)<option value="{{ $k2 }}"{!! $k2 === $r['section_key'] ? ' selected' : '' !!}>{{ $l2 }}</option>@endforeach
                    </select>
                  </div>
                  <div>
                    <label>Shown when</label>
                    <select class="fq-select" name="policy">
                      @foreach ($fqPolicies as $k2 => $l2)<option value="{!! $k2 !!}"{!! $k2 === $r['policy'] ? ' selected' : '' !!}>{!! $l2 !!}</option>@endforeach
                    </select>
                  </div>
                  <div>
                    <label>Question</label>
                    <input class="fq-input" name="question" maxlength="255" required value="{{ $r['question'] }}" />
                  </div>
                  <div class="fq-full">
                    <label>Answer</label>
                    <div class="fq-toolbar" role="toolbar" aria-label="Formatting">
                      <button type="button" class="fq-tb" data-fmt="bold" title="Bold"><i class="bi bi-type-bold" aria-hidden="true"></i>Bold</button>
                      <button type="button" class="fq-tb" data-fmt="list" title="Bulleted list"><i class="bi bi-list-ul" aria-hidden="true"></i>List</button>
                      <span class="fq-tb-menu"><button type="button" class="fq-tb" data-menu title="Link to a page on this site"><i class="bi bi-link-45deg" aria-hidden="true"></i>Link to a page <i class="bi bi-chevron-down fq-tb-caret" aria-hidden="true"></i></button>
                        <div class="fq-tb-list">
                          <button type="button" data-link="venusep_venue_booking.php">Venues &amp; rooms (home)</button>
                          <button type="button" data-link="../customer/booking-history.php">Booking history</button>
                          <button type="button" data-link="transaction-history.php">Transaction history</button>
                          <button type="button" data-link="customer-profile.php">Profile</button>
                          <button type="button" data-link="faq.php#gcash">FAQ &middot; paying with GCash</button>
                          <button type="button" data-link="faq.php#after">FAQ &middot; after you book</button>
                        </div></span>
                      <span class="fq-tb-menu"><button type="button" class="fq-tb" data-menu title="Insert a value that updates itself"><i class="bi bi-123" aria-hidden="true"></i>Insert a current value <i class="bi bi-chevron-down fq-tb-caret" aria-hidden="true"></i></button>
                        <div class="fq-tb-list">
                          <button type="button" data-value="{discount}">USeP discount<small>{{ (int) $DISCOUNT_PERCENT }}%</small></button>
                          <button type="button" data-value="{grace_days}">Days to pay after the event<small>{{ (int) $POSTPAY_GRACE_DAYS }} days</small></button>
                          <button type="button" data-value="{rate_communal}">Hostel rate &middot; communal CR<small>{!! $fqValues['{rate_communal}'] !!} per head, per night</small></button>
                          <button type="button" data-value="{rate_private}">Hostel rate &middot; private CR<small>{!! $fqValues['{rate_private}'] !!} per head, per night</small></button>
                          <button type="button" data-value="{cr_communal}">Room label &middot; communal CR<small>{{ $HOSTEL_CR_LABEL['communal'] }}</small></button>
                          <button type="button" data-value="{cr_private}">Room label &middot; private CR<small>{{ $HOSTEL_CR_LABEL['private'] }}</small></button>
                        </div></span>
                    </div>
                    <div class="fq-editor" contenteditable="true" data-placeholder="Type the answer here." aria-label="Answer">{!! $fqAnswer($r['answer'], true) !!}</div>
                    <textarea class="fq-textarea" name="answer" maxlength="4000" hidden>{{ $r['answer'] }}</textarea>
                  </div>
                  <div class="fq-actions">
                    <button class="fq-btn fq-btn-primary" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i>Save changes</button>
                    <button class="fq-btn" type="button" data-cancel="faq-{{ (int) $r['id'] }}">Cancel</button>
                    @if ($r['is_builtin'])<span class="fq-hint">Built-in: the shipped wording stays available under "Restore original".</span>@endif
                  </div>
                </form>
              </div>
              @endforeach
            </section>
            @endforeach
            </div>
            @endfragment

            @endif
          </div>
        </div>
      </main>
@endsection

@push('body-end')
    <!-- [5] DELETE MODAL — one dialog, filled in for whichever row asked -->
    <div class="fq-modal" id="fqModal" role="dialog" aria-modal="true" aria-labelledby="fqModalTitle" hidden>
      <div class="fq-dialog">
        <div class="fq-dialog-icon"><i class="bi bi-trash" aria-hidden="true"></i></div>
        <h3 id="fqModalTitle">Delete this question?</h3>
        <p>Customers will no longer see it. This cannot be undone — if you might want it back later, use <strong>Hide</strong> instead.</p>
        <div class="fq-dialog-q" id="fqModalQ"></div>
        <form method="post" action="{{ route('admin.faq-save') }}" id="fqModalForm">
          @csrf
          <input type="hidden" name="action" value="delete" />
          <input type="hidden" name="id" value="" id="fqModalId" />
          <div class="fq-dialog-actions">
            <button class="fq-btn" type="button" data-modal-close>Keep it</button>
            <button class="fq-btn fq-btn-delete" type="submit"><i class="bi bi-trash" aria-hidden="true"></i>Delete</button>
          </div>
        </form>
      </div>
    </div>
@endpush

@push('scripts')
    <!-- [6] PAGE SCRIPT -->
    <script>
  /* Values from PHP for assets/js/admin/faq-management.js */
  var faqManagementPage = {
    fqLive: @json($fqLive),
    faqSaveUrl: @json(route('admin.faq-save')),
  };
</script>
<script src="{{ asset_stamped('assets/js/admin/faq-management.js') }}"></script>
@endpush
