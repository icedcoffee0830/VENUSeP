/* Script for resources/views/admin/admin-register.blade.php. */
      document.addEventListener('DOMContentLoaded', function () {
        const setMessage = function (id, message) {
          const el = document.getElementById(id);
          if (el) el.textContent = message;
        };
        const setFieldState = function (field, messageId, message) {
          if (!field) return;
          let target = field;
          if (field.type !== 'checkbox') target = field.closest('.input-group');
          if (target) target.classList.toggle('is-invalid', Boolean(message));
          field.setAttribute('aria-invalid', message ? 'true' : 'false');
          setMessage(messageId, message);
        };

        document.querySelectorAll('[data-password-toggle]').forEach(function (toggle) {
          toggle.addEventListener('click', function () {
            const input = document.getElementById(toggle.dataset.passwordToggle);
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            const icon = toggle.querySelector('i');
            if (icon) { icon.classList.toggle('bi-eye', !show); icon.classList.toggle('bi-eye-slash', show); }
          });
        });

        const CSRF = adminRegisterPage.csrf;

        /* Staff or Administrator: an administrator has no venue to choose. */
        function regRole() { const r = document.querySelector('[name="account_role"]:checked'); return r ? r.value : 'staff'; }
        document.querySelectorAll('[name="account_role"]').forEach(function (r) {
          r.addEventListener('change', function () {
            const admin = regRole() === 'admin';
            document.getElementById('venueGroup').hidden = admin;
            document.getElementById('groupGroup').hidden = !admin;
            document.getElementById('roleSubtitle').textContent = admin
              ? 'An administrator runs one group: its offices, staff and settings. You will confirm with your own password, and they set up two-step verification at their first sign-in.'
              : 'Staff handle one venue’s bookings. Choose which venue below.';
          });
        });
        document.querySelectorAll('[data-auth-form]').forEach(function (form) {
          form.querySelectorAll('input, select').forEach(function (field) {
            field.addEventListener(field.type === 'checkbox' || field.tagName === 'SELECT' ? 'change' : 'input', function () {
              setFieldState(field, field.getAttribute('aria-describedby'), '');
            });
          });

          form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const email = form.querySelector('[name="email"]');
            const password = form.querySelector('[name="password"]');
            let hasError = false;
            setMessage(form.dataset.successTarget, '');

            const fullName = form.querySelector('[name="full_name"]');
            const contactNumber = form.querySelector('[name="contact_number"]');
            const confirmPassword = form.querySelector('[name="confirm_password"]');
            const fullNameError = fullName.value.trim() ? '' : 'Please enter your full name.';
            const contactError = contactNumber.value.trim() ? '' : 'Please enter your contact number.';
            const passwordError = password.value.length >= 8 ? '' : 'Password must be at least 8 characters.';
            const confirmError = confirmPassword.value && confirmPassword.value === password.value ? '' : 'Passwords must match.';

            setFieldState(fullName, 'fullNameValidation', fullNameError);
            setFieldState(contactNumber, 'contactNumberValidation', contactError);
            setFieldState(password, 'passwordValidation', passwordError);
            setFieldState(confirmPassword, 'confirmPasswordValidation', confirmError);
            hasError = hasError || Boolean(fullNameError || contactError || passwordError || confirmError);
            /* every staff account handles one venue — no unassigned staff.
               An administrator handles all of them, so has none. */
            const isAdmin = regRole() === 'admin';
            const venue = form.querySelector('[name="venue_id"]');
            const venueError = isAdmin || (venue && venue.value) ? '' : 'Choose the venue this staff member will handle.';
            if (venue) setFieldState(venue, 'venueValidation', venueError);
            hasError = hasError || Boolean(venueError);
            const group = form.querySelector('[name="office_group"]');
            const groupError = !isAdmin || group.value ? '' : 'Choose the group this admin will run.';
            if (group) setFieldState(group, 'groupValidation', groupError);
            hasError = hasError || Boolean(groupError);

            const emailError = email.value.trim() && email.checkValidity() ? '' : 'Please enter a valid email address.';
            setFieldState(email, 'emailValidation', emailError);
            hasError = hasError || Boolean(emailError);

            if (hasError) {
              const firstInvalid = form.querySelector('[aria-invalid="true"]');
              if (firstInvalid) firstInvalid.focus();
              return;
            }

            /* Create the STAFF account for real. The page is already admin-only
               (admin_require_login(['admin'])), and the endpoint re-checks that
               — a page guard is not a security boundary, since this is a POST
               anyone could aim at directly.

               The admin stays signed in as themselves afterwards: they created
               an account, they did not become it. */
            /* An administrator can do everything, so creating one needs YOUR
               password — a borrowed admin session must not be enough. */
            let creatorPw = null;
            if (isAdmin) {
              creatorPw = await venusepPrompt(
                (form.querySelector('[name="full_name"]').value.trim() || 'This person') + ' will be able to change the settings, rooms, payment accounts and staff of their group’s offices.',
                { title: 'Create an administrator?', label: 'Your own admin password', inputType: 'password',
                  autocomplete: 'current-password', okLabel: 'Create administrator', danger: true });
              if (creatorPw === null) return;          /* cancelled — nothing sent */
            }
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            const body = new FormData(form);
            body.append('csrf', CSRF);
            body.append('kind', isAdmin ? 'admin' : 'staff');
            if (isAdmin) { body.delete('venue_id'); body.append('creator_password', creatorPw); } else { body.delete('office_group'); }
            fetch(adminRegisterPage.registerSubmitUrl, { method: 'POST', body: body, headers: { 'X-CSRF-TOKEN': CSRF }, credentials: 'same-origin' })
              .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
              .then(function (out) {
                if (submitBtn) submitBtn.disabled = false;
                if (!out.ok) {
                  const map = { full_name: 'fullNameValidation', email: 'emailValidation',
                                contact_number: 'contactNumberValidation', password: 'passwordValidation',
                                confirm_password: 'confirmPasswordValidation',
                                venue_id: 'venueValidation', office_group: 'groupValidation' };
                  const target = out.field && form.querySelector('[name="' + out.field + '"]');
                  if (target) { setFieldState(target, map[out.field], out.message); target.focus(); }
                  else { setMessage(form.dataset.successTarget, out.message || 'The account was not created.'); }
                  return;
                }
                setMessage(form.dataset.successTarget, isAdmin ? 'Administrator account created — they set up two-step verification at their first sign-in.' : 'Staff account created.');
                form.reset();
                document.querySelector('[name="account_role"]:checked')?.dispatchEvent(new Event('change'));
              })
              .catch(function () {
                if (submitBtn) submitBtn.disabled = false;
                setMessage(form.dataset.successTarget, 'Could not reach the server, so the account was not created.');
              });
          });
        });
      });
    
