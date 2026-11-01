  .bk-more-body{padding:18px 19px 20px}
  .bk-more-body h3{margin:0;font-size:17px;font-weight:700;letter-spacing:-.01em;color:#fff}
  .bk-more-meta{margin:7px 0 0;font-size:12.5px;line-height:1.55;color:#d5b8b5}
  .bk-more-price{margin:6px 0 0;font-size:13px;line-height:1.5;color:#d5b8b5}
  /* usep_price_html() paints for a white page; recolour it for the dark card (UI only) */
  .bk-more-meta s,.bk-more-price s{color:#ac8f8d!important}
  .bk-more-meta strong,.bk-more-price strong{color:#fff!important}
  .bk-more-meta span,.bk-more-price span{color:#f2d0cb!important}
  .bk-more-meta span,.bk-more-price span{font-size:12px!important}   /* type floor: the 11px note from pricing.php */
  #app span[style*="ui-monospace"]{font-size:12px!important}          /* type floor: the "room photo" placeholder labels */
  /* ---- room page layout: photo strip on top, then details + booking panel side by side ---- */
  .bk-cols{display:grid;grid-template-columns:minmax(0,1fr) 379px;gap:34px;align-items:start}
  .bk-aside{position:sticky;top:88px}
  /* ---- photo strip: swipe / drag only (no arrows, no thumbnails, no click-to-open) ---- */
  .bk-gal{position:relative;margin:0 0 22px}
  .bk-gal-track{display:flex;gap:10px;height:400px;overflow-x:auto;overflow-y:hidden;scroll-snap-type:x mandatory;overscroll-behavior-x:contain;scrollbar-width:none;cursor:grab;-webkit-tap-highlight-color:transparent}
  .bk-gal-track::-webkit-scrollbar{display:none}
  .bk-gal-track:focus-visible{outline:2px solid rgba(161,22,38,.5);outline-offset:3px;border-radius:20px}
  .bk-gal-track.drag{scroll-snap-type:none;cursor:grabbing;user-select:none}
  .bk-gal-slide{position:relative;flex:0 0 auto;height:100%;aspect-ratio:3/2;border-radius:20px;overflow:hidden;background-color:#e9e7e2;background-size:cover;background-position:center;scroll-snap-align:start}
  .bk-gal-slide::after{content:"";position:absolute;inset:auto 0 0 0;height:38%;background:linear-gradient(180deg,transparent,rgba(14,5,6,.5));pointer-events:none}
  .bk-gal-slide.solo{flex-basis:100%;aspect-ratio:auto}
  .bk-gal-slide.ph{display:flex;align-items:center;justify-content:center;background-image:repeating-linear-gradient(45deg,rgba(0,0,0,.035) 0 11px,transparent 11px 22px)}
  .bk-gal-slide.ph span{font:500 12px/1 ui-monospace,Menlo,monospace;color:#9a958c}
  .bk-gal-slide.ph::after{display:none}
  .bk-gal-pill{position:absolute;top:14px;z-index:3;display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 13px;border-radius:999px;border:1px solid rgba(255,255,255,.28);background:rgba(20,8,10,.55);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);color:#fff;font:600 12.5px/1 'Inter',system-ui,sans-serif;letter-spacing:.01em}
  /* first slide: the 360° tour — the panorama as a still, tap to look around (a live viewer here would swallow the swipe) */
  .bk-gal-360s{appearance:none;border:0;padding:0;cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;color:#fff;font-family:inherit;text-align:center;user-select:none;-webkit-user-select:none}
  .bk-gal-360s::before{content:"";position:absolute;inset:0;background:rgba(20,8,10,.34);transition:background 200ms ease}
  .bk-gal-360s:hover::before{background:rgba(138,18,34,.42)}
  .bk-gal-360s>*{position:relative;z-index:1}
  .bk-360-ico{display:grid;place-items:center;width:58px;height:58px;margin-bottom:6px;border-radius:50%;border:1.5px solid rgba(255,255,255,.7);background:rgba(20,8,10,.35);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
  .bk-gal-360s strong{font-size:20px;font-weight:750;letter-spacing:-.01em}
  .bk-gal-360s small{font-size:13px;font-weight:500;color:#f2d0cb}
  .bk-gal-count{right:14px;pointer-events:none}
  .bk-gal-dots{position:absolute;left:0;right:0;bottom:14px;z-index:2;display:flex;justify-content:center;gap:6px;pointer-events:none}
  .bk-gal-dots i{display:block;width:7px;height:7px;border-radius:999px;background:rgba(255,255,255,.6);box-shadow:0 1px 3px rgba(0,0,0,.35);transition:width 220ms ease,background 220ms ease}
  .bk-gal-dots i.on{width:22px;background:#fff}
  /* ---- booking panel: the landing page's crimson-to-black card ---- */
  .bk-panel{position:relative;padding:22px 20px 22px;border-radius:24px;color:#fff;background:linear-gradient(162deg,#7d1120 0%,#3c0c14 48%,#17080a 100%);box-shadow:0 26px 64px rgba(138,18,34,.3)}
  .bk-panel::before{content:"";position:absolute;inset:0;border-radius:inherit;pointer-events:none;background:radial-gradient(420px 300px at 92% 0%,rgba(232,62,74,.3),transparent 62%),radial-gradient(360px 300px at 4% 100%,rgba(10,4,5,.6),transparent 66%)}
  .bk-panel>*{position:relative}
  .bk-panel a{color:#fff;text-decoration:underline;text-underline-offset:2px}
  .bk-panel a:hover{color:#f2d0cb}
