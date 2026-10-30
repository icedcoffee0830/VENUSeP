    if (typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined') return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    gsap.registerPlugin(ScrollTrigger);
    ScrollTrigger.config({ ignoreMobileResize: true });
    var EASE = 'power3.out';

    /* ---- (1) screen entrance ---- */
    /* The blocks to stagger: walk down from #app through single-child wrappers
       until a node has several children — those are the screen's sections. */
    function screenBlocks() {
      var node = document.getElementById('app'), depth = 0;
      while (node && node.children.length === 1 && depth < 5) { node = node.children[0]; depth++; }
      if (!node || !node.children.length) return [];
      var vis = function (el) { return el.offsetHeight > 0; };
      var kids = Array.prototype.filter.call(node.children, vis);
      if (kids.length <= 3) {                       /* two columns is too coarse: use their sections instead */
        var out = [];
        kids.forEach(function (k) {
          var g = Array.prototype.filter.call(k.children, vis);
          if (g.length >= 2 && g.length <= 8) out.push.apply(out, g); else out.push(k);
        });
        kids = out;
      }
      return kids.slice(0, 12);
    }
    /* Every block gets a scroll trigger, like the landing page: it rises in when
       it scrolls into view and drops back out when it leaves, so scrolling up
       and down replays the pop. The FIRST paint after a screen change is the
       staggered entrance; on a same-screen re-render (typing, picking a date)
       the blocks are re-armed silently so nothing jumps under your hands. */
    var appTriggers = [], mode = 'scroll';
    /* Re-arm the reveal for the blocks of the current screen. Two cases:
         entrance — the screen changed: hide everything, stagger it in, and
                    re-measure the whole page (it changed height).
         instant  — a re-render mid-typing (every keystroke redraws #app): the
                    same blocks are back as fresh DOM nodes, so just snap each
                    one to the state it already had. Cheap on purpose: no
                    pre-hide, no page-wide re-measure, and all style writes
                    happen before all layout reads (no thrash). This is what
                    keeps typing smooth on a phone. */
    function armScreen(entrance) {
      appTriggers.forEach(function (t) { t.kill(); }); appTriggers = [];
      var blocks = screenBlocks();
      if (!blocks.length) return;
      mode = entrance ? 'entrance' : 'instant';
      if (entrance) blocks.forEach(function (el) { gsap.set(el, { opacity: 0, y: 18, scale: .985 }); });   /* writes first */
      var arming = true;                                                                                    /* then the reads — no writes until every trigger exists */
      blocks.forEach(function (el, i) {
        function paint(on) {
          if (mode === 'instant') {              /* re-render mid-typing: snap to the right state */
            if (arming) return;                  /* deferred to the batch below (one layout, not seven) */
            gsap.set(el, { opacity: on ? 1 : 0, y: on ? 0 : 18, scale: on ? 1 : .985, clearProps: on ? 'transform' : '' });
            return;
          }
          gsap.to(el, { opacity: on ? 1 : 0, y: on ? 0 : 18, scale: on ? 1 : .985,
            duration: on ? .75 : .35, ease: EASE, overwrite: 'auto',
            delay: (on && mode === 'entrance') ? i * .07 : 0,
            clearProps: on ? 'transform' : '' });
        }
        appTriggers.push(ScrollTrigger.create({ trigger: el, start: 'top 92%', end: 'bottom top',
          onToggle: function (s) { paint(s.isActive); }, onRefresh: function (s) { paint(s.isActive); } }));
      });
      arming = false;
      if (entrance) ScrollTrigger.refresh();     /* the screen changed height: re-measure everything below it too */
      else appTriggers.forEach(function (t) { t.vars.onRefresh(t); });   /* each new trigger already knows if it is in view: apply all states in one batch */
      setTimeout(function () { mode = 'scroll'; }, 60);
    }
    /* PHONE CTA BAR. On a phone the booking form sits below the photos and the
       description; this bar keeps "Reserve" one tap away and hides itself once
       the form is on screen. Detail screen only; nothing on desktop. */
