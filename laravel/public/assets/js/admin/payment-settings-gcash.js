/* Script for resources/views/admin/payment-settings.blade.php. */
      function psDigits(s) { return String(s).replace(/\D+/g, ''); }
      function psPretty(s) { return psDigits(s).replace(/^(\d{4})(\d{3})(\d{4})$/, '$1 $2 $3'); }
      function psValid(s) { const d = psDigits(s); return d.length === 11 && d.startsWith('09'); }
      function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

      function psField(card, f) { return card.querySelector('[data-f="' + f + '"]'); }

      function psEdit(btn) {
        const card = btn.closest('.ps-card');
        if (card.querySelector('.ps-control')) return;          // already editing
        const name = psField(card, 'name'), number = psField(card, 'number');
        card.dataset.oldName = name.textContent;
        card.dataset.oldNumber = number.textContent;

        name.innerHTML = '<input class="ps-control" data-i="name" value="' + esc(card.dataset.oldName) + '" placeholder="Name exactly as it shows on GCash">';
        number.innerHTML = '<input class="ps-control" data-i="number" value="' + esc(card.dataset.oldNumber) + '" placeholder="09xx xxx xxxx" inputmode="numeric">'
          + '<div class="ps-err" data-e hidden></div>';

        card.querySelector('.ps-foot').innerHTML =
          '<span class="ps-saved" data-s hidden>Saved</span>'
          + '<button type="button" class="ps-btn" onclick="psCancel(this)">Cancel</button>'
          + '<button type="button" class="ps-btn ps-btn-primary" onclick="psSave(this)">Save</button>';

        const eff = document.createElement('div');
        eff.className = 'ps-effect';
        eff.dataset.eff = '1';
        eff.textContent = 'Changing this changes what customers are told to pay AND what the checker accepts. Existing unpaid bookings for this venue will expect the new account.';
        card.querySelector('.ps-foot').before(eff);
        card.querySelector('[data-i="name"]').focus();
      }

      function psCancel(btn) {
        const card = btn.closest('.ps-card');
        psRestore(card, card.dataset.oldName, card.dataset.oldNumber, false);
      }

      async function psSave(btn) {
        const card = btn.closest('.ps-card');
        const name = card.querySelector('[data-i="name"]').value.trim();
        const raw = card.querySelector('[data-i="number"]').value;
        const err = card.querySelector('[data-e]');

        /* Refuse to save something the checker could never match. Saving a
           malformed number would not error anywhere — it would just reject
           every receipt for this venue, quietly. */
        if (!name) { err.textContent = 'The account name cannot be empty — the checker matches it when the number is masked on a receipt.'; err.hidden = false; return; }
        if (!psValid(raw)) { err.textContent = 'Needs 11 digits starting with 09. The checker compares digits, so a number in any other shape rejects every receipt.'; err.hidden = false; return; }

        /* This is where the venue's money lands, so the server re-asks for the
           password before it will move — same treatment as the refund switch,
           and for a stronger reason: anyone with a borrowed admin session could
           otherwise point a venue's payments at their own number. The field is
           a real password input — the browser's prompt() showed it as typed. */
        const pw = await venusepPrompt(
          'Every future payment for ' + card.dataset.venue + ' will go to ' + psPretty(raw) + ', and receipts will be checked against it.',
          { title: 'Change where ' + card.dataset.venue + ' is paid?', label: 'Your admin password', inputType: 'password',
            autocomplete: 'current-password', okLabel: 'Confirm change', danger: true });
        if (pw === null) return;                /* cancelled — nothing changes */

        err.hidden = true;
        const body = new URLSearchParams({
          _token: PS_CSRF, venue: card.dataset.venue,
          account_name: name, mobile_number: psDigits(raw), password: pw
        });
        fetch(paymentSettingsPage.gcashAccountSaveUrl, { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
          .then(function (res) {
            if (!res.ok) {
              err.textContent = res.message + (res.attemptsLeft != null ? ' ' + res.attemptsLeft + ' attempt(s) left.' : '');
              err.hidden = false;
              return;
            }
            psRestore(card, name, psPretty(raw), true);
          })
          .catch(function () { err.textContent = 'Could not reach the server, so nothing was changed.'; err.hidden = false; });
      }

      function psRestore(card, name, number, saved) {
        psField(card, 'name').textContent = name;
        psField(card, 'number').textContent = number;
        const eff = card.querySelector('[data-eff]');
        if (eff) eff.remove();
        card.querySelector('.ps-foot').innerHTML =
          (saved ? '<span class="ps-saved">Saved</span>' : '')
          + '<button type="button" class="ps-btn" onclick="psEdit(this)">Edit</button>';
      }
    
