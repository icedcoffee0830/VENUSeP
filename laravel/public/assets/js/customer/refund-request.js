/* Script for resources/views/customer/refund-request.blade.php. */
    

      document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('rr-form');
        if (!form) return;                       // refusal page — nothing to wire
        const errorBox = document.getElementById('rr-error');
        const reason = document.getElementById('rr-reason');
        const details = document.getElementById('rr-details');
        const counter = document.getElementById('rr-count');
        const confirmBox = document.getElementById('rr-confirm');
        const done = document.getElementById('rr-done');
        const MIN_DETAIL = 20;
        const gcashField = document.getElementById('rr-gcash');   /* GCash bookings only */

        /* Same normalisation the server uses (cb_normalise_mobile). Both sides
           compare NUMBERS, never strings, so "0917 555 0123" and "09175550123"
           are never reported as different. */
        function normMobile(raw) {
          let d = String(raw || "").replace(/\D+/g, "");
          if (d.length === 12 && d.slice(0, 2) === '63') d = '0' + d.slice(2);
          if (d.length === 10 && d.charAt(0) === '9')    d = '0' + d;
          return (d.length === 11 && d.slice(0, 2) === '09') ? d : null;
        }

        /* The reference is read back from the query string the server already
           validated, so the form never has to carry it a second time. */
        const bookingRef = new URLSearchParams(window.location.search).get('booking');

        /* What has already happened to this booking's refund, if anything.
           open     -> a second request must not be filed; offer withdrawal instead
           fix      -> staff sent it back; show what to correct and let them resubmit
           denied   -> final, nothing more to file
           refunded -> done, nothing more to file */
        const existing = RR_EXISTING;
        if (existing && existing.status === 'open') {
          form.hidden = true;
          const lede = document.querySelector('.rr-lede'); if (lede) lede.hidden = true;
          const already = document.getElementById('rr-already');
          if (already) already.hidden = false;
          return;
        }
        if (existing && (existing.status === 'denied' || existing.status === 'refunded')) {
          form.hidden = true;
          const lede = document.querySelector('.rr-lede'); if (lede) lede.hidden = true;
          const closed = document.getElementById('rr-closed');
          if (closed) {
            document.getElementById('rr-closed-title').textContent =
              existing.status === 'denied' ? 'This refund request was denied' : 'This booking has already been refunded';
            document.getElementById('rr-closed-body').textContent =
              existing.status === 'denied'
                ? 'Staff denied the request' + (existing.staffNote ? ': ' + existing.staffNote : '.') + ' A denial is final — to use the venue you would need to make a new booking. Your original booking was not affected by the request.'
                : 'The refund was paid out' + (existing.decidedAt ? ' on ' + existing.decidedAt : "") + ', so the booking is now closed and its date has been released. Your proof of payment is below — keep the reference number.';
            /* proof of payout, refunded requests only */
            const proof = existing.status === 'refunded' ? (existing.proof || null) : null;
            if (proof) {
              const box = document.getElementById('rr-proof');
              document.getElementById('rr-proof-ref').textContent = proof.reference || 'not recorded';
              document.getElementById('rr-proof-amt').textContent = proof.amount || '—';
              document.getElementById('rr-proof-to').textContent  = proof.to || '—';
              if (box) box.hidden = false;
            }
            closed.hidden = false;
          }
          return;
        }
        if (existing && existing.status === 'fix') {
          const notice = document.getElementById('rr-fix');
          if (notice) {
            document.getElementById('rr-fix-note').textContent = existing.staffNote || "";
            notice.hidden = false;
          }
          /* pre-fill what they told us last time so they only fix the document */
          if (existing.reason) { reason.value = existing.reason; }
          if (existing.details) { details.value = existing.details; }
          /* keep the destination they gave last time — they are fixing a document,
             not re-choosing where the money goes */
          if (existing.refundTo && gcashField) { gcashField.value = existing.refundTo; }
        }

        /* A document row is satisfied EITHER by attaching the file, or — for the
           Official Receipt only — by ticking "I do not have this yet". Required
           rows need the file; the optional one needs a decision either way, so
           nobody submits having simply skipped past it. */
        const docRows = Array.from(document.querySelectorAll('.rr-doc'));
        const markRow = function (row) {
          const icon = row.querySelector('i');
          if (row.dataset.filled === '1') icon.className = 'bi bi-check-circle-fill';
          else if (row.dataset.pending === '1') icon.className = 'bi bi-clock-history';
          else icon.className = 'bi bi-paperclip';
        };
        docRows.forEach(function (row) {
          const file = row.querySelector('input[type="file"]');
          const nohave = row.querySelector('.rr-nohave');
          file.addEventListener('change', function () {
            row.dataset.filled = file.files && file.files.length ? '1' : '0';
            if (row.dataset.filled === '1' && nohave) { nohave.checked = false; row.dataset.pending = '0'; }
            markRow(row);
          });
          if (nohave) {
            nohave.addEventListener('change', function () {
              row.dataset.pending = nohave.checked ? '1' : '0';
              if (nohave.checked) { file.value = null; row.dataset.filled = '0'; }
              markRow(row);
            });
          }
        });

        details.addEventListener('input', function () {
          const left = MIN_DETAIL - details.value.trim().length;
          counter.textContent = left > 0 ? left + ' more characters needed.' : 'Looks good.';
        });

        const fail = function (message) {
          errorBox.textContent = message;
          errorBox.setAttribute('open', 'open');
          window.scrollTo({ top: 0, behavior: 'smooth' });
        };

        form.addEventListener('submit', function (event) {
          event.preventDefault();
          errorBox.removeAttribute('open');
          if (!reason.value) return fail('Choose a reason for the refund.');
          if (details.value.trim().length < MIN_DETAIL) return fail('Please describe what happened in at least ' + MIN_DETAIL + ' characters — staff read this before deciding.');
          const missingRequired = docRows.filter(function (row) { return row.dataset.optional === '0' && row.dataset.filled !== '1'; });
          if (missingRequired.length) return fail('Attach the documents needed to file this request. Still missing: ' + missingRequired.map(function (row) { return row.dataset.label; }).join(', ') + '.');
          const undecided = docRows.filter(function (row) { return row.dataset.optional === '1' && row.dataset.filled !== '1' && row.dataset.pending !== '1'; });
          if (undecided.length) return fail('For the ' + undecided[0].dataset.label + ', either attach it or tick "I do not have this yet".');
          if (!confirmBox.checked) return fail('Tick the box to confirm you understand how your request will be handled.');
          let refundTo = null;
          if (gcashField) {
            refundTo = normMobile(gcashField.value);
            if (!refundTo) { gcashField.focus(); return fail('Enter a valid GCash number in the form 09XX XXX XXXX — this is where your refund will be sent.'); }
          }

          /* File it FOR REAL. The booking is NOT cancelled — this records that a
             request exists, which is what the history page and the staff queue
             both read. It used to go to includes/refund-store.php, browser
             storage that no staff member on another machine could ever see. */
          const orStillOwed = docRows.some(function (row) {
            return row.dataset.optional === '1' && row.dataset.pending === '1';
          });

          const fd = new FormData();
          fd.append('_token', CSRF);
          fd.append('booking', bookingRef);
          fd.append('action', RR_RESUBMIT ? 'resubmit' : 'file');
          fd.append('reason_category', reason.value);
          fd.append('reason', details.value.trim());
          fd.append('refund_to', refundTo || '');
          fd.append('or_pending', orStillOwed ? '1' : '0');
          docRows.forEach(function (row) {
            const f = row.querySelector('input[type="file"]');
            if (f && f.files && f.files[0]) fd.append('support[]', f.files[0], f.files[0].name);
          });

          const btn = form.querySelector('button[type="submit"]');
          if (btn) { btn.disabled = true; }
          fetch(refundRequestPage.refundSubmitUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
            .then(function (out) {
              if (btn) { btn.disabled = false; }
              if (!out.ok) { return fail(out.message || 'Your request was not filed.'); }
              if (orStillOwed) { const note = document.getElementById('rr-done-or'); if (note) note.hidden = false; }
              form.hidden = true;
              document.querySelector('.rr-lede').hidden = true;
              done.hidden = false;
              window.scrollTo({ top: 0, behavior: 'smooth' });
            })
            .catch(function () {
              if (btn) { btn.disabled = false; }
              fail('Could not reach the server, so your request was not filed.');
            });
        });
      });
    
