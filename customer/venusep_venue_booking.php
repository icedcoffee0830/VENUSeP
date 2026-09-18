<?php require_once __DIR__ . '/../includes/auth.php'; venusep_session_start(); /* public page — the session only tells the header whether someone is logged in */ ?>
<?php
/* Both room lists come from ONE shared source each, so this landing page can
   never drift from the booking pages or admin Venue Management. */
include __DIR__ . '/../includes/venue-rooms.php';
include __DIR__ . '/../includes/hostel-rooms.php';
require_once __DIR__ . '/../includes/room-photos.php';
require_once __DIR__ . '/../includes/venue-photos.php';
/* Who is looking, and the discount rule — so a USeP account sees its price with
   the full price crossed out, and everyone else sees a nudge. This is a PREVIEW:
   the ID decides the discount at approval (includes/pricing.php). */
require_once __DIR__ . '/../includes/customer-bookings.php';   /* $customerContact */
ob_start(); include __DIR__ . '/../includes/pricing.php'; $pricingJs = ob_get_clean();   /* PHP helpers now; JS block echoed later */
$isUsep = usep_is_account($customerContact['email']);

/* =====================================================================
   PRESENTATION ONLY from here to the closing tag. Nothing below touches
   the data, the rules, or the booking pages — it only decides how the
   shared data above is DRAWN. All links go to the same pages as before.
   ===================================================================== */

/* The room's cover photo: the first photo uploaded via admin Venue
   Management (Edit Room -> Edit photos), or a manually-dropped
   assets/img/venues/<id>.<ext> (see the README there). Null draws the
   placeholder illustration instead. $venueName set = this is a VENUE
   card, not a room card — checked against venues.cover_photo (admin
   Venue Management -> Edit Details -> Change photo) first. */
function lp_photo($id, $venueName = null) {
  if ($venueName !== null) {
    $venuePhoto = vp_cover_url_by_name($venueName);
    if ($venuePhoto) return $venuePhoto;
  }
  return rp_cover_url($id);
}
/* One drawn placeholder per KIND of space, reused: hall | gym | bunk | private */
function lp_art($kind) {
  return '<svg class="lp-ph-art" viewBox="0 0 400 250" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><use href="#lp-art-' . $kind . '"/></svg>';
}
/* The picture area of a card: the real photo if one exists, else the drawing
   plus a "Photo slot" tag; a scrim over both; any badge on top. Every card on
   the page draws its picture through this one function. */
function lp_photo_block($id, $kind, $badge = '', $venueName = null) {
  $photo = lp_photo($id, $venueName);
  $inner = $photo
    ? '<img class="lp-ph-img" loading="lazy" decoding="async" src="' . htmlspecialchars($photo) . '" alt="">'
    : lp_art($kind);
  $tag   = '';   /* the "Photo slot" tag is off: the drawings stand as illustrations until real photos land (see assets/img/venues/README.md) */
  return '<div class="lp-ph"><div class="lp-ph-in">' . $inner . '<div class="lp-ph-scrim"></div></div>' . $tag . $badge . '</div>';
}
/* Which drawing suits a venue room — a UI guess from the capacity, nothing more. */
function lp_room_kind(array $room) {
  return $room['capacity'] >= 500 ? 'gym' : 'hall';
}

$lpToday    = date('Y-m-d');
$lpName     = $customerContact['name'];
$lpFirst    = explode(' ', trim($lpName))[0];
$lpParts    = preg_split('/\s+/', trim($lpName));
$lpInitials = strtoupper(substr($lpParts[0], 0, 1) . substr(end($lpParts), 0, 1));

/* The three places. Names must match the `venue` field in
   includes/venue-rooms.php and $HOSTEL_VENUE in includes/hostel-rooms.php —
   the counts and prices below are computed from those lists. The blurbs are
   copy for this page only; the data has no venue description field. */
$lpVenueBlurb = [
  'Bahay Alumni' => 'The alumni house — ballroom, function room, boardroom and garden pavilion.',
  'USeP Venues'  => 'University halls — the gymnasium, the CIC audio-visual room and conference spaces.',
];
$lpVenueArt   = ['Bahay Alumni' => 'hall', 'USeP Venues' => 'gym'];
$lpVenueSlug  = ['Bahay Alumni' => 'venue-bahay-alumni', 'USeP Venues' => 'venue-usep-venues'];

$lpVenueNames = array_values(array_unique(array_column($venueRooms, 'venue')));
$lpVenueCount = count($lpVenueNames) + 1;                               /* + the hostel */
$lpSpaceCount = count($venueRooms) + count($hostelRooms);
$lpBedCount   = array_sum(array_column($hostelRooms, 'beds'));
$lpMinHostel  = min($HOSTEL_RATES);

/* Footer facts. Confirm these with the venue office before go-live. */
$lpOfficeWhere = 'USeP Tagum–Mabini Campus, Apokon, Tagum City';
$lpOfficeHours = 'Monday to Friday, 8:00 AM – 5:00 PM';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Venues in USEP | Booking Landing Page</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    /* ------------------------------------------------------------------
       TOKENS — crimson + black, USeP's own family. Change a colour here
       and the whole page follows.
       ------------------------------------------------------------------ */
    :root {
      --ink:        #120809;   /* page black, red-cast                */
      --ink-2:      #100709;
      --crimson:    #a11626;   /* links, accents                       */
      --crimson-hi: #c9202e;   /* light bleeding into dark surfaces    */
      --crimson-lo: #7d0f1e;
      --gold:       #d9930d;   /* the discount, the pin, maintenance   */
      --paper:      #ffffff;
      --cream:      #fcf6f4;   /* warm, pink-tinted                    */
      --line:       #efe0db;
      --line-2:     #f2e3df;
      --text:       #1d1214;
      --muted:      #705e5e;
      --muted-2:    #96807e;
      --green:      #1c7a4f;   /* available / free beds — semantic     */
      --amber:      #8a5a12;
      --ease:       cubic-bezier(.22,.61,.36,1);
      --ease-soft:  cubic-bezier(.16,1,.3,1);     /* long, gentle settle */
      --font-display: Archivo, Inter, system-ui, sans-serif;
      --font-body:    Inter, system-ui, -apple-system, "Segoe UI", sans-serif;
    }
    * { box-sizing: border-box; }
    /* (no scroll-behavior:smooth here — it breaks ScrollTrigger measurements on zoom/resize; anchor links smooth-scroll via JS instead) */
    body { margin: 0; background: var(--paper); color: var(--text); font-family: var(--font-body);
           -webkit-font-smoothing: antialiased; overflow-x: hidden; }
    a { color: var(--crimson); text-decoration: none; }
    a:hover { color: var(--text); }
    h1, h2, h3, h4 { font-family: var(--font-display); margin: 0; }
    p { margin: 0; }
    .lp-wrap { max-width: 1240px; margin: 0 auto; padding: 0 28px; }
    .lp-eyebrow { font-size: 13.5px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
    .lp-h2 { font-size: 42px; font-weight: 800; letter-spacing: -.026em; line-height: 1.06; margin-top: 15px; }
    .lp-lead { font-size: 15.5px; line-height: 1.74; color: var(--muted); margin-top: 15px; }

    /* film grain — drawn, not an image file. Keeps the gradients from looking flat. */
    .lp-grain { position: relative; }
    .lp-grain::after { content: ""; position: absolute; inset: 0; pointer-events: none; z-index: 1;
      opacity: .05; mix-blend-mode: overlay; background-size: 180px 180px;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='180'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='180' height='180' filter='url(%23n)' opacity='0.6'/%3E%3C/svg%3E"); }
    .lp-grain > * { position: relative; z-index: 2; }
    .lp-glow { position: absolute; inset: 0; z-index: 0; pointer-events: none; }

    /* ------------------------------------------------------------------
       MOTION. Anything with data-reveal (or the children of data-stagger)
       is hidden by GSAP (loaded at the bottom) and animated in when it
       scrolls into view, back out when it leaves — so the reveals replay
       up and down. No GSAP, no JS, or reduced-motion = the page is simply
       visible and nothing moves. All of it lives in the script; no CSS here.
       ------------------------------------------------------------------ */
    @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
    .lp-lift { transition: transform 380ms var(--ease-soft), box-shadow 380ms var(--ease-soft); }
    .lp-lift[style*="transform"] { transition-property: box-shadow; }   /* GSAP is moving it: no CSS tween fighting it */
    .lp-lift:hover { transform: translateY(-4px); box-shadow: 0 20px 48px rgba(18,8,9,.16); }
    .lp-btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; min-height: 56px;
      padding: 0 28px; border-radius: 15px; border: 0; cursor: pointer; font-family: var(--font-display);
      font-size: 15.5px; font-weight: 700; transition: transform 100ms ease, background 200ms ease, color 200ms ease; }
    .lp-btn:active { transform: scale(.97); }
    .lp-btn svg { flex: none; }
    .lp-i { fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; flex: none; }
    .lp-btn-white { background: #fff; color: var(--text); }
    .lp-btn-white:hover { color: var(--crimson); }
    .lp-btn-ghost { background: rgba(255,255,255,.07); color: #fff; border: 1px solid rgba(255,255,255,.28); }
    .lp-btn-ghost:hover { background: rgba(255,255,255,.14); color: #fff; }

    /* ------------------------------------------------------------------
       HERO — black core, crimson bleeding in from the corners
       ------------------------------------------------------------------ */
    .lp-hero { position: relative; overflow: hidden; background: var(--ink); color: #fff;
      min-height: 100vh; min-height: 100svh; display: flex; flex-direction: column; justify-content: center; padding: 140px 0 120px; }
    .lp-hero > .lp-wrap { width: 100%; }
    .lp-hero-glow { background:
      radial-gradient(1200px 820px at 94% 4%, rgba(201,32,46,.62), transparent 60%),
      radial-gradient(940px 780px at 2% 98%, rgba(148,17,34,.58), transparent 62%),
      radial-gradient(760px 540px at 44% 44%, rgba(9,3,4,.74), transparent 72%),
      radial-gradient(620px 420px at 78% 24%, rgba(232,62,74,.22), transparent 64%); }
    .lp-hero-art { position: absolute; left: 0; right: 0; top: 0; margin: 0 auto; height: 100%; width: 100%; max-width: 1500px; opacity: .13; z-index: 0; }
    .lp-hero-vignette { background: radial-gradient(120% 100% at 50% 50%, transparent 34%, rgba(8,3,4,.82) 100%); }
    .lp-hero-copy { max-width: 980px; margin: 0 auto; text-align: center; }
    .lp-pill { display: inline-flex; align-items: center; gap: 9px; padding: 9px 16px; border-radius: 999px;
      background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.2); color: #f7e7e3; }
    .lp-hero h1 { margin-top: 26px; font-size: 72px; line-height: .97; letter-spacing: -.034em; font-weight: 800; }
    .lp-hero h1 br { display: inline; }   /* the break is deliberate: wide line, then narrow */
    .lp-hero-sub { margin: 26px auto 0; max-width: 600px; font-size: 16.5px; line-height: 1.74; color: #dac5c3; }
    .lp-hero-actions { margin-top: 34px; display: flex; align-items: center; justify-content: center; gap: 13px; flex-wrap: wrap; }
    .lp-stats { margin-top: 44px; display: flex; align-items: stretch; justify-content: center; text-align: left; }
    .lp-stat { padding: 0 34px; border-left: 1px solid rgba(255,255,255,.16); }
    .lp-stat:first-child { padding-left: 0; border-left: 0; }
    .lp-stat b { display: block; font-family: var(--font-display); font-size: 32px; font-weight: 800; line-height: 1; }
    .lp-stat b.gold { color: var(--gold); }
    .lp-stat span { display: block; margin-top: 7px; font-size: 13.5px; color: #aa9291; }
    .lp-scroll-cue { position: absolute; left: 0; right: 0; bottom: 26px; z-index: 3; display: flex; flex-direction: column; align-items: center; gap: 8px;
      font-size: 12px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; color: #aa9291; pointer-events: none;
      transition: opacity 400ms var(--ease-soft), transform 400ms var(--ease-soft); }
    .lp-scroll-cue svg { display: block; animation: lp-cue 1.9s var(--ease-soft) infinite; }
    .lp-scroll-cue.gone { opacity: 0; transform: translateY(8px); }
    @keyframes lp-cue { 0%, 100% { transform: translateY(0); opacity: .55; } 50% { transform: translateY(7px); opacity: 1; } }
    @media (prefers-reduced-motion: reduce) { .lp-scroll-cue svg { animation: none; } }

    /* ------------------------------------------------------------------
       CARDS — shared by venues, spaces, hostel
       ------------------------------------------------------------------ */
    .lp-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 22px; }
    .lp-card { display: block; border-radius: 22px; overflow: hidden; border: 1px solid var(--line); background: #fff; color: var(--text); }
    .lp-card:hover { color: var(--text); }
    .lp-ph { position: relative; overflow: hidden; aspect-ratio: 16 / 10; background: linear-gradient(158deg, #241214 0%, #55292c 52%, #8a4743 100%); }
    .lp-ph-in { position: absolute; inset: 0; transition: transform 900ms var(--ease-soft); }
    .lp-card:hover .lp-ph-in { transform: scale(1.045); }
    .lp-ph-art { display: block; width: 100%; height: 100%; }
    .lp-ph-img { display: block; width: 100%; height: 100%; object-fit: cover; }
    .lp-ph-scrim { position: absolute; inset: 0; background: linear-gradient(180deg, transparent 46%, rgba(14,5,6,.6) 100%); }
    .lp-ph-tag { position: absolute; left: 13px; bottom: 13px; padding: 5px 10px; border-radius: 7px; background: rgba(255,255,255,.17);
      border: 1px solid rgba(255,255,255,.24); color: #fff; font-size: 12px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
    .lp-badge { position: absolute; right: 13px; top: 13px; padding: 5px 11px; border-radius: 999px; background: rgba(255,255,255,.95);
      font-size: 12px; font-weight: 700; }
    .lp-badge-closed { color: var(--amber); }
    .lp-badge-note { color: var(--muted); }
    .lp-card-body { padding: 21px 22px 23px; }
    .lp-card h3 { font-size: 19px; font-weight: 700; letter-spacing: -.012em; }
    .lp-card-meta { margin-top: 7px; font-size: 13.5px; color: var(--muted-2); }
    .lp-card-desc { margin-top: 12px; font-size: 14.5px; line-height: 1.62; color: var(--muted); }
    .lp-card-price { margin-top: 17px; padding-top: 16px; border-top: 1px solid var(--line-2); font-size: 14px; line-height: 1.5; }
    .lp-card-price strong { font-family: var(--font-display); font-size: 19px; font-weight: 800; }
    .lp-card-price s { font-size: 14px; }

    /* ------------------------------------------------------------------
       VENUES — cream band
       ------------------------------------------------------------------ */
    .lp-venues { background: var(--cream); padding: 92px 0 96px; }
    .lp-venues .lp-eyebrow { color: var(--muted-2); }
    .lp-section-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; }
    .lp-section-head a { font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; padding-bottom: 6px; }
    .lp-venue-card .lp-ph { aspect-ratio: 4 / 3; }
    .lp-venue-card .lp-card-body { padding: 24px 24px 26px; }
    .lp-venue-card h3 { font-size: 22px; font-weight: 800; letter-spacing: -.016em; }
    .lp-venue-card .lp-card-desc { margin-top: 10px; font-size: 14px; }
    .lp-venue-foot { margin-top: 20px; padding-top: 17px; border-top: 1px solid var(--line-2); display: flex; align-items: center;
      justify-content: space-between; gap: 12px; font-size: 13.5px; }
    .lp-venue-foot .lp-count { font-weight: 600; color: var(--muted-2); }
    .lp-venue-foot .lp-from { font-family: var(--font-display); font-weight: 700; font-size: 14px; text-align: right; }

    /* ------------------------------------------------------------------
       HOW IT WORKS — black with crimson glow
       ------------------------------------------------------------------ */
    .lp-how { position: relative; overflow: hidden; background: var(--ink-2); color: #fff; padding: 96px 0 104px; }
    .lp-how-glow { background:
      radial-gradient(1000px 560px at 92% -6%, rgba(201,32,46,.34), transparent 62%),
      radial-gradient(820px 560px at 2% 104%, rgba(122,15,28,.5), transparent 66%); }
    .lp-how .lp-eyebrow { color: #96807e; }
    .lp-how .lp-h2 { max-width: 700px; }
    .lp-steps { margin-top: 52px; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 22px; }
    .lp-step { padding: 30px 27px 32px; border-radius: 22px; background: rgba(255,255,255,.045); border: 1px solid rgba(255,255,255,.1); }
    .lp-step-n { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 13px; background: rgba(255,255,255,.1);
      border: 1px solid rgba(255,255,255,.14); font-family: var(--font-display); font-size: 16px; font-weight: 800; }
    .lp-step h3 { margin-top: 21px; font-size: 18px; font-weight: 700; }
    .lp-step p { margin-top: 10px; font-size: 15px; line-height: 1.7; color: #b29b9a; }

    /* ------------------------------------------------------------------
       SPACES — white
       ------------------------------------------------------------------ */
    .lp-spaces { background: #fff; padding: 100px 0; }
    .lp-spaces .lp-eyebrow { color: var(--muted-2); }
    .lp-notice { margin: 26px 0 32px; display: inline-flex; align-items: flex-start; gap: 10px; padding: 13px 17px; border-radius: 13px;
      max-width: 680px; font-size: 14.5px; line-height: 1.62; }
    .lp-notice svg { flex: none; margin-top: 1px; }
    .lp-notice-usep { background: #f6fbf7; border: 1px solid #d8ecdf; color: var(--green); }
    .lp-notice-nudge { background: #fdf7f5; border: 1px solid #f0ddd8; color: #4f403f; }
    .lp-group { display: flex; align-items: baseline; justify-content: space-between; gap: 20px; margin: 40px 0 18px;
      padding-bottom: 13px; border-bottom: 1px solid var(--line); scroll-margin-top: 130px; }   /* clears the fixed nav, plus the reveal rise */
    .lp-group:first-of-type { margin-top: 0; }
    .lp-group h3 { font-size: 22px; font-weight: 800; letter-spacing: -.016em; color: var(--text); }
    .lp-group span { font-size: 14px; color: var(--muted); text-align: right; }
    .lp-grid-3 + .lp-group { margin-top: 44px; }
    /* usep_price_html() prints its note at 11px inline; the page floor is 12px (UI only, the function is untouched) */
    .lp-card-price span, .lp-venue-foot .lp-from span, .lp-group span span, .lp-cr-head span span, .lp-hs-card .lp-card-meta span { font-size: 12px !important; }

    /* ------------------------------------------------------------------
       HOSTEL — crimson to black, rooms listed and grouped by CR type
       ------------------------------------------------------------------ */
    .lp-hostel { position: relative; overflow: hidden; color: #fff; padding: 96px 0 100px;
      background: linear-gradient(162deg, #7d1120 0%, #3c0c14 48%, #17080a 100%); }
    .lp-hostel-glow { background:
      radial-gradient(900px 560px at 86% 2%, rgba(232,62,74,.3), transparent 62%),
      radial-gradient(800px 560px at 6% 100%, rgba(10,4,5,.62), transparent 66%); }
    .lp-hostel-art { position: absolute; right: 0; top: 0; height: 330px; width: 42%; opacity: .11; z-index: 0; }
    .lp-hostel .lp-eyebrow { color: #f2d0cb; }
    .lp-hostel .lp-lead { color: #e9d0cd; max-width: 620px; }
    .lp-cr-head { margin-top: 46px; display: flex; align-items: baseline; justify-content: space-between; gap: 20px;
      padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,.18); }
    .lp-cr-head + .lp-cr-head, .lp-grid-3 + .lp-cr-head { margin-top: 52px; }
    .lp-cr-head h3 { font-size: 21px; font-weight: 800; letter-spacing: -.014em; }
    .lp-cr-head span { font-size: 14px; color: #d5b8b5; text-align: right; }
    .lp-hostel .lp-grid-3 { margin-top: 22px; gap: 20px; }
    .lp-hs-card { background: rgba(255,255,255,.08); border-color: rgba(255,255,255,.17); color: #fff; }
    .lp-hs-card:hover { color: #fff; }
    .lp-hs-card .lp-ph { background: linear-gradient(158deg, #2c181a, #82453f); }
    .lp-hs-card .lp-card-body { padding: 20px 21px 22px; }
    .lp-hs-card h3 { font-size: 18px; }
    .lp-hs-card .lp-card-meta { color: #d5b8b5; font-size: 14px; margin-top: 8px; line-height: 1.55; }
    /* usep_price_html() paints its own colours for a white page. On these
       dark cards they would vanish, so the card recolours them. UI only. */
    .lp-hs-card .lp-card-meta s      { color: #ac8f8d !important; }
    .lp-hs-card .lp-card-meta strong { color: #fff !important; }
    .lp-hs-card .lp-card-meta span   { color: #f2d0cb !important; }
    .lp-cr-head span s      { color: #ac8f8d !important; }
    .lp-cr-head span strong { color: #fff !important; }
    .lp-cr-head span span   { color: #f2d0cb !important; }
    .lp-free { margin-top: 15px; display: inline-flex; align-items: center; gap: 8px; padding: 7px 13px; border-radius: 999px;
      font-size: 12px; font-weight: 600; }
    .lp-free i { width: 6px; height: 6px; border-radius: 50%; display: block; }
    .lp-free-yes { background: rgba(255,209,102,.16); border: 1px solid rgba(255,209,102,.42); color: #ffd166; }
    .lp-free-yes i { background: #ffd166; }
    .lp-free-no { background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.17); color: #bda4a2; }
    .lp-free-no i { background: #96807e; }
    .lp-free-closed { background: rgba(178,58,58,.26); border: 1px solid rgba(255,123,138,.4); color: #ff9aa6; }
    .lp-free-closed i { background: #ff7b8a; }

    /* ------------------------------------------------------------------
       REFUND — crimson card on white
       ------------------------------------------------------------------ */
    .lp-refund { background: #fff; padding: 76px 0 84px; }
    .lp-refund-card { display: flex; align-items: center; justify-content: space-between; gap: 36px; padding: 34px 40px;
      border-radius: 24px; color: #fff; background: linear-gradient(118deg, #9a1526 0%, #5e0f1a 48%, #23090c 100%);
      box-shadow: 0 26px 64px rgba(138,18,34,.3); }
    .lp-refund-left { display: flex; align-items: flex-start; gap: 18px; }
    .lp-refund-icon { flex: none; display: grid; place-items: center; width: 48px; height: 48px; border-radius: 14px;
      background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2); }
    .lp-refund h3 { font-size: 20px; font-weight: 700; }
    .lp-refund p { margin-top: 8px; max-width: 640px; font-size: 15px; line-height: 1.68; color: #f0d3d0; }
    .lp-refund .lp-btn { flex: none; min-height: 50px; padding: 0 24px; border-radius: 14px; background: #fff; color: #8a1222;
      font-size: 14.5px; box-shadow: 0 10px 24px rgba(10,4,5,.28); }

    /* ------------------------------------------------------------------
       FOOTER — crimson to black
       ------------------------------------------------------------------ */
    .lp-footer { position: relative; overflow: hidden; color: #fff;
      background: linear-gradient(158deg, #8a1222 0%, #400d16 40%, #14080a 100%); }
    .lp-footer-glow { background:
      radial-gradient(900px 480px at 96% -8%, rgba(232,62,74,.3), transparent 62%),
      radial-gradient(760px 500px at 4% 100%, rgba(8,3,4,.6), transparent 66%); }
    .lp-footer .lp-wrap { padding-top: 52px; }
    .lp-footer-grid { display: grid; grid-template-columns: 1.5fr 1fr 1fr 1.25fr; gap: 40px; padding-bottom: 36px; }
    .lp-footer-logo { height: 20px; width: auto; display: block; filter: invert(1); }
    .lp-footer-about { margin-top: 14px; max-width: 300px; font-size: 13.5px; line-height: 1.66; color: #b29b9a; }
    .lp-footer-disc { margin-top: 16px; display: inline-flex; align-items: center; gap: 8px; padding: 8px 13px; border-radius: 999px;
      background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.13); font-size: 13.5px; font-weight: 600; color: #e6d7d5; }
    .lp-footer-disc i { width: 7px; height: 7px; border-radius: 50%; background: var(--gold); display: block; }
    .lp-footer h4 { margin-bottom: 12px; font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #96807e; }
    .lp-footer-links { display: grid; gap: 8px; }
    .lp-footer-links a { font-size: 13.5px; color: #e6d7d5; }
    .lp-footer-links a:hover { color: #fff; }
    .lp-footer-row { display: flex; align-items: flex-start; gap: 9px; font-size: 13.5px; line-height: 1.55; color: #e6d7d5; }
    .lp-footer-row svg { flex: none; margin-top: 2px; }
    .lp-footer-rows { display: grid; gap: 10px; }
    .lp-footer-bottom { border-top: 1px solid rgba(255,255,255,.1); padding: 16px 0 22px; display: flex; align-items: center;
      justify-content: space-between; gap: 20px; flex-wrap: wrap; font-size: 13.5px; color: #96807e; }
    .lp-footer-bottom a { color: #96807e; margin-left: 24px; }
    .lp-footer-bottom a:hover { color: #fff; }

    /* ------------------------------------------------------------------
       RESPONSIVE
       ------------------------------------------------------------------ */
    @media (max-width: 1080px) {
      .lp-hero h1 { font-size: 52px; }
      .lp-grid-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .lp-steps { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .lp-footer-grid { grid-template-columns: 1fr 1fr; gap: 36px; }
    }
    @media (max-width: 720px) {
      .lp-wrap { padding: 0 18px; }
      .lp-hero { padding: 110px 0 96px; }
      .lp-hero h1 { font-size: 42px; letter-spacing: -.03em; text-wrap: balance; }
      .lp-hero h1 br { display: none; }   /* phone: too narrow for the forced break */
      .lp-hero-art { height: 78%; top: 40px; opacity: .12; }
      .lp-hero-actions { display: grid; }
      .lp-hero-actions .lp-btn { width: 100%; }
      .lp-stat { padding: 0 20px; }
      .lp-stat b { font-size: 25px; }
      .lp-h2 { font-size: 30px; }
      .lp-grid-3, .lp-steps { grid-template-columns: 1fr; gap: 14px; }
      .lp-venues, .lp-how, .lp-spaces, .lp-hostel { padding: 58px 0 62px; }
      .lp-section-head { flex-direction: column; align-items: flex-start; }
      .lp-cr-head { flex-direction: column; align-items: flex-start; gap: 6px; }
      .lp-cr-head span { text-align: left; }
      .lp-refund { padding: 50px 0 56px; }
      .lp-refund-card { flex-direction: column; align-items: flex-start; padding: 26px 22px; }
      .lp-refund .lp-btn { width: 100%; }
      .lp-footer-grid { grid-template-columns: 1fr 1fr; gap: 30px 20px; }
      .lp-footer-brand { grid-column: 1 / -1; }
      .lp-footer-office { grid-column: 1 / -1; }
      .lp-footer-bottom { display: grid; gap: 12px; }
      .lp-footer-bottom a { margin: 0 18px 0 0; }
    }
  </style>
</head>
<body>
<script>document.body.classList.add('js');</script>

<!-- The three drawn placeholders, defined once and reused by <use>. A real
     photo in assets/img/venues/ replaces the drawing for that room. -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="lp-art-hall" viewBox="0 0 400 250">
    <g stroke="#fff" stroke-opacity=".32" fill="none" stroke-width="1.6">
      <path d="M80 172V108a30 30 0 0 1 60 0v64Z"/><path d="M170 172V98a30 30 0 0 1 60 0v74Z"/><path d="M260 172V108a30 30 0 0 1 60 0v64Z"/>
      <path d="M24 172h352"/><path d="M150 172v-32h100v32"/>
    </g>
    <g fill="#fff" fill-opacity=".14"><rect x="84" y="196" width="24" height="8" rx="4"/><rect x="120" y="196" width="24" height="8" rx="4"/><rect x="156" y="196" width="24" height="8" rx="4"/><rect x="192" y="196" width="24" height="8" rx="4"/><rect x="228" y="196" width="24" height="8" rx="4"/><rect x="264" y="196" width="24" height="8" rx="4"/><rect x="300" y="196" width="24" height="8" rx="4"/></g>
  </symbol>
  <symbol id="lp-art-gym" viewBox="0 0 400 250">
    <g stroke="#fff" stroke-opacity=".32" fill="none" stroke-width="1.6">
      <path d="M24 84c50-30 100-42 176-42s126 12 176 42"/><path d="M24 108c50-28 100-40 176-40s126 12 176 40"/>
      <path d="M64 84v98M134 68v114M200 62v120M266 68v114M336 84v98"/><path d="M14 182h372"/>
      <rect x="128" y="128" width="144" height="54" rx="3"/><path d="M200 128v54M128 155h144" stroke-opacity=".5"/>
    </g>
  </symbol>
  <symbol id="lp-art-bunk" viewBox="0 0 400 250">
    <g stroke="#fff" stroke-opacity=".38" fill="none" stroke-width="1.7">
      <rect x="52" y="72" width="140" height="46" rx="6"/><rect x="52" y="146" width="140" height="46" rx="6"/>
      <path d="M62 72V56M182 72V56M62 146v-28M182 146v-28M62 192v22M182 192v22"/>
      <rect x="228" y="72" width="140" height="46" rx="6"/><rect x="228" y="146" width="140" height="46" rx="6"/>
      <path d="M238 72V56M358 72V56M238 146v-28M358 146v-28M238 192v22M358 192v22"/><path d="M14 214h372"/>
    </g>
    <g fill="#fff" fill-opacity=".22"><rect x="64" y="82" width="40" height="16" rx="7"/><rect x="64" y="156" width="40" height="16" rx="7"/><rect x="240" y="82" width="40" height="16" rx="7"/><rect x="240" y="156" width="40" height="16" rx="7"/></g>
  </symbol>
  <symbol id="lp-art-private" viewBox="0 0 400 250">
    <g stroke="#fff" stroke-opacity=".38" fill="none" stroke-width="1.7">
      <rect x="44" y="88" width="132" height="42" rx="6"/><rect x="44" y="158" width="132" height="42" rx="6"/>
      <path d="M54 88V72M166 88V72M54 158v-28M166 158v-28M54 200v20M166 200v20"/>
      <path d="M244 52v168M244 52h122M244 220h122"/><path d="M272 132a22 22 0 0 1 44 0v10h-44Z"/><path d="M266 142h56M294 142v22"/>
      <rect x="336" y="96" width="26" height="60" rx="4"/><path d="M14 220h372"/>
    </g>
    <g fill="#fff" fill-opacity=".22"><rect x="56" y="98" width="36" height="15" rx="7"/><rect x="56" y="168" width="36" height="15" rx="7"/></g>
  </symbol>
  <symbol id="lp-i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
  <symbol id="lp-i-pin" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="2.6"/></symbol>
  <symbol id="lp-i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
  <symbol id="lp-art-colonnade" viewBox="0 0 1200 760">
    <g stroke="#fff" fill="none" stroke-width="1.6">
      <path d="M120 250 600 96l480 154"/><path d="M120 250h960"/><path d="M150 250h900" stroke-opacity=".5"/>
      <path d="M196 250v368M330 250v368M464 250v368M598 250v368M732 250v368M866 250v368M1000 250v368"/>
      <path d="M180 618h32M314 618h32M448 618h32M582 618h32M716 618h32M850 618h32M984 618h32"/>
      <path d="M232 618V438a31 31 0 0 1 62 0v180M366 618V438a31 31 0 0 1 62 0v180M500 618V438a31 31 0 0 1 62 0v180M634 618V438a31 31 0 0 1 62 0v180M768 618V438a31 31 0 0 1 62 0v180M902 618V438a31 31 0 0 1 62 0v180" stroke-opacity=".62"/>
      <path d="M60 618h1100M90 650h1040M120 682h980" stroke-opacity=".42"/>
    </g>
  </symbol>
</svg>

<!-- ==================== NAV — the shared customer nav, transparent over the hero ==================== -->
<?php $navMode = 'hero'; include __DIR__ . '/../includes/customer-nav.php'; ?>

<main>

  <!-- ==================== HERO ==================== -->
  <section class="lp-hero lp-grain">
    <div class="lp-glow lp-hero-glow"></div>
    <svg class="lp-hero-art" viewBox="0 0 1200 760" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><use href="#lp-art-colonnade"/></svg>
    <div class="lp-glow lp-hero-vignette"></div>

    <div class="lp-scroll-cue" id="lpCue" aria-hidden="true">
      Scroll
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
    </div>

    <div class="lp-wrap">
      <div class="lp-hero-copy" data-reveal data-hero>
        <span class="lp-pill lp-eyebrow" data-hero-item>
          <svg class="lp-i" width="13" height="13" style="color:var(--gold)" aria-hidden="true"><use href="#lp-i-pin"/></svg>
          USeP Tagum&ndash;Mabini Campus
        </span>
        <h1 data-hero-item>Reserve a campus venue in<br>minutes, not visits.</h1>
        <p class="lp-hero-sub" data-hero-item>Alumni halls, university venues and hostel beds &mdash; check real availability, reserve online, and pay by GCash or cash.</p>

        <div class="lp-hero-actions" data-hero-item>
          <a class="lp-btn lp-btn-white" href="#venue-listings">
            Browse the spaces
            <svg class="lp-i" width="16" height="16" aria-hidden="true"><use href="#lp-i-arrow"/></svg>
          </a>
          <a class="lp-btn lp-btn-ghost" href="#hostel-listings">Hostel beds</a>
        </div>

        <!-- real numbers, computed from the shared lists -->
        <div class="lp-stats" data-hero-item>
          <div class="lp-stat"><b data-count="<?php echo (int) $lpVenueCount; ?>"><?php echo (int) $lpVenueCount; ?></b><span>venues</span></div>
          <div class="lp-stat"><b data-count="<?php echo (int) $lpSpaceCount; ?>"><?php echo (int) $lpSpaceCount; ?></b><span>bookable spaces</span></div>
          <div class="lp-stat"><b class="gold" data-count="<?php echo (int) $DISCOUNT_PERCENT; ?>" data-suffix="%"><?php echo (int) $DISCOUNT_PERCENT; ?>%</b><span>off for USeP</span></div>
        </div>
      </div>
    </div>
  </section>

  <!-- ==================== VENUES ==================== -->
  <section class="lp-venues" id="venues">
    <div class="lp-wrap">
      <div class="lp-section-head" data-reveal>
        <div>
          <span class="lp-eyebrow">Where you can book</span>
          <h2 class="lp-h2">Three places on campus</h2>
        </div>
        <a href="#venue-listings">See every space
          <svg class="lp-i" width="15" height="15" aria-hidden="true"><use href="#lp-i-arrow"/></svg>
        </a>
      </div>

      <div class="lp-grid-3" style="margin-top:32px" data-stagger>
<?php foreach ($lpVenueNames as $venueName):
        $rooms = venueRoomsFor($venueRooms, $venueName);
        if (!$rooms) continue;
        $caps  = array_column($rooms, 'capacity');
        $minFee = min(array_column($rooms, 'fee'));
        $slug  = $lpVenueSlug[$venueName] ?? 'venue';
        $anchor = 'venue-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($venueName)); ?>
        <a class="lp-card lp-venue-card lp-lift" href="#<?php echo htmlspecialchars($anchor); ?>">
          <?php echo lp_photo_block($slug, $lpVenueArt[$venueName] ?? 'hall', '', $venueName); ?>
          <div class="lp-card-body">
            <h3><?php echo htmlspecialchars($venueName); ?></h3>
            <p class="lp-card-desc"><?php echo htmlspecialchars($lpVenueBlurb[$venueName] ?? ''); ?></p>
            <div class="lp-venue-foot">
              <span class="lp-count"><?php echo count($rooms); ?> spaces &middot; <?php echo (int) min($caps); ?>&ndash;<?php echo number_format(max($caps)); ?> seats</span>
              <span class="lp-from">from <?php echo usep_price_html((int) $minFee, $isUsep); ?></span>
            </div>
          </div>
        </a>
<?php endforeach; ?>
        <a class="lp-card lp-venue-card lp-lift" href="#hostel-listings">
          <?php echo lp_photo_block('venue-usep-hostel', 'bunk', '', $HOSTEL_VENUE); ?>
          <div class="lp-card-body">
            <h3><?php echo htmlspecialchars($HOSTEL_VENUE); ?></h3>
            <p class="lp-card-desc">Bunk rooms booked by the bed, with communal or private bathrooms.</p>
            <div class="lp-venue-foot">
              <span class="lp-count"><?php echo count($hostelRooms); ?> rooms &middot; <?php echo (int) $lpBedCount; ?> beds</span>
              <span class="lp-from">from <?php echo usep_price_html((int) $lpMinHostel, $isUsep, '/night'); ?></span>
            </div>
          </div>
        </a>
      </div>
    </div>
  </section>

  <!-- ==================== HOW IT WORKS ==================== -->
  <section class="lp-how lp-grain" id="how">
    <div class="lp-glow lp-how-glow"></div>
    <div class="lp-wrap">
      <div data-reveal>
        <span class="lp-eyebrow">How it works</span>
        <h2 class="lp-h2">Four steps, and you know where you stand at each one.</h2>
      </div>
      <div class="lp-steps" data-stagger>
        <div class="lp-step">
          <span class="lp-step-n">1</span>
          <h3>Pick a space and time</h3>
          <p>Live availability, so you never request a slot that is already taken. Reservations must start at least 12 hours ahead.</p>
        </div>
        <div class="lp-step">
          <span class="lp-step-n">2</span>
          <h3>Upload a valid ID</h3>
          <p>Say whether you are USeP-affiliated. Staff confirm it from your ID &mdash; that is what unlocks the <?php echo (int) $DISCOUNT_PERCENT; ?>% rate.</p>
        </div>
        <div class="lp-step">
          <span class="lp-step-n">3</span>
          <h3>Staff review your request</h3>
          <p>You will see the decision, and the reason behind it, in your booking history.</p>
        </div>
        <div class="lp-step">
          <span class="lp-step-n">4</span>
<?php if ($REFUNDS_ENABLED): /* pre-pay — the refund switch also sets payment timing (DB-DECISIONS #18) */ ?>
          <h3>Pay before your date</h3>
          <p>GCash or over the counter, at least one day before the event. Then your booking is confirmed.</p>
<?php else: ?>
          <h3>Pay after your event</h3>
          <p>Nothing to pay up front. Once your event is over, pay by GCash or over the counter within <?php echo (int) $POSTPAY_GRACE_DAYS; ?> days.</p>
<?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ==================== SPACES ==================== -->
  <section class="lp-spaces" id="venue-listings">
    <div class="lp-wrap">
      <div data-reveal>
        <span class="lp-eyebrow">The spaces</span>
        <h2 class="lp-h2">Pick one and open its booking page</h2>
        <p class="lp-lead" style="max-width:560px">Dates, times, ID upload and payment all happen there.</p>
      </div>

      <?php if ($isUsep): ?>
        <!-- USeP account: prices below are shown discounted with the full price
             crossed out. A preview, not a grant — staff confirm from the ID. -->
        <div class="lp-notice lp-notice-usep" data-reveal>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 13 4.5 4.5L19 7"/></svg>
          <span>You are signed in with a USeP account, so prices show your <strong><?php echo (int) $DISCOUNT_PERCENT; ?>% USeP rate</strong> with the full price crossed out. It is confirmed from your USeP ID when staff approve the booking.</span>
        </div>
      <?php else: ?>
        <!-- everyone else: full price, plus the nudge — a USeP student on a Gmail
             account should still find out the discount exists. -->
        <div class="lp-notice lp-notice-nudge" data-reveal>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#a11626" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
          <span><strong>USeP student, faculty or staff?</strong> You get <?php echo (int) $DISCOUNT_PERCENT; ?>% off &mdash; choose "USeP-affiliated" and upload your USeP ID when you book.</span>
        </div>
      <?php endif; ?>

      <!-- Venue rooms come from the ONE shared source (includes/venue-rooms.php),
           the same data the booking page and admin Venue Management read.
           ONE BLOCK PER VENUE, in the order the data lists them: the venue cards
           above link to these anchors, so "Bahay Alumni" lands on Bahay Alumni. -->
<?php foreach ($lpVenueNames as $venueName):
        $rooms  = venueRoomsFor($venueRooms, $venueName);
        if (!$rooms) continue;
        $anchor = 'venue-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($venueName));   /* Bahay Alumni -> venue-bahay-alumni */
        $minFee = min(array_column($rooms, 'fee')); ?>
      <div class="lp-group" id="<?php echo htmlspecialchars($anchor); ?>" data-reveal>
        <h3><?php echo htmlspecialchars($venueName); ?></h3>
        <span><?php echo count($rooms); ?> space<?php echo count($rooms) === 1 ? '' : 's'; ?> &middot; from <?php echo usep_price_html((int) $minFee, $isUsep, ' per day'); ?></span>
      </div>
      <div class="lp-grid-3" data-stagger>
<?php   foreach ($rooms as $room):
          $mt     = $room['maintenance'];
          $covers = $mt && venueMaintCovers($mt, $lpToday);
          /* the badge is the room's maintenance state, from the shared window */
          $badge  = '';
          if ($covers && $mt['blocks']) $badge = '<span class="lp-badge lp-badge-closed">Closed &middot; ' . htmlspecialchars($mt['reason']) . '</span>';
          elseif ($covers)              $badge = '<span class="lp-badge lp-badge-note">Notice &middot; '  . htmlspecialchars($mt['reason']) . '</span>'; ?>
        <a class="lp-card lp-lift" href="room-reservation.php?room=<?php echo urlencode($room['id']); ?>">
          <?php echo lp_photo_block($room['id'], lp_room_kind($room), $badge); ?>
          <div class="lp-card-body">
            <h3><?php echo htmlspecialchars($room['name']); ?></h3>
            <p class="lp-card-meta">up to <?php echo number_format((int) $room['capacity']); ?> guests</p>
            <p class="lp-card-desc"><?php echo htmlspecialchars($room['description']); ?></p>
            <div class="lp-card-price"><?php echo usep_price_html((int) $room['fee'], $isUsep, ' per day'); ?></div>
          </div>
        </a>
<?php   endforeach; ?>
      </div>
<?php endforeach; ?>
    </div>
  </section>

  <!-- ==========================================================
       USeP HOSTEL — a separate section on purpose. The hostel is a
       venue in the data model, but you book a BED here, not a room,
       and it is priced per head per night. Its two CR types are shown
       apart because that is the choice a guest actually makes.
       Rooms come from includes/hostel-rooms.php (one source).
       ========================================================== -->
  <section class="lp-hostel lp-grain" id="hostel-listings">
    <div class="lp-glow lp-hostel-glow"></div>
    <svg class="lp-hostel-art" viewBox="0 0 400 250" preserveAspectRatio="xMaxYMin slice" aria-hidden="true"><use href="#lp-art-bunk"/></svg>
    <div class="lp-wrap">
      <div data-reveal>
        <span class="lp-eyebrow"><?php echo htmlspecialchars($HOSTEL_VENUE); ?></span>
        <h2 class="lp-h2">Book a bed, not the room.</h2>
        <p class="lp-lead">You reserve <strong>beds</strong>, not rooms. Others may book the remaining beds in the same room &mdash; book all six and it is yours. Priced per head, per night.</p>
      </div>

<?php foreach (['communal', 'private'] as $crType):
        $rooms = hostelRoomsByType($hostelRooms, $crType);
        if (!$rooms) continue; ?>
      <div class="lp-cr-head" data-reveal>
        <h3><?php echo htmlspecialchars($HOSTEL_CR_LABEL[$crType]); ?></h3>
        <span><?php echo $crType === 'private' ? 'Bathroom inside the room' : 'Shared bathroom outside the room'; ?> &middot; <?php echo usep_price_html((int) $HOSTEL_RATES[$crType], $isUsep, ' per head, per night'); ?></span>
      </div>
      <div class="lp-grid-3" data-stagger>
<?php foreach ($rooms as $room):
          /* Tonight's free beds — COUNTED from the roster, never a stored number.
             It is a room x night fact, so the card says which night it means. */
          $tonight = date('Y-m-d');
          $free    = hostelBedsFree($room, $tonight);
          $mt      = $room['maintenance'];
          $closed  = $mt && $mt['blocks'] && hostelMaintCovers($mt, $tonight); ?>
        <a class="lp-card lp-hs-card lp-lift" href="hostel-reservation.php?room=<?php echo urlencode($room['id']); ?>">
          <?php echo lp_photo_block($room['id'], $crType === 'private' ? 'private' : 'bunk'); ?>
          <div class="lp-card-body">
            <h3><?php echo htmlspecialchars($room['name']); ?></h3>
            <p class="lp-card-meta"><?php echo (int) $room['beds']; ?> beds &middot; <?php echo usep_price_html((int) $HOSTEL_RATES[$room['cr_type']], $isUsep, ' per head, per night'); ?></p>
            <span class="lp-free <?php echo $closed ? 'lp-free-closed' : (!$free ? 'lp-free-no' : 'lp-free-yes'); ?>">
              <i></i>
              <?php
                if ($closed)      echo 'Closed for maintenance tonight';
                elseif (!$free)   echo 'No beds free tonight';
                else              echo $free . ' of ' . (int) $room['beds'] . ' beds free tonight';
              ?>
            </span>
          </div>
        </a>
<?php endforeach; ?>
      </div>
<?php endforeach; ?>
    </div>
  </section>

  <!-- ==================== REFUND ====================
       Follows the admin refund switch (includes/refund-policy.php). OFF is the
       USeP default: every new booking is final. -->
  <section class="lp-refund">
    <div class="lp-wrap">
      <div class="lp-refund-card" data-reveal>
        <div class="lp-refund-left">
          <span class="lp-refund-icon">
<?php if ($REFUNDS_ENABLED): ?>
            <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10h13a5 5 0 0 1 0 10h-6"/><path d="m7 6-4 4 4 4"/></svg>
<?php else: ?>
            <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
<?php endif; ?>
          </span>
          <div>
<?php if ($REFUNDS_ENABLED): ?>
            <h3>Plans change &mdash; you can ask for a refund.</h3>
            <p>Approved bookings that are paid and have not been held yet are eligible. Request it from your booking history; staff make the final call and you will see the reason either way.</p>
<?php else: ?>
            <h3>All bookings are non-refundable.</h3>
            <p>Please check your date, room and details before you pay &mdash; once paid, a booking cannot be refunded. If USeP has to close or cancel your venue, the venue office will offer you a replacement room or a new date.</p>
<?php endif; ?>
          </div>
        </div>
        <a class="lp-btn" href="faq.php#after">Read the FAQ</a>
      </div>
    </div>
  </section>

</main>

<!-- ==================== FOOTER ==================== -->
<footer class="lp-footer lp-grain">
  <div class="lp-glow lp-footer-glow"></div>
  <div class="lp-wrap">
    <div class="lp-footer-grid">
      <div class="lp-footer-brand">
        <img class="lp-footer-logo" src="../logo/Logo Header 3.png" alt="VENUSeP">
        <p class="lp-footer-about">Venue and hostel booking for the University of Southeastern Philippines, Tagum&ndash;Mabini Campus. Run by the campus venue office.</p>
        <span class="lp-footer-disc"><i></i><?php echo (int) $DISCOUNT_PERCENT; ?>% off for USeP students &amp; staff</span>
      </div>
      <div>
        <h4>Book</h4>
        <div class="lp-footer-links">
          <a href="#venue-listings">Browse venues</a>
          <a href="#hostel-listings">Hostel beds</a>
          <a href="calendar.php">Availability calendar</a>
          <a href="booking-history.php">My bookings</a>
          <a href="transaction-history.php">Transaction history</a>
        </div>
      </div>
      <div>
        <h4>Help</h4>
        <div class="lp-footer-links">
          <a href="faq.php">Frequently asked questions</a>
          <a href="faq.php">How to pay by GCash</a>
<?php if ($REFUNDS_ENABLED): ?>
          <a href="booking-history.php">Request a refund</a>
<?php else: ?>
          <a href="faq.php#after">Refund policy</a>
<?php endif; ?>
          <a href="faq.php">USeP discount rules</a>
        </div>
      </div>
      <div class="lp-footer-office">
        <h4>Venue office</h4>
        <div class="lp-footer-rows">
          <div class="lp-footer-row">
            <svg class="lp-i" width="15" height="15" style="color:#96807e" aria-hidden="true"><use href="#lp-i-pin"/></svg>
            <span><?php echo htmlspecialchars($lpOfficeWhere); ?></span>
          </div>
          <div class="lp-footer-row">
            <svg class="lp-i" width="15" height="15" style="color:#96807e" aria-hidden="true"><use href="#lp-i-clock"/></svg>
            <span><?php echo htmlspecialchars($lpOfficeHours); ?></span>
          </div>
        </div>
      </div>
    </div>
    <div class="lp-footer-bottom">
      <span>&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</span>
      <span><a href="faq.php#after">Booking &amp; cancellation policy</a></span>
    </div>
  </div>
</footer>

<!-- GSAP — motion the CSS cannot do. Two effects only (see below).
     Same CDN as the rest of the project. If it fails to load, the page
     is complete without it: numbers already show their final value. -->
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>
<script>
  (function () {
    /* No GSAP (CDN down) or reduced-motion: leave everything visible and stop. */
    if (typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined') return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    gsap.registerPlugin(ScrollTrigger);
    ScrollTrigger.config({ ignoreMobileResize: true });   /* phone address-bar show/hide is not a resize */
    var EASE = 'power3.out';                               /* fast start, long gentle settle */

    /* reveal(): hide the targets, then paint them in or out whenever their
       trigger enters/leaves the viewport — and again after every refresh
       (browser zoom, window resize, fonts loading). Nothing is remembered
       between frames, so a re-measure can never leave an element stuck. */
    function reveal(targets, trigger, opts) {
      opts = opts || {};
      gsap.set(targets, { opacity: 0, y: opts.rise || 28 });
      function paint(on) {
        gsap.to(targets, {
          opacity: on ? 1 : 0, y: on ? 0 : (opts.rise || 28),
          duration: on ? 1.1 : 0.45, ease: EASE, overwrite: 'auto',
          stagger: on ? (opts.stagger || 0) : 0,
          delay: on ? (opts.delay || 0) : 0,
          clearProps: on ? 'transform' : ''      /* landed: give transform back to CSS (hover lift) */
        });
        if (on && opts.onShow) opts.onShow();
      }
      ScrollTrigger.create({
        trigger: trigger, start: opts.start || 'top 88%', end: 'bottom top',
        onToggle:  function (self) { paint(self.isActive); },
        onRefresh: function (self) { paint(self.isActive); }
      });
    }

    /* Hero: pieces arrive in order on load; the stats count up as they land. */
    var heroWrap = document.querySelector('[data-hero]');
    if (heroWrap) {
      gsap.set(heroWrap, { opacity: 1 });
      reveal(heroWrap.querySelectorAll('[data-hero-item]'), heroWrap,
        { start: 'top 80%', rise: 34, stagger: 0.14, delay: 0.15, onShow: countUp });
    }

    /* Everything else: single blocks, and grids whose children stagger. */
    document.querySelectorAll('[data-reveal]:not([data-hero])').forEach(function (el) { reveal(el, el); });
    document.querySelectorAll('[data-stagger]').forEach(function (g) { reveal(g.children, g, { stagger: 0.09 }); });

    /* Stats count up from 0. */
    function countUp() {
      document.querySelectorAll('.lp-stat b[data-count]').forEach(function (el) {
        var target = parseInt(el.getAttribute('data-count'), 10) || 0;
        var suffix = el.getAttribute('data-suffix') || '';
        var box = { v: 0 };
        el.textContent = '0' + suffix;
        gsap.to(box, { v: target, duration: 1.6, ease: 'power2.out', delay: 0.6, overwrite: true,
          onUpdate: function () { el.textContent = Math.round(box.v) + suffix; } });
      });
    }

    /* Parallax: the colonnade moves 18% of its height across the hero's scroll. */
    gsap.to('.lp-hero-art', { yPercent: 18, ease: 'none',
      scrollTrigger: { trigger: '.lp-hero', start: 'top top', end: 'bottom top', scrub: 0.6 } });
  })();

  /* In-page links scroll smoothly, and the scroll cue fades once you start.
     (The nav bar itself lives in includes/customer-nav.php.) Done here, not with CSS scroll-behavior,
     because that CSS property makes ScrollTrigger mis-measure on zoom/resize. */
  (function () {
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.querySelectorAll('a[href^="#"]').forEach(function (a) {
      var id = a.getAttribute('href');
      if (id.length < 2) return;
      a.addEventListener('click', function (e) {
        var t = document.querySelector(id);
        if (!t) return;
        e.preventDefault();
        t.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
      });
    });
    var cue = document.getElementById('lpCue');
    function paint() { if (cue) cue.classList.toggle('gone', window.scrollY > 60); }
    window.addEventListener('scroll', paint, { passive: true });
    paint();
  })();
</script>
</body>
</html>
