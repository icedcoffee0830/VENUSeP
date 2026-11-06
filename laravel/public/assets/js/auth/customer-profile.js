/* Script for resources/views/auth/customer-profile.blade.php. */
      document.addEventListener('DOMContentLoaded', function () {
        const setFieldError = function (field, messageId, message) {
          const group = field.closest('.profile-form-group');
          if (group) group.classList.toggle('is-invalid', Boolean(message));
          field.setAttribute('aria-invalid', message ? 'true' : 'false');
          const el = document.getElementById(messageId);
          if (el) el.textContent = message;
        };

        /* on the document: the Account Security section is redrawn after a change */
        document.addEventListener('click', function (e) {
          const toggle = e.target.closest('[data-profile-password-toggle]');
          if (!toggle) return;
          const input = document.getElementById(toggle.dataset.profilePasswordToggle);
          if (!input) return;
          const showing = input.type === 'password';
          input.type = showing ? 'text' : 'password';
          toggle.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
          toggle.setAttribute('aria-pressed', showing ? 'true' : 'false');
          const icon = toggle.querySelector('i');
          if (icon) { icon.classList.toggle('bi-eye', !showing); icon.classList.toggle('bi-eye-slash', showing); }
        });

        const CSRF = customerProfilePage.csrf;

        /* ---- profile picture ----
           A PICTURE ONLY. It is stored under assets/, which the web server
           hands to anyone with the URL — right for a photo someone chose to
           show, and the reason IDs and receipts go somewhere else entirely
           (booking_documents, outside the web root, behind document-view.php). */
        const avatarInput = document.getElementById('avatarInput');
        const avatarMsg = document.getElementById('avatarMessage');
        const sendAvatar = function (body) {
          avatarMsg.textContent = 'Uploading…';
          fetch(customerProfilePage.profileSaveUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
            .then(function (res) {
              avatarMsg.textContent = res.message || '';
              /* the summary, its Remove button and the header chip all come from the server */
              if (res.ok) Live.refresh(null, ['hd-user', 'cp-avatar', 'cp-avatar-remove']);
            })
            .catch(function () { avatarMsg.textContent = 'Could not reach the server.'; });
        };
        if (avatarInput) {
          avatarInput.addEventListener('change', function () {
            if (!avatarInput.files || !avatarInput.files[0]) return;
            const body = new FormData();
            body.append('_token', CSRF);
            body.append('action', 'photo');
            body.append('photo', avatarInput.files[0], avatarInput.files[0].name);
            avatarInput.value = '';
            sendAvatar(body);
          });
        }
        document.addEventListener('click', function (e) {
          if (!e.target.closest('#avatarRemove')) return;
          const body = new FormData();
          body.append('_token', CSRF);
          body.append('action', 'photo');
          body.append('remove', '1');
          sendAvatar(body);
        });

        const profileForm = document.getElementById('customerProfileForm');
        if (profileForm) {
          const profileFields = [
            ['fullName', 'fullNameValidation', 'Please enter your full name.'],
            ['email', 'emailValidation', 'Please enter a valid email address.'],
            ['contactNumber', 'contactNumberValidation', 'Please enter your contact number.'],
            ['address', 'addressValidation', 'Please enter your address.'],
          ];
          profileForm.addEventListener('submit', function (event) {
            event.preventDefault();
            let hasError = false;
            profileFields.forEach(function (cfg) {
              const field = document.getElementById(cfg[0]);
              if (!field) return;
              let message = '';
              if (cfg[0] === 'email') message = field.value.trim() && field.checkValidity() ? '' : cfg[2];
              else message = field.value.trim() ? '' : cfg[2];
              setFieldError(field, cfg[1], message);
              hasError = hasError || Boolean(message);
            });
            if (hasError) { document.getElementById('profileSuccessMessage').textContent = ''; return; }

            /* Save FOR REAL. The per-field checks above stay for fast feedback;
               the server repeats them and owns the one the browser cannot
               answer — whether the new email is already another account's. */
            const body = new FormData(profileForm);
            body.append('_token', CSRF);
            body.append('action', 'details');
            const out = document.getElementById('profileSuccessMessage');
            out.textContent = 'Saving…';
            fetch(customerProfilePage.profileSaveUrl, { method: 'POST', body: body, credentials: 'same-origin' })
              .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
              .then(function (res) {
                if (!res.ok) {
                  out.textContent = '';
                  const map = { full_name: ['fullName', 'fullNameValidation'], email: ['email', 'emailValidation'],
                                contact_number: ['contactNumber', 'contactNumberValidation'],
                                current_password: ['emailPassword', 'emailPasswordValidation'] };
                  const t = res.field && map[res.field];
                  const f = t && document.getElementById(t[0]);
                  if (f) { setFieldError(f, t[1], res.message); f.focus(); }
                  else { out.textContent = res.message || 'Nothing was saved.'; }
                  return;
                }
                out.textContent = res.message;
                /* The saved email is the new "current" one: the password field goes away again. */
                const em = document.getElementById('email');
                em.dataset.original = em.value.trim().toLowerCase();
                const ep = document.getElementById('emailPassword');
                if (ep) ep.value = '';
                toggleEmailPassword();
                /* Repaint the summary card beside the form — leaving the old
                   name there after a rename would look like the save failed. */
                document.getElementById('profileSummaryName').textContent = document.getElementById('fullName').value.trim();
                Live.refresh(null, ['hd-user']);   /* the header chip follows the name */
              })
              .catch(function () { out.textContent = 'Could not reach the server, so nothing was saved.'; });
          });
          profileForm.addEventListener('input', function (e) { setFieldError(e.target, e.target.getAttribute('aria-describedby'), ''); });
          /* A new email needs the current password: show that field only while the email differs. */
          function toggleEmailPassword() {
            const group = document.getElementById('emailPasswordGroup');
            const em = document.getElementById('email');
            if (group) group.hidden = em.value.trim().toLowerCase() === em.dataset.original;
          }
          document.getElementById('email').addEventListener('input', toggleEmailPassword);
          profileForm.addEventListener('reset', function () {
            window.setTimeout(function () {
              toggleEmailPassword();
              const ep = document.getElementById('emailPassword');
              if (ep) setFieldError(ep, 'emailPasswordValidation', '');
              profileFields.forEach(function (cfg) { const f = document.getElementById(cfg[0]); if (f) setFieldError(f, cfg[1], ''); });
              document.getElementById('profileSuccessMessage').textContent = '';
            }, 0);
          });
        }

        /* The section again from the server, and its two forms wired up again. */
        const redrawSecurity = function () { window.tfaRedraw(); return Live.refresh(null, ['cp-security', 'cp-email-password']).then(bindSecurity); };
        function bindSecurity() {
        const passwordForm = document.getElementById('customerPasswordForm');
        const cur0 = document.getElementById('currentPassword');
        if (passwordForm) {
          passwordForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const cur = document.getElementById('currentPassword');   // absent when SETTING a first password (#23)
            const nw = document.getElementById('newPassword');
            const cf = document.getElementById('confirmNewPassword');
            const any = Boolean((cur && cur.value) || nw.value || cf.value);
            const curErr = cur && any && !cur.value ? 'Please enter your current password.' : '';
            const nwErr = any && !nw.value ? 'Please enter a new password.' : (nw.value && nw.value.length < 8 ? 'New password must be at least 8 characters.' : '');
            const cfErr = any && !cf.value ? 'Please confirm your new password.' : (nw.value !== cf.value ? 'New passwords must match.' : '');
            if (cur) setFieldError(cur, 'currentPasswordValidation', curErr);
            setFieldError(nw, 'newPasswordValidation', nwErr);
            setFieldError(cf, 'confirmNewPasswordValidation', cfErr);
            if (curErr || nwErr || cfErr || !any) return;

            /* The CURRENT password is verified server-side — a session alone
               must never be enough to change the credential that created it,
               or anyone at an unlocked machine could lock the owner out. */
            const body = new FormData(passwordForm);
            body.append('_token', CSRF);
            body.append('action', 'password');
            const out = document.getElementById('passwordSuccessMessage');
            out.textContent = 'Saving…';
            fetch(customerProfilePage.profileSaveUrl, { method: 'POST', body: body, credentials: 'same-origin' })
              .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
              .then(function (res) {
                if (!res.ok) {
                  out.textContent = '';
                  const map = { current_password: [cur, 'currentPasswordValidation'],
                                new_password: [nw, 'newPasswordValidation'],
                                confirm_new_password: [cf, 'confirmNewPasswordValidation'] };
                  const t = res.field && map[res.field];
                  if (t && t[0]) { setFieldError(t[0], t[1], res.message); t[0].focus(); }
                  else { out.textContent = res.message || 'Your password was not changed.'; }
                  /* The Google confirmation ran out: the section offers it again. */
                  if (res.reauth) window.setTimeout(redrawSecurity, 2500);
                  return;
                }
                out.textContent = res.message;
                passwordForm.reset();
                /* A FIRST password changes what this section offers (Change, Disconnect). */
                if (!cur) window.setTimeout(redrawSecurity, 1500);
              })
              .catch(function () { out.textContent = 'Could not reach the server, so your password was not changed.'; });
          });
          ['currentPassword', 'newPassword', 'confirmNewPassword'].forEach(function (id) {
            const field = document.getElementById(id);
            if (!field) return;
            field.addEventListener('input', function () {
              if (cur0) setFieldError(cur0, 'currentPasswordValidation', '');
              setFieldError(document.getElementById('newPassword'), 'newPasswordValidation', '');
              setFieldError(document.getElementById('confirmNewPassword'), 'confirmNewPasswordValidation', '');
              document.getElementById('passwordSuccessMessage').textContent = '';
            });
          });
        }

        /* ---- Google (#23): Connect / Disconnect, each after re-typing the password.
           The server re-checks everything; connecting then leaves for Google. ---- */
        const googlePanel = document.querySelector('[data-google-panel]');
        const googleBox = googlePanel && googlePanel.querySelector('.google-confirm');
        if (googleBox) {
          const pw = document.getElementById('googlePassword');
          const msg = document.getElementById('googlePasswordMessage');
          const go = googlePanel.querySelector('[data-google-go-button]');
          const starters = googlePanel.querySelectorAll('[data-google-action]');
          let action = null;
          const close = function () {
            googleBox.hidden = true; pw.value = ''; setFieldError(pw, 'googlePasswordMessage', '');
            starters.forEach(function (b) { b.hidden = false; });
          };
          starters.forEach(function (btn) {
            btn.addEventListener('click', function () {
              action = btn.dataset.googleAction;
              go.textContent = btn.dataset.googleGo;
              btn.hidden = true;
              googleBox.hidden = false;
              pw.focus();
            });
          });
          googlePanel.querySelector('[data-google-cancel]').addEventListener('click', close);
          pw.addEventListener('input', function () { setFieldError(pw, 'googlePasswordMessage', ''); });
          pw.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go.click(); } });
          go.addEventListener('click', function () {
            if (!pw.value) { setFieldError(pw, 'googlePasswordMessage', 'Enter your password.'); pw.focus(); return; }
            const body = new FormData();
            body.append('_token', CSRF);
            body.append('action', action);
            body.append('password', pw.value);
            go.disabled = true;
            msg.textContent = '';
            fetch(customerProfilePage.profileSaveUrl, { method: 'POST', body: body, credentials: 'same-origin' })
              .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
              .then(function (res) {
                if (res.ok && res.redirect) { window.location.href = res.redirect; return; }   // off to Google
                go.disabled = false;
                if (!res.ok) { setFieldError(pw, 'googlePasswordMessage', res.message || 'Nothing was changed.'); pw.focus(); return; }
                msg.style.color = 'var(--ok)';
                msg.textContent = res.message;
                window.setTimeout(redrawSecurity, 1500);
              })
              .catch(function () { go.disabled = false; setFieldError(pw, 'googlePasswordMessage', 'Could not reach the server, so nothing was changed.'); });
          });
        }
        }
        bindSecurity();
      });
    
