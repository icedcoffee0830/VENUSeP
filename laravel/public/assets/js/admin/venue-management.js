/* Script for resources/views/admin/venue-management.blade.php. */
      /* The jump bar and the search work together, and run again over redrawn cards. */
      let vmJump = 'all';
      const search = document.querySelector('.vm-search');
      const vmFilter = () => {
        const q = search.value.toLowerCase();
        document.querySelectorAll('[data-jump]').forEach((a) => {
          if (a.dataset.jump === vmJump) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
        });
        document.querySelectorAll('.vm-venue').forEach((section) => {
          let shown = 0;
          section.querySelectorAll('.vm-card').forEach((card) => {
            const show = card.textContent.toLowerCase().includes(q) && (vmJump !== 'attention' || card.dataset.attention === '1');
            card.hidden = !show;
            if (show) shown++;
          });
          /* a venue with nothing to show goes too, unless nothing is narrowing the list */
          const narrowed = q !== '' || vmJump === 'attention';
          section.hidden = (vmJump.startsWith('venue-') && section.id !== vmJump) || (narrowed && !shown);
        });
      };
      search.addEventListener('input', vmFilter);
      document.addEventListener('click', (e) => {
        const link = e.target.closest('[data-jump]');
        if (!link) return;
        e.preventDefault();
        vmJump = link.dataset.jump;
        vmFilter();
      });
      vmFilter();

      document.addEventListener('click', async (e) => {
        const button = e.target.closest('[data-delete-entity]');
        if (!button) return;
        const name = button.dataset.deleteName;
        const ok = await venusepConfirm(name + ' disappears from every list and can no longer be booked. Past bookings, receipts and reports keep its name. This cannot be undone.',
          { title: 'Delete ' + name + '?', okLabel: 'Delete', danger: true });
        if (!ok) return;

        button.disabled = true;
        await Live.send(venueManagementPage.catalogDeleteUrl,
          { confirm: 'delete', entity: button.dataset.deleteEntity, room_id: button.dataset.deleteId }, vmFilter);
        button.disabled = false;
      });

      // Hiding a room asks first (Aron 2026-11-01); showing it just flips it. Then the cards are
      // redrawn, so the card, its chip and every count on this page come back from the database.
      document.addEventListener('click', async (e) => {
        const button = e.target.closest('[data-toggle-id]');
        if (!button) return;
        if (!button.classList.contains('is-off') && !(await venusepConfirm(
              '"' + button.dataset.toggleName + '" will be hidden from customers until you show it again.',
              { title: 'Hide this room?', okLabel: 'Hide' }))) return;
        button.disabled = true;
        const result = await Live.post(venueManagementPage.roomToggleActiveUrl, { room_id: button.dataset.toggleId });
        button.disabled = false;
        if (!result.ok) { venusepAlert(result.message || 'Nothing changed.'); return; }
        // hidden with upcoming bookings: the reply names them — show it first
        if (result.notes && result.notes.length) await venusepAlert(result.message, { title: 'Room hidden' });
        else Live.say(result.message || 'Saved.');
        await Live.refresh();
        vmFilter();
      });
    
