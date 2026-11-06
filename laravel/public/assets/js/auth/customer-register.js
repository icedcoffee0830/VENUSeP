/* Script for resources/views/auth/customer-register.blade.php. */
      document.addEventListener('DOMContentLoaded', function () {
        const setMessage = function (id, message) { const el = document.getElementById(id); if (el) el.textContent = message; };
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
        const CSRF = customerRegisterPage.csrf;
        document.querySelectorAll('[data-auth-form]').forEach(function (form) {
          form.querySelectorAll('input').forEach(function (field) {
            field.addEventListener(field.type === 'checkbox' ? 'change' : 'input', function () { setFieldState(field, field.getAttribute('aria-describedby'), ''); });
            field.addEventListener('focus', function () {
              if (!window.matchMedia('(max-width: 860px)').matches) return;
              window.setTimeout(function () { field.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 250);
            });
          });
          form.addEventListener('submit', function (event) {
            event.preventDefault();
            const email = form.querySelector('[name="email"]');
            const password = form.querySelector('[name="password"]');
            let hasError = false;
            setMessage(form.dataset.successTarget, '');
            const fullName = form.querySelector('[name="full_name"]');
            const contactNumber = form.querySelector('[name="contact_number"]');
            const confirmPassword = form.querySelector('[name="confirm_password"]');
            const terms = form.querySelector('[name="terms"]');
            const fullNameError = fullName.value.trim() ? '' : 'Please enter your full name.';
            const contactError = contactNumber.value.trim() ? '' : 'Please enter your contact number.';
            const passwordError = password.value.length >= 8 ? '' : 'Password must be at least 8 characters.';
            const confirmError = confirmPassword.value && confirmPassword.value === password.value ? '' : 'Passwords must match.';
            const termsError = terms.checked ? '' : 'You must agree to the Terms and Conditions.';
            setFieldState(fullName, 'fullNameValidation', fullNameError);
            setFieldState(contactNumber, 'contactNumberValidation', contactError);
            setFieldState(password, 'passwordValidation', passwordError);
            setFieldState(confirmPassword, 'confirmPasswordValidation', confirmError);
            setFieldState(terms, 'termsValidation', termsError);
            hasError = Boolean(fullNameError || contactError || passwordError || confirmError || termsError);
            const emailError = email.value.trim() && email.checkValidity() ? '' : 'Please enter a valid email address.';
            setFieldState(email, 'emailValidation', emailError);
            hasError = hasError || Boolean(emailError);
            if (hasError) { const f = form.querySelector('[aria-invalid="true"]'); if (f) f.focus(); return; }

            /* Create the account FOR REAL. These checks above stay because they
               give fast, per-field feedback — but the server runs all of them
               again, and it owns the ones the browser cannot answer honestly:
               whether the email is already registered, and whether the password
               hashes and stores. The page only claims success once it has. */
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            const body = new FormData(form);
            body.append('_token', CSRF);
            body.append('kind', 'customer');
            fetch(customerRegisterPage.registerSubmitUrl, { method: 'POST', body: body, credentials: 'same-origin' })
              .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
              .then(function (out) {
                if (submitBtn) submitBtn.disabled = false;
                if (!out.ok) {
                  /* Put the message on the FIELD it belongs to when the server
                     names one — "that email is already registered" beside the
                     email box beats a banner the eye slides past. */
                  const map = { full_name: 'fullNameValidation', email: 'emailValidation',
                                contact_number: 'contactNumberValidation', password: 'passwordValidation',
                                confirm_password: 'confirmPasswordValidation', terms: 'termsValidation' };
                  const target = out.field && form.querySelector('[name="' + out.field + '"]');
                  if (target) { setFieldState(target, map[out.field], out.message); target.focus(); }
                  else { setMessage(form.dataset.successTarget, out.message || 'The account was not created.'); }
                  return;
                }
                setMessage(form.dataset.successTarget, form.dataset.successMessage);
                window.setTimeout(function () { window.location.href = form.dataset.redirect; }, 800);
              })
              .catch(function () {
                if (submitBtn) submitBtn.disabled = false;
                setMessage(form.dataset.successTarget, 'Could not reach the server, so the account was not created.');
              });
          });
        });
      });
    
