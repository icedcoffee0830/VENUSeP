/* Script for the Server panel (resources/views/failover/panel.blade.php): the Server page and
   the emergency page. serverPage = { csrf, actionUrl } comes from the page. Every action posts
   to ServerController::action, which re-checks who is asking. */
(function () {
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

  function post(fields) {
    const body = new FormData();
    body.append('_token', serverPage.csrf);
    Object.keys(fields).forEach((k) => body.append(k, fields[k]));
    return fetch(serverPage.actionUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => r.json().catch(() => ({ ok: false, message: 'The server sent an unreadable reply.' })))
      .catch(() => ({ ok: false, message: 'Could not reach this laptop’s website.' }));
  }

  /* ---- QR code for this laptop's address ---- */
  const qrEl = document.querySelector('[data-sv-qr]');
  if (qrEl && typeof window.qrcode === 'function') {
    const qr = window.qrcode(0, 'M');
    qr.addData(qrEl.getAttribute('data-sv-qr'));
    qr.make();
    qrEl.innerHTML = qr.createSvgTag(4, 0);
    qrEl.setAttribute('role', 'img');
    qrEl.setAttribute('aria-label', 'QR code for ' + qrEl.getAttribute('data-sv-qr'));
    qrEl.hidden = false;
  }

  /* ---- what a report means: role pill, state pill, the buttons ---- */
  function view(r) {
    const pill = (cls, text) => '<span class="sv-pill ' + cls + '">' + esc(text) + '</span>';
    if (!r.reachable) return { role: '—', state: pill('is-off', 'Not answering'), acts: ['remove'] };
    const role = { main: pill('is-main', 'Main'), copy: pill('is-copy', 'Copy'), locked: pill('is-copy', 'Locked') }[r.role] || '—';
    let state, acts = [];
    if (r.role === 'main') {
      state = r.current ? pill('is-ok', 'Working') : pill('is-slow', 'Takes saves');
      if (r.safe) acts.push('switch');
    } else if (r.role === 'locked') {
      state = r.current ? pill('is-slow', 'Locked after a restart') : pill('is-off', 'Not copying');
      acts.push(r.current ? 'unlock' : 'copy');
    } else if (r.own) {
      state = pill('is-off', 'Has changes of its own'); acts.push('rebuild');
    } else if (r.behind === null) {
      state = pill('is-slow', 'Its main is not answering'); acts.push('switch');
    } else if (!r.running) {
      state = pill('is-off', 'Copy stopped'); acts.push('copy', 'rebuild');
    } else if (r.behind > 0) {
      state = pill('is-slow', r.behind + (r.behind === 1 ? ' change behind' : ' changes behind')); acts.push('switch');
    } else {
      state = pill('is-ok', 'Up to date'); acts.push('switch');
    }
    if (r.level && r.checks[0] && !r.checks[0][0]) state += ' <span class="sv-muted">· ' + esc(r.checks[0][1]) + '</span>';
    return { role: role, state: state, acts: acts };
  }

  const LABEL = { switch: 'Switch to this', unlock: 'Unlock saving', copy: 'Make it a copy', rebuild: 'Rebuild this copy', remove: 'Remove', add: 'Add to the list' };
  function buttons(r, acts) {
    return ['test'].concat(acts).map(function (a) {
      if (a === 'test') return '<button type="button" class="sv-btn" data-act="test">Test</button>';
      const off = (a === 'switch' || a === 'unlock') && !r.safe;
      const cls = a === 'switch' || a === 'unlock' ? 'sv-btn sv-btn-outline' : 'sv-btn';
      return '<button type="button" class="' + cls + '" data-act="' + a + '"' + (off ? ' disabled title="' + esc(why(r) || 'Not safe to switch to yet') + '"' : '') + '>' + LABEL[a] + '</button>';
    }).join('');
  }
  const why = (r) => (r.checks || []).filter((c) => !c[0]).map((c) => c[1]).join('; ');

  function paint(tr, r) {
    const v = view(r);
    if (tr.dataset.listed === '0' && r.reachable && !/^(127\.|localhost$|::1$)/.test(r.ip)) v.acts.unshift('add');
    tr.querySelector('[data-cell=role]').innerHTML = v.role;
    tr.querySelector('[data-cell=state]').innerHTML = v.state;
    tr.querySelector('[data-cell=actions]').innerHTML = buttons(r, v.acts);
    tr._report = r;
  }

  function test(tr) {
    tr.querySelector('[data-cell=state]').innerHTML = '<span class="sv-muted">Checking…</span>';
    return post({ action: 'test', ip: tr.dataset.ip }).then(function (res) {
      if (res.ok) paint(tr, res.report);
      else tr.querySelector('[data-cell=state]').innerHTML = '<span class="sv-bad">' + esc(res.message) + '</span>';
    });
  }

  /* one at a time: the website on a laptop runs one request at a time */
  const rows = () => Array.from($('svRows').querySelectorAll('tr'));
  rows().reduce((p, tr) => p.then(() => test(tr)), Promise.resolve());

  /* ---- the result box under the list ---- */
  function show(html) { const box = $('svResult'); box.innerHTML = html; box.hidden = false; }
  function checksHtml(title, r) {
    return '<strong>' + esc(title) + '</strong><ul>' + (r.checks || []).map((c) => '<li class="' + (c[0] ? 'is-yes' : 'is-no') + '">' + esc(c[1]) + '</li>').join('') + '</ul>'
      + (r.reachable ? (r.safe ? 'Safe to switch to.' : (r.current && r.role === 'main' ? 'This is the main.' : 'Not safe to switch to.')) : '');
  }
  function stepsHtml(res) {
    return '<strong>' + esc(res.message || '') + '</strong><ul>' + (res.steps || []).map(function (s) {
      return '<li class="' + (s[1] === true ? 'is-yes' : s[1] === null ? 'is-warn' : 'is-no') + '">' + esc(s[0]) + (s[2] ? ': ' + esc(s[2]) : '') + '</li>';
    }).join('') + '</ul>';
  }

  /* ---- password + authenticator code, for the actions that change servers ---- */
  async function confirmed(message, title, okLabel, danger) {
    const v = await venusepPrompt(message, {
      title: title, okLabel: okLabel, cancelLabel: 'Cancel', danger: !!danger,
      fields: [
        { label: 'Your password', inputType: 'password', autocomplete: 'current-password' },
        { label: 'Code from your authenticator app', inputType: 'text', autocomplete: 'one-time-code', placeholder: '6 digits', spellcheck: false },
      ],
    });
    return v && { password: v[0], code: v[1] };
  }

  function lock(on) { document.querySelectorAll('.sv-btn').forEach((b) => { if (on) { b.dataset.was = b.disabled ? '1' : ''; b.disabled = true; } else b.disabled = b.dataset.was === '1'; }); }

  async function act(tr, a) {
    const ip = tr.dataset.ip, name = tr.dataset.name || ip, r = tr._report || {};
    if (a === 'test') return test(tr).then(() => tr._report && show(checksHtml('Test of ' + ip + ' (' + name + ')', tr._report)));
    if (a === 'add' || a === 'remove') {
      if (a === 'remove' && !(await venusepConfirm(name + ' (' + ip + ') stops getting copies of the photos, IDs and receipts, and is no longer checked. Its database is not touched. You can add it again later.',
        { title: 'Remove ' + name + ' from the list?', okLabel: 'Remove', cancelLabel: 'Cancel', danger: true }))) return;
      const res = await post({ action: a, ip: ip });
      if (!res.ok) return venusepAlert(res.message);
      return location.reload();
    }
    let ask;
    if (a === 'switch' || a === 'unlock') {
      ask = await confirmed(a === 'unlock'
        ? 'It restarted locked, so nothing is saved by mistake. Unlocking lets it take every save again.'
        : name + ' becomes the main and starts taking every save. The old main becomes a copy (when it is back on) and catches up. The other copies follow ' + name + '. Everyone keeps working; the address people open does not change.',
        a === 'unlock' ? 'Unlock saving on ' + name + ' (' + ip + ')?' : 'Switch to ' + name + ' (' + ip + ')?', a === 'unlock' ? 'Unlock' : 'Switch now');
    } else if (a === 'copy') {
      ask = await confirmed('It stops taking saves and copies everything from the main, starting from what it already has.', 'Make ' + name + ' (' + ip + ') a copy?', 'Make it a copy');
    } else if (a === 'rebuild') {
      ask = await confirmed('Its database is thrown away and loaded fresh from the main. Changes made only on this laptop are lost: the main always wins.', 'Rebuild the copy on ' + name + ' (' + ip + ')?', 'Rebuild', true);
    }
    if (!ask) return;
    lock(true);
    show('<strong>Working… this can take up to 30 seconds. Keep this page open.</strong>');
    const res = await post(Object.assign({ action: a === 'unlock' ? 'switch' : a, ip: ip }, ask));
    lock(false);
    if (res.steps) show(stepsHtml(res));
    else show('<strong class="' + (res.ok ? '' : 'sv-bad') + '">' + esc(res.message || (res.ok ? 'Done.' : 'It did not work.')) + '</strong>');
    if (res.ok) setTimeout(() => location.reload(), 2500);
  }

  $('svRows').addEventListener('click', function (e) {
    const b = e.target.closest('[data-act]');
    if (b && !b.disabled) act(b.closest('tr'), b.dataset.act);
  });

  /* ---- Find, and Test a typed address ---- */
  function addRow(ip) {
    const tr = document.createElement('tr');
    tr.dataset.ip = ip; tr.dataset.listed = '0';
    tr.innerHTML = '<td><strong>New</strong> <span class="sv-muted">(not in the list)</span></td><td class="sv-mono" translate="no">' + esc(ip) + '</td>'
      + '<td data-cell="role">—</td><td data-cell="state"></td><td><div class="sv-actions" data-cell="actions"></div></td>';
    $('svRows').appendChild(tr);
    return tr;
  }
  const known = (ip) => rows().some((tr) => tr.dataset.ip === ip);

  $('svFind').addEventListener('click', async function () {
    this.disabled = true;
    show('<strong>Looking for servers on this hotspot… about 5 seconds.</strong>');
    const res = await post({ action: 'find' });
    this.disabled = false;
    if (!res.ok) return show('<strong class="sv-bad">' + esc(res.message) + '</strong>');
    const fresh = res.found.filter((ip) => !known(ip));
    show('<strong>' + (res.found.length ? 'Found ' + res.found.length + (res.found.length === 1 ? ' server' : ' servers') + (fresh.length ? '; the new ones are added to the list below for testing.' : ', all already listed.') : 'No servers found. Type the address instead (it is shown on the other laptop’s Server page).') + '</strong>');
    fresh.reduce((p, ip) => p.then(() => test(addRow(ip))), Promise.resolve());
  });

  async function testTyped() {
    const ip = $('svIp').value.trim();
    if (!ip) return $('svIp').focus();
    show('<strong>Testing ' + esc(ip) + '…</strong>');
    const res = await post({ action: 'test', ip: ip });
    if (!res.ok) return show('<strong class="sv-bad">' + esc(res.message) + '</strong>');
    show(checksHtml('Test of ' + ip, res.report)
      + (res.report.reachable && !known(ip) && !/^(127\.|localhost$|::1$)/.test(ip) ? '<div class="sv-actions"><button type="button" class="sv-btn" id="svAddTyped">Add to the list</button></div>' : ''));
    const add = $('svAddTyped');
    if (add) add.onclick = async () => { const r = await post({ action: 'add', ip: ip }); r.ok ? location.reload() : venusepAlert(r.message); };
  }
  $('svTest').addEventListener('click', testTyped);
  $('svIp').addEventListener('keydown', (e) => { if (e.key === 'Enter') testTyped(); });

  /* ---- saving without a copy ---- */
  const saving = $('svSaving');
  if (saving) saving.addEventListener('click', async function () {
    const on = this.dataset.on === '1';
    const ask = await confirmed(on
      ? 'Every save waits again until a copy has it.'
      : 'Saves stop waiting for a copy. Anything saved while this is on exists on this one main only, and is lost if it breaks before a copy is back.',
      on ? 'Protect saving again?' : 'Allow saving without a copy?', on ? 'Protect saving' : 'Allow it', !on);
    if (!ask) return;
    const res = await post(Object.assign({ action: 'saving', on: on ? 1 : 0 }, ask));
    if (!res.ok) return venusepAlert(res.message);
    location.reload();
  });
})();
