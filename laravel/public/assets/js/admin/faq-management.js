/* Script for resources/views/admin/faq-management.blade.php. */
      (function () {
        /* preview switch: show only the rows customers get under that policy */
        var seg = document.querySelectorAll('[data-preview]'), previewing = null;
        function preview(mode) {
          previewing = mode;
          seg.forEach(function (b) { b.classList.toggle('active', b.dataset.preview === mode); });
          document.querySelectorAll('.fq-row').forEach(function (row) {
            var p = row.dataset.policy;
            row.classList.toggle('is-off', mode !== 'all' && p !== 'any' && p !== mode);
          });
          document.querySelectorAll('.fq-card[id] .fq-card-head small').forEach(function (s) {
            var card = s.closest('.fq-card'); var n = card.querySelectorAll('.fq-row:not(.is-off)').length;
            s.textContent = n + ' question' + (n === 1 ? '' : 's') + (mode === 'all' ? '' : ' shown');
          });
        }
        seg.forEach(function (b) { b.addEventListener('click', function () { preview(b.dataset.preview); try { sessionStorage.setItem('venusep_faq_preview', b.dataset.preview); } catch (e) {} }); });
        /* which preview to start on: ?show= from a save (so the row just saved is
           visible), else the choice remembered for this tab, else the live setting */
        var show = new URLSearchParams(location.search).get('show');
        var remembered = null; try { remembered = sessionStorage.getItem('venusep_faq_preview'); } catch (e) {}
        var start = (show === 'refunds_on' || show === 'refunds_off') ? show : (remembered || faqManagementPage.fqLive);
        preview(start);

        /* THE ANSWER EDITOR. What the admin sees is what customers get: bold is
           bold, lists are lists, links are links, live values are chips. On
           submit the editor is turned back into the stored text format
           (**bold**, "- " lists, [text](url), {placeholders}) in the hidden
           textarea, so the server and customer page never see HTML. */
        function serialize(node) {
          var out = '';
          node.childNodes.forEach(function (n) {
            if (n.nodeType === 3) { out += n.nodeValue.replace(/\u00a0/g, ' '); return; }
            if (n.nodeType !== 1) return;
            var tag = n.tagName.toLowerCase();
            if (n.classList && n.classList.contains('fq-token')) { out += n.dataset.token; return; }
            if (tag === 'br') { out += '\n'; return; }
            if (tag === 'b' || tag === 'strong') { var t = serialize(n).trim(); out += t ? '**' + t + '**' : ''; return; }
            if (tag === 'a') { var href = n.getAttribute('href') || ''; href = href.replace(/^.*\/customer\//, ''); out += '[' + serialize(n).trim() + '](' + href + ')'; return; }
            if ((tag === 'ul' || tag === 'ol' || tag === 'p' || tag === 'div') && out && !/\n$/.test(out)) out += '\n\n';   // a block right after inline text starts its own paragraph
            if (tag === 'ul' || tag === 'ol') { out += '\n' + Array.prototype.map.call(n.querySelectorAll(':scope > li'), function (li) { return '- ' + serialize(li).trim(); }).join('\n') + '\n\n'; return; }
            if (tag === 'li') { out += '- ' + serialize(n).trim() + '\n'; return; }
            if (tag === 'p' || tag === 'div') { var inner = serialize(n); out += (inner.trim() ? inner.replace(/\n+$/, '') : '') + '\n\n'; return; }
            out += serialize(n);
          });
          return out;
        }
        function editorText(ed) { return serialize(ed).replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim(); }
        function syncEditor(form) {
          var ed = form.querySelector('.fq-editor'), ta = form.querySelector('textarea[name=answer]');
          if (ed && ta) ta.value = editorText(ed);
          return ta ? ta.value : '';
        }
        document.addEventListener('submit', function (e) {
          var form = e.target; if (!form.querySelector('.fq-editor')) return;
          var text = syncEditor(form);
          if (!text) { e.preventDefault(); form.classList.add('is-invalid'); form.querySelector('.fq-editor').focus(); }
        });
        /* Every form here still works as a plain post. With this script it answers in place:
           the same rules run, the list comes back, and the page stays where it is. */
        document.addEventListener('submit', async function (e) {
          var form = e.target, url = form.getAttribute('action');   /* form.action is the input of that name */
          if (e.defaultPrevented || url !== faqManagementPage.faqSaveUrl) return;
          e.preventDefault();
          var res = await Live.post(url, new FormData(form));
          closeModal();
          if (!res.ok) { Live.say(res.message || 'Nothing was saved.', 'bad'); return; }
          await Live.refresh();
          if (form.querySelector('[name=action]').value === 'add') {
            form.reset();
            form.querySelector('.fq-editor').innerHTML = '';
          }
          preview(res.show === 'refunds_on' || res.show === 'refunds_off' ? res.show : previewing);
          Live.say(res.message);
        });
        document.addEventListener('input', function (e) { var f = e.target.closest && e.target.closest('.fq-form'); if (f) f.classList.remove('is-invalid'); });
        /* paste as plain text so nothing odd comes in from Word or a web page */
        document.addEventListener('paste', function (e) {
          var ed = e.target.closest && e.target.closest('.fq-editor'); if (!ed) return;
          e.preventDefault(); document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain'));
        });
        /* the Bold / List buttons light up red while the caret sits in bold text / a list */
        function paintToolbar() {
          var sel = window.getSelection(); var node = sel && sel.rangeCount ? sel.getRangeAt(0).startContainer : null;
          var ed = node && (node.nodeType === 1 ? node : node.parentElement).closest('.fq-editor');
          document.querySelectorAll('.fq-tb.is-on').forEach(function (b) { b.classList.remove('is-on'); });
          if (!ed) return;
          var tb = ed.parentElement.querySelector('.fq-toolbar'); if (!tb) return;
          tb.querySelector('[data-fmt="bold"]').classList.toggle('is-on', document.queryCommandState('bold'));
          tb.querySelector('[data-fmt="list"]').classList.toggle('is-on', document.queryCommandState('insertUnorderedList'));
        }
        document.addEventListener('selectionchange', paintToolbar);
        function chip(token, label) { return '<span class="fq-token" contenteditable="false" data-token="' + token + '">' + label + '</span>&nbsp;'; }
        document.addEventListener('click', function (e) {
          var openMenu = document.querySelector('.fq-tb-menu.open');
          if (openMenu && !e.target.closest('.fq-tb-menu')) openMenu.classList.remove('open');
          var tb = e.target.closest('.fq-toolbar'); if (!tb) return;
          var b = e.target.closest('button'); if (!b) return;
          var ed = tb.parentElement.querySelector('.fq-editor');
          if (b.hasAttribute('data-menu')) {
            var m = b.closest('.fq-tb-menu'), was = m.classList.contains('open');
            if (openMenu) openMenu.classList.remove('open');
            m.classList.toggle('open', !was); return;
          }
          ed.focus();
          if (b.dataset.fmt === 'bold') { document.execCommand('bold'); paintToolbar(); }
          if (b.dataset.fmt === 'list') { document.execCommand('insertUnorderedList'); paintToolbar(); }
          if (b.dataset.link) {
            var sel = window.getSelection();
            if (!sel || sel.isCollapsed) document.execCommand('insertHTML', false, '<a href="' + b.dataset.link + '">' + (b.textContent.trim()) + '</a>&nbsp;');
            else document.execCommand('createLink', false, b.dataset.link);
            b.closest('.fq-tb-menu').classList.remove('open');
          }
          if (b.dataset.value) {
            var label = b.querySelector('small') ? b.querySelector('small').textContent.replace(/ — updates by itself$/, '').replace(/ per head, per night$/, '') : b.dataset.value;
            document.execCommand('insertHTML', false, chip(b.dataset.value, label));
            b.closest('.fq-tb-menu').classList.remove('open');
          }
        });
        /* Enter inside a chip's neighbourhood should never split the chip; a chip is deleted whole */
        document.addEventListener('keydown', function (e) {
          if (e.key !== 'Backspace' && e.key !== 'Delete') return;
          var ed = e.target.closest && e.target.closest('.fq-editor'); if (!ed) return;
          var sel = window.getSelection(); if (!sel.rangeCount || !sel.isCollapsed) return;
          var r = sel.getRangeAt(0), n = r.startContainer, o = r.startOffset, target = null;
          if (e.key === 'Backspace') { if (n.nodeType === 3 && o === 0) target = n.previousSibling; else if (n.nodeType === 1) target = n.childNodes[o - 1]; }
          else { if (n.nodeType === 3 && o === n.length) target = n.nextSibling; else if (n.nodeType === 1) target = n.childNodes[o]; }
          if (target && target.nodeType === 1 && target.classList.contains('fq-token')) { e.preventDefault(); target.remove(); }
        });

        /* inline edit forms */
        document.addEventListener('click', function (e) {
          var ed = e.target.closest('[data-edit]');
          if (ed) { document.getElementById(ed.dataset.edit).classList.add('is-editing'); return; }
          var cn = e.target.closest('[data-cancel]');
          if (cn) { document.getElementById(cn.dataset.cancel).classList.remove('is-editing'); return; }
          /* delete modal */
          var del = e.target.closest('[data-delete]');
          if (del) {
            var row = document.getElementById('faq-' + del.dataset.delete);
            document.getElementById('fqModalId').value = del.dataset.delete;
            document.getElementById('fqModalQ').textContent = row ? row.dataset.question : '';
            var m = document.getElementById('fqModal'); m.hidden = false; m.classList.add('open');
            m.querySelector('[data-modal-close]').focus();
            return;
          }
          if (e.target.closest('[data-modal-close]') || e.target.id === 'fqModal') { closeModal(); }
        });
        function closeModal() { var m = document.getElementById('fqModal'); m.classList.remove('open'); m.hidden = true; }
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

        /* keep the section the admin was working in after a save (faq-save.php redirects with #section) */
        if (location.hash) { var t = document.querySelector(location.hash); if (t) t.scrollIntoView({ block: 'start' }); }
      })();
    
