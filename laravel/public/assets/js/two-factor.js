/* Two-step verification in the browser (DB-DECISIONS #20). Draws the QR,
   copies/downloads recovery codes, holds "Continue" until the codes are marked
   saved, and runs the profile panel ([data-tfa-panel]) against profile-save.php.
   The server re-checks everything; nothing here is trusted. */
(function () {
  'use strict';

  function renderQr(el) {
    var value = el.getAttribute('data-tfa-qr');
    if (!value) return;
    if (typeof window.qrcode !== 'function') { el.hidden = true; return; }   // the typed key is still there
    var qr = window.qrcode(0, 'M');
    qr.addData(value);
    qr.make();
    el.innerHTML = qr.createSvgTag(4, 0);
    el.setAttribute('role', 'img');
    el.setAttribute('aria-label', 'QR code for setting up VENUSeP in your authenticator app. The same key is written out below it.');
    el.hidden = false;
  }

  /* "45 seconds", "14 min 59 s", "3 h 59 min" — the same words as tfa_wait_text() in PHP. */
  function waitText(s) {
    if (s >= 3600) return Math.floor(s / 3600) + ' h ' + Math.floor((s % 3600) / 60) + ' min';
    if (s >= 60) return Math.floor(s / 60) + ' min ' + (s % 60) + ' s';
    return s + (s === 1 ? ' second' : ' seconds');
  }

  /* A running code lock, counted down in place. Keeps `button` disabled until the
     lock ends, then says so and hands the cursor back. Counts to a fixed end time,
     so a slow tab never shows more time than the server will actually enforce.
     Writes the same structure as tfa_view_lock() in includes/two-factor-views.php. */
  function lockCountdown(el, seconds, button, input) {
    clearInterval(el._tfaLock);
    if (!el.querySelector('[data-tfa-wait]')) {
      el.textContent = '';
      var text = document.createElement('span');
      text.setAttribute('data-tfa-text', '');
      var wait = document.createElement('span');
      wait.setAttribute('aria-live', 'off');   // announce the lock once, not every second
      wait.setAttribute('data-tfa-wait', '');
      text.append('Too many wrong codes. Try again in ', wait, '.');
      el.appendChild(text);
    }
    var end = Date.now() + seconds * 1000;
    var wait = el.querySelector('[data-tfa-wait]');
    if (button) button.disabled = true;
    var tick = function () {
      var left = Math.ceil((end - Date.now()) / 1000);
      if (left > 0) {
        wait.textContent = waitText(left);
        return;
      }
      clearInterval(el._tfaLock);
      el.querySelector('[data-tfa-text]').textContent = 'You can try again now.';
      el.classList.remove('is-bad');
      el.classList.add('is-ready');
      if (button) button.disabled = false;
      if (input) { input.value = ''; input.focus(); }
    };
    tick();
    el._tfaLock = setInterval(tick, 250);
  }

  function flash(btn, text) {
    var label = btn.querySelector('span') || btn;
    if (!btn.dataset.tfaLabel) btn.dataset.tfaLabel = label.textContent;
    label.textContent = text;
    clearTimeout(btn._tfaTimer);
    btn._tfaTimer = setTimeout(function () { label.textContent = btn.dataset.tfaLabel; }, 2000);
  }

  function copyText(text, btn) {
    var fallback = function () {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta);
      flash(btn, ok ? 'Copied' : 'Select and copy it');
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { flash(btn, 'Copied'); }, fallback);
    } else {
      fallback();
    }
  }

  function codesText(box) {
    var codes = [];
    try { codes = JSON.parse(box.getAttribute('data-tfa-codes') || '[]'); } catch (e) { codes = []; }
    var account = box.getAttribute('data-tfa-account') || '';
    return 'VENUSeP recovery codes\n' + (account ? account + '\n' : '') + '\n' + codes.join('\n') +
      '\n\nEach code works once. Made ' + new Date().toLocaleDateString() + '.\n';
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-tfa-copy], [data-tfa-copy-codes], [data-tfa-download]');
    if (!btn) return;
    if (btn.hasAttribute('data-tfa-copy')) { copyText(btn.getAttribute('data-tfa-copy'), btn); return; }
    var box = btn.closest('[data-tfa-codes]');
    if (!box) return;
    if (btn.hasAttribute('data-tfa-copy-codes')) { copyText(codesText(box), btn); return; }
    var url = URL.createObjectURL(new Blob([codesText(box)], { type: 'text/plain' }));
    var a = document.createElement('a');
    a.href = url;
    a.download = 'venusep-recovery-codes.txt';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    flash(btn, 'Downloaded');
  });

  /* "Continue"/"Done" wait for the "I saved these" tick. Disabled here, not in the
     HTML, so the form still works if this script never loads. */
  function bindSaved(scope) {
    var box = scope.querySelector('[data-tfa-saved]');
    var go = scope.querySelector('[data-tfa-saved-submit]');
    if (!box || !go) return;
    var sync = function () { go.disabled = !box.checked; };
    box.addEventListener('change', sync);
    sync();
  }

  function initPanel(panel) {
    var endpoint = panel.getAttribute('data-endpoint');
    var csrf = panel.getAttribute('data-csrf');
    var q = function (sel) { return panel.querySelector(sel); };
    var actions = q('[data-tfa-actions]');
    var prompt = q('[data-tfa-prompt]');
    var promptLabel = q('[data-tfa-prompt-label]');
    var promptInput = q('[data-tfa-current]');
    var promptGo = q('[data-tfa-prompt-go]');
    var setup = q('[data-tfa-setup]');
    var newCode = q('[data-tfa-new-code]');
    var codesBlock = q('[data-tfa-codes-block]');
    var msg = q('[data-tfa-message]');
    var pending = null;   // the action waiting on the prompt

    var say = function (text, good) {
      if (msg._tfaLock) {   // a new message replaces a running countdown; the server still enforces the lock
        clearInterval(msg._tfaLock);
        msg._tfaLock = null;
        promptGo.disabled = false;
      }
      msg.classList.remove('is-ready');
      msg.textContent = text || '';
      msg.classList.toggle('is-bad', !!text && !good);
      msg.classList.toggle('is-good', !!text && !!good);
    };
    var show = function (el) {
      [prompt, setup, codesBlock].forEach(function (b) { if (b) b.hidden = b !== el; });
      if (actions) actions.hidden = !!el;
    };
    var busy = function (on) {
      panel.querySelectorAll('button').forEach(function (b) {
        if (on) { b.dataset.tfaWasDisabled = b.disabled ? '1' : ''; b.disabled = true; }
        else { b.disabled = b.dataset.tfaWasDisabled === '1'; }
      });
      panel.setAttribute('aria-busy', on ? 'true' : 'false');
    };
    var post = function (fields) {
      var body = new URLSearchParams(fields);
      body.set('csrf', csrf);
      say('');
      msg.textContent = 'Checking…';
      busy(true);
      return fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
        .catch(function () { return { ok: false, message: 'Could not reach the server.' }; })
        .then(function (res) { busy(false); return res; });
    };

    var openSetup = function (res) {
      var qr = setup.querySelector('[data-tfa-qr]');
      qr.setAttribute('data-tfa-qr', res.uri || '');
      renderQr(qr);
      setup.querySelector('[data-tfa-key]').textContent = String(res.secret || '').replace(/(.{4})(?=.)/g, '$1 ');
      newCode.value = '';
      show(setup);
      newCode.focus();
    };
    var openCodes = function (codes) {
      var box = codesBlock.querySelector('[data-tfa-codes]');
      box.setAttribute('data-tfa-codes', JSON.stringify(codes || []));
      var list = codesBlock.querySelector('[data-tfa-codes-list]');
      list.innerHTML = '';
      (codes || []).forEach(function (c) { var li = document.createElement('li'); li.textContent = c; list.appendChild(li); });
      var saved = codesBlock.querySelector('[data-tfa-saved]');
      saved.checked = false;
      saved.dispatchEvent(new Event('change'));
      show(codesBlock);
      saved.focus();
    };

    panel.querySelectorAll('[data-tfa-action]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        pending = btn.getAttribute('data-tfa-action');
        say('');
        promptLabel.textContent = btn.getAttribute('data-tfa-ask');
        promptInput.type = pending === 'begin' ? 'password' : 'text';
        promptInput.autocomplete = pending === 'begin' ? 'current-password' : 'one-time-code';
        promptInput.maxLength = pending === 'begin' ? 256 : 11;
        promptInput.value = '';
        promptGo.textContent = btn.getAttribute('data-tfa-go');
        promptGo.classList.toggle('btn-profile-danger', pending === 'disable');
        show(prompt);
        promptInput.focus();
      });
    });

    prompt.addEventListener('submit', function (e) {
      e.preventDefault();
      var value = promptInput.value;
      if (!value.trim()) { say(pending === 'begin' ? 'Enter your password.' : 'Enter a code from your phone or a recovery code.'); promptInput.focus(); return; }
      var fields = pending === 'begin' ? { action: 'tfa_begin', password: value }
        : { action: pending === 'move' ? 'tfa_begin' : 'tfa_' + pending, code: value };
      post(fields).then(function (res) {
        if (!res.ok && res.seconds > 0) {   // locked: count it down, keep the button held
          msg.classList.add('is-bad');
          msg.classList.remove('is-good', 'is-ready');
          lockCountdown(msg, res.seconds, promptGo, promptInput);
          return;
        }
        if (!res.ok) { say(res.message || 'Nothing was changed.'); promptInput.select(); return; }
        say('');
        if (pending === 'begin' || pending === 'move') openSetup(res);
        else if (pending === 'codes') { say(res.message, true); openCodes(res.codes); }
        else window.location.reload();
      });
    });

    setup.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!newCode.value.trim()) { say('Enter the 6-digit code the app shows.'); newCode.focus(); return; }
      post({ action: 'tfa_confirm', code: newCode.value }).then(function (res) {
        if (!res.ok) { say(res.message || 'Nothing was changed.'); newCode.select(); return; }
        say(res.message, true);
        openCodes(res.codes);
      });
    });

    panel.querySelectorAll('[data-tfa-back]').forEach(function (btn) {
      btn.addEventListener('click', function () { say(''); show(null); });
    });
    codesBlock.querySelector('[data-tfa-saved-submit]').addEventListener('click', function () { window.location.reload(); });
    bindSaved(codesBlock);
  }

  function init() {
    document.querySelectorAll('[data-tfa-countdown]').forEach(function (el) {
      var form = el.closest('form');
      lockCountdown(el, parseInt(el.getAttribute('data-tfa-countdown'), 10) || 0,
        form && form.querySelector('button[type="submit"]'), form && form.querySelector('input[name="code"]'));
    });
    document.querySelectorAll('[data-tfa-qr]').forEach(renderQr);
    document.querySelectorAll('form').forEach(bindSaved);
    document.querySelectorAll('[data-tfa-panel]').forEach(initPanel);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
