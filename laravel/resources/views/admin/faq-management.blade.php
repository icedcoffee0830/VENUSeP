@extends('layouts.admin')
@section('title', 'FAQ Management')
@section('active', 'FAQ Management')

@push('styles')
    <style>
      .app-content-header { padding: 26px 0 0; }
    </style>

    <!-- [1] PAGE CSS -->
    <style>
      :root { --fq-border: #e5e5e5; --fq-muted: #6b675f; --fq-text: #1c1b19; --fq-crimson: #a11626; --fq-crimson-lo: #7d0f1e; --fq-shadow: 0 1px 2px rgba(15,23,42,.04); }
      .fq-header { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-start; justify-content: space-between; margin-bottom: 1.2rem; }
      .fq-heading h1 { margin: 0 0 4px; font-size: 1.55rem; font-weight: 700; letter-spacing: -.01em; }
      .fq-heading p { margin: 0; color: var(--fq-muted); font-size: .9rem; max-width: 72ch; }
      .fq-flash { display: flex; align-items: center; gap: 8px; padding: 11px 14px; border-radius: 10px; font-size: .88rem; font-weight: 600; margin-bottom: 1rem; }
      .fq-flash.ok  { background: #eaf6ef; color: #1c7a4f; }
      .fq-flash.bad { background: #fcecec; color: #b23a3a; }
      .fq-card { background: #fff; border: 1px solid var(--fq-border); border-radius: 14px; box-shadow: var(--fq-shadow); margin-bottom: 1.1rem; }
      .fq-card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--fq-border); flex-wrap: wrap; }
      .fq-card-head h2 { margin: 0; font-size: 1rem; font-weight: 700; display: flex; align-items: center; }
      .fq-card-head h2 small { font-weight: 600; color: var(--fq-muted); font-size: .8rem; margin-left: 8px; }
      .fq-num { display: inline-grid; place-items: center; width: 26px; height: 26px; border-radius: 8px; background: #fbe9ec; color: var(--fq-crimson); font-size: .75rem; font-weight: 800; margin-right: 10px; }
      /* the preview switch */
      .fq-preview { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 14px 18px; }
      .fq-preview-label { font-size: .86rem; font-weight: 700; }
      .fq-preview-label small { display: block; font-weight: 500; color: var(--fq-muted); font-size: .78rem; margin-top: 2px; }
      .fq-seg { display: inline-flex; border: 1px solid var(--fq-border); border-radius: 999px; padding: 3px; background: #faf9f7; margin-left: auto; }
      .fq-seg button { border: 0; background: transparent; font: inherit; font-size: .82rem; font-weight: 700; padding: 7px 16px; border-radius: 999px; cursor: pointer; color: var(--fq-muted); }
      .fq-seg button.active { background: var(--fq-crimson); color: #fff; }
      .fq-seg button .fq-live { font-weight: 500; opacity: .8; font-size: .74rem; margin-left: 4px; }
      /* forms */
      .fq-form { display: grid; grid-template-columns: 220px 220px 1fr; gap: 12px 14px; padding: 16px 18px; }
      .fq-form label { display: block; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--fq-muted); margin-bottom: 5px; }
      .fq-form .fq-full { grid-column: 1 / -1; }
      .fq-input, .fq-select, .fq-textarea { width: 100%; border: 1px solid #d7d7d7; border-radius: 10px; background: #fff; font: inherit; font-size: .9rem; color: var(--fq-text); padding: 10px 12px; }
      .fq-textarea { min-height: 120px; resize: vertical; line-height: 1.55; }
      /* the answer editor: what you see is what customers get */
      .fq-editor { min-height: 120px; border: 1px solid #d7d7d7; border-radius: 10px; background: #fff; padding: 10px 12px; font-size: .9rem; line-height: 1.6; color: var(--fq-text); outline: 0; overflow-wrap: anywhere; }
      .fq-editor:focus { border-color: var(--fq-crimson); box-shadow: 0 0 0 3px rgba(161,22,38,.12); }
      .fq-editor:empty::before { content: attr(data-placeholder); color: #a9a39b; }
      .fq-editor p, .fq-editor div { margin: 0 0 8px; }
      .fq-editor p:last-child, .fq-editor div:last-child { margin-bottom: 0; }
      .fq-editor ul { margin: 0 0 8px; padding-left: 22px; }
      .fq-editor a { color: var(--fq-crimson); text-decoration: underline; }
      .fq-editor .fq-token { display: inline-block; padding: 0 7px; border-radius: 6px; background: #fbe9ec; color: var(--fq-crimson); font-weight: 700; font-size: .84em; line-height: 1.7; border: 1px solid rgba(161,22,38,.2); cursor: default; user-select: all; }
      .fq-editor .fq-token::after { content: " \21BB"; font-weight: 400; opacity: .6; font-size: .8em; }
      .fq-form.is-invalid .fq-editor { border-color: #b23a3a; }
      .fq-editor-err { grid-column: 1 / -1; display: none; color: #b23a3a; font-size: .8rem; margin-top: -6px; }
      .fq-form.is-invalid .fq-editor-err { display: block; }
      .fq-input:focus, .fq-select:focus, .fq-textarea:focus { outline: 0; border-color: var(--fq-crimson); box-shadow: 0 0 0 3px rgba(161,22,38,.12); }
      .fq-actions { grid-column: 1 / -1; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
      .fq-hint { font-size: .78rem; color: var(--fq-muted); margin-left: auto; }
      .fq-help { grid-column: 1 / -1; font-size: .8rem; color: var(--fq-muted); line-height: 1.6; }
      /* the answer toolbar */
      .fq-toolbar { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
      .fq-tb { display: inline-flex; align-items: center; gap: 5px; height: 32px; padding: 0 11px; border: 1px solid var(--fq-border); border-radius: 8px; background: #fff; font: inherit; font-size: .8rem; font-weight: 600; color: var(--fq-text); cursor: pointer; }
      .fq-tb:hover { border-color: var(--fq-crimson); color: var(--fq-crimson); }
      .fq-tb.is-on { background: var(--fq-crimson); border-color: var(--fq-crimson); color: #fff; }   /* the caret is inside bold / a list */
      .fq-tb i { font-size: 1rem; }
      .fq-tb-caret { font-size: .7rem !important; margin-left: 2px; }
      .fq-tb-menu { position: relative; }
      .fq-tb-list { position: absolute; top: calc(100% + 4px); left: 0; z-index: 30; min-width: 280px; background: #fff; border: 1px solid var(--fq-border); border-radius: 10px; box-shadow: 0 12px 30px rgba(0,0,0,.12); padding: 6px; display: none; }
      .fq-tb-menu.open .fq-tb-list { display: block; }
      .fq-tb-list button { display: block; width: 100%; text-align: left; border: 0; background: transparent; font: inherit; font-size: .84rem; font-weight: 600; color: var(--fq-text); padding: 8px 10px; border-radius: 7px; cursor: pointer; }
      .fq-tb-list button:hover { background: #fbe9ec; color: var(--fq-crimson); }
      .fq-tb-list button small { display: block; font-weight: 500; color: var(--fq-muted); font-size: .74rem; }
      .fq-btn { display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 15px; border-radius: 10px; border: 1px solid var(--fq-border); background: #fff; color: var(--fq-text); font: inherit; font-size: .84rem; font-weight: 600; cursor: pointer; text-decoration: none; }
      .fq-btn:hover { border-color: #bdb7ac; }
      .fq-btn:disabled { opacity: .4; cursor: default; }
      .fq-btn-primary { background: var(--fq-crimson); border-color: var(--fq-crimson); color: #fff; }
      .fq-btn-primary:hover { background: var(--fq-crimson-lo); border-color: var(--fq-crimson-lo); }
      .fq-btn-sm { height: 32px; padding: 0 11px; font-size: .78rem; }
      .fq-btn-danger { color: #b23a3a; }
      .fq-btn-danger:hover { background: #fcecec; border-color: #f1c5c5; }
      /* rows */
      .fq-row { display: grid; grid-template-columns: 1fr auto; gap: 8px 16px; padding: 14px 18px; border-bottom: 1px solid var(--fq-border); }
      .fq-row:last-child { border-bottom: 0; }
      .fq-row.is-hidden .fq-q, .fq-row.is-hidden .fq-a { opacity: .55; }
      .fq-row.is-off { display: none; }                       /* filtered out by the preview switch */
      .fq-q { font-weight: 700; font-size: .95rem; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
      .fq-a { color: #4a463f; font-size: .86rem; line-height: 1.6; margin: 4px 0 0; }
      .fq-a p, .fq-a div { margin: 0 0 6px; }
      .fq-a ul { margin: 4px 0 6px; padding-left: 20px; }
      .fq-a a { color: var(--fq-crimson); }
      .fq-meta { font-size: .74rem; color: #9a958c; margin-top: 4px; }
      .fq-badge { display: inline-flex; align-items: center; gap: 4px; border-radius: 999px; padding: 2px 9px; font-size: 11px; font-weight: 700; background: #eef0f2; color: #55606b; }
      .fq-badge.builtin { background: #f2eee9; color: #6b5e4e; }
      .fq-badge.added { background: #eaf6ef; color: #1c7a4f; }
      .fq-badge.on  { background: #fdf3e6; color: #8a5a12; }
      .fq-badge.off { background: #fbe9ec; color: var(--fq-crimson); }
      .fq-badge.changed { background: #e9eff8; color: #2b4a7e; }
      .fq-tools { display: flex; gap: 6px; align-items: flex-start; flex-wrap: wrap; justify-content: flex-end; }
      .fq-tools form { margin: 0; }
      .fq-edit { grid-column: 1 / -1; display: none; border-top: 1px dashed var(--fq-border); margin-top: 6px; }
      .fq-row.is-editing .fq-edit { display: grid; }
      .fq-row.is-editing .fq-tools, .fq-row.is-editing .fq-a, .fq-row.is-editing .fq-meta { display: none; }
      .fq-empty { padding: 18px; color: var(--fq-muted); font-size: .86rem; }
      .fq-off { padding: 14px 18px; background: #fcecec; color: #b23a3a; border-radius: 12px; font-size: .88rem; margin-bottom: 1rem; }
      /* delete modal */
      .fq-modal { position: fixed; inset: 0; z-index: 2000; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(18,8,10,.55); backdrop-filter: blur(3px); }
      .fq-modal.open { display: flex; }
      .fq-dialog { width: 100%; max-width: 440px; background: #fff; border-radius: 18px; box-shadow: 0 30px 80px rgba(0,0,0,.35); padding: 26px 26px 22px; }
      .fq-dialog-icon { width: 46px; height: 46px; border-radius: 999px; background: #fcecec; color: #b23a3a; display: grid; place-items: center; font-size: 1.3rem; margin-bottom: 14px; }
      .fq-dialog h3 { margin: 0 0 6px; font-size: 1.1rem; font-weight: 800; letter-spacing: -.01em; }
      .fq-dialog p { margin: 0; color: var(--fq-muted); font-size: .88rem; line-height: 1.55; }
      .fq-dialog-q { margin: 12px 0 0; padding: 10px 12px; border: 1px solid var(--fq-border); border-radius: 10px; font-size: .86rem; font-weight: 600; color: var(--fq-text); }
      .fq-dialog-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px; }
      .fq-btn-delete { background: #b23a3a; border-color: #b23a3a; color: #fff; }
      .fq-btn-delete:hover { background: #8f2c2c; border-color: #8f2c2c; }
      @media (max-width: 900px) { .fq-form { grid-template-columns: 1fr; } .fq-row { grid-template-columns: 1fr; } .fq-tools { justify-content: flex-start; } .fq-seg { margin-left: 0; } }
    </style>
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
                <div class="fq-preview-label">Show the FAQ as customers see it when refunds are<small>Some answers change with the refund switch (payment timing does too). This preview changes nothing — the live setting is <strong>{!! $REFUNDS_ENABLED ? 'ON' : 'OFF' !!}</strong>.</small></div>
                <div class="fq-seg" role="group" aria-label="Preview refund policy">
                  <button type="button" data-preview="refunds_on"{!! $fqLive === 'refunds_on' ? ' class="active"' : '' !!}>Refunds ON{!! $fqLive === 'refunds_on' ? '<span class="fq-live">· live</span>' : '' !!}</button>
                  <button type="button" data-preview="refunds_off"{!! $fqLive === 'refunds_off' ? ' class="active"' : '' !!}>Refunds OFF{!! $fqLive === 'refunds_off' ? '<span class="fq-live">· live</span>' : '' !!}</button>
                  <button type="button" data-preview="all">Everything</button>
                </div>
              </div>
            </section>

            <!-- ADD -->
            <section class="fq-card" aria-labelledby="fqAddTitle">
              <div class="fq-card-head"><h2 id="fqAddTitle">Add a question <small>{!! $fqTotal !!} in total · {!! $fqAdded !!} added{!! $fqChanged ? ' · ' . $fqChanged . ' built-in edited' : '' !!}{!! $fqHidden ? ' · ' . $fqHidden . ' hidden' : '' !!}</small></h2></div>
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
                        <button type="button" data-value="{rate_communal}">Hostel rate &middot; communal CR<small>&#8369;{{ number_format((int) ($HOSTEL_RATES['communal'] ?? 0)) }} per head, per night</small></button>
                        <button type="button" data-value="{rate_private}">Hostel rate &middot; private CR<small>&#8369;{{ number_format((int) ($HOSTEL_RATES['private'] ?? 0)) }} per head, per night</small></button>
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
                          <button type="button" data-value="{rate_communal}">Hostel rate &middot; communal CR<small>&#8369;{{ number_format((int) ($HOSTEL_RATES['communal'] ?? 0)) }} per head, per night</small></button>
                          <button type="button" data-value="{rate_private}">Hostel rate &middot; private CR<small>&#8369;{{ number_format((int) ($HOSTEL_RATES['private'] ?? 0)) }} per head, per night</small></button>
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
      (function () {
        /* preview switch: show only the rows customers get under that policy */
        var seg = document.querySelectorAll('[data-preview]');
        function preview(mode) {
          seg.forEach(function (b) { b.classList.toggle('active', b.dataset.preview === mode); });
          document.querySelectorAll('.fq-row').forEach(function (row) {
            var p = row.dataset.policy;
            row.classList.toggle('is-off', mode !== 'all' && p !== 'any' && p !== mode);
          });
          document.querySelectorAll('.fq-card[id] .fq-card-head small').forEach(function (s) {
            var card = s.closest('.fq-card'); var n = card.querySelectorAll('.fq-row:not(.is-off)').length;
            s.textContent = n + ' question' + (n === 1 ? '' : 's') + (mode === 'all' ? '' : ' shown');
          });
        }
        seg.forEach(function (b) { b.addEventListener('click', function () { preview(b.dataset.preview); try { sessionStorage.setItem('venusep_faq_preview', b.dataset.preview); } catch (e) {} }); });
        /* which preview to start on: ?show= from a save (so the row just saved is
           visible), else the choice remembered for this tab, else the live setting */
        var show = new URLSearchParams(location.search).get('show');
        var remembered = null; try { remembered = sessionStorage.getItem('venusep_faq_preview'); } catch (e) {}
        var start = (show === 'refunds_on' || show === 'refunds_off') ? show : (remembered || '{!! $fqLive !!}');
        preview(start);

        /* THE ANSWER EDITOR. What the admin sees is what customers get: bold is
           bold, lists are lists, links are links, live values are chips. On
           submit the editor is turned back into the stored text format
           (**bold**, "- " lists, [text](url), {placeholders}) in the hidden
           textarea, so the server and customer page never see HTML. */
        function serialize(node) {
          var out = '';
          node.childNodes.forEach(function (n) {
            if (n.nodeType === 3) { out += n.nodeValue.replace(/\u00a0/g, ' '); return; }
            if (n.nodeType !== 1) return;
            var tag = n.tagName.toLowerCase();
            if (n.classList && n.classList.contains('fq-token')) { out += n.dataset.token; return; }
            if (tag === 'br') { out += '\n'; return; }
            if (tag === 'b' || tag === 'strong') { var t = serialize(n).trim(); out += t ? '**' + t + '**' : ''; return; }
            if (tag === 'a') { var href = n.getAttribute('href') || ''; href = href.replace(/^.*\/customer\//, ''); out += '[' + serialize(n).trim() + '](' + href + ')'; return; }
            if ((tag === 'ul' || tag === 'ol' || tag === 'p' || tag === 'div') && out && !/\n$/.test(out)) out += '\n\n';   // a block right after inline text starts its own paragraph
            if (tag === 'ul' || tag === 'ol') { out += '\n' + Array.prototype.map.call(n.querySelectorAll(':scope > li'), function (li) { return '- ' + serialize(li).trim(); }).join('\n') + '\n\n'; return; }
            if (tag === 'li') { out += '- ' + serialize(n).trim() + '\n'; return; }
            if (tag === 'p' || tag === 'div') { var inner = serialize(n); out += (inner.trim() ? inner.replace(/\n+$/, '') : '') + '\n\n'; return; }
            out += serialize(n);
          });
          return out;
        }
        function editorText(ed) { return serialize(ed).replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim(); }
        function syncEditor(form) {
          var ed = form.querySelector('.fq-editor'), ta = form.querySelector('textarea[name=answer]');
          if (ed && ta) ta.value = editorText(ed);
          return ta ? ta.value : '';
        }
        document.addEventListener('submit', function (e) {
          var form = e.target; if (!form.querySelector('.fq-editor')) return;
          var text = syncEditor(form);
          if (!text) { e.preventDefault(); form.classList.add('is-invalid'); form.querySelector('.fq-editor').focus(); }
        });
        document.addEventListener('input', function (e) { var f = e.target.closest && e.target.closest('.fq-form'); if (f) f.classList.remove('is-invalid'); });
        /* paste as plain text so nothing odd comes in from Word or a web page */
        document.addEventListener('paste', function (e) {
          var ed = e.target.closest && e.target.closest('.fq-editor'); if (!ed) return;
          e.preventDefault(); document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain'));
        });
        /* the Bold / List buttons light up red while the caret sits in bold text / a list */
        function paintToolbar() {
          var sel = window.getSelection(); var node = sel && sel.rangeCount ? sel.getRangeAt(0).startContainer : null;
          var ed = node && (node.nodeType === 1 ? node : node.parentElement).closest('.fq-editor');
          document.querySelectorAll('.fq-tb.is-on').forEach(function (b) { b.classList.remove('is-on'); });
          if (!ed) return;
          var tb = ed.parentElement.querySelector('.fq-toolbar'); if (!tb) return;
          tb.querySelector('[data-fmt="bold"]').classList.toggle('is-on', document.queryCommandState('bold'));
          tb.querySelector('[data-fmt="list"]').classList.toggle('is-on', document.queryCommandState('insertUnorderedList'));
        }
        document.addEventListener('selectionchange', paintToolbar);
        function chip(token, label) { return '<span class="fq-token" contenteditable="false" data-token="' + token + '">' + label + '</span>&nbsp;'; }
        document.addEventListener('click', function (e) {
          var openMenu = document.querySelector('.fq-tb-menu.open');
          if (openMenu && !e.target.closest('.fq-tb-menu')) openMenu.classList.remove('open');
          var tb = e.target.closest('.fq-toolbar'); if (!tb) return;
          var b = e.target.closest('button'); if (!b) return;
          var ed = tb.parentElement.querySelector('.fq-editor');
          if (b.hasAttribute('data-menu')) {
            var m = b.closest('.fq-tb-menu'), was = m.classList.contains('open');
            if (openMenu) openMenu.classList.remove('open');
            m.classList.toggle('open', !was); return;
          }
          ed.focus();
          if (b.dataset.fmt === 'bold') { document.execCommand('bold'); paintToolbar(); }
          if (b.dataset.fmt === 'list') { document.execCommand('insertUnorderedList'); paintToolbar(); }
          if (b.dataset.link) {
            var sel = window.getSelection();
            if (!sel || sel.isCollapsed) document.execCommand('insertHTML', false, '<a href="' + b.dataset.link + '">' + (b.textContent.trim()) + '</a>&nbsp;');
            else document.execCommand('createLink', false, b.dataset.link);
            b.closest('.fq-tb-menu').classList.remove('open');
          }
          if (b.dataset.value) {
            var label = b.querySelector('small') ? b.querySelector('small').textContent.replace(/ — updates by itself$/, '').replace(/ per head, per night$/, '') : b.dataset.value;
            document.execCommand('insertHTML', false, chip(b.dataset.value, label));
            b.closest('.fq-tb-menu').classList.remove('open');
          }
        });
        /* Enter inside a chip's neighbourhood should never split the chip; a chip is deleted whole */
        document.addEventListener('keydown', function (e) {
          if (e.key !== 'Backspace' && e.key !== 'Delete') return;
          var ed = e.target.closest && e.target.closest('.fq-editor'); if (!ed) return;
          var sel = window.getSelection(); if (!sel.rangeCount || !sel.isCollapsed) return;
          var r = sel.getRangeAt(0), n = r.startContainer, o = r.startOffset, target = null;
          if (e.key === 'Backspace') { if (n.nodeType === 3 && o === 0) target = n.previousSibling; else if (n.nodeType === 1) target = n.childNodes[o - 1]; }
          else { if (n.nodeType === 3 && o === n.length) target = n.nextSibling; else if (n.nodeType === 1) target = n.childNodes[o]; }
          if (target && target.nodeType === 1 && target.classList.contains('fq-token')) { e.preventDefault(); target.remove(); }
        });

        /* inline edit forms */
        document.addEventListener('click', function (e) {
          var ed = e.target.closest('[data-edit]');
          if (ed) { document.getElementById(ed.dataset.edit).classList.add('is-editing'); return; }
          var cn = e.target.closest('[data-cancel]');
          if (cn) { document.getElementById(cn.dataset.cancel).classList.remove('is-editing'); return; }
          /* delete modal */
          var del = e.target.closest('[data-delete]');
          if (del) {
            var row = document.getElementById('faq-' + del.dataset.delete);
            document.getElementById('fqModalId').value = del.dataset.delete;
            document.getElementById('fqModalQ').textContent = row ? row.dataset.question : '';
            var m = document.getElementById('fqModal'); m.hidden = false; m.classList.add('open');
            m.querySelector('[data-modal-close]').focus();
            return;
          }
          if (e.target.closest('[data-modal-close]') || e.target.id === 'fqModal') { closeModal(); }
        });
        function closeModal() { var m = document.getElementById('fqModal'); m.classList.remove('open'); m.hidden = true; }
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

        /* keep the section the admin was working in after a save (faq-save.php redirects with #section) */
        if (location.hash) { var t = document.querySelector(location.hash); if (t) t.scrollIntoView({ block: 'start' }); }
      })();
    </script>
@endpush
