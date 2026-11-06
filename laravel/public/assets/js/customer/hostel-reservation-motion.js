/* Script for resources/views/customer/hostel-reservation.blade.php. */
  bookingMotion(function (EASE) {
    (function () {
      if (!window.matchMedia || !window.matchMedia('(max-width: 720px)').matches) return;
      var bar = document.createElement('div'); bar.className = 'bk-cta'; bar.setAttribute('aria-hidden', 'true');
      bar.innerHTML = '<div class="bk-cta-price"></div><button type="button" class="bk-cta-btn">Reserve a bed</button>';
      document.body.appendChild(bar);
      bar.querySelector('.bk-cta-btn').addEventListener('click', function () {
        var aside = document.querySelector('#app aside'); if (!aside) return;
        aside.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var first = aside.querySelector('input, select, button'); if (first) setTimeout(function () { first.focus({ preventScroll: true }); }, 500);
      });
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { if (en.target === watched) formSeen = en.isIntersecting; });   /* ignore late reports about a form that was re-rendered away */
        paintBar();
      }, { threshold: 0.15 });
      var formSeen = false, watched = null;
      function paintBar() {
        var onDetail = typeof state !== 'undefined' && state.screen === 'detail';
        var aside = document.querySelector('#app aside');
        if (aside !== watched) { if (watched) io.unobserve(watched); watched = aside; if (aside) io.observe(aside); formSeen = false; }
        var show = onDetail && aside && !formSeen;
        if (show) { try { bar.querySelector('.bk-cta-price').innerHTML = '<strong>' + peso(roomRate(getRoom())) + '</strong>per head, per night'; } catch (e) {} }
        bar.classList.toggle('show', !!show); document.body.classList.toggle('bk-cta-on', !!show);
      }
      var r0 = render; render = function () { r0.apply(this, arguments); paintBar(); };
      paintBar();
    })();

    var lastScreen = (typeof state !== 'undefined' && state.screen) || '';
    var pageRender = render;                          /* the page's own render() */
    render = function () {
      pageRender.apply(this, arguments);
      var now = (typeof state !== 'undefined' && state.screen) || '';
      var changed = now !== lastScreen; lastScreen = now;
      armScreen(changed);
    };
    armScreen(true);                                  /* the first screen, already drawn */

    /* ---- (2) scroll reveals for the static parts ---- */
    function reveal(targets, trigger, opts) {
      opts = opts || {};
      gsap.set(targets, { opacity: 0, y: opts.rise || 24 });
      function paint(on) {
        gsap.to(targets, { opacity: on ? 1 : 0, y: on ? 0 : (opts.rise || 24), duration: on ? 1 : .4, ease: EASE,
          overwrite: 'auto', stagger: on ? (opts.stagger || 0) : 0, clearProps: on ? 'transform' : '' });
      }
      ScrollTrigger.create({ trigger: trigger, start: 'top 88%', end: 'bottom top',
        onToggle: function (s) { paint(s.isActive); }, onRefresh: function (s) { paint(s.isActive); } });
    }
    var head = document.querySelector('.bk-more-head'); if (head) reveal(head, head);
    var grid = document.querySelector('.bk-more-grid'); if (grid) reveal(grid.children, grid, { stagger: .09 });
    var alt  = document.querySelector('.bk-more-alt');  if (alt)  reveal(alt, alt);
    var foot = document.querySelector('.bk-footer .bk-footer-grid'); if (foot) reveal(foot.children, foot, { stagger: .08, rise: 16 });
  });
