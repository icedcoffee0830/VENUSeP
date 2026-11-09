/* THE TRANSACTION HISTORY PRINTOUT (2026-11-08; the customer's page prints it too since
   2026-11-09). partials/transaction-print is the paper: USeP's letterhead, the title, the
   filters, the rows and who printed it. It prints exactly the rows on the table's current
   page; pick "All" in the page size to print the whole (filtered) list. The columns are
   the table's print ones (print / titlePrint / formatterPrint). Ctrl+P fires beforeprint,
   which does the same.

   venusepTransactionPrint(table, filtersInUse) returns the Print button's handler.
   filtersInUse() lists the filters set, in words; venusepPrintDay('2026-11-09') reads
   "Nov 9, 2026" for them. */
function venusepPrintDay(value) {
  return new Date(`${value}T00:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

function venusepTransactionPrint(table, filtersInUse) {
  const printOut = document.querySelector('.th-print');
  document.body.appendChild(printOut);   /* a child of <body>, so printing can hide everything else; its images load now */
  const WRAP = ['Customer Name', 'Venue', 'Payment Status', 'Booking Status'];   /* the long-text columns may wrap, found by name since columns can be moved */
  let printPrepared = false;
  const prepareForPrint = () => {
    if (printPrepared) return;
    printPrepared = true;
    const tableBox = printOut.querySelector('[data-print="table"]');
    tableBox.innerHTML = table.getHtml('visible', false, null, 'print');   /* unstyled: the screen's sizes, copied onto every cell, would push the table off the paper */
    const heads = [...tableBox.querySelectorAll('thead th')].map((th) => th.textContent.trim());
    tableBox.querySelectorAll('tbody tr').forEach((tr) => [...tr.children].forEach((td, i) => {
      if (WRAP.includes(heads[i])) td.classList.add('th-print-wrap');
    }));
    const printed = tableBox.querySelectorAll('tbody tr').length;
    const pages = table.getPageMax();
    const filters = filtersInUse();
    printOut.querySelector('[data-print="filters"]').textContent = `Filters: ${filters.length ? filters.join(' · ') : 'none'}`;
    printOut.querySelector('[data-print="rows"]').textContent = `Rows: ${printed} of ${table.getDataCount('active')}`
      + (pages > 1 ? ` (page ${table.getPage()} of ${pages})` : '');
    printOut.querySelector('[data-print="printed"]').textContent = 'Printed ' + new Date().toLocaleString('en-PH',
      { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    document.documentElement.classList.add('th-printing');
  };
  const restoreAfterPrint = () => {
    if (!printPrepared) return;
    printPrepared = false;
    document.documentElement.classList.remove('th-printing');
    printOut.querySelector('[data-print="table"]').innerHTML = '';
  };
  window.addEventListener('beforeprint', prepareForPrint);
  window.addEventListener('afterprint', restoreAfterPrint);
  return () => {
    prepareForPrint();
    requestAnimationFrame(() => setTimeout(() => window.print(), 50));
  };
}
