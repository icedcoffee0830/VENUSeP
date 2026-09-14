<?php
/* =====================================================================
   REFUND STORE — [SIM] the shared refund-request state.
   THE ONE SOURCE for refund state, mirroring includes/gcash-checker.php
   (a shared engine kept in ONE file and included, never copy-pasted).

   Included by:
     customer/refund-request.php   (files a request, blocks a second one)
     customer/booking-history.php  (shows the state, withdraws, resubmits)
     admin/booking-requests.php    (surfaces open requests in the queue)
     admin/booking-request.php     (staff decide; writes the outcome back)

   WHY THIS EXISTS: there is no database yet, so a refund the customer
   files has nowhere to live and staff can never see it. localStorage is
   shared by every page on this origin, so it can carry the request from
   the customer side to the admin side and the decision back again. That
   is ALL it is for — a wire between two mockups.

   ⚠️ AT DATABASE TIME THIS FILE IS DELETED. Every one of these calls
   becomes a row in `refunds` + a `bookings.payment_status`. Nothing here
   is a design decision; the decisions live in DB-TRANSITION.md.

   ⚠️ It is per-browser and per-machine: clearing site data resets the
   demo, and a second computer sees nothing. Fine for a demo, useless for
   anything real — which is exactly why it is quarantined in one file.

   STATUS values (the agreed refund chain, 2026-09-09):
     open      filed, waiting on staff. Customer may withdraw.
     fix       returned for correction — PAPERWORK only, not a denial.
               The customer may correct and resubmit; booking untouched.
     denied    final. The customer would have to book again. Booking kept.
     refunded  paid out. ONLY NOW is the booking closed and the date freed.
   ===================================================================== */
?>
<script>
/* Refund store [SIM] — see includes/refund-store.php for why this exists. */
(function () {
  const KEY = 'venusep_refunds';
  const today = function () { return new Date().toISOString().slice(0, 10); };
  const readAll = function () {
    try {
      const raw = JSON.parse(localStorage.getItem(KEY) || '{}');
      return (raw && typeof raw === 'object' && !Array.isArray(raw)) ? raw : {};
    } catch (e) { return {}; }          /* blocked or corrupt storage — behave as empty */
  };
  const writeAll = function (all) {
    try { localStorage.setItem(KEY, JSON.stringify(all)); return true; } catch (e) { return false; }
  };
  window.RefundStore = {
    all: readAll,
    get: function (ref) { return readAll()[ref] || null; },
    /* Filing, or re-filing after a correction. Keeps the original filed date so
       "no staff reply for N days" measures the real wait, not the resubmission. */
    open: function (rec) {
      const all = readAll();
      const previous = all[rec.ref];
      all[rec.ref] = {
        ref: rec.ref,
        reason: rec.reason || "",
        details: rec.details || "",
        orPending: !!rec.orPending,
        refundTo: rec.refundTo || (previous && previous.refundTo) || null,   /* where the money goes; null = cash booking */
        filed: (previous && previous.filed) || today(),
        resubmitted: previous ? today() : null,
        status: 'open',
        staffNote: "",
        decidedAt: null
      };
      return writeAll(all);
    },
    withdraw: function (ref) { const all = readAll(); delete all[ref]; return writeAll(all); },
    /* status: 'fix' | 'denied' | 'refunded'. The note is what the customer reads.
       `proof` is set only on 'refunded' — the GCash reference, the amount, the
       destination and the receipt filename, so the customer can see they were
       actually paid. (The image itself is not stored: localStorage is the wrong
       place for it and it belongs in the DB at wiring time.) */
    decide: function (ref, status, note, proof) {
      const all = readAll();
      if (!all[ref]) return false;
      all[ref].status = status;
      all[ref].staffNote = note || "";
      all[ref].decidedAt = today();
      if (proof) all[ref].proof = proof;
      return writeAll(all);
    },
    markORProvided: function (ref) {
      const all = readAll();
      if (!all[ref]) return false;
      all[ref].orPending = false;
      return writeAll(all);
    },
    daysSince: function (iso) {
      if (!iso) return 0;
      return Math.floor((Date.now() - new Date(iso + 'T00:00:00')) / 86400000);
    }
  };
})();
</script>
