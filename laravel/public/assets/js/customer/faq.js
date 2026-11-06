/* Script for resources/views/customer/faq.blade.php. */
  /* Smooth in-page scrolling (JS, not CSS scroll-behavior — that breaks
     ScrollTrigger's measurements on zoom) and the "you are here" mark in the
     side list. Works without GSAP. */
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
    var links = document.querySelectorAll('.fq-toc a');
    var sections = Array.prototype.map.call(links, function (a) { return document.querySelector(a.getAttribute('href')); });
    function mark() {
      var y = window.scrollY + 140, current = 0;
      sections.forEach(function (s, i) { if (s && s.offsetTop <= y) current = i; });
      links.forEach(function (a, i) { a.classList.toggle('is-here', i === current); });
    }
    window.addEventListener('scroll', mark, { passive: true });
    mark();
  })();

  /* Reveal motion — the landing page's rule: rise + fade in when a block
     scrolls into view, out when it leaves; re-evaluated after any refresh
     (zoom, resize). No GSAP or reduced-motion = everything simply visible. */
  (function () {
    if (typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined') return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    gsap.registerPlugin(ScrollTrigger);
    ScrollTrigger.config({ ignoreMobileResize: true });
    document.querySelectorAll('[data-reveal]').forEach(function (el, i) {
      gsap.set(el, { opacity: 0, y: 24 });
      function paint(on) {
        gsap.to(el, { opacity: on ? 1 : 0, y: on ? 0 : 24, duration: on ? 1 : 0.4, ease: 'power3.out', overwrite: 'auto',
                      delay: on && i < 2 ? i * 0.12 : 0, clearProps: on ? 'transform' : '' });
      }
      /* the sticky TOC rides along with the whole FAQ body, so it stays until the BODY ends — not its own slot */
      var sticky = el.classList.contains('fq-toc') ? el.parentElement : el;
      ScrollTrigger.create({ trigger: el, start: 'top 90%', endTrigger: sticky, end: 'bottom top',
        onToggle: function (s) { paint(s.isActive); }, onRefresh: function (s) { paint(s.isActive); } });
    });
  })();
