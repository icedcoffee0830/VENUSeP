/* =====================================================================
   LIST PAGES: the pieces they share. Pair: public/assets/css/list-page.css.

   venusepPager(noun)   Tabulator options for the one-line pager:
                        "1–7 of 7 people   ‹ ›   Rows 10".
   .lp-filters-btn      on a phone, opens the filters of its .lp-tools.
   .lp-menu             a native popover: opens under its button.
   ===================================================================== */
(function () {
  'use strict';

  window.venusepPager = function (noun) {
    return {
      paginationButtonCount: 0,
      langs: { default: { pagination: { page_size: 'Rows', prev: '‹', next: '›' } } },
      paginationCounter: function (size, row, page, total) {
        if (!total) return '0 ' + noun;
        return row + '–' + Math.min(row + size - 1, total) + ' of ' + total + ' ' + noun;
      },
    };
  };

  document.addEventListener('click', function (event) {
    var button = event.target.closest('.lp-filters-btn');
    if (!button) return;
    var open = button.closest('.lp-tools').classList.toggle('is-open');
    button.setAttribute('aria-expanded', String(open));
  });

  /* A popover sits in the top layer, so no table can cut it off; it only needs placing. */
  document.addEventListener('toggle', function (event) {
    var menu = event.target;
    if (!menu.classList || !menu.classList.contains('lp-menu') || event.newState !== 'open') return;
    var anchor = document.querySelector('[popovertarget="' + menu.id + '"]').getBoundingClientRect();
    var width = menu.offsetWidth, height = menu.offsetHeight;
    menu.style.left = Math.max(8, Math.min(anchor.right - width, window.innerWidth - width - 8)) + 'px';
    menu.style.top = (anchor.bottom + height + 8 > window.innerHeight ? Math.max(8, anchor.top - height - 4) : anchor.bottom + 4) + 'px';
  }, true);
})();
