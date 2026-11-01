  .bk-panel input:focus,.bk-panel button:focus-visible{outline:2px solid rgba(255,255,255,.8);outline-offset:1px}
  .bk-panel ::placeholder{color:#a5a19a}
  .bk-eyebrow{display:block;margin-bottom:8px;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#f2d0cb}
  .bk-go{width:100%;height:50px;border:none;border-radius:14px;font-size:15px;font-weight:700;transition:transform 160ms ease,background 160ms ease}
  .bk-go.on{background:#fff;color:#8a1222;cursor:pointer}
  .bk-go.on:hover{background:#fbeceb}
  .bk-go.on:active{transform:scale(.98)}
  .bk-go.off{background:rgba(255,255,255,.16);color:rgba(255,255,255,.62);cursor:not-allowed}
  /* tablet + phone: everything stacks — photos, title, details, then the booking panel */
  @media (max-width:900px){
    .bk-cols{grid-template-columns:1fr;gap:26px}
    .bk-aside{position:static}
  }
  @media (max-width:720px){
    #app main{padding-left:16px!important;padding-right:16px!important}
    .bk-gal{margin-bottom:18px}
    .bk-gal-track{height:min(66vw,320px);gap:8px}
    .bk-gal-slide{flex-basis:88%;aspect-ratio:auto;border-radius:16px}
    .bk-gal-slide.solo{flex-basis:100%}
    .bk-gal-pill{top:10px;height:32px;padding:0 11px}
    .bk-360-ico{width:48px;height:48px}
    .bk-gal-360s strong{font-size:17px}
    .bk-gal-count{right:10px}
    .bk-gal-dots{bottom:11px}
    .bk-panel{padding:20px 16px;border-radius:20px}
    /* 16px stops iOS Safari zooming the page when a field is focused; taller fields are easier to hit */
    .bk-panel input{font-size:16px!important;height:46px!important}
