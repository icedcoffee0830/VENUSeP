/* Script for resources/views/auth/admin-profile.blade.php. */
            (function () {
              const form = document.getElementById('vpPwForm');
              const out = document.getElementById('vpPwMessage');
              if (!form) return;
              const setShown = function (toggle, show) {
                document.getElementById(toggle.dataset.passwordToggle).type = show ? 'text' : 'password';
                toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
                toggle.querySelector('i').className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye');
              };
              const toggles = form.querySelectorAll('[data-password-toggle]');
              toggles.forEach(function (toggle) {
                toggle.addEventListener('click', function () { setShown(toggle, toggle.getAttribute('aria-pressed') !== 'true'); });
              });
              form.addEventListener('submit', function (e) {
                e.preventDefault();
                const body = new FormData(form);
                body.append('_token', adminProfilePage.csrf);
                body.append('action', 'password');
                out.style.color = '#6b675f'; out.textContent = 'Saving…';
                fetch(adminProfilePage.profileSaveUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                  .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
                  .then(function (res) {
                    out.style.color = res.ok ? '#1c7a4f' : '#b23a3a';
                    out.textContent = res.message || (res.ok ? 'Your password was changed.' : 'Your password was not changed.');
                    if (!res.ok) {
                      const field = res.field && form.querySelector('[name="' + res.field + '"]');
                      if (field) field.focus();
                      return;
                    }
                    form.reset();
                    toggles.forEach(function (toggle) { setShown(toggle, false); });
                    if (adminProfilePage.vpMustChange) location.replace(adminProfilePage.dashboardUrl);   // the password is their own now: on to work
                  })
                  .catch(function () { out.style.color = '#b23a3a'; out.textContent = 'Could not reach the server.'; });
              });
            })();
          

            /* Saves to the same profile-save.php the customer portal uses — it
               works out WHICH profile row from the session, so neither side can
               edit the other's. The bio field was dropped: there is no column
               for it and nothing displays one. */
            (function () {
              const form = document.getElementById('vpForm');
              const out = document.getElementById('vpMessage');
              if (!form) return;
              const email = document.getElementById('profile-email');
              const emailPw = document.getElementById('profile-email-pw');
              /* A new email needs the current password: that field shows only while the email differs. */
              function toggleEmailPw() {
                document.getElementById('profile-email-pw-field').hidden = email.value.trim().toLowerCase() === email.dataset.original;
              }
              email.addEventListener('input', toggleEmailPw);
              form.addEventListener('reset', function () { window.setTimeout(toggleEmailPw, 0); });
              form.addEventListener('submit', function (e) {
                e.preventDefault();
                const name = (document.getElementById('profile-first').value.trim() + ' ' +
                              document.getElementById('profile-last').value.trim()).trim();
                if (!name) { out.style.color = '#b23a3a'; out.textContent = 'Enter your name.'; return; }
                const body = new URLSearchParams({
                  _token: adminProfilePage.csrf,
                  action: 'details',
                  full_name: name,
                  email: email.value.trim(),
                  current_password: emailPw.value,
                  contact_number: document.getElementById('profile-number').value.trim()
                });
                out.style.color = '#6b675f'; out.textContent = 'Saving…';
                fetch(adminProfilePage.profileSaveUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                  .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
                  .then(function (res) {
                    out.style.color = res.ok ? '#1c7a4f' : '#b23a3a';
                    out.textContent = res.message || (res.ok ? 'Saved.' : 'Nothing was saved.');
                    if (!res.ok && res.field === 'current_password') emailPw.focus();
                    if (res.ok) {
                      email.dataset.original = email.value.trim().toLowerCase();   // the saved email is the current one now
                      emailPw.value = '';
                      toggleEmailPw();
                      /* the card follows the saved name at once — initials too,
                         unless a photo stands in for them */
                      document.getElementById('vpCardName').textContent = name;
                      const av = document.getElementById('vpAvatar');
                      if (av && av.tagName !== 'IMG') av.textContent = vpInitials(name);
                      Live.refresh(null, ['hd-user']);   /* the header chip */
                    }
                  })
                  .catch(function () { out.style.color = '#b23a3a'; out.textContent = 'Could not reach the server.'; });
              });
            })();

            function vpInitials(name) {
              const p = String(name || '').trim().split(/\s+/);
              return ((p[0] || '').charAt(0) + (p[1] || '').charAt(0)).toUpperCase() || '?';
            }

            /* The profile picture — profile-save.php action=photo, which validates
               it as a real image (same rules as room photos). The card swaps to
               the new picture without a reload. */
            (function () {
              const input = document.getElementById('vpPhotoInput');
              const remove = document.getElementById('vpPhotoRemove');
              const msg = document.getElementById('vpPhotoMsg');
              if (!input) return;
              const CSRF = adminProfilePage.csrf;
              function show(photo) {
                const old = document.getElementById('vpAvatar');
                let el;
                if (photo) {
                  el = document.createElement('img');
                  el.className = 'profile-avatar profile-avatar-img';
                  el.alt = 'Your profile picture';
                  el.src = '../' + photo + '?v=' + Date.now();      // a new file under the same name
                } else {
                  el = document.createElement('div');
                  el.className = 'profile-avatar';
                  el.setAttribute('aria-hidden', 'true');
                  el.textContent = vpInitials(document.getElementById('vpCardName').textContent);
                }
                el.id = 'vpAvatar';
                old.replaceWith(el);
                remove.hidden = !photo;
                document.querySelector('.profile-photo-btn').textContent = photo ? 'Change photo' : 'Add photo';
              }
              function send(body, busy) {
                body.append('_token', CSRF);
                body.append('action', 'photo');
                msg.style.color = '#6b675f'; msg.textContent = busy;
                return fetch(adminProfilePage.profileSaveUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                  .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
                  .then(function (res) {
                    msg.style.color = res.ok ? '#1c7a4f' : '#b23a3a';
                    msg.textContent = res.message || (res.ok ? 'Saved.' : 'Nothing was changed.');
                    if (res.ok) { show(res.photo); Live.refresh(null, ['hd-user']); }
                  })
                  .catch(function () { msg.style.color = '#b23a3a'; msg.textContent = 'Could not reach the server.'; });
              }
              input.addEventListener('change', function () {
                const file = input.files && input.files[0];
                input.value = '';
                if (!file) return;
                const body = new FormData();
                body.append('photo', file, file.name);
                send(body, 'Uploading…');
              });
              remove.addEventListener('click', async function () {
                if (!(await venusepConfirm('Your initials will show instead.', { title: 'Remove your photo?', okLabel: 'Remove', danger: true }))) return;
                const body = new FormData();
                body.append('remove', '1');
                send(body, 'Removing…');
              });
            })();
          
