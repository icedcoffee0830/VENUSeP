/* Script for resources/views/partials/header.blade.php. */
  /* The burger. Desktop: collapse/expand the sidebar and remember the choice
     for the next page. Phone: the sidebar is hidden by default and the burger
     slides it in over the page; tapping outside closes it. */
  (function () {
    var KEY = 'venusep_sidebar', body = document.body;
    var phone = window.matchMedia('(max-width: 767.98px)');
    try { if (localStorage.getItem(KEY) === 'closed') body.classList.add('sb-closed'); } catch (e) {}
    document.getElementById('hdBurger').addEventListener('click', function () {
      if (phone.matches) { body.classList.toggle('sb-open'); return; }
      var closed = body.classList.toggle('sb-closed');
      try { localStorage.setItem(KEY, closed ? 'closed' : 'open'); } catch (e) {}
    });
    document.addEventListener('click', function (e) {
      if (phone.matches && body.classList.contains('sb-open') && !e.target.closest('aside.sidebar, #hdBurger')) body.classList.remove('sb-open');
    });
  })();
