/* Script for resources/views/admin/backup-restore.blade.php. */
      (function () {
        const CSRF = backupRestorePage.csrf;
        function busy(text) {
          const d = document.createElement('div');
          d.className = 'br-busy';
          d.innerHTML = '<div></div>';
          d.firstChild.textContent = text;
          document.body.appendChild(d);
          return function () { d.remove(); };
        }
        function post(fields, file) {
          const body = new FormData();
          body.append('_token', CSRF);
          Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
          if (file) body.append('archive', file, file.name);
          return fetch(backupRestorePage.backupActionUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
            .catch(function () { return { ok: false, message: 'Could not reach the server.' }; });
        }

        document.getElementById('brCreate').addEventListener('click', function () {
          const done = busy('Making the backup…');
          post({ action: 'create' }).then(function (res) {
            done();
            if (!res.ok) { venusepAlert(res.message || 'The backup was not made.'); return; }
            Live.refresh();
            venusepAlert('The backup is saved on this computer.', { tone: 'ok', title: 'Backup made' });
          });
        });

        /* on the document: the list is redrawn after a change */
        document.addEventListener('click', async function (e) {
          const b = e.target.closest('[data-delete]');
          if (!b) return;
          if (!(await venusepConfirm('This backup file is removed for good.', { title: 'Delete this backup?', okLabel: 'Delete', danger: true }))) return;
          post({ action: 'delete', file: b.dataset.delete }).then(function (res) {
            if (!res.ok) { venusepAlert(res.message || 'Nothing was deleted.'); return; }
            Live.say(res.message);
            Live.refresh();
          });
        });

        /* A restore asks for the admin's own password — the server re-checks it
           with the shared lockout — then signs everyone out. */
        async function restore(fields, file, what) {
          const pw = await venusepPrompt(
            'Everything made after ' + what + ' is replaced: bookings, payments, accounts and settings. A safety copy of the current data is made first. Everyone is signed out afterwards, you included.',
            { title: 'Restore this backup?', label: 'Your admin password', inputType: 'password',
              autocomplete: 'current-password', okLabel: 'Restore', danger: true });
          if (pw === null) return;
          const done = busy('Restoring… this can take a minute. Do not close this page.');
          fields.action = 'restore';
          fields.password = pw;
          const res = await post(fields, file);
          done();
          if (!res.ok) { venusepAlert(res.message || 'Nothing was restored.'); return; }
          venusepAlert(res.message, { tone: 'ok', title: 'Restored' }).then(function () { location.href = backupRestorePage.adminLoginUrl; });
        }
        document.addEventListener('click', function (e) {
          const b = e.target.closest('[data-restore]');
          if (!b) return;
          const row = b.closest('tr');
          restore({ file: b.dataset.restore }, null, 'the backup of ' + row.cells[0].textContent.trim());
        });
        const up = document.getElementById('brUploadGo');
        if (up) up.addEventListener('click', function () {
          const f = document.getElementById('brUpload').files[0];
          if (!f) { venusepAlert('Choose a backup file first.'); return; }
          restore({}, f, 'that backup');
        });
      })();
    
