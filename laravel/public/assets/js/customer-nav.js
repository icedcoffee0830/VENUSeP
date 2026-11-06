/* Script for legacy/includes/customer-nav.php. */
  /* hero mode: transparent over the hero, crimson once you scroll past it */
  (function () {
    var nav = document.getElementById('cnNav');
    function paint() { nav.classList.toggle('solid', window.scrollY > 90); }
    window.addEventListener('scroll', paint, { passive: true });
    paint();
  })();
