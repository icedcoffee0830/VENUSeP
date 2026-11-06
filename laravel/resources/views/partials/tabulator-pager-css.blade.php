{{-- The table pager wraps instead of hiding Next and Last past the table's edge. --}}
      .tabulator .tabulator-footer, .tabulator .tabulator-footer .tabulator-footer-contents { white-space: normal; }
      .tabulator .tabulator-footer .tabulator-footer-contents { flex-wrap: wrap; row-gap: .5rem; }
      .tabulator .tabulator-footer .tabulator-paginator { display: flex; flex-wrap: wrap; align-items: center; row-gap: .4rem; }
      /* Each page moves the pager above the rows (tableBuilt), so its line goes under it. Doubled class: the pages set border-top themselves, some after this. */
      .tabulator .tabulator-footer.tabulator-footer { border-top: 0; border-bottom: 1px solid #e7e2db; }
