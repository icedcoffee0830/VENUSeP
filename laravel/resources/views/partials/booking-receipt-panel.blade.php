function receiptPanelHtml(){
  const o=state.ocr;
  const fileInput=`<input type="file" id="gcFile" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="checkReceipt(this)">`;
  if(!o){
    const on=state.agreeExact;
    return fileInput+`
    <div onclick="${on?'pickReceipt()':''}" style="border:1.5px dashed rgba(0,0,0,.18);border-radius:11px;padding:26px 14px;text-align:center;background:#fff;${on?'cursor:pointer':'opacity:.55;cursor:not-allowed'}">
      <div style="font-size:13.5px;font-weight:600;color:#4a463f">${on?'Tap to upload a screenshot of your GCash receipt':'Tick the exact-amount box above to enable the upload'}</div>
      <div style="font-size:12.5px;color:#a5a19a;margin-top:3px">PNG or JPG · up to ${UPLOAD_MAX_MB} MB · keep the reference number and amount visible · checked instantly with OCR</div>
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
        <div id="gcBar" style="height:100%;width:${Math.round((o.pct||0)*100)}%;background:#a11626;border-radius:999px;transition:width .25s"></div>
      </div>
      <div style="font-size:12.5px;color:#a5a19a;margin-top:8px">Reading the receipt with OCR — runs in your browser, usually 5–15 seconds.</div>
    </div>`;
  }
  const r=o.rec;
  /* Verdict wording. Keys match the schema's gcash_receipts.verdict enum
     (accepted / manual_review / rejected) so the app and the database speak one
     vocabulary. "manual review" is NOT a problem the customer must fix — the
     slot is held and staff decide — so it says so, and the issue list below
     still tells them exactly what looked off. */
  const V={
    accepted:{ bg:'#f2faf5', bd:'#d4ebdd', fg:'#1c7a4f', t:'Receipt accepted — payment recorded', s:'All automatic checks passed. Staff give the final confirmation in GCash.' },
    manual_review:{ bg:'#fdf7ee', bd:'#f3e3c8', fg:'#8a5a12', t:'Receipt received — a coordinator will check it', s:'Something did not match automatically, so a person will look at it. Your slot is held while they do — you do not need to resubmit.' },
    rejected:{ bg:'#fdf2f2', bd:'#f2d8d8', fg:'#b23a3a', t:'Receipt rejected by the automatic check', s:'Fix the issues below, then upload a new receipt.' },
  }[r.status];
  const p=r.parsed;
  const kv=[
    ['Ref no.', p.refDisplay||p.ref||'—'],
    ['Amount read', p.effAmountC!=null?centavosFmt(p.effAmountC):'—'],
    ['Amount required', centavosFmt(gcExpectedCentavos())],   /* the number they can act on — shown even when a person is reviewing */
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
      <button onclick="removeReceipt()" style="flex:none;background:#fff;border:1px solid rgba(0,0,0,.14);border-radius:8px;padding:6px 11px;font-size:12px;font-weight:600;color:#4a463f;cursor:pointer">${r.status==='rejected'?'Try another':'Remove'}</button>
    </div>
    <div style="display:flex;gap:13px;padding:13px 14px">
      ${o.thumb?`<img src="${o.thumb}" alt="receipt" style="width:64px;height:84px;object-fit:cover;border-radius:8px;border:1px solid rgba(0,0,0,.1);flex:none">`:''}
      <div style="flex:1;display:grid;grid-template-columns:1fr 1fr;gap:8px 14px;align-content:start">
        ${kv.map(([k,v])=>`<div><div style="font-size:12px;color:#8a857d">${k}</div><div style="font-size:13px;font-weight:640;color:#1c1b19;overflow-wrap:anywhere">${esc(v)}</div></div>`).join('')}
      </div>
    </div>
    ${issues.length?`
    <div style="padding:2px 14px 12px">
      ${issues.map(i=>`<div style="display:flex;gap:8px;align-items:flex-start;padding:4px 0"><span style="width:7px;height:7px;border-radius:999px;background:${i.hard?'#b23a3a':'#c99a3c'};margin-top:5px;flex:none"></span><span style="font-size:12.5px;color:#4a463f;line-height:1.45">${esc(GC_LABELS[i.c]||i.c)}</span></div>`).join('')}
    </div>`:''}
    ${r.confidence!=null?`<div style="padding:0 14px 11px;font-size:12px;color:#a5a19a">OCR confidence ${r.confidence}% · ${esc(o.fileName)}</div>`:''}
  </div>`;
}
