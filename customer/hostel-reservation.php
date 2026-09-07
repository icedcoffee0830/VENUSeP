<?php
/* The hostel's rooms + the availability model live in ONE include, shared with
   the landing page and admin Venue Management, so they cannot drift apart. */
include __DIR__ . '/../includes/hostel-rooms.php';
?>
<!DOCTYPE html>
<!-- ==================================================================
  USeP HOSTEL RESERVATION — customer booking UI (self-contained mockup)
  ==================================================================
  A SEPARATE page from room-reservation.php on purpose. The hostel is a
  venue in the data model, but almost nothing a customer types is the
  same, and its payment goes through CEDU. Splitting where the DATA
  differs; sharing where the MACHINERY is the same (the GCash receipt
  checker is one engine, included by both — see includes/gcash-checker.php).

  ENTRY: venusep_venue_booking.php links here with ?room=h1…h5.

  WHAT IS DIFFERENT FROM A VENUE BOOKING — all of it falls out of
  "booking is per BED, priced per NIGHT":

    Venue                         Hostel
    ---------------------------   -------------------------------------
    Event name                    (gone)
    Date + start/end time         Check-in / check-out -> nights
    Attendees (a number)          Named occupants, one per bed
    days INCLUSIVE (Aug1-3 = 3)   nights EXCLUSIVE (Aug1->4 = 3)
    Aug 1 -> Aug 1 is valid       Aug 1 -> Aug 1 is 0 nights, rejected
    unavailable days are          a blocked night rejects the WHOLE stay
      excluded, booking is          (a guest cannot leave and come back)
      built AROUND them
    fee x days                    beds x rate/head x nights
    GCash -> business account     POS from CEDU first, then cash to staff
                                    or GCash to the STAFF's account

  THE LOAD-BEARING RULE: `beds` is NEVER stored. beds = occupants.length.
  Storing both lets them disagree (beds=3 but 2 people named) and nothing
  would say which is right. The bed selector grows/shrinks the roster.

  MAP OF THE SCRIPT — Ctrl+F the quoted text:
    "---------- data"            rooms (from PHP) + constants
    "---------- state"           the one object that drives the UI
    "---------- helpers"         nights, beds-free, gender mix, maintenance
    "---------- actions"         what the buttons call
    "---------- date-picker"     calendar popup
    "---------- CEDU flow"       ID -> POS wait -> payment -> OR
    "screen: DETAIL"             room page + booking panel (entry)
    "screen: REVIEW"             summary + valid-ID upload
    "screen: PENDING"            waiting for staff to fetch the POS from CEDU
    "screen: PAYMENT"            cash to staff / GCash to staff account
    "screen: CONFIRMATION"       confirmed + what still has to arrive
    "---------- render"

  [SIM] = simulation-only so the mockup runs with no server:
    · $hostelRooms       sample rooms (includes/hostel-rooms.php)
    · ACCOUNT            fake logged-in customer
    · $gcAccount         the hostel STAFF's designated GCash account
    · demoRecordPos()    fake "staff came back from CEDU with the POS"
    · demoIssueOr()      fake "cashier issued the Official Receipt"
    · localStorage       stands in for the receipts database
  ================================================================== -->
<html lang="en">
<head>
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VENUSeP | Hostel Reservation</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..800&display=swap" rel="stylesheet">
<!-- Pannellum 360 panorama viewer — same lib and same hero treatment as the
     venue page. A hostel room needs it MORE than a hall does: the bunk layout
     and where the CR sits are exactly what a guest wants to look around at. -->
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
  /* custom date-picker dropdown (booking panel) — same look as the venue page */
  .cal-pop{position:absolute;top:calc(100% + 6px);z-index:70;background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;box-shadow:0 12px 36px rgba(0,0,0,.14);padding:12px;width:274px}
  .cal-nav{width:28px;height:28px;border:none;background:none;border-radius:8px;cursor:pointer;color:#5c584f;font-size:15px;line-height:1}
  .cal-nav:hover{background:#f0eeea}
  .cal-day{width:32px;height:32px;border:none;background:none;border-radius:999px;display:flex;align-items:center;justify-content:center;font:500 12.5px 'Inter',system-ui,sans-serif;color:#1c1b19;cursor:pointer;padding:0}
  .cal-day:hover:not(:disabled){background:#eef0f4}
  .cal-day:disabled{color:#c6c2ba;text-decoration:line-through;cursor:default;background:none}
  .cal-day.out{color:#c6c2ba}
  .cal-day.today:not(.sel){box-shadow:inset 0 0 0 1.5px #b9c0d0}
  .cal-day.sel,.cal-day.sel:hover{background:#1f2a44;color:#fff;font-weight:650}
  /* bed roster rows */
  .bed-row{display:grid;grid-template-columns:22px 1fr 92px;gap:8px;align-items:center;padding:7px 0;border-top:1px solid rgba(0,0,0,.06)}
  .bed-no{font-size:11.5px;font-weight:650;color:#a5a19a;text-align:center}
  .bed-name{height:36px;border:1px solid rgba(0,0,0,.14);border-radius:9px;padding:0 10px;font-size:13px;background:#fff;width:100%}
  .g-seg{display:flex;border:1px solid rgba(0,0,0,.14);border-radius:9px;overflow:hidden;height:36px;background:#fff}
  .g-seg button{flex:1;border:none;background:none;font-size:12px;font-weight:600;color:#8a857d;cursor:pointer;padding:0}
  .g-seg button.on{background:#1f2a44;color:#fff}
</style>
</head>
<body>
<div id="app"></div>

<?php
/* The GCash accounts, from the ONE source that admin Payment Settings edits.
   The hostel's is a designated STAFF account — deliberately NOT a venue business
   account, because the staff cash it out and hand it to the University Cashier,
   who issues the OR. The SHARED checker verifies every hostel receipt against it:
   same engine as the venues, different expected receiver. That is exactly why the
   engine is an include and not a copy — see includes/gcash-checker.php. */
include __DIR__ . '/../includes/payment-settings.php';
include __DIR__ . '/../includes/gcash-checker.php';
?>
<script>
/* Unlike room-reservation.php (which serves two venues), every room on this page
   belongs to the hostel — so gcAccount() below is constant. It is still a
   function because that is the checker's contract. */
const GCASH_ACCOUNTS = <?php echo json_encode($gcAccounts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script>
/* ---------- data ---------- */
const NOW = new Date();
const TODAY = isoOf(NOW);

/* Rooms come from the ONE PHP source, so this page, the landing page and admin
   Venue Management can never disagree about what exists. */
const ROOMS    = <?php echo json_encode($hostelRooms, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const RATES    = <?php echo json_encode($HOSTEL_RATES); ?>;
const CR_LABEL = <?php echo json_encode($HOSTEL_CR_LABEL); ?>;
const HOSTEL   = <?php echo json_encode($HOSTEL_VENUE); ?>;

/* [SIM] the logged-in customer — from the session in the real app. */
const ACCOUNT = { name:'Juan Miguel Dela Cruz', role:'Student · CIC', email:'jmdelacruz@usep.edu.ph', phone:'0917 555 0123' };

/* [SIM] where the customer hands cash to the hostel staff. */
const CASH_PAY = { where:'USeP Hostel front desk', hours:'Mon–Sat · 8:00 AM – 5:00 PM' };

/* [SIM] the office the staff walks to for the POS, and the one that issues the OR. */
const CEDU     = { name:'CEDU', full:'College of Engineering & Development Unit' };
const CASHIER  = { name:'University Cashier', full:'USeP Cashier’s Office' };

/* [SIM] Sample equirectangular panorama — same stand-in the venue page uses.
   Swap for real per-room images (room.panorama) later. */
const SAMPLE_PANO = 'https://pannellum.org/images/alma.jpg';

/* ---------- state ---------- */
const ROOM_PARAM = new URLSearchParams(location.search).get('room');
const ROOM_OK = ROOMS.some(r=>r.id===ROOM_PARAM);
if(!ROOM_OK) location.replace('venusep_venue_booking.php');

let state = {
  screen: 'detail',                       // detail | review | pending | payment | done
  roomId: ROOM_OK ? ROOM_PARAM : ROOMS[0].id,
  tab: 'overview',                        // overview | availability | policies
  /* NOTE: there is no `beds` field. beds = occupants.length, always. The bed
     stepper grows/shrinks this list, so the count and the roster are one thing. */
  booking: { checkIn:'', checkOut:'', occupants:[ {name:'', gender:'F'} ] },
  payMethod: 'gcash',                     // 'gcash' (staff account) | 'cash' (to staff)
  idFile: null,                           // required to submit
  posNumber: null,                        // null until staff returns from CEDU -> payment LOCKED
  orNumber: null,                         // null until the cashier issues it -> post-confirmation only
  agreeExact: false,
  ocr: null,
  reference: 'USEP-H-' + Math.floor(100000 + Math.random()*900000),
  cal: null,
  modal: null,
};

/* ---------- helpers ---------- */
function isoOf(dt){ return dt.getFullYear()+'-'+String(dt.getMonth()+1).padStart(2,'0')+'-'+String(dt.getDate()).padStart(2,'0'); }
function peso(n){ return '₱' + Number(n).toLocaleString('en-PH'); }
function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtDate(d){ if(!d) return '—'; const dt=new Date(d+'T00:00:00'); return dt.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'}); }
function fmtShort(d){ if(!d) return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
function getRoom(){ return ROOMS.find(r=>r.id===state.roomId) || null; }
function roomRate(r){ return RATES[r.cr_type]; }
function initials(n){ return n.split(' ').map(w=>w[0]).slice(0,2).join(''); }

/* NIGHTS ARE EXCLUSIVE of the check-out date: Aug 1 -> Aug 4 occupies the nights
   of Aug 1, 2 and 3 = 3 nights. This is NOT the venue's inclusive day count, and
   reusing that here would over-bill every stay by one night. */
function nightsOf(ci,co){
  const out=[];
  if(!ci||!co||co<=ci) return out;
  let cur=new Date(ci+'T00:00:00'); const end=new Date(co+'T00:00:00');
  while(cur<end && out.length<366){ out.push(isoOf(cur)); cur.setDate(cur.getDate()+1); }
  return out;
}

/* ---------- maintenance (same window shape as every venue room) ---------- */
function maintCovers(m,ds){ return !!m && ds>=m.from && (m.until==null || ds<=m.until); }
function maintLive(m){ return !!m && (m.until==null || m.until>=TODAY); }

/* A night is BLOCKED only by HARD maintenance. Beds being full is a different
   thing: it limits HOW MANY beds you can take, it does not close the room. */
function nightBlocked(ns){
  const r=getRoom();
  return !!r && maintCovers(r.maintenance,ns) && r.maintenance.blocks;
}
function firstBlockedNight(nights){ return nights.find(nightBlocked) || null; }

/* ---------- occupancy: every number here is COUNTED, never stored ---------- */
function occupantsOn(r,ns){ return (r.occupied && r.occupied[ns]) || []; }
function bedsTaken(r,ns){ return occupantsOn(r,ns).length; }      // the load-bearing rule
function bedsFree(r,ns){ return Math.max(0, r.beds - bedsTaken(r,ns)); }

/* A bed must be free EVERY night of the stay, so the limit is the worst night. */
function maxBedsFree(r,nights){
  if(!nights.length) return r.beds;
  return nights.reduce((min,ns)=>Math.min(min,bedsFree(r,ns)), r.beds);
}
/* Gender mix is DISPLAY ONLY — a guest may want to know the room's mix. Nothing
   is enforced, no bed is gender-locked, and the bed counter stays a plain number. */
function genderMix(r,ns){
  let F=0,M=0;
  for(const o of occupantsOn(r,ns)) (o.gender==='F') ? F++ : M++;
  return {F,M};
}
function mixLabel(mix){
  const p=[]; if(mix.F) p.push(mix.F+' female'); if(mix.M) p.push(mix.M+' male');
  return p.length?p.join(', '):'empty';
}

/* beds = occupants.length. Never read a stored count. */
function bedCount(){ return state.booking.occupants.length; }

/* ---------- the one derivation everything reads ---------- */
function derive(){
  const R=getRoom(); const b=state.booking;
  const nights=nightsOf(b.checkIn,b.checkOut);
  const beds=bedCount();
  const rate=R?roomRate(R):0;
  const maxFree=R?maxBedsFree(R,nights):0;
  const blocked=firstBlockedNight(nights);
  const total=R?(beds*rate*nights.length):0;
  const named=b.occupants.every(o=>o.name.trim().length>0);
  const badRange=!!(b.checkIn && b.checkOut && b.checkOut<=b.checkIn);

  /* the status card: neutral surface + a small dot, same as the venue page */
  let slot={show:false};
  if(b.checkIn && b.checkOut){
    if(badRange){
      slot={show:true,dot:'#d9930d',title:'Check-out must be after check-in',
            detail: b.checkOut===b.checkIn ? 'A stay is at least one night — pick the next day or later for check-out.'
                                           : 'Adjust your dates to see availability.'};
    }else if(blocked){
      /* A hostel stay is ONE CONTIGUOUS thing. The venue books around a closed
         day; a guest cannot leave and come back, so the whole range is refused. */
      slot={show:true,dot:'#d9930d',title:'The room is closed for part of your stay',
            detail:'Closed for maintenance on '+fmtShort(blocked)+' ('+esc(R.maintenance.reason)+'). A stay cannot be split around a closure — please pick different dates.'};
    }else if(maxFree===0){
      slot={show:true,dot:'#d9930d',title:'No beds left for these dates',
            detail:'Every bed in this room is taken on at least one of your nights. Try other dates or another room.'};
    }else if(beds>maxFree){
      slot={show:true,dot:'#d9930d',title:'Only '+maxFree+' bed'+(maxFree>1?'s':'')+' free for these dates',
            detail:'A bed has to be free every night of your stay. Reduce the number of beds or shorten the stay.'};
    }else if(!named){
      slot={show:true,dot:'#d9930d',title:'Name every guest',
            detail:'Each bed is booked for a named person — the staff use these at check-in.'};
    }else{
      slot={show:true,dot:'#2f9e63',title:nights.length+' night'+(nights.length>1?'s':'')+' · '+beds+' bed'+(beds>1?'s':''),
            detail:fmtShort(b.checkIn)+' → '+fmtShort(b.checkOut)+' · '+maxFree+' of '+R.beds+' beds free'};
    }
  }

  const ready = !!(R && nights.length>0 && !blocked && beds>0 && beds<=maxFree && named);

  let hint='';
  if(!b.checkIn||!b.checkOut) hint='Pick your check-in and check-out dates';
  else if(badRange) hint='Check-out must be after check-in';
  else if(blocked) hint='Closed for maintenance during your stay — pick different dates';
  else if(maxFree===0) hint='No beds available for these dates';
  else if(beds>maxFree) hint='Only '+maxFree+' bed'+(maxFree>1?'s':'')+' free — reduce your booking';
  else if(!named) hint='Enter a name for every bed';

  return { R, b, nights, beds, rate, maxFree, blocked, total, named, badRange, slot, ready, hint };
}

/* ---------- actions ---------- */
function setBooking(key,val){
  state.booking[key]=val;
  /* keep check-out after check-in — a stay is at least one night */
  if(key==='checkIn' && state.booking.checkOut && state.booking.checkOut<=val){
    const nx=new Date(val+'T00:00:00'); nx.setDate(nx.getDate()+1);
    state.booking.checkOut=isoOf(nx);
  }
  render();
}
/* The bed stepper IS the roster editor — that is what makes beds = occupants.length
   true by construction rather than by discipline. */
function setBeds(n){
  const R=getRoom(); const o=state.booking.occupants;
  n=Math.max(1, Math.min(n, R?R.beds:6));
  while(o.length<n) o.push({name:'', gender:'F'});
  while(o.length>n) o.pop();
  render();
}
function setOccupant(i,key,val){
  const o=state.booking.occupants[i]; if(!o) return;
  o[key]=val;
  if(key==='gender') render();            // name typing must not steal focus
  else updateReady();
}
/* Typing a name should not re-render the whole screen (it would drop the caret),
   so patch only the two things a name can change. */
function updateReady(){
  const d=derive();
  const btn=document.getElementById('hbGo'); const hint=document.getElementById('hbHint');
  if(btn){ btn.disabled=!d.ready; btn.style.opacity=d.ready?'1':'.45'; btn.style.cursor=d.ready?'pointer':'not-allowed'; }
  if(hint) hint.textContent=d.hint;
}
function setTab(k){ state.tab=k; render(); }
function goHome(){ location.href='venusep_venue_booking.php'; }
function goReview(){ if(derive().ready){ state.screen='review'; window.scrollTo(0,0); render(); } }
function backToDetail(){ state.screen='detail'; render(); }
function setPayMethod(m){ state.payMethod=m; render(); }
function toggleAgreeExact(el){ state.agreeExact=!!el.checked; render(); }
function closeModal(){ state.modal=null; render(); }
function restart(){ location.href='venusep_venue_booking.php'; }

/* ---------- date-picker ---------- */
function openCal(field){
  const cur=state.booking[field]||state.booking.checkIn||TODAY;
  state.cal = state.cal && state.cal.field===field ? null : { field, month:cur.slice(0,7) };
  render();
}
function closeCal(){ if(state.cal){ state.cal=null; render(); } }
function calNav(dir){
  if(!state.cal) return;
  const [y,m]=state.cal.month.split('-').map(Number);
  const d=new Date(y,m-1+dir,1);
  state.cal.month=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
  render();
}
function calPick(iso){
  if(!state.cal) return;
  const f=state.cal.field; state.cal=null;
  setBooking(f,iso);
}
function calHtml(field){
  if(!state.cal || state.cal.field!==field) return '';
  const [y,m]=state.cal.month.split('-').map(Number);
  const first=new Date(y,m-1,1), start=first.getDay(), dim=new Date(y,m,0).getDate();
  const sel=state.booking[field];
  /* check-out must be at least the night after check-in */
  const min = field==='checkOut' ? nextDay(state.booking.checkIn||TODAY) : TODAY;
  const cells=[];
  for(let i=0;i<start;i++) cells.push('<span></span>');
  for(let d=1;d<=dim;d++){
    const iso=y+'-'+String(m).padStart(2,'0')+'-'+String(d).padStart(2,'0');
    const off = iso<min;
    const cls='cal-day'+(iso===sel?' sel':'')+(iso===TODAY?' today':'');
    cells.push(`<button class="${cls}" ${off?'disabled':''} onclick="event.stopPropagation();calPick('${iso}')">${d}</button>`);
  }
  const label=first.toLocaleDateString('en-US',{month:'long',year:'numeric'});
  return `
  <div class="cal-pop" onclick="event.stopPropagation()">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
      <button class="cal-nav" onclick="calNav(-1)">‹</button>
      <span style="font-size:13px;font-weight:650">${label}</span>
      <button class="cal-nav" onclick="calNav(1)">›</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;justify-items:center">
      ${['S','M','T','W','T','F','S'].map(d=>`<span style="font-size:10.5px;font-weight:600;color:#a5a19a;padding:4px 0">${d}</span>`).join('')}
      ${cells.join('')}
    </div>
  </div>`;
}
function nextDay(iso){ const d=new Date(iso+'T00:00:00'); d.setDate(d.getDate()+1); return isoOf(d); }

/* ---------- CEDU flow ----------
   The hostel does NOT use the venue's payment path. Money moves:
       customer -> staff -> university cashier
   and two documents come from other offices: the POS (from CEDU, BEFORE the
   customer may pay) and the OR (from the cashier, AFTER payment is confirmed).
   The POS is a real gate — payment stays locked without it. The OR is NOT a
   payment status: payment is already finished when it arrives. It is tracked
   as a pending DOCUMENT so nobody thinks the booking is unpaid. */
function uploadId(input){
  const f=input.files && input.files[0]; if(!f) return;
  if(state.idFile && state.idFile.url){ try{ URL.revokeObjectURL(state.idFile.url); }catch(e){} }
  state.idFile={ name:f.name, url:URL.createObjectURL(f) };
  input.value=''; render();
}
function removeId(){
  if(state.idFile && state.idFile.url){ try{ URL.revokeObjectURL(state.idFile.url); }catch(e){} }
  state.idFile=null; render();
}
function submitRequest(){
  if(!derive().ready || !state.idFile) return;
  state.screen='pending'; state.posNumber=null; window.scrollTo(0,0); render();
}
/* [SIM] the staff side is not connected — this stands in for a staff member
   walking to CEDU, getting the POS, and typing its number into the booking. */
function demoRecordPos(){
  state.posNumber='POS-'+Math.floor(10000 + Math.random()*90000);
  state.screen='payment'; window.scrollTo(0,0); render();
}
function receiptGate(){ return state.payMethod==='cash' ? true : receiptOk(); }
function confirmBooking(){
  if(!state.posNumber) return;              // payment cannot exist before the POS
  if(!receiptGate()) return;
  state.screen='done'; window.scrollTo(0,0); render();
}
/* [SIM] the cashier issuing the OR, which happens AFTER the booking is already
   confirmed — this is why the OR is a document flag and not a payment status. */
function demoIssueOr(){
  state.orNumber='OR-'+Math.floor(100000 + Math.random()*900000);
  render();
}

/* the shared GCash checker calls back into these three — the only things this
   page knows that the engine does not: which account, what is owed, and for
   which booking. */
function gcAccount(){ return GCASH_ACCOUNTS[HOSTEL] || { name:'', number:'' }; }
function gcExpectedCentavos(){ return Math.round(derive().total*100); }   // hostel: beds x rate x nights
function gcBookingRef(){ return state.reference; }

/* ---------- shared bits ---------- */
/* The SAME header as the venue page (room-reservation.php's header()): real logo,
   sticky translucent bar, account chip linking to the profile. Kept byte-for-byte
   identical so the two pages read as one system. */
function pageHeader(){
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

/* the maintenance chip — DERIVED from the window, never stored. Identical rule
   to the venue page: no window = no chip, because a room with nothing wrong has
   nothing to say. */
function maintChip(R){
  const m=R.maintenance; if(!maintLive(m)) return '';
  const started=TODAY>=m.from;
  const tone=m.blocks?{bg:'#f6e4e4',fg:'#b23a3a'}:{bg:'#f4ecd6',fg:'#8a6d1f'};
  const head=m.blocks?'Closed for maintenance':'Partial maintenance';
  let when;
  if(!started)     when = m.until ? fmtShort(m.from)+' – '+fmtShort(m.until) : 'from '+fmtShort(m.from);
  else if(m.until) when = 'until '+fmtShort(m.until);
  else             when = 'no set return date';
  return `<span title="${esc(m.reason)}" style="padding:4px 11px;border-radius:999px;font-size:12.5px;background:${tone.bg};color:${tone.fg}">${esc(head+' · '+when)}</span>`;
}
/* MEDIUM maintenance never blocks, so this notice is its ONLY route to the
   guest — and it has to land before they pay. */
function maintDisclosure(R){
  const m=R.maintenance;
  if(!maintLive(m) || m.blocks) return '';
  const when = TODAY>=m.from ? (m.until?'until '+fmtShort(m.until):'until further notice')
                             : (m.until?fmtShort(m.from)+' – '+fmtShort(m.until):'from '+fmtShort(m.from));
  return `
    <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:12px;margin-bottom:12px;background:#fff;border:1px solid rgba(0,0,0,.09)">
      <span style="width:8px;height:8px;border-radius:999px;background:#d9930d;margin-top:5px;flex:none"></span>
      <div>
        <div style="font-size:13.5px;font-weight:650;color:#1c1b19">Partial maintenance ${esc(when)}</div>
        <div style="font-size:12.5px;color:#7a766f;margin-top:1px">${esc(m.reason)}. The room is still open and bookable — we just want you to know before you reserve.</div>
      </div>
    </div>`;
}
const PHOTO_TILE = 'background:#e9e7e2;background-image:repeating-linear-gradient(45deg,rgba(0,0,0,.035) 0 11px,transparent 11px 22px);display:flex;align-items:center;justify-content:center';
function svgBed(s,c){ return `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="${c||'currentColor'}" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M2 18v-6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v6"/><path d="M2 18h20M2 18v2M22 18v2"/><path d="M6 10V8a2 2 0 0 1 2-2h3v4"/></svg>`; }
function svgUsers(size){ return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`; }

/* ---------- 360 panorama + photo gallery (room detail hero) ----------
   Identical treatment to the venue page. One persistent viewer node is MOVED
   into the hero slot on each render — appendChild relocates the live node
   without destroying it, so the constant re-renders from typing guest names
   never reload the panorama. Rebuilt only when the room changes. */
var HERO = { node:null, viewer:null, roomId:null };
function mountHeroPano(){
  if(state.screen!=='detail') return;
  const slot=document.getElementById('heroPanoSlot');
  if(!slot || typeof pannellum==='undefined') return;   // offline → leave the placeholder
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

/* Photo gallery lightbox — view-only, mounted outside #app. Placeholder
   gradient "photos" (no real images yet). */
const GAL_GRADS=['#eef1f5,#dfe4ea','#f3eee9,#e6ddd3','#e9eef3,#d5e0ea','#eef3ee,#d9e6da','#f3eef1,#e6d5de','#eaf0f3,#d5e2ea','#f2efe8,#e4dccf','#eef3f0,#dbe7df'];
function galGrad(i){ return 'background:linear-gradient(135deg,'+GAL_GRADS[i%GAL_GRADS.length]+')'; }
function openGallery(){
  const R=getRoom(); if(!R || document.getElementById('galOverlay')) return;
  const n=Math.max(1, R.photos||4);
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

/* ---------- amenity icons ----------
   Same idea as the venue page's amenityIcon(), but matched to the HOSTEL
   catalog: a bed, a locker and a shower — not projectors and stages. */
const AI=(function(){
  const sw='fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
  const S=p=>`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}>${p}</svg>`;
  return {
    bunk:  S('<path d="M2 18v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5"/><path d="M2 18h20M2 18v3M22 18v3"/><path d="M2 9V4M22 9V4M2 6h20"/>'),
    shower:S('<path d="M4 20V7a3 3 0 0 1 6 0v1"/><line x1="10" y1="8" x2="20" y2="8"/><line x1="13" y1="12" x2="13" y2="13"/><line x1="16" y1="12" x2="16" y2="14"/><line x1="19" y1="12" x2="19" y2="13"/>'),
    door:  S('<path d="M3 21h18"/><path d="M6 21V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v17"/><circle cx="15" cy="12" r="1"/>'),
    lock:  S('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'),
    bulb:  S('<path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5.76.76 1.23 1.52 1.41 2.5"/>'),
    wind:  S('<path d="M9.59 4.59A2 2 0 1 1 11 8H2"/><path d="M12.59 19.41A2 2 0 1 0 14 16H2"/><path d="M17.73 7.73A2.5 2.5 0 1 1 19.5 12H2"/>'),
    wifi:  S('<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>'),
    table: S('<path d="M3 10h18"/><path d="M5 10V6h14v4"/><path d="M6 10v10M18 10v10"/>'),
    power: S('<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'),
    coffee:S('<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>'),
    stairs:S('<path d="M3 20h4v-4h4v-4h4V8h4V4"/>'),
    window:S('<rect x="3" y="3" width="18" height="18" rx="1"/><line x1="12" y1="3" x2="12" y2="21"/><line x1="3" y1="12" x2="21" y2="12"/>'),
    check: S('<polyline points="20 6 9 17 4 12"/>'),
  };
})();
function amenityIcon(label){
  const t=String(label).toLowerCase();
  const has=(...ks)=>ks.some(k=>t.includes(k));
  let k='check';
  if(has('bunk','bed')) k='bunk';
  else if(has('shower','hot')) k='shower';
  else if(has('bathroom','cr','toilet')) k='door';
  else if(has('locker','safe')) k='lock';
  else if(has('light','lamp','reading')) k='bulb';
  else if(has('air-condition','aircon','conditioned','fan','ventil')) k='wind';
  else if(has('wi-fi','wifi','internet')) k='wifi';
  else if(has('table','desk','study')) k='table';
  else if(has('outlet','power','socket','charg')) k='power';
  else if(has('lounge','water','drinking','coffee','pantry')) k='coffee';
  else if(has('step-free','ground','floor','stair','access')) k='stairs';
  else if(has('window','courtyard','view','quiet')) k='window';
  return AI[k];
}

/* a small "n of 6" bed strip — the occupancy fact that replaces a status label */
function bedStrip(taken,total,size){
  const w=size||9;
  let out='<span style="display:inline-flex;gap:3px;vertical-align:middle">';
  for(let i=0;i<total;i++) out+=`<span style="width:${w}px;height:${w}px;border-radius:2px;background:${i<taken?'#1f2a44':'#dcd9d3'}"></span>`;
  return out+'</span>';
}

/* ---------- screen: DETAIL ---------- */
function detailScreen(){
  const d=derive(); const R=d.R; if(!R) return '';
  const b=state.booking;
  const rate=roomRate(R);

  const tabDefs=[['overview','Overview'],['availability','Availability'],['policies','Policies']];
  const tabs=tabDefs.map(([k,label])=>`
    <button onclick="setTab('${k}')" style="background:none;border:none;padding:12px 14px;font-size:14px;font-weight:${state.tab===k?680:550};color:${state.tab===k?'#1c1b19':'#8a857d'};border-bottom:2px solid ${state.tab===k?'#1f2a44':'transparent'};cursor:pointer;margin-bottom:-1px">${label}</button>`).join('');

  let content='';
  if(state.tab==='overview'){
    /* same "At a glance" treatment as the venue page — but the FACTS are the
       ones a bed has. No catering, no bookable hours, no "best for". */
    const sw='fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
    const I={
      tag:`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.4"/></svg>`,
      moon:`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>`,
      cr:`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}><path d="M4 20V7a3 3 0 0 1 6 0v1"/><line x1="10" y1="8" x2="20" y2="8"/><line x1="13" y1="12" x2="13" y2="13"/><line x1="16" y1="12" x2="16" y2="14"/></svg>`,
      users:`<svg width="19" height="19" viewBox="0 0 24 24" ${sw}><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>`,
    };
    const facts=[
      { icon:I.tag,   text:'Rate: '+peso(rate)+' per head, per night — paid in full' },
      { icon:I.moon,  text:'Charged per night; check-out day is not a night' },
      { icon:I.cr,    text:R.cr_type==='private' ? 'Private CR inside the room' : 'Communal CR — shared, outside the room' },
      { icon:I.users, text:'You book beds, not the room — book all '+R.beds+' and it is yours' },
    ];
    const row=(icon,label)=>`<div style="display:flex;align-items:flex-start;gap:11px;font-size:14px;line-height:1.45;color:#1c1b19"><span style="color:#3a372f;flex:none;display:flex;margin-top:1px">${icon}</span>${esc(label)}</div>`;
    content=`
      <p style="margin:0 0 28px;font-size:15.5px;line-height:1.7;color:#3a372f;max-width:70ch">${esc(R.description)}</p>
      <h3 style="margin:0 0 16px;font-size:16px;font-weight:660">At a glance</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px 28px;margin-bottom:34px">${facts.map(f=>row(f.icon,f.text)).join('')}</div>
      <h3 style="margin:0 0 16px;font-size:16px;font-weight:660">In this room</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:16px 28px">${R.amenities.map(a=>row(amenityIcon(a),a)).join('')}</div>`;
  }else if(state.tab==='availability'){
    /* Occupancy is a room x NIGHT fact, so it can only be shown per night —
       there is no single label that is true of the room. */
    const upcoming=[];
    let cur=new Date(TODAY+'T00:00:00');
    for(let i=0;i<14;i++){ upcoming.push(isoOf(cur)); cur.setDate(cur.getDate()+1); }
    content=`
      <div style="font-size:13px;color:#7a766f;margin-bottom:12px">Beds are booked one at a time, so a room can be partly full. Here are the next 14 nights.</div>
      <div style="border:1px solid rgba(0,0,0,.08);border-radius:12px;overflow:hidden;background:#fff">
        ${upcoming.map((ns,i)=>{
          const taken=bedsTaken(R,ns), free=bedsFree(R,ns), mix=genderMix(R,ns);
          const closed=nightBlocked(ns);
          const line=i?'border-top:1px solid rgba(0,0,0,.06);':'';
          return `<div style="display:flex;align-items:center;gap:12px;padding:10px 14px;${line}">
            <span style="font-size:13px;color:#4a463f;min-width:118px">${esc(fmtShort(ns))}</span>
            ${closed
              ? `<span style="font-size:12.5px;color:#b23a3a">Closed for maintenance</span>`
              : `${bedStrip(taken,R.beds)}
                 <span style="font-size:12.5px;color:${free?'#4a463f':'#b23a3a'}">${free?free+' of '+R.beds+' free':'full'}</span>
                 <span style="font-size:12px;color:#a5a19a;margin-left:auto">${taken?esc(mixLabel(mix)):''}</span>`}
          </div>`;
        }).join('')}
      </div>`;
  }else{
    /* same policy layout as the venue page — hairline-separated blocks */
    const policy=(title,body)=>`<div style="padding:18px 0;border-top:1px solid rgba(0,0,0,.08)"><div style="font-weight:640;font-size:14px;margin-bottom:5px">${title}</div><p style="margin:0;font-size:13.5px;line-height:1.6;color:#4a463f;max-width:70ch">${body}</p></div>`;
    content=`
      <h3 style="margin:0 0 4px;font-size:16px;font-weight:660">Booking &amp; stay policies</h3>
      ${policy('Booking is per bed','You reserve <strong>beds</strong>, not the room. Other guests may book the remaining beds in the same room — unless you book all '+R.beds+', which gives you the whole room. A bed has to be free on <strong>every night</strong> of your stay.')}
      ${policy('Every bed is named','Give the name of the person sleeping in each bed. Staff match these against your valid ID at check-in. The gender mix of the room is shown so you know who you are sharing with — <strong>no bed is reserved by gender</strong>.')}
      ${policy('Nights, not days','A stay is counted in <strong>nights</strong>: check in on the 1st and out on the 4th and that is 3 nights. Your check-out day is not charged. A stay must be <strong>continuous</strong> — if the room is closed for maintenance on any night in your range, those dates cannot be booked at all.')}
      ${policy('Payment goes through '+CEDU.name,'After you book, hostel staff request a <strong>POS</strong> from '+CEDU.name+'. <strong>You cannot pay until it arrives</strong> — nothing is wrong and nothing is late while you wait. Once it is in, pay cash to the staff or GCash to the designated staff account (send the <strong>exact amount</strong> — anything else is rejected automatically).')}
      ${policy('One full payment','The whole stay is paid at once, up front. There is no per-night billing and no partial payment.')}
      ${policy('Your receipts','You get a <strong>Transaction Receipt</strong> straight away, a <strong>GCash Payment Receipt</strong> if you paid online, and an <strong>Official Receipt</strong> from the '+CASHIER.name+' once the staff hand over your payment. The OR arrives after your booking is already confirmed — the booking is not waiting on it.')}
      ${policy('At check-in','Show the staff your <strong>POS</strong> and your <strong>Official Receipt</strong>, plus the valid ID you submitted. Each guest sleeps in the bed booked under their name.')}
      ${policy('Refunds','A refund requires the system Transaction Receipt, the GCash Payment Receipt (if you paid by GCash), <strong>and</strong> the Official Receipt. Requests missing any of these cannot be processed.')}`;
  }

  /* --- booking panel --- */
  const dateField=(field,label,val)=>`
    <div style="position:relative;flex:1;min-width:0">
      <div style="font-size:12px;font-weight:600;color:#5c584f;margin-bottom:5px">${label}</div>
      <button onclick="event.stopPropagation();openCal('${field}')" style="width:100%;height:42px;border:1px solid rgba(0,0,0,.14);border-radius:10px;background:#fff;padding:0 11px;font-size:13.5px;color:${val?'#1c1b19':'#a5a19a'};text-align:left;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:6px">
        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${val?esc(fmtShort(val)):'Select'}</span>
      </button>
      ${calHtml(field)}
    </div>`;

  const beds=d.beds;
  const cap=R.beds;
  const roster=state.booking.occupants.map((o,i)=>`
    <div class="bed-row">
      <span class="bed-no">${i+1}</span>
      <input class="bed-name" type="text" value="${esc(o.name)}" placeholder="Full name of guest ${i+1}" oninput="setOccupant(${i},'name',this.value)">
      <span class="g-seg">
        <button class="${o.gender==='F'?'on':''}" onclick="setOccupant(${i},'gender','F')">Female</button>
        <button class="${o.gender==='M'?'on':''}" onclick="setOccupant(${i},'gender','M')">Male</button>
      </span>
    </div>`).join('');

  const firstNight=d.nights[0];
  const mixNow=firstNight?genderMix(R,firstNight):null;
  const takenNow=firstNight?bedsTaken(R,firstNight):0;

  const slot = d.slot.show ? `
    <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:12px;margin-bottom:12px;background:#fff;border:1px solid rgba(0,0,0,.09)">
      <span style="width:8px;height:8px;border-radius:999px;background:${d.slot.dot};margin-top:5px;flex:none"></span>
      <div>
        <div style="font-size:13.5px;font-weight:650;color:#1c1b19">${d.slot.title}</div>
        <div style="font-size:12.5px;color:#7a766f;margin-top:1px">${d.slot.detail}</div>
      </div>
    </div>` : '';

  return `
  ${pageHeader()}
  <main onclick="closeCal()" style="max-width:1180px;margin:0 auto;padding:20px 24px 72px">
    <a onclick="goHome()" style="display:inline-flex;align-items:center;gap:7px;font-size:13.5px;font-weight:600;cursor:pointer;margin-bottom:16px">← All rooms</a>

    <div style="display:grid;grid-template-columns:minmax(0,1fr) 379px;gap:34px;align-items:start">
    <!-- LEFT -->
    <div style="min-width:0">
      <!-- same hero as the venue page: the 360 panorama as the big main shot,
           two stacked photos beside it, thumbnail strip underneath -->
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
            <span>${esc(HOSTEL)}</span>
            <span style="display:inline-flex;align-items:center;gap:6px" title="${esc(CR_LABEL[R.cr_type])}">${amenityIcon(R.cr_type==='private'?'private bathroom':'shared bathroom')}${esc(CR_LABEL[R.cr_type])}</span>
            <span style="display:inline-flex;align-items:center;gap:6px" title="${R.beds} beds in this room">${svgBed(17,'currentColor')}${R.beds} beds</span>
            ${maintChip(R)}
          </div>
        </div>
      </div>

      <div style="display:flex;gap:4px;border-bottom:1px solid rgba(0,0,0,.1);margin-top:18px">${tabs}</div>
      <div style="margin-top:24px">${content}</div>
    </div>

      <aside style="position:sticky;top:88px">
        <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;box-shadow:0 1px 2px rgba(0,0,0,.04),0 12px 32px rgba(0,0,0,.05);padding:18px 18px 20px">
          <div style="font-size:16px;font-weight:680;margin-bottom:2px">Reserve a bed</div>
          <div style="font-size:12.5px;color:#8a857d;margin-bottom:16px">You book <strong>beds</strong>, not the room. Book all ${cap} and the room is yours.</div>
          ${maintDisclosure(R)}

          <div style="display:flex;gap:9px;margin-bottom:14px">
            ${dateField('checkIn','Check-in',b.checkIn)}
            ${dateField('checkOut','Check-out',b.checkOut)}
          </div>

          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:4px">
            <div>
              <div style="font-size:12px;font-weight:600;color:#5c584f">Beds</div>
              <div style="font-size:11.5px;color:#a5a19a">${d.nights.length?d.maxFree+' free for your dates':'up to '+cap}</div>
            </div>
            <div style="display:flex;align-items:center;gap:0;border:1px solid rgba(0,0,0,.14);border-radius:10px;overflow:hidden;height:38px;background:#fff">
              <button onclick="setBeds(${beds-1})" ${beds<=1?'disabled':''} style="width:36px;height:100%;border:none;background:none;font-size:17px;color:${beds<=1?'#c6c2ba':'#4a463f'};cursor:${beds<=1?'default':'pointer'}">−</button>
              <span style="min-width:26px;text-align:center;font-size:14px;font-weight:680">${beds}</span>
              <button onclick="setBeds(${beds+1})" ${beds>=cap?'disabled':''} style="width:36px;height:100%;border:none;background:none;font-size:17px;color:${beds>=cap?'#c6c2ba':'#4a463f'};cursor:${beds>=cap?'default':'pointer'}">+</button>
            </div>
          </div>

          <div style="margin:10px 0 14px">
            <div style="font-size:12px;font-weight:600;color:#5c584f;margin-bottom:2px">Who is sleeping in each bed?</div>
            ${roster}
            ${beds===cap?`<div style="font-size:11.5px;color:#7a766f;margin-top:8px;padding-top:8px;border-top:1px solid rgba(0,0,0,.06)">Booking all ${cap} beds gives you the whole room to yourselves.</div>`:''}
          </div>

          ${firstNight&&takenNow?`
          <div style="font-size:12px;color:#8a857d;margin-bottom:12px;padding:9px 11px;border:1px solid rgba(0,0,0,.08);border-radius:10px">
            Already in this room on ${esc(fmtShort(firstNight))}: <strong style="font-weight:640;color:#4a463f">${esc(mixLabel(mixNow))}</strong>
            <div style="font-size:11px;color:#a5a19a;margin-top:2px">Shown so you know the room's mix — beds are not reserved by gender.</div>
          </div>`:''}

          ${slot}

          <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;padding-top:12px;border-top:1px solid rgba(0,0,0,.08)">
            <div>
              <div style="font-size:12.5px;color:#8a857d">${d.nights.length?`${beds} bed${beds>1?'s':''} × ${peso(rate)} × ${d.nights.length} night${d.nights.length>1?'s':''}`:'Total'}</div>
              <div style="font-size:22px;font-weight:720;letter-spacing:-.01em">${peso(d.total)}</div>
            </div>
          </div>
          <div style="font-size:11.5px;color:#a5a19a;margin-top:4px">Paid in full after the staff get your POS from ${esc(CEDU.name)}.</div>

          <button id="hbGo" onclick="goReview()" ${d.ready?'':'disabled'} style="width:100%;height:46px;margin-top:12px;border:none;border-radius:12px;background:#1f2a44;color:#fff;font-size:14px;font-weight:650;cursor:${d.ready?'pointer':'not-allowed'};opacity:${d.ready?1:.45}">Continue</button>
          <div id="hbHint" style="font-size:12px;color:#a5a19a;text-align:center;margin-top:8px;min-height:16px">${esc(d.hint)}</div>
        </div>
      </aside>
    </div>
  </main>`;
}

/* rows shared by review / pending / payment / done */
function bookingRows(){
  const d=derive(); const R=d.R;
  return { d, R,
    stayLabel: fmtShort(d.b.checkIn)+' → '+fmtShort(d.b.checkOut),
    nightsLabel: d.nights.length+' night'+(d.nights.length>1?'s':''),
    bedsLabel: d.beds+' bed'+(d.beds>1?'s':''),
    mathLabel: `${d.beds} × ${peso(d.rate)} × ${d.nights.length} night${d.nights.length>1?'s':''}`,
  };
}
const blk=(label,value,span)=>`<div style="min-width:0;${span?'grid-column:1 / -1;':''}"><div style="font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#a3a09a;margin-bottom:3px">${label}</div><div style="font-size:13.5px;font-weight:640;color:#1c1b19;overflow-wrap:anywhere">${value}</div></div>`;

function rosterHtml(occupants){
  return `
    <div style="margin:12px 0 2px">
      <div style="font-size:11px;font-weight:600;color:#a5a19a;text-transform:uppercase;letter-spacing:.06em;margin-bottom:7px">Guests (one per bed)</div>
      <div>
        ${occupants.map((o,i)=>`
          <div style="display:flex;justify-content:space-between;gap:16px;padding:9px 0;${i?'border-top:1px solid rgba(0,0,0,.06);':''}font-size:13px">
            <span style="color:#5c584f">Bed ${i+1} · ${esc(o.name||'—')}</span>
            <span style="font-weight:600;color:#1c1b19">${o.gender==='F'?'Female':'Male'}</span>
          </div>`).join('')}
      </div>
    </div>`;
}

/* ---------- screen: REVIEW ---------- */
function reviewScreen(){
  const x=bookingRows(); const R=x.R; if(!R) return '';
  const idOk=!!state.idFile;
  return `
  ${pageHeader()}
  <main style="max-width:620px;margin:0 auto;padding:34px 24px 72px">
    <button onclick="backToDetail()" style="background:none;border:none;color:#8a857d;font-size:13px;font-weight:600;cursor:pointer;padding:0;margin-bottom:14px">← Back</button>
    <h1 style="margin:0 0 6px;font-size:25px;font-weight:720;letter-spacing:-.01em">Review your booking</h1>
    <p style="margin:0 0 22px;font-size:14px;color:#7a766f">Check the details, then attach a valid ID. Staff request your POS from ${esc(CEDU.name)} after you submit.</p>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px">
        ${blk('Room', esc(R.name)+' <span style="font-weight:400;color:#8a857d">· '+esc(CR_LABEL[R.cr_type])+'</span>')}
        ${blk('Booked by', esc(ACCOUNT.name))}
        ${blk('Check-in', esc(fmtDate(x.d.b.checkIn)))}
        ${blk('Check-out', esc(fmtDate(x.d.b.checkOut)))}
        ${blk('Stay', esc(x.nightsLabel))}
        ${blk('Beds', esc(x.bedsLabel))}
      </div>
      ${rosterHtml(state.booking.occupants)}
      <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-top:14px;padding-top:13px;border-top:1px solid rgba(0,0,0,.08)">
        <span style="font-size:12.5px;color:#8a857d">${esc(x.mathLabel)}</span>
        <span style="font-size:21px;font-weight:720">${peso(x.d.total)}</span>
      </div>
    </div>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:18px;margin-top:14px">
      <div style="font-size:14.5px;font-weight:680;margin-bottom:3px">Valid ID <span style="color:#b23a3a">*</span></div>
      <div style="font-size:12.5px;color:#8a857d;margin-bottom:12px">One ID for the person booking. Staff match it at check-in, and ${esc(CEDU.name)} needs it on the POS request.</div>
      <input type="file" id="idFile" accept="image/png,image/jpeg,image/webp,application/pdf" style="display:none" onchange="uploadId(this)">
      ${idOk?`
        <div style="display:flex;align-items:center;gap:11px;border:1px solid rgba(0,0,0,.12);border-radius:11px;padding:11px 13px;background:#fff">
          <span style="width:8px;height:8px;border-radius:999px;background:#2f9e63;flex:none"></span>
          <span style="flex:1;font-size:13px;font-weight:600;color:#4a463f;overflow-wrap:anywhere">${esc(state.idFile.name)}</span>
          <button onclick="removeId()" style="flex:none;background:#fff;border:1px solid rgba(0,0,0,.14);border-radius:8px;padding:6px 11px;font-size:12px;font-weight:600;color:#4a463f;cursor:pointer">Remove</button>
        </div>`:`
        <div onclick="document.getElementById('idFile').click()" style="border:1.5px dashed rgba(0,0,0,.18);border-radius:11px;padding:24px 14px;text-align:center;background:#fff;cursor:pointer">
          <div style="font-size:13.5px;font-weight:600;color:#4a463f">Tap to upload your valid ID</div>
          <div style="font-size:11.5px;color:#a5a19a;margin-top:3px">PNG, JPG or PDF · school or government ID</div>
        </div>`}
    </div>

    <button onclick="submitRequest()" ${idOk?'':'disabled'} style="width:100%;height:48px;margin-top:16px;border:none;border-radius:12px;background:#1f2a44;color:#fff;font-size:14.5px;font-weight:650;cursor:${idOk?'pointer':'not-allowed'};opacity:${idOk?1:.45}">Submit booking request</button>
    <div style="font-size:12px;color:#a5a19a;text-align:center;margin-top:8px">${idOk?'You cannot pay yet — the POS has to come from '+esc(CEDU.name)+' first.':'Attach a valid ID to continue'}</div>
  </main>`;
}

/* ---------- screen: PENDING (waiting on CEDU for the POS) ---------- */
function pendingScreen(){
  const x=bookingRows(); const R=x.R; if(!R) return '';
  const st=(label,status,bg,fg)=>`
    <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:13px 0;border-bottom:1px solid rgba(0,0,0,.06)">
      <span style="font-size:13.5px;color:#4a463f">${label}</span>
      <span style="padding:4px 11px;border-radius:999px;font-size:12px;background:${bg};color:${fg}">${status}</span>
    </div>`;
  return `
  ${pageHeader()}
  <main style="max-width:600px;margin:0 auto;padding:44px 24px 72px;text-align:center">
    <div style="width:46px;height:46px;border-radius:999px;background:#fbeee0;display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#8a5a12" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <h1 style="margin:0 0 8px;font-size:25px;font-weight:720;letter-spacing:-.01em">Submitted — waiting for your POS</h1>
    <p style="margin:0 auto;max-width:460px;font-size:14.5px;line-height:1.6;color:#4a463f">Hostel staff take your booking to <strong>${esc(CEDU.name)}</strong> and come back with a <strong>POS</strong>. Payment opens once they have it — there is nothing for you to do until then, and nothing to pay yet.</p>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:4px 18px;margin-top:24px;text-align:left;box-shadow:0 1px 2px rgba(0,0,0,.04),0 12px 32px rgba(0,0,0,.05)">
      ${st('Booking request','Received','#e8f2ec','#1c7a4f')}
      ${st('Valid ID ('+esc(state.idFile?state.idFile.name:'submitted')+')','Under review','#fdf3e6','#8a5a12')}
      ${st('POS from '+esc(CEDU.name),'Staff are requesting it','#fdf3e6','#8a5a12')}
      <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:13px 0">
        <span style="font-size:13.5px;color:#4a463f">Payment</span>
        <span style="padding:4px 11px;border-radius:999px;font-size:12px;background:#fff;border:1px solid rgba(0,0,0,.14);color:#6b675f">Locked until the POS arrives</span>
      </div>
    </div>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:16px;margin-top:14px;text-align:left">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px">
        ${blk('Reference', esc(state.reference))}
        ${blk('Room', esc(R.name))}
        ${blk('Stay', esc(x.stayLabel)+' <span style="font-weight:400;color:#8a857d">· '+esc(x.nightsLabel)+'</span>')}
        ${blk('Amount (pay after the POS)', peso(x.d.total))}
      </div>
    </div>

    <div style="border:1.5px dashed rgba(0,0,0,.16);border-radius:12px;padding:14px 16px;margin-top:18px;text-align:left">
      <div style="font-size:11px;font-weight:650;letter-spacing:.06em;text-transform:uppercase;color:#a5a19a;margin-bottom:6px">Demo only</div>
      <div style="font-size:12.5px;color:#8a857d;line-height:1.5;margin-bottom:10px">The staff side isn't connected in this mockup — use this to simulate a staff member coming back from ${esc(CEDU.name)} and recording the POS number.</div>
      <button onclick="demoRecordPos()" style="height:42px;padding:0 18px;border:1px solid rgba(0,0,0,.16);border-radius:10px;background:#fff;font-size:13px;font-weight:640;cursor:pointer">Simulate POS received → unlock payment</button>
    </div>

    <button onclick="restart()" style="background:none;border:none;color:#8a857d;font-size:13px;font-weight:600;cursor:pointer;margin-top:18px;text-decoration:underline">Browse other rooms</button>
  </main>`;
}

/* ---------- screen: PAYMENT ---------- */
/* Reuses the SHARED receipt checker (includes/gcash-checker.php) — same engine as
   the venue, pointed at the hostel staff's account via $gcAccount. */
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
  const paid=receiptGate();

  const methodCard=(m,title,sub)=>{
    const on=state.payMethod===m;
    return `
    <button onclick="setPayMethod('${m}')" style="flex:1;text-align:left;border:1px solid ${on?'#1f2a44':'rgba(0,0,0,.14)'};border-radius:12px;padding:12px 13px;background:${on?'#f7f8fa':'#fff'};cursor:pointer">
      <div style="display:flex;align-items:center;gap:8px">
        <span style="width:14px;height:14px;border-radius:999px;border:1.5px solid ${on?'#1f2a44':'#c6c2ba'};display:flex;align-items:center;justify-content:center;flex:none">${on?'<span style="width:7px;height:7px;border-radius:999px;background:#1f2a44"></span>':''}</span>
        <span style="font-size:13.5px;font-weight:650">${title}</span>
      </div>
      <div style="font-size:11.5px;color:#8a857d;margin-top:4px;line-height:1.45">${sub}</div>
    </button>`;
  };

  const gcashBody=`
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;padding:14px;border:1px solid rgba(0,0,0,.08);border-radius:12px;background:#fff;margin-bottom:14px">
      ${blk('Send to (GCash name)', esc(gcAccount().name))}
      ${blk('GCash number', esc(gcAccount().number.replace(/^(\d{4})(\d{3})(\d{4})$/,'$1 $2 $3')))}
      ${blk('Reference to include', esc(state.reference))}
      ${blk('Exact amount', '<span style="font-size:16px">'+peso(x.d.total)+'</span>')}
      <div style="grid-column:1 / -1;font-size:11.5px;color:#8a857d;line-height:1.5;border-top:1px solid rgba(0,0,0,.06);padding-top:11px">
        This is the hostel staff's designated GCash account — <strong>not</strong> the same account as the event venues. The staff cash this out and hand it to the ${esc(CASHIER.name)}, who issues your Official Receipt.
      </div>
    </div>
    <label style="display:flex;align-items:flex-start;gap:9px;font-size:12.5px;color:#4a463f;line-height:1.5;margin-bottom:12px;cursor:pointer">
      <input type="checkbox" ${state.agreeExact?'checked':''} onchange="toggleAgreeExact(this)" style="margin-top:2px;width:15px;height:15px;accent-color:#1f2a44;flex:none">
      <span>I sent <strong>exactly ${peso(x.d.total)}</strong> to the account above. A different amount is rejected automatically.</span>
    </label>
    ${receiptPanelHtml()}`;

  const cashBody=`
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;padding:14px;border:1px solid rgba(0,0,0,.08);border-radius:12px;background:#fff;margin-bottom:14px">
      ${blk('Pay at', esc(CASH_PAY.where))}
      ${blk('Open', esc(CASH_PAY.hours))}
      ${blk('Quote your POS', esc(state.posNumber||'—'))}
      ${blk('Exact amount', '<span style="font-size:16px">'+peso(x.d.total)+'</span>')}
      <div style="grid-column:1 / -1;font-size:11.5px;color:#8a857d;line-height:1.5;border-top:1px solid rgba(0,0,0,.06);padding-top:11px">
        You hand the cash to the hostel staff, who bring it to the ${esc(CASHIER.name)} with your POS. Your Official Receipt comes back from the cashier.
      </div>
    </div>`;

  return `
  ${pageHeader()}
  <main style="max-width:620px;margin:0 auto;padding:34px 24px 72px">
    <h1 style="margin:0 0 6px;font-size:25px;font-weight:720;letter-spacing:-.01em">Payment</h1>
    <p style="margin:0 0 18px;font-size:14px;color:#7a766f">Your POS is in. You can pay now — one full payment for the whole stay.</p>

    <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;margin-bottom:16px;background:#fff;border:1px solid rgba(0,0,0,.09)">
      <span style="width:8px;height:8px;border-radius:999px;background:#2f9e63;flex:none"></span>
      <div style="flex:1">
        <div style="font-size:13.5px;font-weight:650;color:#1c1b19">POS received from ${esc(CEDU.name)} · ${esc(state.posNumber||'—')}</div>
        <div style="font-size:12.5px;color:#7a766f;margin-top:1px">Recorded by the hostel staff. Keep this number — you show it at check-in.</div>
      </div>
    </div>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:18px;margin-bottom:14px">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px">
        ${blk('Room', esc(R.name)+' <span style="font-weight:400;color:#8a857d">· '+esc(CR_LABEL[R.cr_type])+'</span>')}
        ${blk('Stay', esc(x.stayLabel)+' <span style="font-weight:400;color:#8a857d">· '+esc(x.nightsLabel)+'</span>')}
        ${blk('Beds', esc(x.bedsLabel))}
        ${blk('Amount due', '<span style="font-size:17px">'+peso(x.d.total)+'</span> <span style="font-weight:400;color:#8a857d;font-size:12px">'+esc(x.mathLabel)+'</span>')}
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-bottom:16px">
      ${methodCard('gcash','GCash','Send to the designated staff account — checked instantly.')}
      ${methodCard('cash','Cash to staff','Hand it to the hostel front desk with your POS.')}
    </div>

    ${cash?cashBody:gcashBody}

    <button onclick="confirmBooking()" ${paid?'':'disabled'} style="width:100%;height:48px;margin-top:4px;border:none;border-radius:12px;background:#1f2a44;color:#fff;font-size:14.5px;font-weight:650;cursor:${paid?'pointer':'not-allowed'};opacity:${paid?1:.45}">${cash?'I will pay at the front desk':'Confirm payment'}</button>
    <div style="font-size:12px;color:#a5a19a;text-align:center;margin-top:8px">${cash?'Your beds are held until you pay at the front desk.':(paid?'':'Upload a receipt that passes the check to continue')}</div>
  </main>`;
}

/* ---------- screen: CONFIRMATION ---------- */
function doneScreen(){
  const x=bookingRows(); const R=x.R; if(!R) return '';
  const cash=state.payMethod==='cash';
  const or=state.orNumber;
  const st=(label,status,bg,fg)=>`
    <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:13px 0;border-bottom:1px solid rgba(0,0,0,.06)">
      <span style="font-size:13.5px;color:#4a463f">${label}</span>
      <span style="padding:4px 11px;border-radius:999px;font-size:12px;background:${bg};color:${fg}">${status}</span>
    </div>`;
  return `
  ${pageHeader()}
  <main style="max-width:600px;margin:0 auto;padding:44px 24px 72px;text-align:center">
    <div style="width:46px;height:46px;border-radius:999px;background:#e8f2ec;display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1c7a4f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <h1 style="margin:0 0 8px;font-size:25px;font-weight:720;letter-spacing:-.01em">${cash?'Beds reserved — pay at the front desk':'Booking confirmed'}</h1>
    <p style="margin:0 auto;max-width:460px;font-size:14.5px;line-height:1.6;color:#4a463f">${cash
      ? 'Your beds are held. Bring your POS to the hostel front desk and hand the staff '+peso(x.d.total)+'.'
      : 'Your payment is verified and your beds are booked. One document is still on its way — see below.'}</p>

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:4px 18px;margin-top:24px;text-align:left;box-shadow:0 1px 2px rgba(0,0,0,.04),0 12px 32px rgba(0,0,0,.05)">
      ${st('Payment', cash?'Awaiting cash at the front desk':'Confirmed','#'+(cash?'fdf3e6':'e8f2ec'),'#'+(cash?'8a5a12':'1c7a4f'))}
      ${st('Transaction receipt','Emailed to you','#e8f2ec','#1c7a4f')}
      ${!cash?st('GCash payment receipt','Yours to keep','#e8f2ec','#1c7a4f'):''}
      <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:13px 0">
        <span style="font-size:13.5px;color:#4a463f">Official Receipt (${esc(CASHIER.name)})</span>
        <span style="padding:4px 11px;border-radius:999px;font-size:12px;background:${or?'#e8f2ec':'#fdf3e6'};color:${or?'#1c7a4f':'#8a5a12'}">${or?esc(or):'Not issued yet'}</span>
      </div>
    </div>

    ${!or?`
    <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:12px;margin-top:14px;background:#fff;border:1px solid rgba(0,0,0,.09);text-align:left">
      <span style="width:8px;height:8px;border-radius:999px;background:#d9930d;margin-top:5px;flex:none"></span>
      <div>
        <div style="font-size:13.5px;font-weight:650;color:#1c1b19">Your Official Receipt is still coming</div>
        <div style="font-size:12.5px;color:#7a766f;margin-top:1px">The staff bring your payment to the ${esc(CASHIER.name)}, who issues the OR. We'll email it when it's ready — <strong>you need it at check-in</strong>. This does not affect your booking: it is already ${cash?'reserved':'paid and confirmed'}.</div>
      </div>
    </div>`:''}

    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:16px;margin-top:14px;text-align:left">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px">
        ${blk('Reference', esc(state.reference))}
        ${blk('POS number', esc(state.posNumber||'—'))}
        ${blk('Room', esc(R.name)+' <span style="font-weight:400;color:#8a857d">· '+esc(CR_LABEL[R.cr_type])+'</span>')}
        ${blk('Stay', esc(x.stayLabel)+' <span style="font-weight:400;color:#8a857d">· '+esc(x.nightsLabel)+'</span>')}
        ${blk('Beds', esc(x.bedsLabel))}
        ${blk('Total', peso(x.d.total))}
      </div>
      ${rosterHtml(state.booking.occupants)}
      <div style="font-size:12px;color:#8a857d;line-height:1.6;margin-top:12px;border-top:1px solid rgba(0,0,0,.06);padding-top:12px">
        <strong style="font-weight:640;color:#4a463f">At check-in:</strong> show the staff your <strong>POS</strong> and your <strong>Official Receipt</strong>, plus the valid ID you submitted. Each guest sleeps in the bed booked under their name.
      </div>
    </div>

    ${!or?`
    <div style="border:1.5px dashed rgba(0,0,0,.16);border-radius:12px;padding:14px 16px;margin-top:18px;text-align:left">
      <div style="font-size:11px;font-weight:650;letter-spacing:.06em;text-transform:uppercase;color:#a5a19a;margin-bottom:6px">Demo only</div>
      <div style="font-size:12.5px;color:#8a857d;line-height:1.5;margin-bottom:10px">Nothing emails in this mockup. Use this to simulate the ${esc(CASHIER.name)} issuing the Official Receipt after the staff hand over the money.</div>
      <button onclick="demoIssueOr()" style="height:42px;padding:0 18px;border:1px solid rgba(0,0,0,.16);border-radius:10px;background:#fff;font-size:13px;font-weight:640;cursor:pointer">Simulate OR issued by the cashier</button>
    </div>`:''}

    <button onclick="restart()" style="background:none;border:none;color:#8a857d;font-size:13px;font-weight:600;cursor:pointer;margin-top:18px;text-decoration:underline">Back to venues</button>
  </main>`;
}

/* ---------- render ---------- */
function currentScreen(){
  switch(state.screen){
    case 'detail':  return detailScreen();
    case 'review':  return reviewScreen();
    case 'pending': return pendingScreen();
    case 'payment': return paymentScreen();
    case 'done':    return doneScreen();
  }
  return '';
}
/* Redraw, then put the caret back where it was — the bed-name inputs re-render
   on every keystroke otherwise and typing would jump to the start. */
function render(){
  const ae=document.activeElement;
  const id=ae&&ae.id?ae.id:null;
  const cls=ae&&ae.className==='bed-name'?[...document.querySelectorAll('.bed-name')].indexOf(ae):-1;
  const pos=ae&&ae.selectionStart;
  document.getElementById('app').innerHTML=`<div style="min-height:100vh;background:#fff">${currentScreen()}</div>`;
  /* re-attach the live 360 node after the innerHTML swap — it is moved, not
     rebuilt, so typing a guest name never reloads the panorama */
  mountHeroPano();
  if(cls>-1){
    const back=document.querySelectorAll('.bed-name')[cls];
    if(back){ back.focus(); try{ back.setSelectionRange(pos,pos); }catch(e){} }
  }else if(id){
    const back=document.getElementById(id);
    if(back && back.focus) back.focus();
  }
}
render();
</script>
</body>
</html>
