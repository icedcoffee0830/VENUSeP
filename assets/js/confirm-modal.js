/* =====================================================================
   SHARED CONFIRM / ALERT MODAL — a styled replacement for the browser's
   native confirm()/alert(), which shows as an ugly, unbranded
   "localhost says..." box the admin and customer pages used everywhere.

   Needs assets/css/confirm-modal.css on the same page.

   venusepAlert(message, opts?) -> Promise<void>
     Shows one OK button. Resolves when dismissed. Safe to call without
     awaiting — exactly like alert(), nothing after it depends on the
     dialog actually being gone yet.

   venusepConfirm(message, opts?) -> Promise<boolean>
     Shows Cancel + OK. Resolves true (OK) or false (Cancel / Escape /
     click outside) — the direct, must-await replacement for confirm().
     Existing call sites written as `if (!confirm(...)) return;` become
     `if (!(await venusepConfirm(...))) return;` inside an async function.

   opts: { title, okLabel, cancelLabel, danger }
     danger:true tints the icon/OK button red (destructive actions, e.g.
     Delete) instead of the neutral/dark default.
   ===================================================================== */
(function () {
  'use strict';

  var ICONS = {
    question:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 2-3 4"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    info:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    warning:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
  };

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
  }

  /* One dialog at a time — a second call waits for the first to close,
     same as the native dialogs never stacking. */
  var queue = Promise.resolve();

  function openDialog(message, opts, mode) {
    opts = opts || {};
    var danger = !!opts.danger;
    var tone = mode === 'confirm' ? (danger ? 'vz-danger' : '') : (opts.tone === 'ok' ? 'vz-ok' : '');
    var icon = danger ? ICONS.warning : (mode === 'confirm' ? ICONS.question : ICONS.info);

    return new Promise(function (resolve) {
      var overlay = document.createElement('div');
      overlay.className = 'vz-modal-overlay';
      overlay.innerHTML =
        '<div class="vz-modal ' + tone + '" role="alertdialog" aria-modal="true" ' +
        (opts.title ? 'aria-label="' + escapeHtml(opts.title) + '"' : 'aria-label="Confirm"') + '>' +
          '<div class="vz-modal-icon">' + icon + '</div>' +
          (opts.title ? '<div class="vz-modal-title">' + escapeHtml(opts.title) + '</div>' : '') +
          '<p class="vz-modal-message">' + escapeHtml(message) + '</p>' +
          '<div class="vz-modal-actions' + (mode === 'alert' ? ' vz-single' : '') + '">' +
            (mode === 'confirm' ? '<button type="button" class="vz-modal-btn vz-cancel" data-vz="cancel">' + escapeHtml(opts.cancelLabel || 'Cancel') + '</button>' : '') +
            '<button type="button" class="vz-modal-btn vz-ok" data-vz="ok">' + escapeHtml(opts.okLabel || 'OK') + '</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(overlay);
      document.body.style.overflow = 'hidden';
      requestAnimationFrame(function () { overlay.classList.add('vz-in'); });

      var okBtn = overlay.querySelector('[data-vz="ok"]');
      var cancelBtn = overlay.querySelector('[data-vz="cancel"]');
      var done = false;

      function close(result) {
        if (done) return;
        done = true;
        document.removeEventListener('keydown', onKey, true);
        overlay.classList.remove('vz-in');
        document.body.style.overflow = '';
        setTimeout(function () { overlay.remove(); }, 160);
        resolve(result);
      }
      function onKey(e) {
        // Enter is deliberately left alone: a focused button already
        // activates on Enter natively, and this would otherwise override
        // whichever one — OK or Cancel — actually has focus.
        if (e.key === 'Escape') { e.preventDefault(); close(false); }
      }
      document.addEventListener('keydown', onKey, true);
      overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(false); });
      if (okBtn) okBtn.addEventListener('click', function () { close(true); });
      if (cancelBtn) cancelBtn.addEventListener('click', function () { close(false); });
      setTimeout(function () { (okBtn || overlay).focus(); }, 0);
    });
  }

  window.venusepAlert = function (message, opts) {
    var run = queue.then(function () { return openDialog(message, opts, 'alert'); });
    queue = run.catch(function () {});
    return run.then(function () {});
  };

  window.venusepConfirm = function (message, opts) {
    var run = queue.then(function () { return openDialog(message, opts, 'confirm'); });
    queue = run.catch(function () {});
    return run;
  };
})();
