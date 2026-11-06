/* Script for resources/views/customer/pay.blade.php. */
      const SUBMIT_URL = payPage.paymentSubmitUrl, PAY_URL = payPage.payUrl, FAQ_URL = payPage.faqUrl;

      /* `state` and render() are what the shared checker drives. */
      const state = { ocr: null, agreeExact: false, method: (PAY.account && !PAY.cashLocked) ? PAY.method : 'cash', sending: false, error: null };

      function gcAccount()          { return PAY.account || { name: '', number: '' }; }
      function gcExpectedCentavos() { return PAY.centavos; }
      function gcBookingRef()       { return PAY.ref; }
      function gcPriorUploads()     { return PAY.prior; }

      function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
      function fmtNumber(n) { return String(n || '').replace(/^(\d{4})(\d{3})(\d{4})$/, '$1 $2 $3'); }
      function setMethod(m) { if (m === 'gcash' && (!PAY.account || PAY.cashLocked)) return; state.method = m; state.error = null; render(); }
      function toggleAgree(el) { state.agreeExact = !!el.checked; render(); }

      /* The receipt panel: upload zone -> OCR progress -> verdict card (same three
         phases and wording as the booking pages). */
      function receiptPanelHtml() {
        const o = state.ocr;
        const fileInput = `<input type="file" id="gcFile" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="checkReceipt(this)">`;
        if (!o) {
          const on = state.agreeExact;
          return fileInput + `
            <div onclick="${on ? 'pickReceipt()' : ''}" role="button" tabindex="${on ? 0 : -1}" style="border:1.5px dashed rgba(0,0,0,.18);border-radius:11px;padding:26px 14px;text-align:center;background:#fff;${on ? 'cursor:pointer' : 'opacity:.55;cursor:not-allowed'}">
              <div style="font-size:13.5px;font-weight:600;color:#4a463f">${on ? 'Tap to upload a screenshot of your GCash receipt' : 'Tick the exact-amount box above to enable the upload'}</div>
              <div style="font-size:12.5px;color:#a5a19a;margin-top:3px">PNG or JPG, up to 7 MB · keep the reference number and amount visible · checked instantly with OCR</div>
            </div>`;
        }
        if (o.phase === 'reading') {
          return `
            <div style="border:1px solid rgba(0,0,0,.12);border-radius:11px;padding:14px;background:#fff">
              <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:9px">
                <span style="font-size:13px;font-weight:600;color:#4a463f;overflow-wrap:anywhere">${esc(o.fileName)}</span>
                <span id="gcBarLabel" style="font-size:12px;color:#6e6a64;flex:none">${esc(o.label)} · ${Math.round((o.pct || 0) * 100)}%</span>
              </div>
              <div style="height:6px;border-radius:999px;background:#e7e4de;overflow:hidden">
                <div id="gcBar" style="height:100%;width:${Math.round((o.pct || 0) * 100)}%;background:#a11626;border-radius:999px;transition:width .25s"></div>
              </div>
              <div style="font-size:12.5px;color:#a5a19a;margin-top:8px">Reading the receipt with OCR — runs in your browser, usually 5–15 seconds.</div>
            </div>`;
        }
        const r = o.rec;
        /* One of THIS booking's earlier uploads again: said plainly, with what
           became of it — it is not sent, so no attempt is used. */
        const V = r.prior
          ? { bg: '#fdf2f2', bd: '#f2d8d8', fg: '#b23a3a', t: 'You already uploaded this receipt',
              s: `Attempt ${r.prior.no} — ${r.prior.outcome}. The same receipt cannot be accepted again, so it will not be sent and no attempt is used. Please upload a different receipt.` }
          : {
          accepted:      { bg: '#f2faf5', bd: '#d4ebdd', fg: '#1c7a4f', t: 'Receipt looks right', s: 'All automatic checks passed. Submit it below; staff give the final confirmation in GCash.' },
          manual_review: { bg: '#fdf7ee', bd: '#f3e3c8', fg: '#8a5a12', t: 'A coordinator will check this receipt', s: 'Something did not match automatically. You can still submit it — a person will look at it and your slot is held meanwhile.' },
          rejected:      { bg: '#fdf2f2', bd: '#f2d8d8', fg: '#b23a3a', t: 'This receipt cannot be accepted', s: 'Fix the issues below, then upload a new receipt.' },
        }[r.status];
        const p = r.parsed;
        /* Same image: the OCR was skipped, so there is nothing read to show. */
        const kv = r.reasons.includes('already_uploaded_file') ? [] : [
          ['Ref no.', p.refDisplay || p.ref || '—'],
          ['Amount read', p.effAmountC != null ? centavosFmt(p.effAmountC) : '—'],
          ['Amount required', centavosFmt(gcExpectedCentavos())],
          ['Date & time', p.datetime || '—'],
          ['Sent to', p.receiverNumber || p.receiverNameMasked || p.receiverNameShort || '—'],
        ];
        const issues = r.prior ? [] : [...r.reasons.map(c => ({ c, hard: true })), ...r.flags.map(c => ({ c, hard: false }))];
        return fileInput + `
          <div style="border:1px solid rgba(0,0,0,.12);border-radius:11px;overflow:hidden;background:#fff">
            <div style="display:flex;gap:11px;align-items:flex-start;padding:13px 14px;background:${V.bg};border-bottom:1px solid ${V.bd}">
              <div style="flex:1">
                <div style="font-size:13.5px;font-weight:640;color:${V.fg}">${V.t}</div>
                <div style="font-size:12px;color:${V.fg};opacity:.85;margin-top:2px">${esc(V.s)}</div>
              </div>
              <button type="button" onclick="removeReceipt()" style="flex:none;background:#fff;border:1px solid rgba(0,0,0,.14);border-radius:8px;padding:6px 11px;font-size:12px;font-weight:600;color:#4a463f;cursor:pointer">${r.status === 'rejected' ? 'Try another' : 'Remove'}</button>
            </div>
            <div style="display:flex;gap:13px;padding:13px 14px">
              ${o.thumb ? `<img src="${o.thumb}" alt="receipt" style="width:64px;height:84px;object-fit:cover;border-radius:8px;border:1px solid rgba(0,0,0,.1);flex:none">` : ''}
              <div style="flex:1;display:grid;grid-template-columns:1fr 1fr;gap:8px 14px;align-content:start">
                ${kv.map(([k, v]) => `<div><div style="font-size:12px;color:#6e6a64">${k}</div><div style="font-size:13px;font-weight:640;color:#1c1b19;overflow-wrap:anywhere">${esc(v)}</div></div>`).join('')}
              </div>
            </div>
            ${issues.length ? `
            <div style="padding:2px 14px 12px">
              ${issues.map(i => `<div style="display:flex;gap:8px;align-items:flex-start;padding:4px 0"><span style="width:7px;height:7px;border-radius:999px;background:${i.hard ? '#b23a3a' : '#c99a3c'};margin-top:5px;flex:none"></span><span style="font-size:12.5px;color:#4a463f;line-height:1.45">${esc(GC_LABELS[i.c] || i.c)}</span></div>`).join('')}
            </div>` : ''}
            ${r.confidence != null ? `<div style="padding:0 14px 11px;font-size:12px;color:#a5a19a">OCR confidence ${r.confidence}% · ${esc(o.fileName)}</div>` : ''}
          </div>`;
      }

      /* The 5-attempt rule (DB-DECISIONS #6). Every upload counts, rejected ones
         too; the server enforces it — this only says where the customer stands. */
      function attemptsLeft() { return Math.max(0, PAY.allowed - PAY.used); }
      function attemptLine() {
        const next = PAY.used + 1, last = attemptsLeft() === 1;
        return `<div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;margin:2px 0 8px">
            <span style="font-weight:600;color:${last ? '#b23a3a' : '#5c584f'}">Attempt ${next} of ${PAY.allowed}</span>
            <span style="color:#6e6a64">${last ? 'Last try — it goes to staff whatever the check says' : attemptsLeft() + ' receipt uploads left'}</span>
          </div>`;
      }

      function gcashBody() {
        const a = gcAccount();
        if (attemptsLeft() === 0) {
          return `
            <div class="pay-note is-bad"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><div><strong>All ${PAY.allowed} receipt attempts are used</strong>You cannot upload another GCash receipt for this booking. Staff will review your payment and contact you &mdash; or pay in cash at the office instead.</div></div>`;
        }
        return `
          <div class="pay-note is-warn"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div><strong>Send the EXACT amount — not more, not less.</strong>A different amount is not confirmed automatically; a coordinator has to review it.</div></div>
          <section class="pay-card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding-bottom:14px;border-bottom:1px solid var(--line)">
              <div><div style="font-size:12.5px;color:#6e6a64">Amount to send</div><div class="pay-amount">${esc(PAY.amount)}</div></div>
              <div style="width:56px;height:56px;border-radius:14px;background:#0a6cf0;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:750">GCash</div>
            </div>
            <div class="pay-grid" style="margin:14px 0">
              <div><div class="pay-k">GCash account name</div><div class="pay-v">${esc(a.name)}</div></div>
              <div><div class="pay-k">GCash number</div><div class="pay-v">${esc(fmtNumber(a.number))}</div></div>
              <div><div class="pay-k">Reference to include</div><div class="pay-v">${esc(PAY.ref)}</div></div>
            </div>
            <label class="pay-agree"><input type="checkbox" ${state.agreeExact ? 'checked' : ''} onchange="toggleAgree(this)"><span>I understand I must send the <strong>exact amount &mdash; ${esc(PAY.amount)}</strong>.</span></label>
            ${attemptLine()}
            ${receiptPanelHtml()}
          </section>`;
      }

      function cashBody() {
        const c = PAY.cash;
        return `
          <section class="pay-card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding-bottom:14px;border-bottom:1px solid var(--line)">
              <div><div style="font-size:12.5px;color:#6e6a64">Amount to pay</div><div class="pay-amount">${esc(PAY.amount)}</div></div>
              <div style="width:56px;height:56px;border-radius:14px;background:#a11626;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:750">Cash</div>
            </div>
            <div style="margin-top:14px">
              <div class="pay-k">Where to pay</div>
              <div class="pay-v" style="font-size:15px">${esc(c.where)}</div>
              ${c.address ? `<div style="font-size:13px;color:#4a463f;margin-top:3px">${esc(c.address)}</div>` : ''}
              <div style="font-size:12.5px;color:#6e6a64;margin-top:3px">${esc(c.hours)}</div>
            </div>
            <div style="font-size:12.5px;color:#4a463f;line-height:1.7;border-top:1px solid var(--line);margin-top:14px;padding-top:12px">Bring your reference <strong>${esc(PAY.ref)}</strong> and a valid ID. Staff record the payment there and your booking updates on its own.</div>
          </section>`;
      }

      function render() {
        const el = document.getElementById('payApp');
        if (!el) return;
        const cash = state.method === 'cash';
        const ready = cash ? !PAY.cashChosen : (attemptsLeft() > 0 && typeof receiptOk === 'function' && receiptOk());
        const methodBtn = (m, title, sub, off) =>
          `<button type="button" class="pay-method${state.method === m ? ' is-on' : ''}" ${off ? 'disabled style="opacity:.5;cursor:not-allowed"' : ''} onclick="setMethod('${m}')"><b>${title}</b><span>${sub}</span></button>`;
        el.innerHTML = `
          <div class="pay-methods" role="group" aria-label="Payment method">
            ${methodBtn('gcash', 'GCash',
                PAY.cashLocked ? 'Closed while you pay in cash — cancel below to use GCash'
                  : (PAY.account ? 'Send online, then upload your receipt' : 'Not available for this venue yet'),
                !PAY.account || PAY.cashLocked)}
            ${methodBtn('cash', PAY.hostel ? 'Cash — hostel front desk' : 'Cash — at the office', 'Pay in person; staff record it')}
          </div>
          ${cash ? cashBody() : gcashBody()}
          <button type="button" class="pay-btn" ${ready && !state.sending ? '' : 'disabled'} onclick="submitPayment()">${
            state.sending ? 'Sending…' : (cash ? (PAY.cashChosen ? (PAY.hostel ? 'You chose cash — pay at the front desk' : 'You chose cash — pay at the office') : 'I will pay in cash') : 'Submit receipt')}</button>
          ${PAY.cashLocked ? `<button type="button" class="pay-btn" style="background:#fff;color:#a11626;border:1.5px solid #a11626;margin-top:10px" ${state.sending ? 'disabled' : ''} onclick="cancelCash()">Cancel cash payment — pay by GCash instead</button>` : ''}
          ${state.error ? `<div class="pay-err" role="alert">${esc(state.error)}</div>` : ''}
          <div class="pay-small">${cash ? 'Your booking stays as it is until staff record the payment.' : 'Staff confirm every GCash payment against the business GCash history.'} Need help? <a href="${FAQ_URL}" style="color:#a11626;font-weight:600">Check the FAQ</a></div>`;
      }

      /* THE CASH CHOICE (2026-10-28): take back "I will pay in cash" — at the
         hostel front desk or the venue cashier. GCash opens again, and staff
         stop seeing their cash button. The server refuses it once staff have
         already recorded the cash. */
      async function cancelCash() {
        if (state.sending) return;
        if (!(await venusepConfirm('You will pay by GCash instead. Staff will no longer expect cash from you at ' + PAY.cash.where + '.',
              { title: 'Cancel your cash payment?', okLabel: 'Yes, cancel cash', cancelLabel: 'Keep paying cash' }))) return;
        const fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('booking', PAY.ref);
        fd.append('method', 'cancel_cash');
        state.sending = true; state.error = null; render();
        try {
          const res = await fetch(SUBMIT_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
          const out = await res.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; });
          if (!out.ok) { state.sending = false; state.error = out.message || 'Nothing was changed.'; render(); return; }
          PAY.cashChosen = PAY.cashLocked = false;
          state.method = PAY.account ? 'gcash' : 'cash';
          await paySent('cash_cancelled');
        } catch (e) {
          state.sending = false;
          state.error = 'Could not reach the server, so nothing was changed.';
          render();
        }
      }

      /* The server took it: the note and the booking's new state, in place. The address
         carries the note, as it did when this was a page load. */
      async function paySent(sent) {
        const url = PAY_URL + '?booking=' + encodeURIComponent(PAY.ref) + '&sent=' + sent;
        history.replaceState(null, '', url);
        await Live.refresh(url);
        state.sending = false; state.ocr = null; state.agreeExact = false; state.error = null;
        render();
      }

      /* Send it FOR REAL. The server recomputes the verdict against the database
         and has the final word; the page only moves on its answer. */
      async function submitPayment() {
        if (state.sending) return;
        const cash = state.method === 'cash';
        if (!cash && !receiptOk()) return;
        const fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('booking', PAY.ref);
        fd.append('method', cash ? 'cash' : 'gcash');
        if (!cash) {
          const rec = state.ocr.rec, p = rec.parsed || {};
          fd.append('receipt', state.ocr.file, state.ocr.fileName || 'receipt.jpg');
          fd.append('ref', p.ref || '');
          fd.append('receiver_name', p.receiverNameMasked || p.receiverNameShort || '');
          fd.append('receiver_number', p.receiverNumber || '');
          fd.append('amount_centavos', p.effAmountC != null ? p.effAmountC : '');
          fd.append('receipt_datetime', p.datetime || '');
          fd.append('confidence', rec.confidence != null ? rec.confidence : '');
          fd.append('flags', JSON.stringify(rec.flags || []));
          fd.append('text_excerpt', rec.textExcerpt || '');
        }
        state.sending = true; state.error = null; render();
        try {
          const res = await fetch(SUBMIT_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
          const out = await res.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; });
          /* The server counts every upload, rejected ones too — take its numbers. */
          if (typeof out.attemptsUsed === 'number') PAY.used = out.attemptsUsed;
          if (typeof out.attemptsAllowed === 'number') PAY.allowed = out.attemptsAllowed;
          if (!out.ok) {
            state.sending = false;
            state.error = out.message || 'Your payment was not recorded.';
            /* The server's rejection outranks the browser's preview. */
            if (out.verdict === 'rejected' && state.ocr && state.ocr.rec) { state.ocr.rec.status = 'rejected'; }
            render(); return;
          }
          /* ok + rejected = the LAST attempt was refused but went to staff anyway. */
          const sent = cash ? 'cash' : (out.verdict === 'rejected' ? 'limit' : (out.verdict === 'accepted' ? 'accepted' : 'manual_review'));
          if (cash) { PAY.cashChosen = PAY.cashLocked = true; }
          await paySent(sent);
        } catch (e) {
          state.sending = false;
          state.error = 'Could not reach the server, so your payment was not recorded.';
          render();
        }
      }

      render();
    
