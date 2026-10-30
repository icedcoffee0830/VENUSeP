<script>
/* ============================================================
   BROWSER BACK / FORWARD — the steps of this one-page flow are real history
   entries, so the browser's Back button does what a customer expects:
     review  → Back → edit details → Back → the landing page
     payment → Back → the status page
   Before submission every step is walkable. Once the request is SUBMITTED the
   form is gone for good (pending/done REPLACE the entry instead of adding one),
   so Back from there leaves the flow to the landing page rather than showing
   an editable form for a booking that already exists.
   ============================================================ */
(function () {
  if (typeof state === 'undefined' || typeof render !== 'function' || !window.history || !history.pushState) return;
  var silent = false;                                  /* true while a popstate is being applied — do not push again */
  var REPLACE = { pending: 1, done: 1 };               /* screens that stand in for the step before them */
  var lastScreen = state.screen;
  history.replaceState({ screen: state.screen }, '');
  var prevRender = render;
  render = function () {
    prevRender.apply(this, arguments);
    var now = state.screen;
    if (now === lastScreen) return;
    lastScreen = now;
    if (silent) return;
    (REPLACE[now] ? history.replaceState : history.pushState).call(history, { screen: now }, '');
  };
  window.addEventListener('popstate', function (e) {
    var to = e.state && e.state.screen;
    if (!to) return;
    var submitted = state.submitted || (state.screen !== 'detail' && state.screen !== 'review');
    if (submitted && (to === 'detail' || to === 'review')) { location.replace('{{ route('customer.venues') . $cq }}'); return; }
    silent = true; state.screen = to; window.scrollTo(0, 0); render(); silent = false;
  });
})();
</script>
