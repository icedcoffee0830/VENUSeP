/* Script for resources/views/customer/pay.blade.php. */
      /* The 30-second check. Not while someone is paying: a chosen receipt or the ticked box
         must stay. The payment script (and its receipt checker) comes with the page, so a
         booking that opens or closes for paying is the one change that loads the page again. */
      Live.watch('mine', async function () {
        if (typeof state !== 'undefined' && (state.ocr || state.sending || state.agreeExact)) return false;
        const before = document.querySelector('[data-pay-code]').dataset;
        const was = { code: before.payCode, open: before.payOpen };
        await Live.refresh();
        const now = document.querySelector('[data-pay-code]').dataset;
        if (now.payCode !== was.code && (now.payOpen === '1' || was.open === '1')) { location.reload(); return; }
        if (typeof render === 'function') render();
      });
    
