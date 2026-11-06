/* Script for resources/views/auth/customer-login.blade.php. */
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
            if (icon) {
              icon.classList.toggle('bi-eye', !show);
              icon.classList.toggle('bi-eye-slash', show);
            }
          });
        });

        /* "Remember me" applies to Google sign-in too: the box sits in the password
           form, so carry its state onto the Google link when it is clicked. */
        const googleSignIn = document.getElementById('googleSignIn');
        const rememberBox = document.getElementById('rememberCustomer');
        if (googleSignIn && rememberBox) {
          googleSignIn.addEventListener('click', function () {
            googleSignIn.href = customerLoginPage.googleStartUrl + (rememberBox.checked ? '?remember=1' : '');
          });
        }

        const form = document.getElementById('customerLoginForm');
        if (!form) return;

        form.querySelectorAll('input').forEach(function (field) {
          field.addEventListener(field.type === 'checkbox' ? 'change' : 'input', function () {
            const messageId = field.name === 'email'
              ? 'emailValidation'
              : field.name === 'password'
                ? 'passwordValidation'
                : null;

            if (messageId) setFieldState(field, messageId, '');
          });
        });

        form.addEventListener('submit', function (event) {
          const email = form.querySelector('[name="email"]');
          const password = form.querySelector('[name="password"]');
          let hasError = false;

          const passwordError = password.value ? '' : 'Please enter your password.';
          setFieldState(password, 'passwordValidation', passwordError);
          hasError = hasError || Boolean(passwordError);

          const emailError = email.value.trim() && email.checkValidity()
            ? ''
            : 'Please enter a valid email address.';
          setFieldState(email, 'emailValidation', emailError);
          hasError = hasError || Boolean(emailError);

          if (hasError) {
            event.preventDefault();
            const firstInvalid = form.querySelector('[aria-invalid="true"]');
            if (firstInvalid) firstInvalid.focus();
          }
          // If valid, allow the browser to POST to this PHP page.
        });
      });
    
