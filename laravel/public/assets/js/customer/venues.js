/* Script for resources/views/customer/venues.blade.php. */
  (function () {
    /* No GSAP (CDN down) or reduced-motion: leave everything visible and stop. */
    if (typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined') return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    gsap.registerPlugin(ScrollTrigger);
    ScrollTrigger.config({ ignoreMobileResize: true });   /* phone address-bar show/hide is not a resize */
    var EASE = 'power3.out';                               /* fast start, long gentle settle */

    /* reveal(): hide the targets, then paint them in or out whenever their
       trigger enters/leaves the viewport — and again after every refresh
       (browser zoom, window resize, fonts loading). Nothing is remembered
       between frames, so a re-measure can never leave an element stuck. */
    function reveal(targets, trigger, opts) {
      opts = opts || {};
      gsap.set(targets, { opacity: 0, y: opts.rise || 28 });
      function paint(on) {
        gsap.to(targets, {
          opacity: on ? 1 : 0, y: on ? 0 : (opts.rise || 28),
          duration: on ? 1.1 : 0.45, ease: EASE, overwrite: 'auto',
          stagger: on ? (opts.stagger || 0) : 0,
          delay: on ? (opts.delay || 0) : 0,
          clearProps: on ? 'transform' : ''      /* landed: give transform back to CSS (hover lift) */
        });
        if (on && opts.onShow) opts.onShow();
      }
      ScrollTrigger.create({
        trigger: trigger, start: opts.start || 'top 88%', end: 'bottom top',
        onToggle:  function (self) { paint(self.isActive); },
        onRefresh: function (self) { paint(self.isActive); }
      });
    }

    /* Hero: pieces arrive in order on load; the stats count up as they land. */
    var heroWrap = document.querySelector('[data-hero]');
    if (heroWrap) {
      gsap.set(heroWrap, { opacity: 1 });
      reveal(heroWrap.querySelectorAll('[data-hero-item]'), heroWrap,
        { start: 'top 80%', rise: 34, stagger: 0.14, delay: 0.15, onShow: countUp });
    }

    /* Everything else: single blocks, and grids whose children stagger. */
    document.querySelectorAll('[data-reveal]:not([data-hero])').forEach(function (el) { reveal(el, el); });
    document.querySelectorAll('[data-stagger]').forEach(function (g) { reveal(g.children, g, { stagger: 0.09 }); });

    /* Stats count up from 0. */
    function countUp() {
      document.querySelectorAll('.lp-stat b[data-count]').forEach(function (el) {
        var target = parseInt(el.getAttribute('data-count'), 10) || 0;
        var suffix = el.getAttribute('data-suffix') || '';
        var box = { v: 0 };
        el.textContent = '0' + suffix;
        gsap.to(box, { v: target, duration: 1.6, ease: 'power2.out', delay: 0.6, overwrite: true,
          onUpdate: function () { el.textContent = Math.round(box.v) + suffix; } });
      });
    }

    /* Parallax: the colonnade moves 18% of its height across the hero's scroll. */
    gsap.to('.lp-hero-art', { yPercent: 18, ease: 'none',
      scrollTrigger: { trigger: '.lp-hero', start: 'top top', end: 'bottom top', scrub: 0.6 } });
  })();

  /* In-page links scroll smoothly, and the scroll cue fades once you start.
     (The nav bar itself lives in includes/customer-nav.php.) Done here, not with CSS scroll-behavior,
     because that CSS property makes ScrollTrigger mis-measure on zoom/resize. */
  (function () {
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.querySelectorAll('a[href^="#"]').forEach(function (a) {
      var id = a.getAttribute('href');
      if (id.length < 2) return;
      a.addEventListener('click', function (e) {
        var t = document.querySelector(id);
        if (!t) return;
        e.preventDefault();
        t.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
      });
    });
    var cue = document.getElementById('lpCue');
    function paint() { if (cue) cue.classList.toggle('gone', window.scrollY > 60); }
    window.addEventListener('scroll', paint, { passive: true });
    paint();
  })();
