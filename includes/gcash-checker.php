<?php
/* =====================================================================
   SHARED GCASH RECEIPT CHECKER — the ONE engine, used by every page that
   takes a GCash payment. Port of the verified PHP engine at
   C:\xamp5\htdocs\gcash-checker (lib/parser.php + checker.php + util.php),
   running fully in the browser via Tesseract.js.

   WHY THIS IS AN INCLUDE AND NOT COPY-PASTED
   -------------------------------------------
   Every venue takes payment into a DIFFERENT GCash account (see
   includes/payment-settings.php). A copy-pasted twin means N engines: fix
   a matcher in one and the others stay broken, and the failure is silent —
   legitimate receipts auto-reject as "receiver mismatch" and it looks like
   an OCR bug. One engine; the account is asked for, never hardcoded.

   HOW TO USE (from a page that takes GCash):
       <?php include __DIR__ . '/../includes/gcash-checker.php'; ?>
   The include takes NO PHP parameters. The page MUST define three things
   in its own script — the only things the engine cannot know:

       gcAccount()           -> {name, number}  the account THIS booking's
                                money must land in. A FUNCTION, not a
                                constant: one page can serve rooms from
                                several venues (room-reservation.php serves
                                both Bahay Alumni and USeP Venues), so the
                                right account depends on the room and can
                                only be resolved at verdict time.
       gcExpectedCentavos()  -> int   the EXACT amount owed, in centavos
       gcBookingRef()        -> string the booking reference (duplicate store)

   plus `state.ocr` (receipt state) and `render()` (redraw after a verdict).
   Everything else below is self-contained.

   HARD GATES (any one => rejected):  duplicate file · not a GCash receipt ·
   duplicate reference no. · receiver != gcAccount() · amount != EXACT
   amount owed.
   SOFT FLAGS (=> needs staff review): unreadable fields, odd ref format,
   stale/future date, Amount != Total, low OCR confidence.
   Absence of data never rejects — only positive mismatch does.
   ===================================================================== */
?>
<script>
/* ============================================================
   GCASH RECEIPT CHECKER — port of the verified PHP engine at
   C:\xamp5\htdocs\gcash-checker (lib/parser.php + checker.php +
   util.php), running fully in the browser via Tesseract.js.

   HARD GATES (any one ⇒ rejected):  duplicate file · not a GCash
   receipt · duplicate reference no. · receiver ≠ business account ·
   amount ≠ EXACT booking fee.
   SOFT FLAGS (⇒ needs staff review): unreadable fields, odd ref
   format, stale/future date, Amount ≠ Total, low OCR confidence.
   Absence of data never rejects — only positive mismatch does.
   ============================================================ */

/* ---- [SIM] duplicate store — localStorage stands in for the receipts database
        (the "Reset receipt history (demo)" link clears it) ---- */
const GC_STORE_KEY='vsp_gcash_receipts';
function gcStore(){ try{ return JSON.parse(localStorage.getItem(GC_STORE_KEY)||'[]'); }catch(e){ return []; } }
function gcRemember(rec){
  const a=gcStore();
  a.push({ ref:rec.parsed.ref||'', sha:rec.sha||'', status:rec.status, at:new Date().toISOString(), booking:gcBookingRef() });
  try{ localStorage.setItem(GC_STORE_KEY, JSON.stringify(a)); }catch(e){}
}
function gcUsedRef(ref){ return !!ref && gcStore().some(r=>r.ref===ref); }
function gcUsedSha(sha){ return !!sha && gcStore().some(r=>r.sha===sha); }
function clearGcashHistory(){ localStorage.removeItem(GC_STORE_KEY); removeReceipt(); }

/* ---- digit-field OCR cleanup (per-field whitelist analog):
   letters Tesseract commonly confuses for digits, fixed ONLY inside
   captured numeric groups — never on free text. ---- */
function digitFix(s){ return String(s).replace(/[OoQ]/g,'0').replace(/[Il|!]/g,'1').replace(/[Ss]/g,'5').replace(/B/g,'8').replace(/[Zz]/g,'2'); }

/* ---- money + ref helpers (util.php ports; integer centavos, no floats) ---- */
function normRef(s){ return digitFix(String(s||'')).replace(/\D+/g,''); }
function centavosFromString(s){
  if(s==null) return null;
  let clean=digitFix(String(s)).replace(/[^0-9.,\-+]/g,'').replace(/,/g,'');
  if(!clean || !/\d/.test(clean)) return null;
  clean=clean.replace(/^[+\-]+/,'');
  const parts=clean.split('.');
  const whole=parts[0]!==''?parts[0]:'0';
  const frac=((parts[1]||'0')+'00').slice(0,2);
  if(!/^\d+$/.test(whole) || !/^\d+$/.test(frac)) return null;
  return parseInt(whole,10)*100+parseInt(frac,10);   // magnitude only (receipts show transfers as −)
}
function centavosFmt(c){ return '₱'+Math.floor(c/100).toLocaleString('en-PH')+'.'+String(c%100).padStart(2,'0'); }

/* ---- masked receiver matching (util.php ports) ----
   Each returns true (match) / false (definite mismatch) / null (no signal). */
function numberMatchesMasked(receiptNumber, expectedNumber){
  if(receiptNumber==null || String(receiptNumber).trim()==='') return null;
  const expected=String(expectedNumber).replace(/\D+/g,'');
  if(expected.length!==11) return null;               // business number misconfigured — never fail customers on it
  let r=String(receiptNumber).replace(/[•xX#]/g,'*').replace(/[^0-9*]/g,'');
  if(r.startsWith('63') && r.length===12) r='0'+r.slice(2);   // +63 9xx… → 09xx…
  if(r.length===10 && r[0]==='9') r='0'+r;
  if(r.length!==11) return null;
  let visible=0;
  for(let i=0;i<11;i++){
    if(r[i]==='*') continue;
    if(r[i]!==expected[i]) return false;
    visible++;
  }
  return visible>=4 ? true : null;
}
function maskedNameMatches(maskedName, expectedName){
  if(maskedName==null || String(maskedName).trim()==='') return null;
  const runs=String(maskedName).match(/[a-z]+/gi)||[];  // visible letter-runs, e.g. "MI....A J.. J." → [MI,A,J,J]
  const letters=String(expectedName).toLowerCase().replace(/[^a-z]/g,'');
  if(!letters || !runs.length) return null;
  let total=0, cursor=0;
  for(const run of runs){
    const pos=letters.indexOf(run.toLowerCase(), cursor);
    if(pos===-1) return false;
    cursor=pos+run.length; total+=run.length;
  }
  return total>=2 ? true : null;                       // one stray letter is not evidence
}
function shortNameMatches(shortName, expectedName){
  if(shortName==null || String(shortName).trim()==='') return null;
  const short=String(shortName).toLowerCase().trim().split(/\s+/);
  const exp=String(expectedName).toLowerCase().trim().split(/\s+/);
  if(!short.length || !exp.length) return null;
  let i=0;
  for(let tok of short){
    tok=tok.replace(/[^a-z]/g,''); if(!tok) continue;
    let found=false;
    for(; i<exp.length; i++){ if(exp[i].startsWith(tok)){ found=true; i++; break; } }
    if(!found) return false;
  }
  return true;
}

/* ---- parser.php port: raw OCR text → structured receipt fields ---- */
function parseReceiptText(text){
  const out={ likeness:0, markers:[], ref:null, refDisplay:null, amountC:null, totalC:null, effAmountC:null,
              receiverNumber:null, receiverNameMasked:null, receiverNameShort:null, datetime:null, datetimeMs:null };
  const lower=text.toLowerCase();
  const mark=(label,pts)=>{ out.likeness+=pts; out.markers.push(label); };

  /* 1. GCash-receipt likeness score (is this even a GCash receipt?) */
  if(lower.includes('sent via gcash')) mark('sent_via_gcash',3);
  else if(lower.includes('gcash'))     mark('mentions_gcash',1);
  if(lower.includes('total amount sent')) mark('total_amount_sent',2);
  if(/ref\.?\s*no/i.test(text))           mark('ref_no_label',2);
  else if(lower.includes('reference number')) mark('reference_number_label',1);
  if(text.includes('₱') || lower.includes('php')) mark('peso_sign',1);
  if(/\bamount\b/i.test(text)) mark('amount_label',1);

  /* 2. Reference number — labelled form first; digit-lookalikes tolerated */
  let m=text.match(/ref(?:erence)?\s*(?:no|number)?\.?\s*[:.]?\s*([0-9OoIl|][0-9OoIl| ]{8,25})/i);
  if(m) out.refDisplay=m[1].trim();
  else if((m=text.match(/\b(\d{4}\s?\d{3}\s?\d{6})\b/))) out.refDisplay=m[1].trim();  // GCash 4-3-6 grouping
  if(out.refDisplay!=null){
    const ref=normRef(out.refDisplay);
    if(ref.length>=10 && ref.length<=16) out.ref=ref;   // real refs are 10–16 digits
    else out.refDisplay=null;
  }
  if(out.ref && out.ref.length===13) mark('ref_13_digits',1);

  /* 3. Amounts — distinguish "Total Amount Sent" from a bare "Amount" */
  const amtRe=/(total\s+amount\s+sent|amount)\b[^0-9\-+\r\n]{0,14}([\-+]?\d[\dOoIl|,]*(?:\.[\dOoIl|]{1,2})?)/gi;
  let mm;
  while((mm=amtRe.exec(text))!==null){
    const isTotal=/total/i.test(mm[1]);
    const c=centavosFromString(mm[2]);
    if(c==null) continue;
    if(isTotal && out.totalC==null) out.totalC=c;
    else if(!isTotal && out.amountC==null) out.amountC=c;
  }
  out.effAmountC = out.totalC!=null ? out.totalC : out.amountC;

  /* 4. Date & time, e.g. "Jul 28, 2025 4:32 PM" */
  if((m=text.match(/\b([A-Z][a-z]{2,8}\.?\s+\d{1,2},\s*\d{4}\s+\d{1,2}:\d{2}\s*[AP]M)\b/i))){
    out.datetime=m[1].replace(/\s+/g,' ').trim();
    const t=Date.parse(out.datetime.replace(/\./g,''));
    if(!isNaN(t)) out.datetimeMs=t;
  }

  /* 5. Receiver number — "Transfer from X to Y" layout, else a PH mobile above the Amount row */
  if((m=text.match(/\bto\s+((?:\+?63\s?|0)?[0-9][0-9*•xX#\s]{6,})/i))){
    out.receiverNumber=m[1].trim();
  }else{
    const amountPos=lower.indexOf('amount');
    const head=amountPos!==-1?text.slice(0,amountPos):text;
    if((m=head.match(/(\+?63\s?9[0-9*•xX#\s]{7,13}|\b09[0-9*•xX#]{4,9})/))) out.receiverNumber=m[1].trim();
  }

  /* 6. Receiver names (masked "MI....A J.. J." + short display name) */
  const lines=text.split(/\r\n|\r|\n/).map(l=>l.trim()).filter(l=>l!=='');
  const stop=/transaction|details|history|receipt|amount|total|date|time|ref|sent|via|gcash|footprint|digital|help|transfer/i;
  for(const line of lines){
    if(/amount/i.test(line)) break;                     // names live above the money rows
    if(stop.test(line)) continue;
    if(!/^[A-Za-z.•* ]{2,40}$/.test(line)) continue;    // a name line, possibly masked — no digits
    if(out.receiverNameMasked==null && /\.\.|[•*]/.test(line)){ out.receiverNameMasked=line; continue; }
    if(out.receiverNameShort==null && line.split(/\s+/).length<=3 && line.length<=24) out.receiverNameShort=line;
  }
  return out;
}

/* ---- customer-friendly labels for every reason/flag code ---- */
const GC_LABELS={
  duplicate_file:'This exact image file was already submitted before.',
  duplicate_ref:'This reference number was already used by a previous submission.',
  not_gcash_receipt:'The image does not look like a GCash receipt (expected markers not found).',
  receiver_mismatch:'The money was sent to a DIFFERENT account, not the business GCash account.',
  amount_mismatch:'The amount on the receipt is not the exact amount required for this booking.',
  unreadable:'Could not read enough text from the image.',
  likeness_uncertain:'Layout only partially matches a GCash send-money receipt.',
  ref_unreadable:'Reference number could not be read.',
  ref_format:'Reference number is not the usual 13 digits.',
  amount_unreadable:'Amount could not be read from the receipt.',
  receiver_unreadable:'Receiver name/number could not be read from the receipt.',
  date_unreadable:'Date & time could not be read.',
  date_future:'Receipt date is in the future.',
  date_stale:'Receipt is more than 14 days old.',
  amounts_inconsistent:'"Amount" and "Total Amount Sent" differ on the same receipt (possible edit).',
  low_confidence:'OCR was not confident reading this image — staff will double-check it.',
  ocr_unavailable:'The OCR engine could not load (internet required) — staff will verify manually.',
};

/* ---- checker.php port: the verdict engine ---- */
function evaluateReceipt(input, expectedCentavos){
  const text=String(input.text||'');
  const flags=(input.preFlags||[]).slice();
  const reasons=[];
  const parsed=parseReceiptText(text);

  /* G1 — exact same file submitted before (blocks even if unreadable) */
  if(input.sha && gcUsedSha(input.sha)) reasons.push('duplicate_file');

  if(text.trim().length<20){
    flags.push('unreadable');
  }else{
    /* G2 — must plausibly BE a GCash receipt before anything else */
    if(parsed.likeness<2) reasons.push('not_gcash_receipt');
    else if(parsed.likeness<=3) flags.push('likeness_uncertain');

    /* G3 — duplicate reference number (strongest anti-reuse gate) */
    if(parsed.ref!=null){
      if(gcUsedRef(parsed.ref)) reasons.push('duplicate_ref');
      if(parsed.ref.length!==13) flags.push('ref_format');
    }else flags.push('ref_unreadable');

    /* G4 — receiver must be THIS booking's account (number beats name).
       Asked for at verdict time, because one page can serve several venues
       and each venue has its own account (includes/payment-settings.php). */
    const acct=gcAccount();
    const numberOk=numberMatchesMasked(parsed.receiverNumber, acct.number);
    const nameOk=maskedNameMatches(parsed.receiverNameMasked, acct.name);
    const shortOk=shortNameMatches(parsed.receiverNameShort, acct.name);
    if(numberOk===false) reasons.push('receiver_mismatch');
    else if(numberOk===true){ /* number confirmed — good regardless of noisy name OCR */ }
    else if(nameOk===true || shortOk===true){ /* name evidence confirms */ }
    else if(nameOk===false || shortOk===false) reasons.push('receiver_mismatch');
    else flags.push('receiver_unreadable');

    /* G5 — EXACT amount, no more no less (integer centavo equality) */
    if(parsed.effAmountC!=null){
      if(parsed.effAmountC!==expectedCentavos) reasons.push('amount_mismatch');
    }else flags.push('amount_unreadable');
    if(parsed.amountC!=null && parsed.totalC!=null && parsed.amountC!==parsed.totalC) flags.push('amounts_inconsistent');

    /* date sanity — soft only */
    if(parsed.datetimeMs==null) flags.push('date_unreadable');
    else{
      const now=Date.now();
      if(parsed.datetimeMs>now+86400000) flags.push('date_future');
      else if(parsed.datetimeMs<now-14*86400000) flags.push('date_stale');
    }

    /* OCR confidence gate (accuracy roadmap item c) */
    if(input.confidence!=null && input.confidence<40) flags.push('low_confidence');
  }

  const uf=[...new Set(flags)], ur=[...new Set(reasons)];
  const status=ur.length?'rejected_auto':(uf.length?'needs_review':'pending_staff');
  return { status, parsed, flags:uf, reasons:ur, sha:input.sha||'',
           confidence:input.confidence!=null?Math.round(input.confidence):null,
           textExcerpt:text.trim().slice(0,900) };
}

/* ---- image fingerprint (duplicate-file gate) ---- */
async function sha256Hex(buf){
  try{
    if(window.crypto && crypto.subtle){
      const h=await crypto.subtle.digest('SHA-256', buf);
      return [...new Uint8Array(h)].map(b=>b.toString(16).padStart(2,'0')).join('');
    }
  }catch(e){}
  const b=new Uint8Array(buf); let a1=2166136261, a2=5381;      // FNV-1a + djb2 fallback (non-secure contexts)
  for(let i=0;i<b.length;i++){ a1=Math.imul(a1^b[i],16777619)>>>0; a2=(Math.imul(a2,33)+b[i])>>>0; }
  return 'h-'+a1.toString(16)+'-'+a2.toString(16)+'-'+b.length.toString(16);
}

/* ---- OCR preprocessing (accuracy roadmap item a): upscale → grayscale →
   auto-invert dark-mode receipts → contrast stretch → optional Otsu binarize ---- */
function gcPreprocess(img, binarize){
  const w=img.naturalWidth||img.width, h=img.naturalHeight||img.height;
  let scale=1;
  if(w<1400) scale=Math.min(2.5, 1400/w);          // upscale small screenshots — biggest OCR win
  else if(w>2600) scale=2600/w;                    // shrink huge photos to keep OCR fast
  const cw=Math.round(w*scale), ch=Math.round(h*scale);
  const c=document.createElement('canvas'); c.width=cw; c.height=ch;
  const ctx=c.getContext('2d',{willReadFrequently:true});
  ctx.imageSmoothingEnabled=true; ctx.imageSmoothingQuality='high';
  ctx.drawImage(img,0,0,cw,ch);
  const id=ctx.getImageData(0,0,cw,ch), d=id.data, n=cw*ch;

  const g=new Uint8ClampedArray(n); let sum=0;
  for(let i=0;i<n;i++){ const p=i*4, v=(d[p]*299+d[p+1]*587+d[p+2]*114)/1000; g[i]=v; sum+=v; }
  if(sum/n<110){ for(let i=0;i<n;i++) g[i]=255-g[i]; }   // dark-mode receipt → black-on-white

  /* contrast stretch between the ~2nd and ~98th percentile */
  const hist=new Uint32Array(256);
  for(let i=0;i<n;i++) hist[g[i]]++;
  let lo=0, hi=255, acc=0; const cut=n*0.02;
  for(let v=0;v<256;v++){ acc+=hist[v]; if(acc>=cut){ lo=v; break; } }
  acc=0;
  for(let v=255;v>=0;v--){ acc+=hist[v]; if(acc>=cut){ hi=v; break; } }
  const range=Math.max(1,hi-lo);
  for(let i=0;i<n;i++) g[i]=(g[i]-lo)*255/range;

  if(binarize){                                     // Otsu threshold (used by the 2nd OCR pass)
    const h2=new Uint32Array(256);
    for(let i=0;i<n;i++) h2[g[i]]++;
    let sumAll=0; for(let v=0;v<256;v++) sumAll+=v*h2[v];
    let wB=0, sumB=0, best=0, thr=127;
    for(let v=0;v<256;v++){
      wB+=h2[v]; if(!wB) continue;
      const wF=n-wB; if(!wF) break;
      sumB+=v*h2[v];
      const mB=sumB/wB, mF=(sumAll-sumB)/wF, between=wB*wF*(mB-mF)*(mB-mF);
      if(between>best){ best=between; thr=v; }
    }
    for(let i=0;i<n;i++) g[i]=g[i]>thr?255:0;
  }

  for(let i=0;i<n;i++){ const p=i*4; d[p]=d[p+1]=d[p+2]=g[i]; d[p+3]=255; }
  ctx.putImageData(id,0,0);
  return c;
}

function gcLoadImage(file){
  return new Promise((res,rej)=>{
    const url=URL.createObjectURL(file);
    const img=new Image();
    img.onload=()=>res({img,url});
    img.onerror=()=>{ URL.revokeObjectURL(url); rej(new Error('not a readable image')); };
    img.src=url;
  });
}

/* progress ticks update the bar directly — no full re-render per tick */
function gcProgress(pct,label){
  if(state.ocr){ state.ocr.pct=pct; state.ocr.label=label; }
  const bar=document.getElementById('gcBar'), lab=document.getElementById('gcBarLabel');
  if(bar) bar.style.width=Math.round(pct*100)+'%';
  if(lab) lab.textContent=label+' · '+Math.round(pct*100)+'%';
}

/* ---- the pipeline: file → fingerprint → preprocess → OCR (2 passes) → parse → verdict ---- */
function pickReceipt(){ const el=document.getElementById('gcFile'); if(el) el.click(); }
async function checkReceipt(input){
  const file=input.files && input.files[0];
  input.value='';                                   // allow re-choosing the same file after Remove
  if(!file) return;
  const expectedC=gcExpectedCentavos();
  if(state.ocr && state.ocr.thumb){ try{ URL.revokeObjectURL(state.ocr.thumb); }catch(e){} }
  state.ocr={ phase:'reading', pct:0, label:'Preparing image', fileName:file.name, thumb:null, rec:null };
  render();
  try{
    const buf=await file.arrayBuffer();
    const sha=await sha256Hex(buf);
    const {img,url}=await gcLoadImage(file);
    state.ocr.thumb=url;

    if(typeof Tesseract==='undefined'){             // offline → same manual-review path as the PHP fallback
      state.ocr.rec=evaluateReceipt({text:'', sha, preFlags:['ocr_unavailable']}, expectedC);
      state.ocr.phase='done'; render(); return;
    }

    /* pass 1 — enhanced grayscale */
    gcProgress(0.03,'Enhancing image');
    const resA=await Tesseract.recognize(gcPreprocess(img,false),'eng',
      { logger:m=>{ if(m.status==='recognizing text') gcProgress(0.05+m.progress*0.55,'Reading receipt (pass 1)'); } });
    let best={ text:resA.data.text, conf:resA.data.confidence, parsed:parseReceiptText(resA.data.text) };

    /* pass 2 — binarized, only when pass 1 missed the essentials (multi-pass OCR) */
    if(best.parsed.ref==null || best.parsed.effAmountC==null || best.parsed.likeness<2){
      gcProgress(0.62,'Enhancing image (pass 2)');
      const resB=await Tesseract.recognize(gcPreprocess(img,true),'eng',
        { logger:m=>{ if(m.status==='recognizing text') gcProgress(0.64+m.progress*0.34,'Reading receipt (pass 2)'); } });
      const cand={ text:resB.data.text, conf:resB.data.confidence, parsed:parseReceiptText(resB.data.text) };
      const score=p=>(p.parsed.ref?2:0)+(p.parsed.effAmountC!=null?2:0)+p.parsed.likeness+(p.conf||0)/100;
      if(score(cand)>score(best)) best=cand;
    }

    gcProgress(1,'Running checks');
    state.ocr.rec=evaluateReceipt({text:best.text, sha, confidence:best.conf}, expectedC);
    state.ocr.phase='done';
  }catch(err){
    state.ocr.rec=evaluateReceipt({text:'', sha:'', preFlags:['unreadable']}, expectedC);
    state.ocr.phase='done';
  }
  render();
}

function removeReceipt(){
  if(state.ocr && state.ocr.thumb){ try{ URL.revokeObjectURL(state.ocr.thumb); }catch(e){} }
  state.ocr=null; render();
}

function receiptOk(){ return !!(state.ocr && state.ocr.phase==='done' && state.ocr.rec && state.ocr.rec.status!=='rejected_auto'); }
</script>
