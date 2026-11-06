/* Script for resources/views/admin/dashboard.blade.php. */
/* ============================================================
   DASHBOARD SCRIPT (demo only, no backend):
   · RES / PAY / BR [SIM] — copied from booking-requests.php so the
     dashboard speaks the SAME status vocabulary; keep them in sync
   · fills the four needs-action counts (same buckets as queue tabs)
   · builds the urgent-first preview rows (each row links to detail)
   · minimal "+ New Booking" modal wiring (open/close/demo submit)
   ============================================================ */
const RES = {
  pending:   { t: 'Pending review', c: 'b-amber' },
  approved:  { t: 'Approved',       c: 'b-green' },
  completed: { t: 'Completed',      c: 'b-navy'  },
  released:  { t: 'Released',       c: 'b-gray'  },
  /* The rest of reservation_statuses — the preview renders real rows now, and
     an unmapped code threw on RES[r.res].c. */
  rejected:  { t: 'Rejected',       c: 'b-red'   },
  cancelled: { t: 'Cancelled',      c: 'b-gray'  },
  disrupted: { t: 'Room closed · action needed', c: 'b-red' },
};
const PAY = {
  locked:      { t: 'Payment locked',              c: 'b-gray'  },
  written_off: { t: 'Written off',                 c: 'b-gray'  },
  await_gcash: { t: 'Awaiting payment · GCash',    c: 'b-amber' },
  await_cash:  { t: 'Awaiting payment · Cash',     c: 'b-amber' },
  auto_pass:   { t: 'Receipt passed auto-check',   c: 'b-navy'  },
  review:      { t: 'Receipt · manual review',     c: 'b-amber' },
  rejected:    { t: 'Receipt rejected',          c: 'b-red'   },
  confirmed:   { t: 'Payment confirmed',           c: 'b-green' },
  paid_cash:   { t: 'Paid at cashier',             c: 'b-green' },   /* fallback: rows carry their own payLabel (hostel: front desk) */
  overdue:     { t: 'Payment overdue',             c: 'b-red'   },
  /* POST-PAY (DB-DECISIONS #18): approved while refunds were OFF, so nothing
     can be paid until the event is over. Not waiting on anyone. */
  await_event: { t: 'Payment due after event',     c: 'b-gray'  },
  refund_req:  { t: 'Refund · under verification', c: 'b-navy'  },
  /* HOSTEL ONLY — waiting on CEDU, not on the customer. */
  await_pos:        { t: 'Awaiting POS · CEDU',              c: 'b-amber' },
  expired:          { t: 'Expired',                          c: 'b-gray'  },
  under_review:     { t: 'Receipt · manual review',          c: 'b-amber' },
  refund_requested: { t: 'Refund · under verification',      c: 'b-navy'  },
  refund_correction:{ t: 'Refund · returned for correction', c: 'b-amber' },
  refund_await_or:  { t: 'Refund · awaiting Official Receipt', c: 'b-amber' },
  refund_processing:{ t: 'Refund · processing',              c: 'b-navy'  },
  refunded:         { t: 'Refunded',                         c: 'b-gray'  },
  refund_denied:    { t: 'Refund denied',                    c: 'b-red'   },
};
/* Every booking that needs someone — one builder, shared with the queue. */
let BR = Live.data('ad-rows');

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

/* the four needs-action counts = the queue's tab definitions */
const countOf = (k) => BR.filter((r) => r.cat === k).length;
function adDraw() {
document.getElementById('statId').textContent = countOf('id');
document.getElementById('statConfirm').textContent = countOf('confirm');
document.getElementById('statReview').textContent = countOf('review');
document.getElementById('statOverdue').textContent = countOf('overdue');

/* latest-first preview: newest request at the top, by submission order (`seq`
   is the booking's own auto-increment id, so it never needs date parsing and
   never ties). The full queue (booking-requests.php) keeps its own
   urgency-first sort — this panel is a "what just came in" preview, not the
   work queue, so it reads chronologically instead. Top 5 shown. */
document.getElementById('adRows').innerHTML = BR.slice()
  .sort((a, b) => b.seq - a.seq)
  .slice(0, 5)
  .map((r) => {
    const res = RES[r.res] || { t: r.res || 'Unknown reservation status', c: 'b-gray' };
    const pay = PAY[r.pay] || { t: r.pay || 'Unknown payment status', c: 'b-gray' };
    return `
    <a class="ad-row" href="${BR_DETAIL}?id=${r.id}">
      <span><div class="r-name">${esc(r.name)}</div><div class="r-sub">${r.id} · ${esc(r.type)}${r.office ? ' · ' + esc(r.office) : ''}</div></span>
      <span class="r-roomcol"><div class="r-room">${esc(r.room)}</div><div class="r-sub">${esc(r.dates)}</div></span>
      <span>
        <span class="r-badges">
          <span class="br-badge ${res.c}">${res.t}</span>
          <span class="br-badge ${pay.c}">${r.pay === 'paid_cash' && r.payLabel ? esc(r.payLabel) : pay.t}</span>
        </span>
        <div class="r-act ${r.cls}">${esc(r.act)}</div>
      </span>
      <span class="r-chev">&rsaquo;</span>
    </a>`;
  }).join('');
}
adDraw();
/* the 30-second check: the tiles and the preview rows, no reload */
Live.watch('queue', () => Live.refresh().then(() => { BR = Live.data('ad-rows'); adDraw(); }));

/* The "+ New Booking" modal that used to sit here is gone. It was a flat form
   that booked nothing — searchAccounts(), fetchRooms() and submitWalkin() were
   empty stubs and an alert. Walk-ins now go through the real booking page in
   counter mode (includes/auth.php), so the counter screen and the customer
   screen are the same screen and cannot drift apart. */
