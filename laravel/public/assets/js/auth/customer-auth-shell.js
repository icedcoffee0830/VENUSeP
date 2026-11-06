/* Script for resources/views/auth/customer-auth-shell.blade.php. */
      /* show / hide a password, same as the login page */
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
    
