const GAL_GRADS=['#eef1f5,#dfe4ea','#f3eee9,#e6ddd3','#e9eef3,#d5e0ea','#eef3ee,#d9e6da','#f3eef1,#e6d5de','#eaf0f3,#d5e2ea','#f2efe8,#e4dccf','#eef3f0,#dbe7df'];
function galGrad(i){ return 'background:linear-gradient(135deg,'+GAL_GRADS[i%GAL_GRADS.length]+')'; }
var GAL_PHOTOS=[];
function openGallery(){
  const R=getRoom(); if(!R || document.getElementById('galOverlay')) return;
  GAL_PHOTOS=R.photoUrls||[];
  const n=GAL_PHOTOS.length||1;          /* no photos yet -> ONE placeholder tile, never a made-up count */
  let thumbs='';
  for(let i=0;i<n;i++){
    const url=GAL_PHOTOS[i];
    const bg=url?('background-size:cover;background-position:center;background-image:url(\''+url+'\')'):galGrad(i);
    thumbs+='<button class="galThumb" data-i="'+i+'" onclick="galSelect('+i+')" style="border:2px solid transparent;border-radius:8px;cursor:pointer;padding:0;aspect-ratio:4/3;'+bg+';display:flex;align-items:center;justify-content:center;color:#8a857d;font:500 10px ui-monospace,monospace">'+(url?'':'Photo '+(i+1))+'</button>';
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
  const url=GAL_PHOTOS[i];
  if(big){
    if(url){ big.setAttribute('style','background-size:cover;background-position:center;background-image:url(\''+url+'\');border-radius:12px;aspect-ratio:16/9'); big.textContent=''; }
    else { big.setAttribute('style', galGrad(i)+';border-radius:12px;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;color:#8a857d;font:500 14px ui-monospace,monospace'); big.textContent='Photo '+(i+1); }
  }
  const g=document.getElementById('galGrid');
  if(g) g.querySelectorAll('.galThumb').forEach(function(b){ b.style.borderColor=(+b.getAttribute('data-i')===i)?'#a11626':'transparent'; });
}
function closeGallery(){ const ov=document.getElementById('galOverlay'); if(ov) ov.remove(); }
