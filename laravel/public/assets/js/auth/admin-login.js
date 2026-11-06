/* Script for resources/views/auth/admin-login.blade.php. */
      document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('adminLoginForm');

        const setMessage = function (id, message) {
          const el = document.getElementById(id);
          if (el) el.textContent = message;
        };

        const setFieldState = function (field, messageId, message) {
          if (!field) return;
          let target = field;
          if (field.matches('[type="radio"]')) {
            target = field.closest('.department-options');
          } else if (field.type !== 'checkbox') {
            target = field.closest('.input-group');
          }
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

        if (!form) return;

        form.querySelectorAll('input').forEach(function (field) {
          field.addEventListener(
            field.type === 'checkbox' || field.type === 'radio' ? 'change' : 'input',
            function () {
              setFieldState(field, field.getAttribute('aria-describedby'), '');
            }
          );
        });

        /* An administrator has no department to pick: the cards switch off (a disabled radio is not sent). */
        const asAdmin = form.querySelector('[name="as_admin"]');
        if (asAdmin) {
          asAdmin.addEventListener('change', function () {
            form.querySelector('.department-options').classList.toggle('is-off', asAdmin.checked);
            form.querySelectorAll('[name="department"]').forEach(function (radio) {
              radio.disabled = asAdmin.checked;
              if (asAdmin.checked) radio.checked = false;
            });
            setFieldState(form.querySelector('[name="department"]'), 'departmentValidation', '');
          });
        }

        // The browser validates the fields; PHP performs the real authentication.
        form.addEventListener('submit', function (event) {
          const email = form.querySelector('[name="email"]');
          const password = form.querySelector('[name="password"]');
          const department = form.querySelector('[name="department"]');
          let hasError = false;

          setMessage('loginSuccessMessage', '');
          const loginMessage = document.getElementById('loginSuccessMessage');
          if (loginMessage) loginMessage.classList.remove('is-error');

          const selectedDepartment = form.querySelector('[name="department"]:checked');
          if (department && !(asAdmin && asAdmin.checked)) {
            const error = selectedDepartment ? '' : 'Please select a department.';
            setFieldState(department, 'departmentValidation', error);
            hasError = hasError || Boolean(error);
          }

          let emailError = '';
          if (!email.value.trim()) {
            emailError = 'Please enter your email address.';
          } else if (!email.checkValidity()) {
            emailError = 'Please enter a valid email address.';
          } else if (email.value.trim().length > 100) {
            emailError = 'Email address is too long.';
          }
          setFieldState(email, 'emailValidation', emailError);
          hasError = hasError || Boolean(emailError);

          let passwordError = '';
          if (!password.value) {
            passwordError = 'Please enter your password.';
          } else if (password.value.length < 8) {
            passwordError = 'Password must contain at least 8 characters.';
          } else if (password.value.length > 64) {
            passwordError = 'Password must not exceed 64 characters.';
          }
          setFieldState(password, 'passwordValidation', passwordError);
          hasError = hasError || Boolean(passwordError);

          if (hasError) {
            event.preventDefault();
            const firstInvalid = form.querySelector('[aria-invalid="true"]');
            if (firstInvalid) firstInvalid.focus();
          }
          // Otherwise allow normal POST to this PHP page.
        });
      });
    
