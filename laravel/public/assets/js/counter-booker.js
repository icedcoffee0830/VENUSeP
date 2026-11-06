/* Script for legacy/includes/counter-booker.php. */
/* COUNTER MODE: who the booking is FOR. In the customer flow this is simply
   whoever is signed in; at the counter it is a person standing there, who may
   or may not already have an account, so it has to be asked and — when they
   claim an account — proved. `verified` only goes true after the server has
   checked their password (admin/customer-verify.php); the page cannot set it
   on its own, and booking-create.php re-checks the session before trusting it. */
const booker0 = {
  mode: 'account',        // 'account' = has a login | 'new' = make them one now | 'walkin' = no account at all
  query: '',              // what staff typed into the search box
  results: [],            // matches, with the phone already masked by the server
  searching: false,
  customerId: 0,          // the chosen account
  name: '', phone: '',    // the chosen account's details, or the walk-in's
  address: '',            // walk-ins only, optional
  email: '',              // walk-ins only, required unless noEmail: where their confirmation + System Receipt go
  noEmail: false,         // walk-ins only: the guest has no email, so the receipt is printed at the counter
  password: '',           // typed by the CUSTOMER, sent once, never stored
  verified: false,        // the server confirmed that password
  noPassword: false,      // the account has none (Google, or just made here): "ID checked" instead
  idIdentity: false,      // ...staff ticked it after looking at a valid ID
  confirmNew: false,      // 'new': the server accepted the details; staff read the email back
  warning: '', notice: '',
  error: '',
  idChecked: false,       // staff confirm they have seen a valid ID in person
  usepId: false,          // ...and that it is a USeP ID -> the discount applies
};


/* The review screen's line for an account: how the person was confirmed to be its owner. */
function counterAccountLine(){
  const b = state.booker;
  return (b.verified ? '&check; Account confirmed by their password' : '&check; ID checked: the account has no password, confirmed from a valid ID')
       + ' &mdash; <strong>'+esc(b.name)+'</strong>';
}

/* ---------- counter: who is this booking for ---------- */
function setBookerMode(m){
  /* Switching mode clears everything chosen under the other one. A customer id
     left behind from a search would otherwise ride along invisibly and book
     under someone who walked away. */
  state.booker = Object.assign({}, booker0, { mode:m, idChecked:state.booker.idChecked, usepId:state.booker.usepId });
  render();
}
function setBookerField(f, v){ state.booker[f] = v; state.booker.error=''; state.booker.confirmNew=false; render(); }
function toggleBookerFlag(f, el){ state.booker[f] = !!el.checked; if(f==='idChecked' && !el.checked) state.booker.usepId=false; if(f==='noEmail' && el.checked) state.booker.email=''; render(); }

let searchTimer = null;
function searchCustomers(v){
  state.booker.query = v;
  state.booker.customerId = 0; state.booker.verified = false;
  clearTimeout(searchTimer);
  if(v.trim().length < 3){ state.booker.results = []; render(); return; }
  state.booker.searching = true; render();
  searchTimer = setTimeout(async function(){
    try{
      const res = await fetch(counterBooker.customerSearchUrl + '?q=' + encodeURIComponent(v.trim()), { credentials:'same-origin' });
      const out = await res.json().catch(function(){ return { ok:false, results:[] }; });
      state.booker.results = out.ok ? out.results : [];
    }catch(e){ state.booker.results = []; }
    state.booker.searching = false; render();
  }, 250);
}
function pickCustomer(id, name, phone, noPassword){
  state.booker.customerId = id; state.booker.name = name; state.booker.phone = phone;
  state.booker.results = []; state.booker.password=''; state.booker.verified = false; state.booker.error='';
  state.booker.noPassword = !!noPassword; state.booker.idIdentity = false; state.booker.notice = '';
  render();
}

/* A NEW ACCOUNT made here (DB-DECISIONS 28): check=1 asks the server first (an email any
   account has is refused, a mobile another uses is a warning); then the confirm step,
   where staff read the email back; then it is made and picked like a search result. */
async function createCounterAccount(check){
  const b = state.booker;
  b.error = ''; render();
  try{
    const body = new URLSearchParams({ csrf: CSRF, _token: CSRF, room: state.roomId, full_name: b.name.trim(),
      email: b.email.trim(), phone: cleanPhone(b.phone) || b.phone, check: check ? '1' : '0' });
    const res = await fetch(counterBooker.customerCreateUrl, { method:'POST', body:body, credentials:'same-origin' });
    const out = await res.json().catch(function(){ return { ok:false, message:'The server sent an unreadable reply.' }; });
    if(!out.ok){ b.error = out.message || 'The account was not made.'; b.confirmNew = false; render(); return; }
    b.warning = out.warning || '';
    if(check){ b.email = out.email; b.confirmNew = true; render(); return; }
    state.booker = Object.assign({}, booker0, { mode:'account', idChecked:b.idChecked, usepId:b.usepId,
      customerId:out.customerId, name:out.name, phone:out.phone, noPassword:true, notice:out.message });
  }catch(e){ b.error = 'Could not reach the server.'; }
  render();
}

/* A likely typo in the email's domain: one of these, at most two letters off. */
const COMMON_EMAIL_DOMAINS = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'usep.edu.ph'];
function emailSuggestion(v){
  const m = /^([^\s@]+)@([^\s@]+)$/.exec(String(v).trim().toLowerCase());
  if(!m || COMMON_EMAIL_DOMAINS.includes(m[2])) return '';
  const near = COMMON_EMAIL_DOMAINS.find(d => editDistance(m[2], d) <= 2);
  return near ? m[1] + '@' + near : '';
}
function editDistance(a, b){
  let prev = Array.from({ length: b.length + 1 }, (_, j) => j);
  for(let i = 1; i <= a.length; i++){
    const row = [i];
    for(let j = 1; j <= b.length; j++) row[j] = Math.min(prev[j] + 1, row[j-1] + 1, prev[j-1] + (a[i-1] === b[j-1] ? 0 : 1));
    prev = row;
  }
  return prev[b.length];
}
/* The customer types their own password on the counter-facing screen. It is
   checked and discarded — no session is started, so the staff member beside
   them stays signed in. Required: without it there is nothing but a name to
   say this is the right account, and a wrong match hands one customer
   another's booking. */
async function verifyBooker(){
  const pw = (document.getElementById('bkPassword')||{}).value || '';
  if(!pw){ state.booker.error='Ask the customer to type their password.'; render(); return; }
  state.booker.error=''; render();
  try{
    const body = new URLSearchParams({ csrf: CSRF, _token: CSRF, customer_id: state.booker.customerId, password: pw });
    const res = await fetch(counterBooker.customerVerifyUrl, { method:'POST', body:body, credentials:'same-origin' });
    const out = await res.json().catch(function(){ return { ok:false, message:'The server sent an unreadable reply.' }; });
    state.booker.verified = !!out.ok;
    state.booker.noPassword = !!out.noPassword;
    state.booker.error = out.ok || out.noPassword ? '' : (out.message || 'That password did not match.');
  }catch(e){ state.booker.verified=false; state.booker.error='Could not reach the server.'; }
  render();
}
function counterBookerReady(){
  const b = state.booker;
  if(!b.idChecked) return false;                       // staff must confirm they saw an ID
  if(b.mode==='account') return b.customerId>0 && (b.verified || (b.noPassword && b.idIdentity)) && !COUNTER_BLOCKED[b.customerId];   // blocked here: no booking
  if(b.mode==='new') return false;                     // made first, then it is an 'account'
  return b.name.trim()!=='' && cleanPhone(b.phone)!==null && (b.noEmail || walkinEmailOk(b.email));
}
/* REQUIRED (decided 2026-10-04): it is where a walk-in's confirmation and
   System Receipt are sent. The server re-checks it (booking-create.php) —
   this only saves a round trip. */
function walkinEmailOk(v){
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim());
}
/* 09XXXXXXXXX or +639XXXXXXXXX, spaces allowed — the same rule as ph_mobile_strict()
   server side (bookings.php), normalised to 09XXXXXXXXX. */
function cleanPhone(raw){
  const m = String(raw||'').replace(/ /g,'').match(/^(?:0|\+63)(9\d{9})$/);
  return m ? '0'+m[1] : null;
}
const PH_MOBILE_PATTERN = '[ ]*(0|[+]63)[ ]*9([ ]*[0-9]){9}[ ]*';   /* the same rule for the input's own check */
function phoneHint(b){
  return (b.phone.trim()!=='' && cleanPhone(b.phone)===null)
    ? `<div role="alert" style="font-size:12.5px;color:#b23a3a;margin-top:8px">${counterBooker.mobileMessage}</div>` : '';
}
function goFromWho(){
  if(!counterBookerReady()) return;
  /* At the counter the affiliation question has already been answered — by the
     ID the staff member just looked at. Feeding it into the same `affiliated`
     field the customer flow uses means the price panel, the review card and the
     submitted value all keep working untouched. */
  state.affiliated = !!state.booker.usepId;
  state.screen='review'; window.scrollTo(0,0); render();
}

function counterBar(){
  return `<div style="position:sticky;top:0;z-index:60;background:#1f1e1e;color:#fff;padding:9px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13px">
    <strong style="letter-spacing:.06em;font-size:11.5px;text-transform:uppercase;background:#a11626;padding:3px 8px;border-radius:999px">Counter booking</strong>
    <span style="opacity:.85">Booking on behalf of a walk-in &middot; staff: <strong>${esc(COUNTER_STAFF)}</strong></span>
    <a href="${counterBooker.dashboardUrl}" style="margin-left:auto;color:#ffd166;font-weight:600;text-decoration:none">Leave counter mode</a>
  </div>`;
}

/* ---------- screen: WHO IS THIS FOR (counter mode only) ----------
   The step the customer flow has no need of: online, the booker is whoever is
   signed in. Here a person is standing at the desk, and three things have to be
   settled before a booking can exist in their name — which account it belongs
   to (or that it belongs to none), that they are really that person, and what
   the staff member saw on their ID. All three end up in the booking's timeline,
   because each one is someone's judgement rather than a fact the system knows. */
function whoScreen(){
  const b = state.booker;
  const card = (inner, pad) => `<div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:${pad||'16px'};box-shadow:0 1px 2px rgba(0,0,0,.04),0 12px 32px rgba(0,0,0,.05);margin-top:14px">${inner}</div>`;
  const label = t => `<div style="font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#6e6a64;margin-bottom:6px">${t}</div>`;
  const inputStyle = 'width:100%;height:42px;border:1px solid rgba(0,0,0,.16);border-radius:10px;padding:0 12px;font-size:14px;font-family:inherit;background:#fff';

  const modeBtn = (m, title, sub) => {
    const on = b.mode === m;
    return `<button type="button" onclick="setBookerMode('${m}')" style="flex:1;min-width:200px;text-align:left;border:1.5px solid ${on?'#a11626':'rgba(0,0,0,.14)'};background:${on?'#fdf4f5':'#fff'};border-radius:12px;padding:12px 14px;cursor:pointer;font-family:inherit">
      <div style="font-size:13.5px;font-weight:680;color:#1c1b19">${title}</div>
      <div style="font-size:12.5px;color:#6e6a64;margin-top:2px">${sub}</div>
    </button>`;
  };

  /* ---- with an account: search, then prove it ---- */
  let accountBlock = '';
  if(b.mode === 'account'){
    const results = b.results.map(r => `
      <button type="button" onclick="pickCustomer(${Number(r.id)}, ${esc(JSON.stringify(String(r.name || '')))}, ${esc(JSON.stringify(String(r.phone || '')))}, ${r.noPassword ? 'true' : 'false'})"
              style="display:block;width:100%;text-align:left;border:none;border-bottom:1px solid #f0efec;background:#fff;padding:10px 12px;cursor:pointer;font-family:inherit">
        <div style="font-size:13.5px;font-weight:640;color:#1c1b19">${esc(r.name)}${r.usep ? ` <span style="font-size:11px;font-weight:650;color:#1c7a4f;background:#eaf6ef;border-radius:999px;padding:1px 7px;margin-left:4px">USeP account</span>` : ''}</div>
        <div style="font-size:12.5px;color:#6e6a64">${esc(r.email)} &middot; ${esc(r.phone)}</div>
        ${COUNTER_BLOCKED[r.id] ? `<div style="font-size:12px;font-weight:650;color:#b23a3a;margin-top:2px">Blocked at this office</div>` : ''}
      </button>`).join('');

    accountBlock = card(`
      ${label('Find the account')}
      <input id="bkSearch" type="text" value="${esc(b.query)}" oninput="searchCustomers(this.value)" placeholder="Full name, exact email, or mobile number" aria-label="Find the account" aria-describedby="bkSearchHint" style="${inputStyle}" autocomplete="off" spellcheck="false">
      <div id="bkSearchHint" style="font-size:12px;color:#77736c;margin-top:6px;line-height:1.5">A name (at least three letters), or the whole email or mobile number. Every office&rsquo;s accounts are searched. Email and phone are shown partly hidden &mdash; enough to confirm, not enough to copy down.</div>
      ${b.searching ? `<div style="font-size:12.5px;color:#6e6a64;margin-top:10px">Searching&hellip;</div>` : ''}
      ${results ? `<div style="border:1px solid rgba(0,0,0,.1);border-radius:10px;overflow:hidden;margin-top:10px">${results}</div>` : ''}
      ${(!b.searching && b.query.trim().length>=3 && !b.results.length && !b.customerId)
        ? `<div style="font-size:12.5px;color:#8a5a12;margin-top:10px">No account matches that. If they have never registered, switch to <strong>New account</strong> or <strong>No account</strong>.</div>` : ''}

      ${b.customerId ? `
        <div style="border-top:1px solid #f0efec;margin-top:14px;padding-top:14px">
          <div style="font-size:13.5px;font-weight:680;color:#1c1b19">${esc(b.name)}</div>
          <div style="font-size:12.5px;color:#6e6a64;margin-bottom:10px">${esc(b.phone)}</div>
          ${COUNTER_BLOCKED[b.customerId] ? `
            <div role="alert" style="background:#fcecec;border:1px solid #f0c9c9;border-radius:10px;padding:10px 12px;margin-bottom:10px;font-size:13px;line-height:1.5;color:#8a1f1f">
              <strong>Blocked at this office.</strong> This customer can&rsquo;t make new bookings here, online or at the counter, until an admin lifts the block.
            </div>` : ''}
          ${COUNTER_OVERDUE[b.customerId] ? `
            <div role="status" style="background:#fdf6e7;border:1px solid #efd9ab;border-radius:10px;padding:10px 12px;margin-bottom:10px;font-size:13px;line-height:1.5;color:#5c3d0c">
              <strong>Unpaid booking:</strong> ${esc(COUNTER_OVERDUE[b.customerId])}. This customer can&rsquo;t book online at this office until it is paid &mdash; you may still book for them here.
            </div>` : ''}
          ${b.notice ? `<div role="status" style="font-size:13px;color:#1c7a4f;margin-bottom:10px">${esc(b.notice)}</div>` : ''}
          ${b.verified ? `
            <div style="display:flex;align-items:center;gap:8px;background:#eaf6ef;border:1px solid #d4ebdd;border-radius:10px;padding:10px 12px">
              <span style="color:#1c7a4f;font-size:15px">&check;</span>
              <div style="font-size:13px;color:#1c7a4f">Identity confirmed &mdash; the customer entered their password.</div>
            </div>` : b.noPassword ? `
            <div style="font-size:12.5px;color:#5c3d0c;background:#fdf6e7;border:1px solid #efd9ab;border-radius:10px;padding:10px 12px;margin-bottom:10px;line-height:1.5">${esc(COUNTER_NO_PASSWORD)}</div>
            <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
              <input type="checkbox" id="bkIdIdentity" ${b.idIdentity?'checked':''} onchange="toggleBookerFlag('idIdentity', this)" style="width:15px;height:15px;margin-top:2px;flex:none;accent-color:#a11626">
              <span style="font-size:13.5px;line-height:1.5;color:#1c1b19"><strong>ID checked:</strong> I looked at a valid ID and it is this person, <strong>${esc(b.name)}</strong>.</span>
            </label>` : `
            ${label('Hand the screen to the customer')}
            <div style="font-size:12.5px;color:#6e6a64;margin-bottom:8px;line-height:1.5">They type their own password to confirm this is their account. It is checked and discarded &mdash; they are not signed in, and you stay signed in.</div>
            <input id="bkPassword" type="password" placeholder="Customer's password" style="${inputStyle}" autocomplete="off">
            ${b.error ? `<div style="font-size:12.5px;color:#b23a3a;margin-top:8px">${esc(b.error)}</div>` : ''}
            <button type="button" onclick="verifyBooker()" style="margin-top:10px;height:40px;padding:0 16px;border:1px solid rgba(0,0,0,.16);border-radius:10px;background:#fff;font-size:13px;font-weight:640;cursor:pointer;font-family:inherit">Confirm identity</button>`}
        </div>` : ''}
    `);
  } else if(b.mode === 'new'){
    /* ---- a new account: details, then the confirm step (the email read back) ---- */
    const suggestion = emailSuggestion(b.email);
    accountBlock = card(b.confirmNew ? `
      ${label('Read it back to the customer')}
      <div style="font-size:13px;color:#4a4440;line-height:1.5">Read this email address back to the customer, letter by letter. Their welcome email goes here.</div>
      <div id="bkConfirmEmail" style="font-size:24px;font-weight:700;letter-spacing:-.01em;color:#1c1b19;margin:12px 0;word-break:break-all">${esc(b.email)}</div>
      <div style="font-size:13px;color:#4a4440">${esc(b.name)} &middot; ${esc(cleanPhone(b.phone) || b.phone)}</div>
      ${b.warning ? `<div role="status" style="background:#fdf6e7;border:1px solid #efd9ab;border-radius:10px;padding:10px 12px;margin-top:10px;font-size:13px;line-height:1.5;color:#5c3d0c">${esc(b.warning)}</div>` : ''}
      ${b.error ? `<div role="alert" style="font-size:12.5px;color:#b23a3a;margin-top:8px">${esc(b.error)}</div>` : ''}
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
        <button type="button" onclick="createCounterAccount(false)" style="height:40px;padding:0 16px;border:none;border-radius:10px;background:#a11626;color:#fff;font-size:13px;font-weight:650;cursor:pointer;font-family:inherit">It is right &mdash; make the account</button>
        <button type="button" onclick="state.booker.confirmNew=false;render()" style="height:40px;padding:0 16px;border:1px solid rgba(0,0,0,.16);border-radius:10px;background:#fff;font-size:13px;font-weight:640;cursor:pointer;font-family:inherit">Change the details</button>
      </div>` : `
      ${label('New account')}
      <input id="bkNewName" type="text" value="${esc(b.name)}" oninput="setBookerField('name', this.value)" placeholder="Full name" aria-label="Full name" style="${inputStyle}" autocomplete="off">
      <div style="height:10px"></div>
      <input id="bkNewEmail" type="text" inputmode="email" spellcheck="false" autocapitalize="off" value="${esc(b.email)}" oninput="setBookerField('email', this.value)" placeholder="Email address" aria-label="Email address" style="${inputStyle}" autocomplete="off">
      ${suggestion ? `<button type="button" id="bkEmailFix" onclick="setBookerField('email', ${esc(JSON.stringify(suggestion))})" style="display:block;margin-top:6px;border:none;background:none;padding:0;color:#a11626;font-size:12.5px;cursor:pointer;font-family:inherit;text-align:left">Did you mean <strong>${esc(suggestion)}</strong>? Use it</button>` : ''}
      <div style="height:10px"></div>
      <input id="bkNewPhone" type="tel" inputmode="tel" pattern="${PH_MOBILE_PATTERN}" title="09XX XXX XXXX or +63 9XX XXX XXXX" value="${esc(b.phone)}" oninput="setBookerField('phone', this.value)" placeholder="Mobile number (09XX XXX XXXX)" aria-label="Mobile number" style="${inputStyle}" autocomplete="off">
      ${phoneHint(b)}
      ${b.error ? `<div role="alert" style="font-size:12.5px;color:#b23a3a;margin-top:8px">${esc(b.error)}</div>` : ''}
      <div style="font-size:12px;color:#77736c;margin-top:8px;line-height:1.5">Search first: an email that already has an account is refused. The account has no password until the customer chooses one from the welcome email.</div>
      <button type="button" onclick="createCounterAccount(true)" style="margin-top:10px;height:40px;padding:0 16px;border:1px solid rgba(0,0,0,.16);border-radius:10px;background:#fff;font-size:13px;font-weight:640;cursor:pointer;font-family:inherit">Check the details</button>
    `);
  } else {
    /* ---- no account: the walk-in shape the database already has ----
       A walk-in has no `users` row (DB-DECISIONS #4), so their email — when
       they give one — goes in customers.contact_email, the single exception
       (#22): it is where their confirmation and System Receipt are sent.
       Required (2026-10-04) and unverified: staff read it back to the guest. */
    accountBlock = card(`
      ${label('Guest details')}
      <input id="bkName" type="text" value="${esc(b.name)}" oninput="setBookerField('name', this.value)" placeholder="Full name" style="${inputStyle}" autocomplete="off">
      <div style="height:10px"></div>
      <input id="bkPhone" type="tel" inputmode="tel" pattern="${PH_MOBILE_PATTERN}" title="09XX XXX XXXX or +63 9XX XXX XXXX" value="${esc(b.phone)}" oninput="setBookerField('phone', this.value)" placeholder="Contact number (09XX XXX XXXX)" aria-label="Contact number" style="${inputStyle}" autocomplete="off">
      ${phoneHint(b)}
      <div style="height:10px"></div>
      ${b.noEmail ? '' : `
      <input id="bkEmail" type="text" inputmode="email" spellcheck="false" autocapitalize="off" aria-label="Guest email" value="${esc(b.email)}" oninput="setBookerField('email', this.value)" placeholder="Email — for their confirmation and receipt" style="${inputStyle}" autocomplete="off" aria-describedby="bkEmailHint">
      ${(b.email.trim()!=='' && !walkinEmailOk(b.email)) ? `<div style="font-size:12.5px;color:#b23a3a;margin-top:8px">That doesn't look like an email address. Check it with the guest.</div>` : ''}
      <div id="bkEmailHint" style="font-size:12px;color:#77736c;margin-top:8px;line-height:1.5">Required: their booking confirmation and receipt are emailed here. Read the address back to the guest. Nothing checks it, so a typo sends their booking to a stranger.</div>`}
      <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;margin-top:${b.noEmail ? '0' : '10px'}">
        <input type="checkbox" id="bkNoEmail" ${b.noEmail?'checked':''} onchange="toggleBookerFlag('noEmail', this)" style="width:15px;height:15px;margin-top:2px;flex:none;accent-color:#a11626">
        <span style="font-size:13px;line-height:1.5;color:#1c1b19">Guest has no email. <strong>Print the receipt</strong> at the counter instead.</span>
      </label>
      <div style="height:10px"></div>
      <input id="bkAddress" type="text" value="${esc(b.address)}" oninput="setBookerField('address', this.value)" placeholder="Address (optional)" style="${inputStyle}" autocomplete="off">
      <div style="font-size:12px;color:#a5a19a;margin-top:8px;line-height:1.5">No account means this booking will not appear in anyone's <strong>My Bookings</strong>, cannot be paid online, and can only be refunded by staff. Payment must be taken at the counter.</div>
    `);
  }

  /* ---- what the staff member saw ---- */
  const idBlock = card(`
    ${label('The ID you were shown')}
    <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
      <input type="checkbox" id="bkIdChecked" ${b.idChecked?'checked':''} onchange="toggleBookerFlag('idChecked', this)" style="width:15px;height:15px;margin-top:2px;flex:none;accent-color:#a11626">
      <span style="font-size:13.5px;line-height:1.5;color:#1c1b19">I have seen a <strong>valid ID</strong> for this person in person.</span>
    </label>
    ${b.idChecked ? `
      <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;margin-top:12px;padding-top:12px;border-top:1px solid #f0efec">
        <input type="checkbox" id="bkUsepId" ${b.usepId?'checked':''} onchange="toggleBookerFlag('usepId', this)" style="width:15px;height:15px;margin-top:2px;flex:none;accent-color:#a11626">
        <span style="font-size:13.5px;line-height:1.5;color:#1c1b19">It was a <strong>USeP ID</strong> &mdash; apply the ${DISCOUNT_PERCENT}% affiliate discount.</span>
      </label>` : ''}
    <div style="font-size:12px;color:#a5a19a;margin-top:12px;line-height:1.5">Recorded against your name, <strong>${esc(COUNTER_STAFF)}</strong>, on this booking's history. The discount is a decision, not a claim &mdash; online it needs an uploaded ID that staff verify; here it needs you to have looked at one.</div>
  `);

  const ready = counterBookerReady();
  return `
  <main style="max-width:680px;margin:0 auto;padding:26px 18px 80px">
    <button onclick="state.screen='detail';window.scrollTo(0,0);render()" style="background:none;border:none;color:#6e6a64;font-size:13px;font-weight:600;cursor:pointer;padding:0;margin-bottom:14px">&larr; Back to the room</button>
    <h1 style="font-size:22px;font-weight:700;letter-spacing:-.02em;margin:0 0 4px">Who is this booking for?</h1>
    <p style="font-size:14px;color:#6b675f;margin:0 0 4px;line-height:1.6">The room and dates are set. Now the person at the counter.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
      ${modeBtn('account','Has an account','They have registered before')}
      ${modeBtn('new','New account','Make one for them now')}
      ${modeBtn('walkin','No account','A pure walk-in')}
    </div>
    ${accountBlock}
    ${idBlock}
    <button onclick="goFromWho()" ${ready?'':'disabled'} style="width:100%;height:48px;margin-top:18px;border:none;border-radius:12px;background:${ready?'#a11626':'#e7e4de'};color:${ready?'#fff':'#a5a19a'};font-size:14.5px;font-weight:680;cursor:${ready?'pointer':'not-allowed'};font-family:inherit">Continue &mdash; review the booking</button>
    ${!ready ? `<div style="font-size:12.5px;color:#a5a19a;text-align:center;margin-top:8px">${b.idChecked ? (b.mode==='account' ? (b.noPassword ? 'Look at a valid ID and tick “ID checked”.' : 'Find the account and have the customer confirm their password.') : b.mode==='new' ? 'Make the account first.' : 'Enter the guest’s name and contact number.') : 'Confirm you have seen a valid ID.'}</div>` : ''}
  </main>`;
}
