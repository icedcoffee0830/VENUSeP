/* The photo viewer (Aron 2026-11-05): dark, full screen, the whole photo as big as it fits.
   ✕, Esc or a tap on the dark area closes it; arrows, swipe and the arrow keys change the photo.
   No zoom. The 360° tour stays separate. A native <dialog>: it keeps focus inside and hands it back. */
const PV_CSS=`
  .pv{position:fixed;inset:0;width:100%;height:100%;max-width:none;max-height:none;margin:0;padding:0;border:0;background:rgba(12,11,10,.94);color:#fff;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;touch-action:pan-y}
  .pv::backdrop{background:transparent}
  .pv-stage{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:56px 16px}
  .pv-stage img{max-width:100%;max-height:100%;object-fit:contain;display:block;border-radius:4px;user-select:none;-webkit-user-drag:none}
  .pv-count{position:absolute;top:0;left:0;height:56px;display:flex;align-items:center;padding:0 18px;font-size:14px;font-weight:600;font-variant-numeric:tabular-nums}
  .pv-btn{position:absolute;width:44px;height:44px;border:0;border-radius:999px;background:rgba(255,255,255,.14);color:#fff;font-size:22px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center}
  .pv-btn:hover{background:rgba(255,255,255,.26)}
  .pv-btn:focus-visible{outline:2px solid #fff;outline-offset:2px}
  .pv-close{top:6px;right:8px}
  .pv-prev,.pv-next{top:50%;margin-top:-22px}
  .pv-prev{left:6px}.pv-next{right:6px}
  @media (min-width:768px){.pv-stage{padding:56px 72px}.pv-prev{left:12px}.pv-next{right:12px}}`;

function openGallery(start){
  const R=getRoom(), photos=((R&&R.photoUrls)||[]).filter(Boolean);
  if(!photos.length) return;
  const pv=document.createElement('dialog');
  pv.className='pv';
  pv.id='photoViewer';
  pv.setAttribute('aria-label',R.name+' photos');
  pv.innerHTML='<style>'+PV_CSS+'</style><div class="pv-stage"><img alt=""></div>'
    +'<button type="button" class="pv-btn pv-close" aria-label="Close photos">✕</button>'
    +(photos.length>1?'<div class="pv-count" aria-live="polite"></div>'
      +'<button type="button" class="pv-btn pv-prev" aria-label="Previous photo">‹</button>'
      +'<button type="button" class="pv-btn pv-next" aria-label="Next photo">›</button>':'');
  const img=pv.querySelector('img'), count=pv.querySelector('.pv-count');
  let at=0, swipeFrom=null;
  const show=function(i){
    at=(i+photos.length)%photos.length;
    img.src=photos[at];
    img.alt=R.name+' photo '+(at+1)+' of '+photos.length;
    if(count) count.textContent=(at+1)+' / '+photos.length;
  };
  pv.addEventListener('click',function(e){
    if(e.target.matches('.pv-stage, .pv-close')) pv.close();
    if(e.target.matches('.pv-prev')) show(at-1);
    if(e.target.matches('.pv-next')) show(at+1);
  });
  pv.addEventListener('keydown',function(e){
    if(e.key==='ArrowLeft') show(at-1);
    if(e.key==='ArrowRight') show(at+1);
  });
  pv.addEventListener('touchstart',function(e){ swipeFrom=e.touches[0].clientX; },{passive:true});
  pv.addEventListener('touchend',function(e){
    const dx=e.changedTouches[0].clientX-swipeFrom;
    if(Math.abs(dx)>40) show(at+(dx<0?1:-1));
  });
  pv.addEventListener('close',function(){ pv.remove(); document.body.style.overflow=''; });
  document.body.appendChild(pv);
  document.body.style.overflow='hidden';
  show(start||0);
  pv.showModal();
}
