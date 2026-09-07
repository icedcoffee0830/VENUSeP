<?php ?>
<!DOCTYPE html>
<!-- ==================================================================
  USeP ROOM RESERVATION — customer booking UI (self-contained mockup)
  ==================================================================
  One page, three parts: a small style block, an empty div (id="app"),
  and ONE big script that draws every screen into #app.

  ENTRY: the landing page (venusep_venue_booking.php) links here with
  ?room=r1…r8. Room BROWSING happens on the landing page now — the old
  in-page browse screen was removed in this VENUSeP port.

  HOW IT WORKS: the `state` object holds what the user is doing right
  now; every click updates `state` then calls render(), which redraws
  the current screen from scratch.

  MAP OF THE SCRIPT — Ctrl+F the quoted text to jump to that section:

    "---------- data"                constants + the ROOMS list (edit rooms here)
    "---------- state"               the one object that drives the whole UI
    "---------- helpers"             formatting, icons, date/availability logic
    "---------- actions"             small functions the buttons call
    "---------- custom date-picker"  calendar popup + time dropdowns
    "---------- 360 panorama"        hero panorama + photo-gallery lightbox
    "---------- approve-first flow"  ID upload → pending → demo staff approval
    "GCASH RECEIPT CHECKER"          OCR + verdict engine (big banner explains it)
    "---------- shared bits"         page header
    "screen: DETAIL"                 room page + booking panel (entry screen)
    "screen: REVIEW"                 booking summary + valid-ID upload
    "screen: PENDING"                waiting-for-staff-approval screen
    "screen: PAYMENT"                GCash / cash step (uses the checker)
    "screen: CONFIRMATION"           done screen
    "---------- modals"              12-hour rule + booked-date warnings
    "---------- render"              redraws the current screen into #app

  [SIM] = simulation-only, so the mockup works with no server. Delete
  or replace these when the real system + database is connected:
    · ROOMS + ACCOUNT     sample rooms and a fake logged-in customer
    · GCASH_ACCOUNTS      one GCash account per venue, from
                          includes/payment-settings.php (edited in admin
                          Payment Settings). gcAccount() picks by room.
    · CASH_PAY            cashier location — placeholder, not decided yet
    · SAMPLE_PANO         demo 360° image used for every room
    · demoApprove()       fake "staff approved" button (pending screen)
    · localStorage store  stands in for the receipts database
  ================================================================== -->
<html lang="en">
<head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VENUSeP | Room Reservation</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..800&display=swap" rel="stylesheet">
<!-- Pannellum 360 panorama viewer (same lib as the 360 test) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.css">
<script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.js"></script>
<!-- Tesseract.js — in-browser OCR for the GCash receipt checker (needs internet) -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<style>
  *{box-sizing:border-box}
  body{margin:0;background:#f4f3f0;color:#1c1b19;font-family:'Inter',system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
  a{color:#1f2a44;text-decoration:none}
  a:hover{color:#2f3f66}
  input,select,button,textarea{font-family:inherit}
  input:focus,select:focus,textarea:focus{outline:2px solid rgba(31,42,68,.35);outline-offset:0}
  ::placeholder{color:#a5a19a}
  button{appearance:none}
  /* custom date-picker dropdown (booking panel) */
  .cal-pop{position:absolute;top:calc(100% + 6px);z-index:70;background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;box-shadow:0 12px 36px rgba(0,0,0,.14);padding:12px;width:274px}
  .cal-nav{width:28px;height:28px;border:none;background:none;border-radius:8px;cursor:pointer;color:#5c584f;font-size:15px;line-height:1}
  .cal-nav:hover{background:#f0eeea}
  .cal-day{width:32px;height:32px;border:none;background:none;border-radius:999px;display:flex;align-items:center;justify-content:center;font:500 12.5px 'Inter',system-ui,sans-serif;color:#1c1b19;cursor:pointer;padding:0}
  .cal-day:hover:not(:disabled){background:#eef0f4}
  .cal-day:disabled{color:#c6c2ba;text-decoration:line-through;cursor:default;background:none}
  .cal-day.out{color:#c6c2ba}
  .cal-day.today:not(.sel){box-shadow:inset 0 0 0 1.5px #b9c0d0}
  .cal-day.sel,.cal-day.sel:hover{background:#1f2a44;color:#fff;font-weight:650}
  /* time dropdown (booking panel) */
  .tt-pop{position:absolute;top:calc(100% + 6px);left:0;right:0;min-width:118px;z-index:70;background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:12px;box-shadow:0 12px 36px rgba(0,0,0,.14);max-height:196px;overflow-y:auto;padding:5px}
  .tt-opt{display:block;width:100%;border:none;background:none;text-align:left;padding:8px 11px;border-radius:8px;font:500 13.5px 'Inter',system-ui,sans-serif;color:#1c1b19;cursor:pointer;white-space:nowrap}
  .tt-opt:hover{background:#f0eeea}
  .tt-opt.sel,.tt-opt.sel:hover{background:#1f2a44;color:#fff}
</style>
</head>
<body>
<!-- empty shell — render() (bottom of the script) draws the current screen in here -->
<div id="app"></div>

<?php
/* The GCash account per venue, from the ONE source that admin Payment Settings
   edits. This page serves rooms from BOTH Bahay Alumni and USeP Venues, and
   they have DIFFERENT accounts — so the account is resolved per room, not per
   page (see gcAccount() below). Both the checker and the payment screen read
   it, so what the customer is told and what is verified cannot diverge. */
include __DIR__ . '/../includes/payment-settings.php';
include __DIR__ . '/../includes/venue-rooms.php';
include __DIR__ . '/../includes/gcash-checker.php';
?>
<script>
/* [SIM] every venue's GCash account — replaced by a lookup when there is a DB. */
const GCASH_ACCOUNTS = <?php echo json_encode($gcAccounts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
/* The venue rooms come from the ONE shared source (includes/venue-rooms.php),
   so this page, the landing page and admin Venue Management cannot disagree. */
const ROOMS = <?php echo json_encode($venueRooms, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script>
/* ============================================================
   USeP Room Reservation — customer booking UI
   Plain HTML + vanilla JS (static front-end mockup).
   Supports MULTI-DAY reservations (start date → end date).
   ============================================================ */

/* ---------- data ---------- */
/* "Now" is the real clock at page load (matches the live PHP app). The 12-hour
   advance rule and the earliest selectable date are both measured from here. */
const NOW = new Date();
const LEAD_MS = 12 * 60 * 60 * 1000;              // bookings must start ≥ 12 h from now
const TODAY = isoOf(NOW);

/* [SIM] Sample equirectangular panorama (swap for real per-room images later). */
const SAMPLE_PANO = 'https://pannellum.org/images/alma.jpg';

/* [SIM] Business GCash account the receipt must be sent to. Mirrors the checker's
   admin "Payment Settings" (kept identical to C:\xamp5\htdocs\gcash-checker
   settings.json so the same sample receipts verify here). In the real app this
   comes from the admin Payment Settings / database, not a hard-coded constant. */
/* the GCash accounts now come from includes/payment-settings.php as
   GCASH_ACCOUNTS (see the include block above); gcAccount() picks the right one
   for the booked room's venue, and both the checker and the payment screen use
   it — one source, so they cannot disagree. */

/* [SIM] Where walk-in / cash payments are made. PLACEHOLDER — the real payment
   location hasn't been decided yet; swap these strings when it is. */
const CASH_PAY = {
  office:'USeP Cashier — Venue Reservations Window',
  address:'Ground Floor, Administration Building, USeP Obrero Campus, Davao City',
  hours:'Mon–Fri · 8:00 AM – 5:00 PM',
};

/* [SIM] the "logged-in customer" — in the real app this comes from the login session. */
const ACCOUNT = { name:'Juan Miguel Dela Cruz', role:'Student · CIC', email:'jmdelacruz@usep.edu.ph', phone:'0917 555 0123' };

/* ROOMS is defined at the top of the page from includes/venue-rooms.php — the ONE
   shared source, so the booking page, the landing page and admin Venue Management
   cannot disagree about what exists. Rooms have NO `status` field:
   "Available"/"Occupied" were only ever `booked[]` wearing a word; closures live
   in `maintenance`. The real app SELECTs these from the database. */

/* ---------- state ---------- */
/* entry: the landing page (venusep_venue_booking.php) links here as
   room-reservation.php?room=r1 … r8. An unknown or missing room id sends
   the visitor back to the landing page. */
const ROOM_PARAM = new URLSearchParams(location.search).get('room');
const ROOM_OK = ROOMS.some(r=>r.id===ROOM_PARAM);
if(!ROOM_OK) location.replace('venusep_venue_booking.php');

let state = {
  screen: 'detail',                                                   // detail | review | pending | payment | done
  roomId: ROOM_OK ? ROOM_PARAM : ROOMS[0].id,   // fallback only renders while the redirect above happens
  tab: 'overview',                                                    // overview | details | availability | policies
  // start/end are the default hours (used for single-day and as the seed for new
  // days); times{} holds per-day overrides for multi-day bookings, keyed by date.
  booking: { eventName:'', date:'', dateEnd:'', start:'', end:'', attendees:'', times:{} },
  payMethod: 'gcash',                                                // 'gcash' | 'cash' (walk-in, pay at the venue office)
  idFile: null,                                                      // uploaded valid ID: null | {name, url} — required to submit
  approved: false,                                                   // staff approved the ID + reservation (payment unlocked)
  agreeExact: false,                                                 // EXACT-amount disclaimer ticked?
  ocr: null,                                                         // receipt check: null | {phase:'reading'|'done', pct, pass, fileName, thumb, rec}
  reference: 'USEP-' + Math.floor(100000 + Math.random()*900000),
  cal: null,                                                         // open date picker: null | {field:'date'|'dateEnd', month:'YYYY-MM'}
  timeDd: null,                                                      // open time dropdown: null | field id
  modal: null,                                                       // null | 'lead' (12-h rule) | {type:'clash', day, soft}
  warnedClash: null,                                                 // last clash already shown as a modal (don't re-nag)
};

/* ---------- helpers ---------- */
function peso(n){ return '₱' + Number(n).toLocaleString('en-PH'); }
function toMin(t){ if(!t) return null; const [h,m]=t.split(':').map(Number); return h*60+m; }
function fmtTime(t){ if(!t) return ''; let [h,m]=t.split(':').map(Number); const ap=h>=12?'PM':'AM'; h=h%12||12; return h+':'+String(m).padStart(2,'0')+' '+ap; }
function fmtDate(d){ if(!d) return '—'; const dt=new Date(d+'T00:00:00'); return dt.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'}); }
function dayCount(d1,d2){ if(!d1) return 0; if(!d2||d2===d1) return 1; const a=new Date(d1+'T00:00:00'), b=new Date(d2+'T00:00:00'); const n=Math.round((b-a)/86400000)+1; return n>0?n:0; }
function fmtRange(d1,d2){ if(!d1) return '—'; if(!d2||d2===d1) return fmtDate(d1); const a=new Date(d1+'T00:00:00'), b=new Date(d2+'T00:00:00'); return a.toLocaleDateString('en-US',{month:'short',day:'numeric'})+' – '+b.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
function getRoom(){ return ROOMS.find(r=>r.id===state.roomId) || null; }
function initials(name){ return name.split(' ').map(w=>w[0]).slice(0,2).join(''); }
function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function svgUsers(size){ return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`; }
function svgCalendar(s){ return `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex:none;color:#8a857d"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>`; }
function svgClock(s){ return `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex:none;color:#8a857d"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`; }
function svgCaret(s){ return `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:none;color:#a5a19a;margin-left:auto"><polyline points="6 9 12 15 18 9"/></svg>`; }

/* line icons for amenities, matched by keyword to our event-venue scope */
const AMENITY_ICONS = (function(){
  const w='width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"';
  const S=(inner)=>`<svg ${w}>${inner}</svg>`;
  return {
    monitor:S('<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>'),
    speaker:S('<rect x="5" y="2" width="14" height="20" rx="2"/><circle cx="12" cy="14" r="4"/><line x1="12" y1="6" x2="12.01" y2="6"/>'),
    mic:S('<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>'),
    wifi:S('<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>'),
    wind:S('<path d="M9.59 4.59A2 2 0 1 1 11 8H2"/><path d="M12.59 19.41A2 2 0 1 0 14 16H2"/><path d="M17.73 7.73A2.5 2.5 0 1 1 19.5 12H2"/>'),
    chair:S('<path d="M5 19h14"/><path d="M6 19v2"/><path d="M18 19v2"/><path d="M6 15V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v9"/><path d="M6 12h12"/>'),
    table:S('<rect x="3" y="7" width="18" height="4" rx="1"/><line x1="6" y1="11" x2="6" y2="18"/><line x1="18" y1="11" x2="18" y2="18"/>'),
    stage:S('<rect x="3" y="4" width="18" height="11" rx="1"/><line x1="12" y1="15" x2="12" y2="19"/><line x1="8" y1="19" x2="16" y2="19"/>'),
    bulb:S('<path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5.76.76 1.23 1.52 1.41 2.5"/>'),
    power:S('<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'),
    board:S('<rect x="3" y="3" width="18" height="14" rx="1"/><line x1="12" y1="17" x2="12" y2="21"/><line x1="7" y1="21" x2="17" y2="21"/>'),
    coffee:S('<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>'),
    camera:S('<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>'),
    door:S('<path d="M3 21h18"/><path d="M6 21V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v17"/><circle cx="15" cy="12" r="1"/>'),
    check:S('<polyline points="20 6 9 17 4 12"/>'),
  };
})();
function amenityIcon(label){
  const t=String(label).toLowerCase();
  const has=(...ks)=>ks.some(k=>t.includes(k));
  let k='check';
  if(has('projector','screen',' tv','tv ','tv/','hdmi','led','backdrop','wall-mounted')) k='monitor';
  else if(has('sound','pa system','surround','speaker','audio')) k='speaker';
  else if(has('mic','microphone')) k='mic';
  else if(has('wi-fi','wifi','internet')) k='wifi';
  else if(has('air-condition','air condition','aircon','conditioned','open-air','outdoor')) k='wind';
  else if(has('light')) k='bulb';
  else if(has('stage','podium','riser','modular')) k='stage';
  else if(has('chair','seating','seat','bleacher','theater','layout','u-shape')) k='chair';
  else if(has('table')) k='table';
  else if(has('power','outlet','catering')) k='power';
  else if(has('whiteboard','board')) k='board';
  else if(has('coffee','pantry','restaurant','food','snack')) k='coffee';
  else if(has('camera','video')) k='camera';
  else if(has('gate','entry','entrance','load-in','access','backstage','room')) k='door';
  return AMENITY_ICONS[k];
}
function isoOf(dt){ return dt.getFullYear()+'-'+String(dt.getMonth()+1).padStart(2,'0')+'-'+String(dt.getDate()).padStart(2,'0'); }

/* every ISO date in the inclusive range [d1, d2] */
function rangeDates(d1,d2){
  if(!d1) return [];
  const end = d2 || d1;
  if(end < d1) return [];
  const out=[]; let cur=new Date(d1+'T00:00:00'); const last=new Date(end+'T00:00:00');
  while(cur<=last && out.length<366){ out.push(isoOf(cur)); cur.setDate(cur.getDate()+1); }
  return out;
}

/* the hours for one day: a per-day override if set, else the default start/end */
function dayTime(dateStr){
  const t=state.booking.times[dateStr];
  return { start:(t&&t.start!=null?t.start:state.booking.start)||'', end:(t&&t.end!=null?t.end:state.booking.end)||'' };
}

/* ---------- maintenance ----------
   A room carries at most ONE window (or null):
       { from:'ISO', until:'ISO'|null, reason:'…', blocks:true|false }
   `until:null` = INDEFINITE. That means no END date — not "no date": staff still
   set a review date so the room can't be closed and forgotten (admin side).
   `blocks:true`  = HARD   — the room cannot be booked on those dates.
   `blocks:false` = MEDIUM — still fully bookable; the window is a DISCLOSURE and
                    must NEVER reach isDayBlocked(). Two tiers, one shape.
   ISO dates compare as strings, same as everywhere else in this file. */
function maintCovers(m,ds){ return !!m && ds>=m.from && (m.until==null || ds<=m.until); }  // covers THIS date? → the gate
function maintLive(m){ return !!m && (m.until==null || m.until>=TODAY); }                  // still relevant at all? → the chip
function maintUntilLabel(m){ return m.until ? 'Until '+fmtDate(m.until) : 'No set return date'; }

/* A day is BLOCKED when anything stops it being booked. ONE question, two
   sources: an existing booking, or HARD maintenance. Returns null when the day
   is free, otherwise the REASON — so the status card and the modal read the
   answer instead of each re-deriving it and drifting apart. */
function dayBlock(ds){
  const r=getRoom(); if(!r) return null;
  const slots=r.booked.filter(b=>b.date===ds);
  if(slots.length) return { why:'booked', slots };
  if(maintCovers(r.maintenance,ds) && r.maintenance.blocks) return { why:'maintenance', m:r.maintenance };
  return null;
}
function isDayBlocked(ds){ return !!dayBlock(ds); }

/* the dates the customer is actually booking: the range minus unavailable days
   — those are excluded automatically, so a Jul 17–20 pick with Jul 18 taken
   books Jul 17 + 19 + 20 around it. */
function activeDates(){
  return rangeDates(state.booking.date, state.booking.dateEnd||state.booking.date).filter(ds=>!isDayBlocked(ds));
}

/* the earliest reservation moment: first ACTIVE day at its start time (or null) */
function earliestStart(){
  const dates=activeDates();
  if(!dates.length) return null;
  const t=dayTime(dates[0]);
  if(!t.start) return null;
  const dt=new Date(dates[0]+'T'+t.start+':00');
  return isNaN(dt.getTime()) ? null : dt;
}
/* the 12-hour advance rule: earliest start must be at least 12 h from now */
function isTooSoon(){ const dt=earliestStart(); return dt!=null && (dt-NOW) < LEAD_MS; }
function earliestAllowed(){ return new Date(NOW.getTime()+LEAD_MS); }

/* true when every day in the range shares the same start/end hours */
function sameHoursEveryDay(dates){
  if(dates.length<2) return true;
  const a=dayTime(dates[0]);
  return dates.every(ds=>{ const t=dayTime(ds); return t.start===a.start && t.end===a.end; });
}

/* Availability, checked per day: each day carries its own hours (dayTime), so a
   day clashes if a booked slot on that same date overlaps that day's window.
   Dates are ISO so they compare as strings. */
function slotStatus(){
  const r=getRoom(); const {date,dateEnd}=state.booking;
  if(!r||!date) return { show:false };
  const end2 = dateEnd || date;
  if(end2 < date) return { show:true, ok:false, badRange:true };
  const allDates=rangeDates(date,end2);
  const dates=allDates.filter(ds=>!isDayBlocked(ds));              // booked OR closed dates are excluded automatically
  if(!dates.length) return { show:true, ok:false, allBlocked:true, why:(dayBlock(allDates[0])||{}).why, allDates };

  // find the first day whose hours are incomplete or out of order (for a precise message)
  let anyInput=false, badDay=null, badReason='';
  for(const ds of dates){
    const t=dayTime(ds);
    if(t.start||t.end) anyInput=true;
    if(badDay) continue;
    if(!t.start||!t.end){ badDay=ds; badReason='missing'; continue; }
    const s=toMin(t.start), e=toMin(t.end);
    if(s===null||e===null){ badDay=ds; badReason='missing'; }
    else if(e<=s){ badDay=ds; badReason='order'; }
  }
  if(!anyInput) return { show:false };
  if(badDay)   return { show:true, ok:false, invalid:true, badDay, badReason, date, dateEnd:end2, dates };

  // unavailable dates were already excluded above, so every remaining day is free
  return { show:true, ok:true, date, dateEnd:end2, dates, allDates };
}

/* first unavailable day in the chosen range — used for the "this date is not
   available" modal right after the dates are picked */
function firstBlockedDayInRange(){
  const {date,dateEnd}=state.booking;
  for(const ds of rangeDates(date, dateEnd||date)){
    if(isDayBlocked(ds)) return { day:ds };
  }
  return null;
}

/* Open the "this date is not available" modal once when the picked range
   contains an unavailable day. A multi-day range books AROUND it (the day is
   excluded automatically); a single unavailable date simply can't be reserved. */
function maybeWarnClash(){
  if(state.modal==='lead') return;                                   // 12-h rule modal wins
  const info=firstBlockedDayInRange();
  if(info){
    if(state.warnedClash!==info.day){ state.warnedClash=info.day; state.modal={type:'clash', day:info.day}; }
    return;
  }
  state.warnedClash=null;
  if(state.modal && state.modal.type==='clash') state.modal=null;
}

/* Everything the booking panel + review/confirm screens derive from state. */
function derive(){
  const R=getRoom();
  const b=state.booking;
  const allDates=rangeDates(b.date, b.dateEnd||b.date);
  const actDates=activeDates();                       // same filter as everywhere else — never a second copy
  const days=Math.max(1, actDates.length);            // billable days = available days only
  const excluded=allDates.length-actDates.length;     // booked or closed days, excluded automatically
  const totalFee=R ? R.fee*days : 0;

  const sr=slotStatus();
  const multi = allDates.length>1;                    // the range spans several days (per-day editor shows)

  /* precise wording for an incomplete/out-of-order day */
  let badDayLabel='', badFix='';
  if(sr.show && sr.invalid){
    const i=sr.dates.indexOf(sr.badDay);
    const dl=new Date(sr.badDay+'T00:00:00').toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'});
    badDayLabel = (multi ? ('Day '+(i+1)+' (') : '') + dl + (multi ? ')' : '');
    badFix = sr.badReason==='missing' ? 'add both a start and end time' : 'set the end time later than the start time';
  }

  /* 12-hour advance rule (mirrors Booking_Refined): the earliest reservation
     start — the first day at its start time — must be at least 12 h from now. */
  const tooSoon = sr.show && !sr.badRange && !sr.invalid && isTooSoon();

  /* status card: neutral surface + a small colored dot (amber = fix something,
     green = good to go) — calmer than the old solid red/green boxes */
  let slot={show:false};
  if(sr.show){
    if(sr.badRange){
      slot={show:true,dot:'#d9930d',title:'End date must be on or after the start date',detail:'Adjust your reservation dates to check availability.'};
    } else if(sr.allBlocked){
      slot={show:true,dot:'#d9930d',title: multi?'These dates are not available':'This date is not available',
        detail: sr.why==='maintenance' ? 'The room is closed for maintenance on these dates — please pick different dates.'
                                       : 'Already booked — please pick different dates.'};
    } else if(sr.invalid){
      let title;
      if(multi) title = badDayLabel+(sr.badReason==='missing'?' needs its hours set':' ends before it starts');
      else title = sr.badReason==='missing' ? 'Add a start and end time' : 'End time must be after start time';
      slot={show:true,dot:'#d9930d',
        title,
        detail: 'Please '+badFix+' for '+(multi?badDayLabel:'this booking')+'.'};
    } else if(tooSoon){
      slot={show:true,dot:'#d9930d',title:'Too soon — book at least 12 hours ahead',detail:'Reservations must start at least 12 hours from now. Pick a later date or start time.'};
    } else if(sr.ok){
      let timeLbl;
      if(sr.dates.length<2){ const t=dayTime(sr.dates[0]); timeLbl=fmtTime(t.start)+' – '+fmtTime(t.end); }
      else if(sameHoursEveryDay(sr.dates)){ const t=dayTime(sr.dates[0]); timeLbl='daily, '+fmtTime(t.start)+' – '+fmtTime(t.end); }
      else { timeLbl='custom hours per day'; }
      const exclNote = excluded>0 ? (' · '+excluded+' unavailable day'+(excluded>1?'s':'')+' excluded') : '';
      slot={show:true,dot:'#2f9e63',title: multi?'These dates are available':'This slot is available',detail:fmtRange(sr.date,sr.dateEnd)+' · '+timeLbl+exclNote};
    }
  }

  const ba=parseInt(b.attendees,10);
  const over=R && !isNaN(ba) && ba>R.capacity;

  const bEnd=b.dateEnd||b.date;
  const dateOk=b.date && bEnd && bEnd>=b.date;
  const slotFree=sr.show && sr.ok && !tooSoon;
  const ready=R && b.eventName && dateOk && slotFree && !isNaN(ba) && ba>0;

  let hint='';
  if(!b.eventName) hint='Enter an event name to continue';
  else if(!dateOk) hint='Pick valid reservation dates';
  else if(sr.show && sr.badRange) hint='End date must be on or after the start date';
  else if(sr.show && sr.allBlocked) hint = sr.why==='maintenance' ? 'Closed for maintenance — pick different dates' : 'These dates are already booked — pick different ones';
  else if(sr.show && sr.invalid) hint= (multi?badDayLabel+': ':'')+badFix;
  else if(!sr.show) hint='Set the hours to check availability';
  else if(tooSoon) hint='Bookings must be made at least 12 hours in advance';
  else if(isNaN(ba)||ba<=0) hint='Enter the number of attendees';

  return { R, b, days, multi, excluded, allDates, dates:sr.dates||actDates, totalFee, slot, over, ready, hint };
}

/* ---------- actions ---------- */
function setState(patch){ Object.assign(state, patch); render(); }
function setBooking(key,val){
  // choosing a start date auto-fills / bumps the end date so the range stays valid
  if(key==='date' && (!state.booking.dateEnd || state.booking.dateEnd < val)) state.booking.dateEnd=val;
  state.booking[key]=val;
  // 12-hour rule: if the chosen date/time is too soon, explain it and clear the
  // offending input so they re-pick; otherwise dismiss any open explainer
  if(key==='date'||key==='start'){
    if(isTooSoon()){
      state.modal='lead';
      if(key==='date'){ state.booking.date=''; state.booking.dateEnd=''; }
      else state.booking.start='';
    } else if(state.modal==='lead') state.modal=null;
  }
  if(key==='date'||key==='dateEnd') maybeWarnClash();
  render();
}
function setDayTime(dateStr,key,val){
  const cur=state.booking.times[dateStr]||{};
  state.booking.times={ ...state.booking.times, [dateStr]:{ ...cur, [key]:val } };
  const dates=activeDates();
  if(key==='start' && dates[0]===dateStr){                                     // only day 1 affects the rule
    if(isTooSoon()){
      state.modal='lead';
      const c=state.booking.times[dateStr]||{};
      state.booking.times={ ...state.booking.times, [dateStr]:{ ...c, start:'' } };   // clear day 1's start
    } else if(state.modal==='lead') state.modal=null;
  }
  render();
}
function openLeadModal(){ state.modal='lead'; render(); }
function closeModal(){ state.modal=null; render(); }

/* ---------- custom date-picker (booking panel) ---------- */
function openCal(field){
  state.timeDd=null;
  if(state.cal && state.cal.field===field){ state.cal=null; render(); return; }   // toggle
  const cur=state.booking[field] || state.booking.date || TODAY;
  state.cal={ field, month: cur.slice(0,7) };
  render();
}
/* time dropdowns share one open-state slot, keyed by the field's id */
function toggleTimeDd(id){ state.cal=null; state.timeDd = state.timeDd===id ? null : id; render(); }
function closeTimeDd(){ if(state.timeDd){ state.timeDd=null; render(); } }
function closeCal(){ if(state.cal){ state.cal=null; render(); } }
function calNav(dir){
  if(!state.cal) return;
  const [y,m]=state.cal.month.split('-').map(Number);
  const d=new Date(y, m-1+dir, 1);
  state.cal.month=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
  render();
}
function calPick(iso){
  if(!state.cal) return;
  const f=state.cal.field;
  state.cal=null;
  // picking a start date past the current end date drags the end date along
  if(f==='date' && state.booking.dateEnd && state.booking.dateEnd<iso) state.booking.dateEnd=iso;
  setBooking(f, iso);                                 // runs the 12-h + booked-date checks
}
function calDateLabel(iso){
  return iso ? new Date(iso+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '';
}
/* the dropdown itself: month header, S–S row, 6x7 day grid. Past days and
   already-booked days are struck through and unclickable. */
function calHtml(field){
  if(!state.cal || state.cal.field!==field) return '';
  const [y,m]=state.cal.month.split('-').map(Number);
  const first=new Date(y, m-1, 1);
  const monthLabel=first.toLocaleDateString('en-US',{month:'long',year:'numeric'});
  const min = field==='dateEnd' ? (state.booking.date||TODAY) : TODAY;
  const sel = state.booking[field]||'';
  const R=getRoom();
  let cells='';
  const cur=new Date(y, m-1, 1-first.getDay());       // back up to the Sunday on/before the 1st
  for(let i=0;i<42;i++){
    const iso=isoOf(cur);
    const inMonth=cur.getMonth()===m-1;
    const booked=!!R && R.booked.some(k=>k.date===iso);
    const off=iso<min || booked;
    const cls='cal-day'+(inMonth?'':' out')+(iso===sel?' sel':'')+(iso===TODAY?' today':'');
    cells+=off
      ? `<button type="button" class="${cls}" disabled${booked&&iso>=min?' title="Already booked"':''}>${cur.getDate()}</button>`
      : `<button type="button" class="${cls}" onclick="calPick('${iso}')">${cur.getDate()}</button>`;
    cur.setDate(cur.getDate()+1);
  }
  return `
  <div onclick="closeCal()" style="position:fixed;inset:0;z-index:69"></div>
  <div class="cal-pop" style="${field==='dateEnd'?'right:0':'left:0'}" onclick="event.stopPropagation()">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
      <button type="button" class="cal-nav" onclick="calNav(-1)">‹</button>
      <span style="font-size:13.5px;font-weight:650;color:#1c1b19">${monthLabel}</span>
      <button type="button" class="cal-nav" onclick="calNav(1)">›</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(7,1fr);justify-items:center;margin-bottom:4px">
      ${['S','M','T','W','T','F','S'].map(d=>`<span style="font-size:11px;font-weight:600;color:#a5a19a;width:32px;text-align:center">${d}</span>`).join('')}
    </div>
    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;justify-items:center">${cells}</div>
  </div>`;
}

/* ---------- 360 panorama + photo gallery (room detail hero) ---------- */
/* Inline 360 panorama in the hero's main tile. One persistent viewer node is
   moved into the hero slot on each render — appendChild relocates the live node
   without destroying it, so the frequent re-renders from the booking form never
   reload the panorama. Rebuilt only when the room changes. */
var HERO = { node:null, viewer:null, roomId:null };
function mountHeroPano(){
  if(state.screen!=='detail') return;
  const slot=document.getElementById('heroPanoSlot');
  if(!slot || typeof pannellum==='undefined') return;   // offline → leave placeholder
  const R=getRoom(); if(!R) return;
  if(!HERO.node){ HERO.node=document.createElement('div'); HERO.node.style.cssText='position:absolute;inset:0'; }
  if(HERO.node.parentNode!==slot){ slot.innerHTML=''; slot.appendChild(HERO.node); }
  if(HERO.viewer && HERO.roomId===state.roomId) return;  // already live for this room
  if(HERO.viewer){ try{ HERO.viewer.destroy(); }catch(e){} HERO.viewer=null; }
  HERO.node.innerHTML='';
  HERO.viewer=pannellum.viewer(HERO.node, {
    type:'equirectangular', panorama:(R.panorama||SAMPLE_PANO), autoLoad:true,
    showControls:true, compass:true, hfov:100, minHfov:45, maxHfov:120, mouseZoom:true, draggable:true
  });
  HERO.roomId=state.roomId;
}

/* Photo gallery lightbox — view-only for customers, mounted outside #app.
   Placeholder gradient "photos" (no real images / no user uploads). */
const GAL_GRADS=['#eef1f5,#dfe4ea','#f3eee9,#e6ddd3','#e9eef3,#d5e0ea','#eef3ee,#d9e6da','#f3eef1,#e6d5de','#eaf0f3,#d5e2ea','#f2efe8,#e4dccf','#eef3f0,#dbe7df'];
function galGrad(i){ return 'background:linear-gradient(135deg,'+GAL_GRADS[i%GAL_GRADS.length]+')'; }
function openGallery(){
  const R=getRoom(); if(!R || document.getElementById('galOverlay')) return;
  const n=Math.max(1, R.photos||5);
  let thumbs='';
  for(let i=0;i<n;i++){
    thumbs+='<button class="galThumb" data-i="'+i+'" onclick="galSelect('+i+')" style="border:2px solid transparent;border-radius:8px;cursor:pointer;padding:0;aspect-ratio:4/3;'+galGrad(i)+';display:flex;align-items:center;justify-content:center;color:#8a857d;font:500 10px ui-monospace,monospace">Photo '+(i+1)+'</button>';
  }
  const ov=document.createElement('div');
  ov.id='galOverlay';
  ov.style.cssText='position:fixed;inset:0;background:rgba(20,18,15,.75);z-index:200;display:flex;align-items:center;justify-content:center;padding:24px';
  ov.innerHTML=
    '<div style="background:#fff;border-radius:16px;max-width:960px;width:100%;max-height:90vh;display:flex;flex-direction:column;overflow:hidden">'
    +'<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid rgba(0,0,0,.08)"><div style="font-size:15px;font-weight:660">'+esc(R.name)+' · Photos</div><button onclick="closeGallery()" style="background:none;border:none;font-size:22px;line-height:1;color:#8a857d;cursor:pointer">&times;</button></div>'
    +'<div style="padding:16px 18px;overflow:auto">'
    +'<div id="galBig"></div>'
    +'<div style="font-size:12px;font-weight:600;color:#8a857d;text-transform:uppercase;letter-spacing:.04em;margin:16px 0 10px">All photos</div>'
    +'<div id="galGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px">'+thumbs+'</div>'
    +'</div></div>';
  ov.addEventListener('click',function(e){ if(e.target===ov) closeGallery(); });
  document.body.appendChild(ov);
  galSelect(0);
}
function galSelect(i){
  const big=document.getElementById('galBig');
  if(big){ big.setAttribute('style', galGrad(i)+';border-radius:12px;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;color:#8a857d;font:500 14px ui-monospace,monospace'); big.textContent='Photo '+(i+1); }
  const g=document.getElementById('galGrid');
  if(g) g.querySelectorAll('.galThumb').forEach(function(b){ b.style.borderColor=(+b.getAttribute('data-i')===i)?'#1f2a44':'transparent'; });
}
function closeGallery(){ const ov=document.getElementById('galOverlay'); if(ov) ov.remove(); }
function applyTimeToAll(){
  const dates=activeDates();
  if(!dates.length) return;
  const t=dayTime(dates[0]);                 // use the first available day's hours as the default...
  state.booking.start=t.start; state.booking.end=t.end;
  state.booking.times={};                     // ...and clear overrides so every day inherits them
  render();
}
/* header logo + "← All rooms" go back to the landing page (room browsing lives there now) */
function goHome(){ location.href='venusep_venue_booking.php'; }
function setTab(k){ state.tab=k; render(); }
function goReview(){ if(derive().ready){ state.screen='review'; render(); } }
function backToDetail(){ state.screen='detail'; render(); }
function backToPending(){ state.screen='pending'; render(); }

/* ---------- approve-first flow ----------
   The request is submitted WITH a valid ID. Staff approve the ID and the
   reservation together; only then does the payment step unlock. */
function uploadId(input){
  const f=input.files && input.files[0];
  input.value='';
  if(!f) return;
  if(state.idFile){ try{ URL.revokeObjectURL(state.idFile.url); }catch(e){} }
  state.idFile={ name:f.name, url:URL.createObjectURL(f) };
  render();
}
function removeId(){
  if(state.idFile){ try{ URL.revokeObjectURL(state.idFile.url); }catch(e){} }
  state.idFile=null; render();
}
function submitRequest(){
  if(!derive().ready || !state.idFile) return;
  state.screen='pending'; render();
}
/* [SIM] mockup stand-in for the staff side (the two UIs aren't connected yet) —
   delete this + its button once real staff approval updates the booking in the DB */
function demoApprove(){ state.approved=true; state.screen='payment'; render(); }

/* payment deadline rule: pay at least 1 day before the event; a booking made
   closer than that pays immediately upon approval */
function payByInfo(){
  const d=state.booking.date;
  if(!d) return { label:'—', late:false };
  const pb=new Date(new Date(d+'T00:00:00').getTime()-86400000);
  if(pb<=NOW) return { label:'immediately upon approval — your event is close', late:true };
  return { label:pb.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}), late:false };
}

/* ============================================================
   GCASH RECEIPT CHECKER — the engine now lives in the shared include
   includes/gcash-checker.php (loaded above), because the hostel pays into
   a DIFFERENT GCash account and two copies of a checker drift apart:
   fix a matcher in one, the other keeps silently auto-rejecting good
   receipts as "receiver mismatch". One engine, account passed in.

   The include calls back into the two things only this page knows:
   what is owed, and which booking it is for.
   ============================================================ */
/* Which account THIS booking's money must land in. Bahay Alumni and USeP Venues
   are different accounts, and this one page serves both — so it depends on the
   room, and the checker asks for it at verdict time rather than being handed a
   page-level constant. */
function gcAccount(){
  const r=getRoom();
  return (r && GCASH_ACCOUNTS[r.venue]) || { name:'', number:'' };
}
function gcExpectedCentavos(){ return Math.round(bookingRows().d.totalFee*100); }   // venue: fee x billable days
function gcBookingRef(){ return state.reference; }
function toggleAgreeExact(el){ state.agreeExact=!!el.checked; render(); }
function setPayMethod(m){ state.payMethod=m; render(); }
function confirmBooking(){
  if(state.payMethod==='cash'){ state.screen='done'; render(); return; }   // walk-in: pay at the office, no receipt yet
  if(!receiptOk()) return;
  gcRemember(state.ocr.rec);                        // consume the ref + file hash (duplicate protection)
  state.screen='done'; render();
}
/* "Browse more rooms" — back to the landing page (it links here again with ?room=) */
function restart(){ location.href='venusep_venue_booking.php'; }

/* ---------- shared bits ---------- */
function header(){
  return `
  <header style="position:sticky;top:0;z-index:40;background:rgba(255,255,255,.92);backdrop-filter:blur(8px);border-bottom:1px solid rgba(0,0,0,.08)">
    <div style="max-width:none;margin:0;padding:14px 40px;display:flex;align-items:center;gap:16px">
      <a href="venusep_venue_booking.php" title="Back to the landing page" style="display:flex;align-items:center">
        <img src="../logo/Logo Header 3.png" alt="VENUSeP logo" style="height:38px;width:auto;display:block">
      </a>
      <div style="flex:1"></div>
      <a href="customer-profile.php" title="Account settings" style="display:flex;align-items:center;gap:9px;padding:6px 8px 6px 6px;border:1px solid rgba(0,0,0,.1);border-radius:999px;background:#fff;color:inherit;text-decoration:none">
        <div style="width:28px;height:28px;border-radius:999px;background:#e7e4de;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:650;color:#5c584f">${esc(initials(ACCOUNT.name))}</div>
        <div style="line-height:1.15;padding-right:4px">
          <div style="font-size:12.5px;font-weight:600">${esc(ACCOUNT.name)}</div>
          <div style="font-size:11px;color:#8a857d">${esc(ACCOUNT.role)}</div>
        </div>
      </a>
    </div>
  </header>`;
}

const PHOTO_TILE = 'background:#e9e7e2;background-image:repeating-linear-gradient(45deg,rgba(0,0,0,.035) 0 11px,transparent 11px 22px);display:flex;align-items:center;justify-content:center';

/* The maintenance chip — DERIVED from the window, never stored, and shown only
   while the window is still live. No window = no chip: an ordinary room says
   nothing, which is all "Available" ever meant. Note the chip is about the room
   TODAY; whether a PICKED DATE is bookable is a different question, answered
   per-date by dayBlock(). That split is the whole point — a closure has dates,
   so it can't be collapsed into one label on the room. */
/* MEDIUM maintenance never blocks, so nothing else in the flow will ever mention
   it — this is its ONLY route to the customer, and it has to land before they
   pay. HARD needs nothing here: dayBlock() already stops the booking and says why. */
function maintDisclosure(R){
  const m=R.maintenance;
  if(!maintLive(m) || m.blocks) return '';
  const when = TODAY>=m.from ? (m.until ? 'until '+fmtDate(m.until) : 'until further notice')
                             : (m.until ? fmtRange(m.from,m.until)  : 'from '+fmtDate(m.from));
  // same neutral-surface + colored-dot card as the slot status below it — they
  // are siblings, so they should read as siblings
  return `
    <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:12px;margin-bottom:12px;background:#fff;border:1px solid rgba(0,0,0,.09)">
      <span style="width:8px;height:8px;border-radius:999px;background:#d9930d;margin-top:5px;flex:none"></span>
      <div>
        <div style="font-size:13.5px;font-weight:650;color:#1c1b19">Partial maintenance ${esc(when)}</div>
        <div style="font-size:12.5px;color:#7a766f;margin-top:1px">${esc(m.reason)}. The room is still open and bookable — we just want you to know before you reserve.</div>
      </div>
    </div>`;
}

function maintChip(R){
  const m=R.maintenance; if(!maintLive(m)) return '';
  const started=TODAY>=m.from;
  const tone=m.blocks?{bg:'#f6e4e4',fg:'#b23a3a'}:{bg:'#f4ecd6',fg:'#8a6d1f'};
  const head=m.blocks?'Closed for maintenance':'Partial maintenance';
  let when;
  if(!started)     when = m.until ? fmtRange(m.from,m.until) : 'from '+fmtDate(m.from);
  else if(m.until) when = 'until '+fmtDate(m.until);
  else             when = 'no set return date';
  return `<span title="${esc(m.reason)}" style="padding:4px 11px;border-radius:999px;font-size:12.5px;background:${tone.bg};color:${tone.fg}">${esc(head+' · '+when)}</span>`;
}

/* ---------- screen: DETAIL + BOOKING PANEL ---------- */
function detailScreen(){
  const d=derive();
  const R=d.R; if(!R) return '';
  const b=state.booking;

  /* tabs */
  const tabDefs=[['overview','Overview'],['availability','Availability'],['policies','Policies']];
  const tabs=tabDefs.map(([k,label])=>`
    <button onclick="setTab('${k}')" style="background:none;border:none;padding:12px 14px;font-size:14px;font-weight:${state.tab===k?680:550};color:${state.tab===k?'#1c1b19':'#8a857d'};border-bottom:2px solid ${state.tab===k?'#1f2a44':'transparent'};cursor:pointer;margin-bottom:-1px">${label}</button>`).join('');

  /* tab content */
  let content='';
  if(state.tab==='overview'){
    const sw='fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
    const I={
      tag:`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.4"/></svg>`,
      clock:`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`,
      star:`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`,
      food:`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}><path d="M12 5a7 7 0 0 0-7 7v3h14v-3a7 7 0 0 0-7-7z"/><line x1="12" y1="3" x2="12" y2="5"/><line x1="2" y1="19" x2="22" y2="19"/></svg>`,
      access:`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}><circle cx="16" cy="4" r="1"/><path d="m18 19 1-7-6 1"/><path d="m5 8 3-3 5.5 3-2.36 3.5"/><path d="M4.24 14.5a5 5 0 0 0 6.88 6"/><path d="M13.76 17.5a5 5 0 0 0-6.88-6"/></svg>`,
    };
    // Capacity & location are already in the title row, so keep only non-redundant facts here
    const facts=[
      { icon:I.tag,    text:'Reservation fee: '+peso(R.fee)+' per day' },
      { icon:I.clock,  text:'Bookable hours: 7 AM – 10 PM daily' },
      { icon:I.star,   text:'Best for: '+R.bestFor },
      { icon:I.food,   text:'Catering: '+R.catering },
      { icon:I.access, text:'Accessibility: '+R.accessible },
    ];
    const row=(icon,label)=>`<div style="display:flex;align-items:flex-start;gap:11px;font-size:14px;line-height:1.45;color:#1c1b19"><span style="color:#3a372f;flex:none;display:flex;margin-top:1px">${icon}</span>${esc(label)}</div>`;
    const factRows=facts.map(f=>row(f.icon,f.text)).join('');
    const amenityRows=R.amenities.map(a=>row(amenityIcon(a),a)).join('');
    content=`
      <p style="margin:0 0 28px;font-size:15.5px;line-height:1.7;color:#3a372f;max-width:70ch">${esc(R.description)}</p>
      <h3 style="margin:0 0 16px;font-size:16px;font-weight:660">At a glance</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px 28px;margin-bottom:34px">${factRows}</div>
      <h3 style="margin:0 0 16px;font-size:16px;font-weight:660">Amenities</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:16px 28px">${amenityRows}</div>`;
  } else if(state.tab==='availability'){
    const list = R.booked.length
      ? R.booked.map(x=>({d:fmtDate(x.date), t:fmtTime(x.start)+' – '+fmtTime(x.end)}))
      : [{d:'No current bookings', t:'This room is open'}];
    content=`
      <h3 style="margin:0 0 6px;font-size:16px;font-weight:660">Availability</h3>
      <p style="margin:0 0 22px;font-size:13.5px;color:#7a766f;max-width:70ch">Bookable hours are 7:00 AM – 10:00 PM daily. Dates listed below already have a booking and are <strong>not available</strong>. Pick your dates and time in the booking panel to check a specific slot.</p>
      <div style="font-size:12.5px;font-weight:600;color:#7a766f;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px">Existing bookings for this room</div>
      ${list.map(x=>`<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid rgba(0,0,0,.08);font-size:13.5px;max-width:520px"><span style="color:#3a372f">${esc(x.d)}</span><span style="color:#8a857d">${esc(x.t)}</span></div>`).join('')}`;
  } else {
    const policy=(title,body)=>`<div style="padding:18px 0;border-top:1px solid rgba(0,0,0,.08)"><div style="font-weight:640;font-size:14px;margin-bottom:5px">${title}</div><p style="margin:0;font-size:13.5px;line-height:1.6;color:#4a463f;max-width:70ch">${body}</p></div>`;
    content=`
      <h3 style="margin:0 0 4px;font-size:16px;font-weight:660">Reservation policies</h3>
      ${policy('Booking window','Reservations must be made <strong>at least 12 hours in advance</strong> — your start time cannot be within 12 hours of booking. A date that already has a reservation is <strong>not available</strong>; in a multi-day range, booked dates are left out automatically and you only pay for the available days.')}
      ${policy('Valid ID & approval','Every booking request must include a photo of a <strong>valid ID</strong> (USeP or government-issued). Your reservation stays <strong>pending</strong> — and payment stays locked — until staff approve both the ID and the reservation.')}
      ${policy('Payment — GCash or cash (after approval)','Once approved, pay online through GCash (send the <strong>exact amount</strong> shown at checkout — not more, not less; incorrect amounts are automatically rejected) or <strong>in cash at the venue office</strong>. Payment is due at least <strong>1 day before your event</strong>; bookings made closer than that pay immediately upon approval. Unpaid reservations may be released after the deadline.')}
      ${policy('Refunds','A refund requires <strong>both</strong> the system transaction receipt <strong>and</strong> the GCash receipt (or the official cashier receipt for cash payments). Requests missing either document cannot be processed.')}
      ${policy('Confirmation','After you pay, staff verify the payment — the GCash reference in the business account, or the cashier record — and give the final confirmation. You are notified at each step.')}`;
  }

  /* range note under the date pickers */
  let rangeNote;
  if(b.date && d.allDates.length>0) rangeNote = d.multi ? ('Multi-day reservation · '+d.days+(d.excluded?(' of '+d.allDates.length):'')+' day'+(d.days>1?'s':'')+' — set the hours for each day below') : 'Single-day reservation';
  else rangeNote = 'Choose a start date; set an end date for multi-day bookings.';

  /* time section: one start/end for a single day, or a per-day schedule for
     multi-day. Each field is a dropdown of half-hour slots within the bookable
     window (7:00 AM – 10:00 PM), displayed in 12-hour AM/PM format. */
  const timeInput = (id,val,onchg)=>{
    const open=state.timeDd===id;
    let opts='';
    if(open){
      for(let h=7;h<=22;h++) for(const mm of ['00','30']){
        if(h===22 && mm==='30') continue;
        const v=String(h).padStart(2,'0')+':'+mm;
        opts+=`<button type="button" class="tt-opt${v===val?' sel':''}" onclick="state.timeDd=null;${onchg.replace('this.value',"'"+v+"'")}">${fmtTime(v)}</button>`;
      }
    }
    return `<div style="position:relative;min-width:0">
      <button id="${id}" type="button" onclick="toggleTimeDd('${id}')" style="display:flex;align-items:center;gap:7px;width:100%;height:42px;padding:0 10px;border:1px solid rgba(0,0,0,.12);border-radius:10px;font-size:13.5px;background:#fff;cursor:pointer;color:${val?'#1c1b19':'#a5a19a'}">
        ${svgClock(15)}<span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${val?fmtTime(val):''}</span>${svgCaret(11)}
      </button>
      ${open?`<div onclick="closeTimeDd()" style="position:fixed;inset:0;z-index:69"></div><div class="tt-pop" onclick="event.stopPropagation()">${opts}</div>`:''}
    </div>`;
  };
  let timeSection;
  if(d.multi){
    let n=0;
    const rows=d.allDates.map(ds=>{
      const lbl=new Date(ds+'T00:00:00').toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'});
      const taken=R.booked.filter(k=>k.date===ds);
      if(taken.length){
        const takenLbl=taken.map(k=>fmtTime(k.start)+'–'+fmtTime(k.end)).join(', ');
        return `<div style="display:grid;grid-template-columns:88px 1fr;gap:8px;align-items:center;margin-bottom:8px">
          <span style="font-size:12px;font-weight:600;color:#a5a19a">${lbl}<div style="font-size:10px;font-weight:500;color:#a5a19a">booked ${takenLbl}</div></span>
          <span style="height:42px;display:flex;align-items:center;padding:0 11px;border:1px dashed rgba(0,0,0,.14);border-radius:9px;font-size:12px;color:#a5a19a;background:#fff">Not available — already booked</span>
        </div>`;
      }
      n++;
      const t=dayTime(ds);
      return `<div style="display:grid;grid-template-columns:88px 1fr 1fr;gap:8px;align-items:center;margin-bottom:8px">
        <span style="font-size:12px;font-weight:600;color:#5c584f">Day ${n}<div style="font-size:11px;font-weight:500;color:#8a857d">${lbl}</div></span>
        ${timeInput('bk-t-'+ds+'-s',t.start,"setDayTime('"+ds+"','start',this.value)")}
        ${timeInput('bk-t-'+ds+'-e',t.end,"setDayTime('"+ds+"','end',this.value)")}
      </div>`;
    }).join('');
    timeSection=`
      <div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <span style="font-size:12px;font-weight:600;color:#5c584f">Hours per day</span>
          <button onclick="applyTimeToAll()" style="background:none;border:none;padding:0;font-size:11.5px;font-weight:600;color:#1f2a44;cursor:pointer">Apply Day 1 to all</button>
        </div>
        <div style="display:grid;grid-template-columns:88px 1fr 1fr;gap:8px;margin-bottom:4px">
          <span></span><span style="font-size:11px;color:#8a857d">Start</span><span style="font-size:11px;color:#8a857d">End</span>
        </div>
        ${rows}
        ${d.excluded?`<div style="font-size:11px;color:#a5a19a;margin-top:2px">Unavailable days are left out automatically — you only book and pay for the available days.</div>`:''}
      </div>`;
  } else {
    timeSection=`
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
        <label style="display:flex;flex-direction:column;gap:5px">
          <span style="font-size:12px;font-weight:600;color:#5c584f">Start time</span>
          ${timeInput('bk-start',b.start,"setBooking('start',this.value)")}
        </label>
        <label style="display:flex;flex-direction:column;gap:5px">
          <span style="font-size:12px;font-weight:600;color:#5c584f">End time</span>
          ${timeInput('bk-end',b.end,"setBooking('end',this.value)")}
        </label>
      </div>`;
  }

  const slot = d.slot.show ? `
    <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:12px;margin-bottom:12px;background:#fff;border:1px solid rgba(0,0,0,.09)">
      <span style="width:8px;height:8px;border-radius:999px;background:${d.slot.dot};margin-top:5px;flex:none"></span>
      <div>
        <div style="font-size:13.5px;font-weight:650;color:#1c1b19">${d.slot.title}</div>
        <div style="font-size:12.5px;color:#7a766f;margin-top:1px">${d.slot.detail}</div>
      </div>
    </div>` : '';

  const capacityWarn = d.over ? `<div style="font-size:12px;color:#8a5a12;margin-bottom:10px;line-height:1.4">Exceeds this room's capacity of ${R.capacity}. You can continue, but staff may reject an over-capacity booking.</div>` : '';

  const feeNote = d.days>1 ? `<span style="display:block;font-size:11px;color:#a5a19a;margin-top:1px">${peso(R.fee)} × ${d.days} days</span>` : '';

  return `
  <main style="max-width:1180px;margin:0 auto;padding:20px 24px 72px">
    <a onclick="goHome()" style="display:inline-flex;align-items:center;gap:7px;font-size:13.5px;font-weight:600;cursor:pointer;margin-bottom:16px">← All rooms</a>

    <div style="display:grid;grid-template-columns:minmax(0,1fr) 379px;gap:34px;align-items:start">
    <!-- LEFT -->
    <div style="min-width:0">
      <!-- gallery, booking.com style: big main shot (the 360 panorama) on the
           left, two stacked photos on the right, thumbnail strip underneath -->
      <div style="display:grid;grid-template-columns:400px 196px;grid-template-rows:196px 196px;gap:8px;border-radius:14px;overflow:hidden">
        <div id="heroPanoSlot" style="grid-row:1 / span 2;position:relative;${PHOTO_TILE}">
          <span style="font:500 13px/1 ui-monospace,Menlo,monospace;color:#9a958c">360° panorama</span>
        </div>
        <div onclick="openGallery()" style="cursor:pointer;${PHOTO_TILE}"><span style="font:500 11px/1 ui-monospace,Menlo,monospace;color:#9a958c">room photo</span></div>
        <div onclick="openGallery()" style="cursor:pointer;${PHOTO_TILE}"><span style="font:500 11px/1 ui-monospace,Menlo,monospace;color:#9a958c">room photo</span></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-top:8px;width:604px;max-width:100%">
        ${[1,2,3,4].map(()=>`<div onclick="openGallery()" style="aspect-ratio:1/1;border-radius:10px;overflow:hidden;cursor:pointer;${PHOTO_TILE}"><span style="font:500 10px/1 ui-monospace,Menlo,monospace;color:#9a958c">room photo</span></div>`).join('')}
        <div onclick="openGallery()" style="aspect-ratio:1/1;border-radius:10px;overflow:hidden;position:relative;cursor:pointer;${PHOTO_TILE}">
          <span style="font:500 10px/1 ui-monospace,Menlo,monospace;color:#9a958c">room photo</span>
          <div style="position:absolute;inset:0;background:rgba(20,18,15,.5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;font-weight:600">+${R.photos} photos</div>
        </div>
      </div>

      <div style="display:flex;flex-wrap:wrap;align-items:flex-start;gap:14px;margin:22px 2px 4px">
        <div style="flex:1;min-width:240px">
          <h1 style="margin:0 0 6px;font-size:27px;font-weight:700;letter-spacing:-.015em">${esc(R.name)}</h1>
          <div style="display:flex;flex-wrap:wrap;align-items:center;gap:16px;font-size:14px;color:#4a463f">
            <span>${esc(R.venue)}</span>
            <span style="display:inline-flex;align-items:center;gap:6px" title="Up to ${R.capacity} guests">${svgUsers(17)}${R.capacity}</span>
            ${maintChip(R)}
          </div>
        </div>
      </div>

      <div style="display:flex;gap:4px;border-bottom:1px solid rgba(0,0,0,.1);margin-top:18px">${tabs}</div>
      <div style="margin-top:24px">${content}</div>
    </div>

    <!-- RIGHT: booking panel -->
    <aside style="position:sticky;top:88px">
      <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;box-shadow:0 1px 2px rgba(0,0,0,.04),0 12px 32px rgba(0,0,0,.05);padding:18px 18px 20px">
        <div style="font-size:16px;font-weight:680;margin-bottom:2px">Reserve this room</div>
        <div style="font-size:12.5px;color:#8a857d;margin-bottom:16px">Pick your dates &amp; time to check the slot. Bookings must start at least 12 hours from now. <a onclick="openLeadModal()" style="font-weight:600;cursor:pointer;white-space:nowrap">Why?</a></div>
        ${maintDisclosure(R)}

        <label style="display:flex;flex-direction:column;gap:5px;margin-bottom:12px">
          <span style="font-size:12px;font-weight:600;color:#5c584f">Event name</span>
          <input id="bk-eventName" type="text" value="${esc(b.eventName)}" oninput="setBooking('eventName',this.value)" placeholder="e.g. CIC Research Colloquium" style="height:42px;padding:0 11px;border:1px solid rgba(0,0,0,.12);border-radius:10px;font-size:14px">
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:6px">
          <div style="display:flex;flex-direction:column;gap:5px;position:relative">
            <span style="font-size:12px;font-weight:600;color:#5c584f">Start date</span>
            <button id="bk-date" type="button" onclick="openCal('date')" style="display:flex;align-items:center;gap:7px;height:42px;padding:0 10px;border:1px solid rgba(0,0,0,.12);border-radius:10px;font-size:13.5px;background:#fff;cursor:pointer;color:#1c1b19">${svgCalendar(15)}<span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${b.date?calDateLabel(b.date):''}</span>${svgCaret(11)}</button>
            ${calHtml('date')}
          </div>
          <div style="display:flex;flex-direction:column;gap:5px;position:relative">
            <span style="font-size:12px;font-weight:600;color:#5c584f">End date</span>
            <button id="bk-dateEnd" type="button" onclick="openCal('dateEnd')" style="display:flex;align-items:center;gap:7px;height:42px;padding:0 10px;border:1px solid rgba(0,0,0,.12);border-radius:10px;font-size:13.5px;background:#fff;cursor:pointer;color:#1c1b19">${svgCalendar(15)}<span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${b.dateEnd?calDateLabel(b.dateEnd):''}</span>${svgCaret(11)}</button>
            ${calHtml('dateEnd')}
          </div>
        </div>
        <div style="font-size:11.5px;color:#8a857d;margin-bottom:12px">${rangeNote}</div>

        ${timeSection}

        ${slot}

        <label style="display:flex;flex-direction:column;gap:5px;margin-bottom:6px">
          <span style="font-size:12px;font-weight:600;color:#5c584f">Number of attendees</span>
          <input id="bk-attendees" type="text" inputmode="numeric" value="${esc(b.attendees)}" oninput="setBooking('attendees',this.value.replace(/\D/g,''))" placeholder="e.g. 50" style="height:42px;padding:0 11px;border:1px solid ${d.over?'#e6c48a':'rgba(0,0,0,.12)'};border-radius:10px;font-size:14px">
        </label>
        ${capacityWarn}
        <div style="height:6px"></div>

        <div style="border-top:1px solid rgba(0,0,0,.09);padding-top:14px;margin-bottom:12px">
          <div style="font-size:12px;font-weight:600;color:#5c584f;margin-bottom:8px">Booking under</div>
          <div style="display:flex;align-items:center;gap:10px;padding:2px 0">
            <div style="width:34px;height:34px;border-radius:999px;background:#1f2a44;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:650;color:#fff">${esc(initials(ACCOUNT.name))}</div>
            <div style="line-height:1.3">
              <div style="font-size:13.5px;font-weight:640">${esc(ACCOUNT.name)}</div>
              <div style="font-size:12px;color:#8a857d">${esc(ACCOUNT.email)}</div>
            </div>
          </div>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:12px">
          <span style="font-size:13px;color:#7a766f">Reservation fee${feeNote}</span>
          <span style="font-size:17px;font-weight:700">${peso(d.totalFee)}</span>
        </div>

        <button onclick="goReview()" ${d.ready?'':'disabled'} style="width:100%;height:48px;border:none;border-radius:11px;font-size:15px;font-weight:680;cursor:${d.ready?'pointer':'not-allowed'};background:${d.ready?'#1f2a44':'#b7b3ab'};color:#fff;opacity:${d.ready?'1':'.85'}">Continue — review &amp; submit request</button>
        ${(!d.ready && d.hint)?`<div style="font-size:11.5px;color:#a5a19a;text-align:center;margin-top:8px">${d.hint}</div>`:`<div style="font-size:11.5px;color:#a5a19a;text-align:center;margin-top:8px">You'll attach a valid ID next · payment opens after staff approval</div>`}
      </div>
    </aside>
    </div>
  </main>`;
}

/* rows shared by review + confirmation */
function bookingRows(){
  const d=derive(); const R=d.R; const b=state.booking;
  const bookerName=ACCOUNT.name;
  const bookerType='USeP account';
  const bookerContact=ACCOUNT.phone+' · '+ACCOUNT.email;
  const rangeLabel=fmtRange(b.date,b.dateEnd);
  const daysLabel=(d.days>1?(d.days+' days'):'1 day')
    +(d.excluded?(' · '+d.excluded+' unavailable'):'')
    +(d.days>1?(' ('+peso(R?R.fee:0)+'/day)'):'');
  let n=0;
  const schedule=d.allDates.map(ds=>{
    if(isDayBlocked(ds)) return { skipped:true, day:'', date:fmtDate(ds), time:'Not available' };
    n++; const t=dayTime(ds);
    return { day:'Day '+n, date:fmtDate(ds), time:(t.start&&t.end)?(fmtTime(t.start)+' – '+fmtTime(t.end)):'—' };
  });
  const sameHours=sameHoursEveryDay(d.dates);
  const first=schedule.find(s=>!s.skipped)||{time:'—'};
  const timeLabel = d.dates.length>1 ? (sameHours ? ('daily, '+first.time) : 'custom hours per day') : first.time;
  return { d, R, b, bookerName, bookerType, bookerContact, rangeLabel, daysLabel, timeLabel, schedule, sameHours };
}

/* per-day schedule list, shown on review + confirmation for multi-day bookings */
function scheduleHtml(x){
  if(!x.d.multi) return '';
  return `
    <div style="margin:12px 0 2px">
      <div style="font-size:11px;font-weight:600;color:#a5a19a;text-transform:uppercase;letter-spacing:.06em;margin-bottom:7px">Daily schedule</div>
      <div>
        ${x.schedule.map((s,i)=>{
          const line=i?'border-top:1px solid rgba(0,0,0,.06);':'';
          return s.skipped
            ? `<div style="display:flex;justify-content:space-between;gap:16px;padding:9px 0;${line}font-size:13px"><span style="color:#a5a19a"><s>${esc(s.date)}</s></span><span style="font-weight:500;color:#a5a19a">${esc(s.time)}</span></div>`
            : `<div style="display:flex;justify-content:space-between;gap:16px;padding:9px 0;${line}font-size:13px"><span style="color:#5c584f">${s.day} · ${esc(s.date)}</span><span style="font-weight:600;color:#1c1b19">${esc(s.time)}</span></div>`;
        }).join('')}
      </div>
    </div>`;
}

/* ---------- screen: REVIEW ---------- */
function reviewScreen(){
  const x=bookingRows(); const R=x.R; if(!R) return '';
  /* key-value blocks (same visual language as the payment card) — left-aligned
     label-over-value cells instead of a long label…value ping-pong list */
  const blk=(label,value,span)=>`<div style="min-width:0;${span?'grid-column:1 / -1;':''}"><div style="font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">${label}</div><div style="font-size:13.5px;font-weight:640;color:#1c1b19;overflow-wrap:anywhere">${value}</div></div>`;
  const blocks=[
    blk('Event name', esc(x.b.eventName||'—')),
    blk('Attendees', esc(x.b.attendees||'—')+(x.d.over?' <span style="color:#8a5a12;font-weight:600">· over capacity</span>':'')),
    blk(x.d.days>1?'Dates':'Date', esc(x.rangeLabel)),
    blk(x.d.days>1?'Time (daily)':'Time', esc(x.timeLabel)),
    blk('Duration', esc(x.daysLabel)),
    blk('Booked by ('+esc(x.bookerType)+')', esc(x.bookerName)),
    blk('Contact', esc(x.bookerContact), true),
  ].join('');
  const step=(n,active,done)=>`<span style="width:26px;height:26px;border-radius:999px;background:${done?'#1c7a4f':active?'#1f2a44':'#e7e4de'};color:${(active||done)?'#fff':'#8a857d'};font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center">${n}</span>`;
  return `
  <main style="max-width:720px;margin:0 auto;padding:26px 24px 72px">
    <a onclick="backToDetail()" style="display:inline-flex;align-items:center;gap:7px;font-size:13.5px;font-weight:600;cursor:pointer;margin-bottom:16px">← Back to room</a>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
      <div style="display:flex;gap:6px">${step(1,true,false)}${step(2,false,false)}${step(3,false,false)}</div>
      <span style="font-size:12.5px;color:#8a857d;font-weight:600">Step 1 of 3 · Review &amp; submit</span>
    </div>
    <h1 style="margin:0 0 18px;font-size:24px;font-weight:700;letter-spacing:-.01em">Review your reservation</h1>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04),0 12px 32px rgba(0,0,0,.05)">
      <div style="display:flex;gap:14px;align-items:center;padding:16px;border-bottom:1px solid rgba(0,0,0,.07)">
        <div style="width:72px;height:72px;border-radius:12px;flex:none;${PHOTO_TILE}"><span style="font:500 9px/1 ui-monospace,Menlo,monospace;color:#9a958c">room photo</span></div>
        <div>
          <div style="font-size:17px;font-weight:680">${esc(R.name)}</div>
          <div style="font-size:13px;color:#8a857d;margin-top:3px">${esc(R.venue)} · Up to ${R.capacity} people</div>
        </div>
      </div>
      <div style="padding:16px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px">${blocks}</div>
        ${scheduleHtml(x)}
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:15px 16px;border-top:1px solid rgba(0,0,0,.07)">
        <span style="font-size:13.5px;font-weight:600;color:#4a463f">Reservation fee <span style="font-weight:500;color:#8a857d">· GCash or cash at the venue</span></span>
        <span style="font-size:20px;font-weight:750;letter-spacing:-.01em">${peso(x.d.totalFee)}</span>
      </div>
    </div>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:16px;margin-top:14px;box-shadow:0 1px 2px rgba(0,0,0,.04),0 12px 32px rgba(0,0,0,.05)">
      <div style="font-size:14px;font-weight:660;margin-bottom:3px">Valid ID <span style="color:#b23a3a">*</span></div>
      <div style="font-size:12.5px;color:#8a857d;margin-bottom:11px">Staff verify your identity before approving the reservation. USeP ID or any government-issued ID.</div>
      <input type="file" id="idFileInput" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="uploadId(this)">
      ${state.idFile?`
      <div style="display:flex;align-items:center;gap:12px;border:1px solid #d4ebdd;background:#f2faf5;border-radius:11px;padding:11px 13px">
        <img src="${state.idFile.url}" alt="ID" style="width:58px;height:38px;object-fit:cover;border-radius:6px;border:1px solid rgba(0,0,0,.1);flex:none">
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;color:#1c7a4f;overflow-wrap:anywhere">${esc(state.idFile.name)}</div>
          <div style="font-size:11.5px;color:#4f7a63">Reviewed by staff together with your reservation</div>
        </div>
        <button onclick="removeId()" style="flex:none;background:none;border:none;color:#8a857d;font-size:12.5px;font-weight:600;cursor:pointer">Remove</button>
      </div>`:`
      <div onclick="document.getElementById('idFileInput').click()" style="border:1.5px dashed rgba(0,0,0,.18);border-radius:11px;padding:22px 14px;text-align:center;cursor:pointer;background:#fff">
        <div style="font-size:13.5px;font-weight:600;color:#4a463f">Upload a photo of your valid ID</div>
        <div style="font-size:11.5px;color:#a5a19a;margin-top:3px">PNG or JPG · make sure the name and photo are readable</div>
      </div>`}
    </div>

    <div style="display:flex;gap:12px;margin-top:20px">
      <button onclick="backToDetail()" style="flex:none;height:48px;padding:0 20px;border:1px solid rgba(0,0,0,.16);border-radius:11px;background:#fff;font-size:14px;font-weight:640;cursor:pointer">Edit details</button>
      <button onclick="submitRequest()" ${state.idFile?'':'disabled'} style="flex:1;height:48px;border:none;border-radius:11px;background:${state.idFile?'#1f2a44':'#b7b3ab'};color:#fff;font-size:15px;font-weight:680;cursor:${state.idFile?'pointer':'not-allowed'};opacity:${state.idFile?'1':'.85'}">Submit booking request</button>
    </div>
    ${state.idFile?'':'<div style="font-size:11.5px;color:#a5a19a;text-align:center;margin-top:8px">Upload a valid ID to submit your request</div>'}
    <div style="font-size:11.5px;color:#a5a19a;text-align:center;margin-top:8px">Payment opens after staff approve your ID and reservation — pay via GCash or cash, at least 1 day before your event.</div>
  </main>`;
}

/* ---------- screen: PENDING APPROVAL ---------- */
/* After submitting (with ID), the request waits for staff to approve BOTH the
   ID and the reservation. Payment stays locked until then. */
function pendingScreen(){
  const x=bookingRows(); const R=x.R; if(!R) return '';
  const pb=payByInfo();
  const st=(label,status,bg,fg)=>`
    <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:13px 0;border-bottom:1px solid rgba(0,0,0,.06)">
      <span style="font-size:13.5px;color:#4a463f">${label}</span>
      <span style="padding:4px 11px;border-radius:999px;font-size:12px;background:${bg};color:${fg}">${status}</span>
    </div>`;
  return `
  <main style="max-width:600px;margin:0 auto;padding:44px 24px 72px;text-align:center">
    <div style="width:46px;height:46px;border-radius:999px;background:#fbeee0;display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#8a5a12" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <h1 style="margin:0 0 8px;font-size:25px;font-weight:720;letter-spacing:-.01em">Request submitted — awaiting approval</h1>
    <p style="margin:0 auto;max-width:440px;font-size:14.5px;line-height:1.6;color:#4a463f">A venue coordinator will review your <strong>ID and reservation</strong>. Once both are approved, the payment step unlocks — you'll pay via GCash or cash at the venue.</p>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:4px 18px;margin-top:24px;text-align:left;box-shadow:0 1px 2px rgba(0,0,0,.04),0 12px 32px rgba(0,0,0,.05)">
      ${st('Reservation request','Pending staff review','#fdf3e6','#8a5a12')}
      ${st('Valid ID ('+esc(state.idFile?state.idFile.name:'submitted')+')','Under review','#fdf3e6','#8a5a12')}
      <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:13px 0">
        <span style="font-size:13.5px;color:#4a463f">Payment</span>
        <span style="padding:4px 11px;border-radius:999px;font-size:12px;background:#fff;border:1px solid rgba(0,0,0,.14);color:#6b675f">Locked until approval</span>
      </div>
    </div>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:16px;margin-top:14px;text-align:left">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px">
        <div style="min-width:0"><div style="font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">Reference</div><div style="font-size:13.5px;font-weight:640">${esc(state.reference)}</div></div>
        <div style="min-width:0"><div style="font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">Room</div><div style="font-size:13.5px;font-weight:640">${esc(R.name)}</div></div>
        <div style="min-width:0"><div style="font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">${x.d.days>1?'Dates':'Date'}</div><div style="font-size:13.5px;font-weight:640">${esc(x.rangeLabel)}</div></div>
        <div style="min-width:0"><div style="font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">Fee (pay after approval)</div><div style="font-size:13.5px;font-weight:640">${peso(x.d.totalFee)}</div></div>
      </div>
      <div style="font-size:12px;color:#8a857d;line-height:1.6;margin-top:14px;border-top:1px solid rgba(0,0,0,.06);padding-top:12px">Once approved, pay ${pb.late?'<strong>'+pb.label+'</strong>':'by <strong>'+pb.label+'</strong> (1 day before your event)'} — unpaid reservations may be released after the deadline.</div>
    </div>

    <div style="border:1.5px dashed rgba(0,0,0,.16);border-radius:12px;padding:14px 16px;margin-top:18px;text-align:left">
      <div style="font-size:11px;font-weight:650;letter-spacing:.06em;text-transform:uppercase;color:#a5a19a;margin-bottom:6px">Demo only</div>
      <div style="font-size:12.5px;color:#8a857d;line-height:1.5;margin-bottom:10px">The staff side isn't connected in this mockup — use this to simulate the coordinator approving your ID and reservation.</div>
      <button onclick="demoApprove()" style="height:42px;padding:0 18px;border:1px solid rgba(0,0,0,.16);border-radius:10px;background:#fff;font-size:13px;font-weight:640;cursor:pointer">Simulate staff approval → proceed to payment</button>
    </div>

    <button onclick="restart()" style="background:none;border:none;color:#8a857d;font-size:13px;font-weight:600;cursor:pointer;margin-top:18px;text-decoration:underline">Browse more rooms</button>
  </main>`;
}

/* ---------- screen: PAYMENT ---------- */
/* The receipt panel has three phases: upload zone → OCR progress → verdict card. */
function receiptPanelHtml(){
  const o=state.ocr;
  const fileInput=`<input type="file" id="gcFile" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="checkReceipt(this)">`;
  if(!o){
    const on=state.agreeExact;
    return fileInput+`
    <div onclick="${on?'pickReceipt()':''}" style="border:1.5px dashed rgba(0,0,0,.18);border-radius:11px;padding:26px 14px;text-align:center;background:#fff;${on?'cursor:pointer':'opacity:.55;cursor:not-allowed'}">
      <div style="font-size:13.5px;font-weight:600;color:#4a463f">${on?'Tap to upload a screenshot of your GCash receipt':'Tick the exact-amount box above to enable the upload'}</div>
      <div style="font-size:11.5px;color:#a5a19a;margin-top:3px">PNG or JPG · keep the reference number and amount visible · checked instantly with OCR</div>
    </div>`;
  }
  if(o.phase==='reading'){
    return `
    <div style="border:1px solid rgba(0,0,0,.12);border-radius:11px;padding:14px;background:#fff">
      <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:9px">
        <span style="font-size:13px;font-weight:600;color:#4a463f;overflow-wrap:anywhere">${esc(o.fileName)}</span>
        <span id="gcBarLabel" style="font-size:12px;color:#8a857d;flex:none">${esc(o.label)} · ${Math.round((o.pct||0)*100)}%</span>
      </div>
      <div style="height:6px;border-radius:999px;background:#e7e4de;overflow:hidden">
        <div id="gcBar" style="height:100%;width:${Math.round((o.pct||0)*100)}%;background:#1f2a44;border-radius:999px;transition:width .25s"></div>
      </div>
      <div style="font-size:11.5px;color:#a5a19a;margin-top:8px">Reading the receipt with OCR — runs in your browser, usually 5–15 seconds.</div>
    </div>`;
  }
  const r=o.rec;
  const V={
    pending_staff:{ bg:'#f2faf5', bd:'#d4ebdd', fg:'#1c7a4f', t:'Receipt accepted — payment recorded', s:'All automatic checks passed. Staff give the final confirmation in GCash.' },
    needs_review:{ bg:'#fdf7ee', bd:'#f3e3c8', fg:'#8a5a12', t:'Receipt received — needs manual review', s:'Some details could not be verified automatically; staff will review them.' },
    rejected_auto:{ bg:'#fdf2f2', bd:'#f2d8d8', fg:'#b23a3a', t:'Receipt rejected by the automatic check', s:'Fix the issues below, then upload a new receipt.' },
  }[r.status];
  const p=r.parsed;
  const kv=[
    ['Ref no.', p.refDisplay||p.ref||'—'],
    ['Amount read', p.effAmountC!=null?centavosFmt(p.effAmountC):'—'],
    ['Date & time', p.datetime||'—'],
    ['Sent to', p.receiverNumber||p.receiverNameMasked||p.receiverNameShort||'—'],
  ];
  const issues=[...r.reasons.map(c=>({c,hard:true})), ...r.flags.map(c=>({c,hard:false}))];
  return fileInput+`
  <div style="border:1px solid rgba(0,0,0,.12);border-radius:11px;overflow:hidden;background:#fff">
    <div style="display:flex;gap:11px;align-items:flex-start;padding:13px 14px;background:${V.bg};border-bottom:1px solid ${V.bd}">
      <div style="flex:1">
        <div style="font-size:13.5px;color:${V.fg}">${V.t}</div>
        <div style="font-size:12px;color:${V.fg};opacity:.85;margin-top:2px">${V.s}</div>
      </div>
      <button onclick="removeReceipt()" style="flex:none;background:#fff;border:1px solid rgba(0,0,0,.14);border-radius:8px;padding:6px 11px;font-size:12px;font-weight:600;color:#4a463f;cursor:pointer">${r.status==='rejected_auto'?'Try another':'Remove'}</button>
    </div>
    <div style="display:flex;gap:13px;padding:13px 14px">
      ${o.thumb?`<img src="${o.thumb}" alt="receipt" style="width:64px;height:84px;object-fit:cover;border-radius:8px;border:1px solid rgba(0,0,0,.1);flex:none">`:''}
      <div style="flex:1;display:grid;grid-template-columns:1fr 1fr;gap:8px 14px;align-content:start">
        ${kv.map(([k,v])=>`<div><div style="font-size:11px;color:#8a857d">${k}</div><div style="font-size:13px;font-weight:640;color:#1c1b19;overflow-wrap:anywhere">${esc(v)}</div></div>`).join('')}
      </div>
    </div>
    ${issues.length?`
    <div style="padding:2px 14px 12px">
      ${issues.map(i=>`<div style="display:flex;gap:8px;align-items:flex-start;padding:4px 0"><span style="width:7px;height:7px;border-radius:999px;background:${i.hard?'#b23a3a':'#c99a3c'};margin-top:5px;flex:none"></span><span style="font-size:12.5px;color:#4a463f;line-height:1.45">${esc(GC_LABELS[i.c]||i.c)}</span></div>`).join('')}
    </div>`:''}
    ${r.confidence!=null?`<div style="padding:0 14px 11px;font-size:11px;color:#a5a19a">OCR confidence ${r.confidence}% · ${esc(o.fileName)}</div>`:''}
  </div>`;
}

function paymentScreen(){
  const x=bookingRows(); const R=x.R; if(!R) return '';
  const cash=state.payMethod==='cash';
  const paid=cash?true:receiptOk();

  /* payment method selector — GCash (online) or cash (walk-in at the office) */
  const methodCard=(m,title,sub)=>{
    const on=state.payMethod===m;
    return `<div onclick="setPayMethod('${m}')" style="border:1.5px solid ${on?'#1f2a44':'rgba(0,0,0,.12)'};background:${on?'#f2f4f9':'#fff'};border-radius:12px;padding:13px 14px;cursor:pointer">
      <div style="display:flex;align-items:center;gap:8px">
        <span style="width:15px;height:15px;border-radius:999px;border:1.5px solid ${on?'#1f2a44':'#b7b3ab'};display:flex;align-items:center;justify-content:center;flex:none">${on?'<span style="width:7px;height:7px;border-radius:999px;background:#1f2a44"></span>':''}</span>
        <span style="font-size:14px;font-weight:680;color:#1c1b19">${title}</span>
      </div>
      <div style="font-size:12px;color:#8a857d;margin-top:3px;padding-left:23px">${sub}</div>
    </div>`;
  };

  const gcashBody=`
    <div style="display:flex;gap:12px;background:#fdf7ee;border:1px solid #f3e3c8;border-radius:12px;padding:15px 16px;margin-bottom:18px">
      <div>
        <div style="font-size:14.5px;color:#8a5a12">Always send the EXACT amount — not more, not less.</div>
        <div style="font-size:13px;color:#8a5a12;opacity:.9;margin-top:2px">Payments with an incorrect amount are automatically rejected.</div>
      </div>
    </div>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.1);border-radius:16px;padding:20px">
      <div style="display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;border-bottom:1px solid rgba(0,0,0,.08)">
        <div>
          <div style="font-size:12.5px;color:#8a857d">Amount to send</div>
          <div style="font-size:30px;font-weight:780;letter-spacing:-.02em;color:#1f2a44">${peso(x.d.totalFee)}</div>
        </div>
        <div style="width:60px;height:60px;border-radius:14px;background:#0a6cf0;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:750;text-align:center;line-height:1.1">GCash</div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;margin:16px 0;padding-bottom:16px;border-bottom:1px solid rgba(0,0,0,.08)">
        <div style="min-width:0"><div style="font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">GCash account name</div><div style="font-size:14px;font-weight:640">${esc(gcAccount().name)}</div></div>
        <div style="min-width:0"><div style="font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">GCash number <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#a3a09a">· ${esc(R.venue)}</span></div><div style="font-size:14px;font-weight:640">${esc(gcAccount().number.replace(/^(\d{4})(\d{3})(\d{4})$/,'$1 $2 $3'))}</div></div>
        <div style="min-width:0"><div style="font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">Reference to include</div><div style="font-size:14px;font-weight:640">${esc(state.reference)}</div></div>
        <div style="min-width:0"><div style="font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">Room</div><div style="font-size:14px;font-weight:640">${esc(R.name)}</div></div>
      </div>

      <div style="margin-top:4px">
        <div style="font-size:12.5px;font-weight:600;color:#5c584f;margin-bottom:7px">Upload GCash receipt</div>
        <label style="display:flex;gap:9px;align-items:flex-start;margin:0 0 10px;cursor:pointer">
          <input type="checkbox" id="agreeExact" ${state.agreeExact?'checked':''} onchange="toggleAgreeExact(this)" style="width:15px;height:15px;margin-top:2px;accent-color:#1f2a44">
          <span style="font-size:12.5px;color:#4a463f;line-height:1.5">I understand I must send the <strong>exact amount — ${peso(x.d.totalFee)}</strong>. A receipt with any other amount is rejected automatically.</span>
        </label>
        ${receiptPanelHtml()}
      </div>
    </div>`;

  const cashBody=`
    <div style="display:flex;gap:12px;background:#f5f7fb;border:1px solid #dfe4ef;border-radius:12px;padding:15px 16px;margin-bottom:18px">
      <div>
        <div style="font-size:14.5px;color:#1f2a44">Pay in cash at the venue office.</div>
        <div style="font-size:13px;color:#1f2a44;opacity:.85;margin-top:2px">Your reservation is held while payment is pending — it is confirmed once the cashier records your payment.</div>
      </div>
    </div>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.1);border-radius:16px;padding:20px">
      <div style="display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;border-bottom:1px solid rgba(0,0,0,.08)">
        <div>
          <div style="font-size:12.5px;color:#8a857d">Amount to pay</div>
          <div style="font-size:30px;font-weight:780;letter-spacing:-.02em;color:#1f2a44">${peso(x.d.totalFee)}</div>
        </div>
        <div style="width:60px;height:60px;border-radius:14px;background:#1f2a44;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:750;text-align:center;line-height:1.1">Cash</div>
      </div>

      <div style="margin:16px 0">
        <div style="font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">Where to pay</div>
        <div style="font-size:15px;font-weight:680;color:#1c1b19">${esc(CASH_PAY.office)}</div>
        <div style="font-size:13px;color:#4a463f;margin-top:3px">${esc(CASH_PAY.address)}</div>
        <div style="font-size:12.5px;color:#8a857d;margin-top:3px">${esc(CASH_PAY.hours)}</div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;margin-bottom:16px">
        <div style="min-width:0"><div style="font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">Quote your reference</div><div style="font-size:14px;font-weight:640">${esc(state.reference)}</div></div>
        <div style="min-width:0"><div style="font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">Room</div><div style="font-size:14px;font-weight:640">${esc(R.name)}</div></div>
      </div>

      <div style="font-size:12.5px;color:#4a463f;line-height:1.7;border-top:1px solid rgba(0,0,0,.07);padding-top:13px">Bring your booking reference and a valid ID. Pay <strong>before your event date</strong> — unpaid reservations may be released. You will receive the official transaction receipt at the counter; keep it (it is required for any refund).</div>
    </div>`;

  return `
  <main style="max-width:720px;margin:0 auto;padding:26px 24px 72px">
    <a onclick="backToPending()" style="display:inline-flex;align-items:center;gap:7px;font-size:13.5px;font-weight:600;cursor:pointer;margin-bottom:16px">← Back to request status</a>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
      <div style="display:flex;gap:6px">
        <span style="width:26px;height:26px;border-radius:999px;background:#1c7a4f;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center">1</span>
        <span style="width:26px;height:26px;border-radius:999px;background:#1f2a44;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center">2</span>
        <span style="width:26px;height:26px;border-radius:999px;background:#e7e4de;color:#8a857d;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center">3</span>
      </div>
      <span style="font-size:12.5px;color:#8a857d;font-weight:600">Step 2 of 3 · Payment</span>
    </div>
    <h1 style="margin:0 0 18px;font-size:24px;font-weight:700;letter-spacing:-.01em">${cash?'Pay at the venue':'Pay with GCash'}</h1>

    <div style="display:flex;gap:10px;align-items:flex-start;background:#f2faf5;border:1px solid #d4ebdd;border-radius:12px;padding:13px 15px;margin-bottom:18px">
      <span style="width:8px;height:8px;border-radius:999px;background:#2f9e63;margin-top:5px;flex:none"></span>
      <div>
        <div style="font-size:13.5px;color:#1c7a4f">Request approved — payment unlocked</div>
        <div style="font-size:12.5px;color:#1c7a4f;opacity:.85;margin-top:1px">Your ID and reservation were approved. Pay ${payByInfo().late?payByInfo().label:'by '+payByInfo().label+' (1 day before your event)'} to secure the slot.</div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px">
      ${methodCard('gcash','GCash','Send online, then upload your receipt — verified instantly')}
      ${methodCard('cash','Cash — walk-in','Reserve now, pay in person at the venue office')}
    </div>

    ${cash?cashBody:gcashBody}

    <button onclick="confirmBooking()" ${paid?'':'disabled'} style="width:100%;height:50px;border:none;border-radius:12px;background:${paid?'#1f2a44':'#b7b3ab'};color:#fff;font-size:15px;font-weight:700;cursor:${paid?'pointer':'not-allowed'};opacity:${paid?'1':'.85'};margin-top:20px">${cash?'Submit reservation — pay at the office':'Submit payment &amp; reservation'}</button>
    <div style="font-size:11.5px;color:#a5a19a;text-align:center;margin-top:9px">${cash?'Your booking stays pending until the cashier records your payment.':'Your booking will be pending staff confirmation after submission.'} Need help? <a href="faq.php" target="_blank" rel="noopener" style="cursor:pointer;color:#1f2a44;font-weight:600;text-decoration:underline">Check the FAQ</a>${cash?'':' · <a onclick="clearGcashHistory()" style="cursor:pointer;color:#8a857d;text-decoration:underline">Reset receipt history (demo)</a>'}</div>
  </main>`;
}

/* ---------- screen: CONFIRMATION ---------- */
function doneScreen(){
  const x=bookingRows(); const R=x.R; if(!R) return '';
  const cash=state.payMethod==='cash';
  const rec=!cash && state.ocr && state.ocr.rec;
  const review=!!(rec&&rec.status==='needs_review');
  const rows=[
    { label:'Reference', value:state.reference },
    { label:'Payment method', value:cash?'Cash — walk-in':'GCash' },
    ...(cash?[{ label:'Pay at', value:CASH_PAY.office }]:[{ label:'GCash ref no.', value:(rec&&(rec.parsed.refDisplay||rec.parsed.ref))||'—' }]),
    { label:'Room', value:R.name },
    { label:'Event', value:x.b.eventName||'—' },
    { label:x.d.days>1?'Dates':'Date', value:x.rangeLabel },
    { label:x.d.days>1?'Time (daily)':'Time', value:x.timeLabel },
    { label:'Duration', value:x.daysLabel },
    { label:'Attendees', value:x.b.attendees||'—' },
    { label:'Booked by', value:x.bookerName },
    { label:cash?'Amount due':'Amount paid', value:peso(x.d.totalFee) },
  ];
  const bodyCopy = cash
    ? 'Your booking request was received and the slot is held. Pay <strong>'+peso(x.d.totalFee)+' in cash</strong> at '+esc(CASH_PAY.office)+' — quote your reference at the counter. The reservation is confirmed once the cashier records your payment.'
    : (review
      ? 'Your booking request was received, but some receipt details could not be verified automatically. A venue coordinator will <strong>manually review your receipt</strong> before confirming.'
      : 'Your payment and booking request were received. This reservation is <strong>pending staff confirmation</strong> — a venue coordinator will confirm the payment in GCash and approve it shortly.');
  const pill = cash
    ? { bg:'#fdf3e6', fg:'#8a5a12', t:'Reservation held — awaiting cash payment' }
    : (review
      ? { bg:'#fdf3e6', fg:'#8a5a12', t:'Receipt under manual review' }
      : { bg:'#e9f5ef', fg:'#1c7a4f', t:'Payment recorded — pending staff confirmation' });
  return `
  <main style="max-width:600px;margin:0 auto;padding:52px 24px 72px;text-align:center">
    <h1 style="margin:0 0 8px;font-size:26px;font-weight:720;letter-spacing:-.01em">Reservation submitted</h1>
    <p style="margin:0 auto 6px;max-width:440px;font-size:15px;line-height:1.6;color:#4a463f">${bodyCopy}</p>
    <div style="display:inline-block;margin:16px auto 0;padding:8px 16px;border-radius:999px;background:${pill.bg};color:${pill.fg};font-size:13px">Status: ${pill.t}</div>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.1);border-radius:16px;padding:6px 18px 8px;margin-top:26px;text-align:left">
      ${rows.map(r=>`<div style="display:flex;justify-content:space-between;gap:16px;padding:12px 0;border-bottom:1px solid rgba(0,0,0,.06)"><span style="font-size:13.5px;color:#8a857d">${r.label}</span><span style="font-size:13.5px;font-weight:600;text-align:right">${esc(r.value)}</span></div>`).join('')}
      ${scheduleHtml(x)}
    </div>

    <div style="font-size:12.5px;color:#8a857d;line-height:1.6;margin-top:16px">${cash
      ? 'Bring your booking reference and a valid ID when paying. Keep the official transaction receipt you receive at the counter — it is required for any refund request.'
      : 'Keep your GCash receipt and this reference number. Both the system transaction receipt and the GCash receipt are required for any refund request.'}</div>

    <button onclick="restart()" style="height:48px;padding:0 26px;border:none;border-radius:11px;background:#1f2a44;color:#fff;font-size:14.5px;font-weight:660;cursor:pointer;margin-top:24px">Browse more rooms</button>
  </main>`;
}

/* ---------- modals: 12-hour rule + booked-date clash ---------- */
function modalHtml(){
  if(state.modal && state.modal.type==='clash') return clashModalHtml();
  if(state.modal!=='lead') return '';
  const e=earliestAllowed();
  const hhmm=String(e.getHours()).padStart(2,'0')+':'+String(e.getMinutes()).padStart(2,'0');
  const when=e.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'})+' · '+fmtTime(hhmm);
  return `
  <div onclick="closeModal()" style="position:fixed;inset:0;background:rgba(20,18,15,.45);backdrop-filter:blur(2px);display:flex;align-items:center;justify-content:center;padding:20px;z-index:100">
    <div onclick="event.stopPropagation()" role="dialog" aria-modal="true" style="background:#fff;border-radius:20px;max-width:380px;width:100%;padding:28px 26px 24px;box-shadow:0 24px 70px rgba(0,0,0,.26);text-align:center">
      <div style="width:46px;height:46px;border-radius:999px;background:#eef0f5;display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1f2a44" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
      <div style="font-size:17px;font-weight:720;letter-spacing:-.01em;margin-bottom:6px">Book at least 12 hours ahead</div>
      <p style="margin:0 auto 16px;font-size:13.5px;line-height:1.6;color:#7a766f;max-width:32ch">Venue staff need time to prepare — reservations must start at least <strong style="color:#4a463f">12 hours from now</strong>. The time you picked is too soon.</p>
      <div style="background:#fff;border:1px solid rgba(0,0,0,.09);border-radius:12px;padding:12px 14px;margin-bottom:18px">
        <div style="font-size:10.5px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:#a5a19a;margin-bottom:3px">Earliest start</div>
        <div style="font-size:15px;font-weight:680;color:#1f2a44">${when}</div>
      </div>
      <button onclick="closeModal()" style="width:100%;height:46px;border:none;border-radius:12px;background:#1f2a44;color:#fff;font-size:14px;font-weight:650;cursor:pointer">Pick a later time</button>
    </div>
  </div>`;
}

/* the "this specific date is not available" modal. Two reasons a date can be
   unavailable — already booked, or closed for maintenance — and dayBlock() says
   which, so this never re-derives it. In a multi-day range the day is excluded
   automatically (Jul 17–20 with Jul 18 taken books Jul 17 + 19 + 20 around it);
   a single unavailable date simply can't be booked. */
function clashModalHtml(){
  const m=state.modal; const R=getRoom(); if(!R) return '';
  const blk=dayBlock(m.day); if(!blk) return '';
  const maint = blk.why==='maintenance';
  const dl=fmtDate(m.day);
  const multi=rangeDates(state.booking.date, state.booking.dateEnd||state.booking.date).length>1;
  const why = maint ? 'The room is <strong>closed for maintenance</strong> on that date'
                    : 'That date already has a reservation, so it is <strong>not available</strong>';
  const body = multi
    ? why+'. It has been left out of your booking automatically — you are only reserving the remaining available days (and only paying for those).'
    : why+'. Please pick a different date.';
  const boxLabel = maint ? 'Maintenance' : ('Existing booking'+(blk.slots.length>1?'s':''));
  const boxValue = maint ? maintUntilLabel(blk.m)
                         : blk.slots.map(s=>fmtTime(s.start)+' – '+fmtTime(s.end)).join(' and ');
  const boxNote  = maint ? blk.m.reason : '';
  const okLabel = multi ? 'OK — book the other days' : 'Got it — I\'ll pick another date';
  return `
  <div onclick="closeModal()" style="position:fixed;inset:0;background:rgba(20,18,15,.45);backdrop-filter:blur(2px);display:flex;align-items:center;justify-content:center;padding:20px;z-index:100">
    <div onclick="event.stopPropagation()" role="dialog" aria-modal="true" style="background:#fff;border-radius:20px;max-width:380px;width:100%;padding:28px 26px 24px;box-shadow:0 24px 70px rgba(0,0,0,.26);text-align:center">
      <div style="width:46px;height:46px;border-radius:999px;background:#fbeee0;display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#8a5a12" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      </div>
      <div style="font-size:17px;font-weight:720;letter-spacing:-.01em;margin-bottom:6px">${dl} is not available</div>
      <p style="margin:0 auto 16px;font-size:13.5px;line-height:1.6;color:#7a766f;max-width:34ch">${body}</p>
      <div style="background:#fff;border:1px solid rgba(0,0,0,.09);border-radius:12px;padding:12px 14px;margin-bottom:18px">
        <div style="font-size:10.5px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:#a5a19a;margin-bottom:3px">${boxLabel}</div>
        <div style="font-size:15px;font-weight:680;color:#1f2a44">${esc(boxValue)||'—'}</div>
        ${boxNote?`<div style="font-size:12px;color:#8a857d;margin-top:3px">${esc(boxNote)}</div>`:''}
      </div>
      <button onclick="closeModal()" style="width:100%;height:46px;border:none;border-radius:12px;background:#1f2a44;color:#fff;font-size:14px;font-weight:650;cursor:pointer">${okLabel}</button>
    </div>
  </div>`;
}

/* ---------- render (with focus/caret preservation) ---------- */
function currentScreen(){
  switch(state.screen){
    case 'detail':  return detailScreen();
    case 'review':  return reviewScreen();
    case 'pending': return pendingScreen();
    case 'payment': return paymentScreen();
    case 'done':    return doneScreen();
    default:        return detailScreen();   // entry screen (browsing lives on the landing page)
  }
}

function render(){
  const active=document.activeElement;
  const id=active && active.id;
  const selStart=active && active.selectionStart;
  const selEnd=active && active.selectionEnd;

  document.getElementById('app').innerHTML =
    `<div style="min-height:100vh;background:#fff">${header()}${currentScreen()}</div>${modalHtml()}`;

  // don't pull focus back to an input while the modal is open
  if(id && !state.modal){
    const el=document.getElementById(id);
    if(el){
      el.focus();
      try{
        if(selStart!=null) el.setSelectionRange(selStart,selEnd);
        // inputs that can't report a selection (e.g. type=number) land at the
        // start after focus — push the caret to the end instead
        else if(typeof el.value==='string') el.setSelectionRange(el.value.length,el.value.length);
      }catch(e){}
    }
  }

  mountHeroPano();   // keep the inline hero panorama alive across re-renders
}

render();
</script>
</body>
</html>
