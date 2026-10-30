/* One calibrated viewer for any box. CALIBRATION: the viewer assumes a full
   sphere (360 x 180, a 2:1 image). Our panoramas are iPhone sweeps — much
   wider than tall — so shown as a sphere they get squeezed into a narrow
   corridor. Measure the image first and tell the viewer how many degrees it
   really spans: an iPhone pano is shot in portrait, so its vertical field of
   view is ~70 degrees; the horizontal span follows from the aspect ratio.
   Panning stops at the image edges; zoom is capped so the VERTICAL view never
   exceeds the image (no black above/below) — that cap depends on the box's
   shape. A true 2:1 image (the sample) is still treated as a full sphere.
   onReady(viewer) runs once it is up; stillWanted() lets the caller cancel. */
function panoViewer(node, src, stillWanted, onReady){
  const probe=new Image();
  function build(aspect){
    const full=aspect===null || Math.abs(aspect-2)<0.15;
    const vaov=70, haov=full?360:Math.min(340, Math.round(vaov*aspect));
    const box=node.getBoundingClientRect(), ratio=Math.max(0.5, box.width/Math.max(1,box.height));
    const fitHfov=Math.floor(2*Math.atan(Math.tan(vaov/2*Math.PI/180)*ratio)*180/Math.PI)-2;
    const opts={ type:'equirectangular', panorama:src, autoLoad:true, showControls:true, showFullscreenCtrl:false, compass:full,
      mouseZoom:true, draggable:true, hfov:full?100:Math.min(fitHfov, Math.round(haov*0.55)), minHfov:45, maxHfov:full?120:Math.min(fitHfov, haov-10) };
    if(!full){ opts.haov=haov; opts.vaov=vaov; opts.minYaw=-haov/2; opts.maxYaw=haov/2; opts.minPitch=-vaov/2; opts.maxPitch=vaov/2; }
    const v=pannellum.viewer(node, opts); onReady(v); return v;
  }
  probe.onload=function(){ if(stillWanted()) build(probe.naturalWidth/Math.max(1,probe.naturalHeight)); };
  probe.onerror=function(){ if(stillWanted()) build(null); };
  probe.src=src;
}

/* "Expand" — our own button in place of the browser's fullscreen. Real
   fullscreen stretched the image across the whole screen and looked blurry;
   this opens a dimmed overlay with the panorama at a comfortable size
   (up to 1100px wide, 16:9), same calibration, Esc / X / click outside closes. */
function openPanoLightbox(src){
  if(document.getElementById('panoLightbox')) return;
  const wrap=document.createElement('div'); wrap.id='panoLightbox';
  wrap.innerHTML='<div class="pl-box"><div class="pl-view"></div><button type="button" class="pl-close" aria-label="Close">&times;</button></div>';
  document.body.appendChild(wrap); document.body.style.overflow='hidden';
  let v=null; const close=function(){ if(v){ try{ v.destroy(); }catch(e){} } wrap.remove(); document.body.style.overflow=''; document.removeEventListener('keydown', onKey); };
  function onKey(e){ if(e.key==='Escape') close(); }
  document.addEventListener('keydown', onKey);
  wrap.addEventListener('click', function(e){ if(e.target===wrap || e.target.closest('.pl-close')) close(); });
  requestAnimationFrame(function(){ wrap.classList.add('open'); panoViewer(wrap.querySelector('.pl-view'), src, function(){ return wrap.isConnected; }, function(viewer){ v=viewer; }); });
}

/* "360° tour" — opens the panorama in the lightbox above. */
function openRoomPano(){ const R=getRoom(); if(R) openPanoLightbox(R.panorama||SAMPLE_PANO); }

/* Photo strip: native scroll-snap does the swiping on touch screens. render()
   redraws #app on every keystroke, which would snap the strip back to photo 1
   while someone types — so the position is remembered and put back here. Mouse
   users get click-and-drag (touch and trackpads already scroll it natively). */
var GALPOS={ room:null, left:0 };
function mountGallery(){
  const t=document.querySelector('#app .bk-gal-track'); if(!t) return;
  if(GALPOS.room!==state.roomId) GALPOS={ room:state.roomId, left:0 };
  const slides=Array.from(t.children), dots=Array.from(document.querySelectorAll('#app .bk-gal-dots i')), idxEl=document.querySelector('#app .bk-gal-idx');
  const pos=function(i){ return slides[i].offsetLeft-slides[0].offsetLeft; };
  const maxLeft=function(){ return Math.max(0,t.scrollWidth-t.clientWidth); };
  const nearest=function(){
    if(t.scrollLeft>=maxLeft()-2) return slides.length-1;
    let best=0; for(let i=1;i<slides.length;i++) if(Math.abs(pos(i)-t.scrollLeft)<Math.abs(pos(best)-t.scrollLeft)) best=i;
    return best;
  };
  const paint=function(){
    GALPOS.left=t.scrollLeft;
    const i=nearest();
    dots.forEach(function(d,k){ d.classList.toggle('on',k===i); });
    if(idxEl) idxEl.textContent=i+1;
  };
  t.scrollLeft=GALPOS.left;
  paint();
  t.addEventListener('scroll',paint,{passive:true});

  let drag=null, moved=false;
  const onMove=function(e){ if(!drag) return; const dx=e.clientX-drag.x; if(Math.abs(dx)>5) moved=true; t.scrollLeft=drag.left-dx; };
  const onUp=function(e){
    window.removeEventListener('pointermove',onMove); window.removeEventListener('pointerup',onUp); window.removeEventListener('pointercancel',onUp);
    if(!drag) return;
    const dx=e.clientX-drag.x; let i=drag.from;
    if(Math.abs(dx)>40) i=Math.max(0,Math.min(slides.length-1,i+(dx<0?1:-1)));
    drag=null; t.classList.remove('drag');
    t.style.scrollBehavior='smooth'; t.scrollLeft=Math.min(pos(i),maxLeft());
    setTimeout(function(){ t.style.scrollBehavior=''; moved=false; },450);
  };
  t.addEventListener('pointerdown',function(e){
    if(e.pointerType!=='mouse' || e.button!==0 || slides.length<2) return;
    drag={ x:e.clientX, left:t.scrollLeft, from:nearest() }; moved=false;
    t.classList.add('drag');
    window.addEventListener('pointermove',onMove); window.addEventListener('pointerup',onUp); window.addEventListener('pointercancel',onUp);
  });
  /* a drag that ends over the 360° tile must not count as a tap on it */
  t.addEventListener('click',function(e){ if(moved){ e.preventDefault(); e.stopPropagation(); } },true);
}

