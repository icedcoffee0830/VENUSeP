{{-- Privacy notice — public, static, no database. Linked from nowhere yet (see routes/web.php).
     Same look as the FAQ page (customer/faq.blade.php): its colours, fonts and the browse-mode nav. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="darkreader-lock">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#7d1120">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VENUSeP | Privacy notice</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  /* The FAQ page's tokens. */
  :root {
    --crimson: #a11626; --gold: #d9930d; --yellow: #ffd166;
    --paper: #f5e9e7; --muted: #d5b8b5; --muted-2: #ac8f8d;
    --line: rgba(255,255,255,.14);
    --font-display: Archivo, Inter, system-ui, sans-serif;
    --font-body: Inter, system-ui, -apple-system, "Segoe UI", sans-serif;
  }
  * { box-sizing: border-box; }
  body { margin: 0; color: var(--paper); font-family: var(--font-body); -webkit-font-smoothing: antialiased; overflow-x: hidden;
         background: #3c0c14 linear-gradient(162deg, #7d1120 0%, #3c0c14 40%, #120809 100%) fixed; }
  a { color: var(--yellow); text-underline-offset: 3px; }
  a:hover { color: #fff; }
  a:focus-visible { outline: 2px solid var(--yellow); outline-offset: 3px; border-radius: 4px; }
  h1, h2 { font-family: var(--font-display); margin: 0; color: #fff; text-wrap: balance; }

  main { padding: 64px 0 80px; scroll-margin-top: 96px; }
  main:focus { outline: none; }
  .pv-wrap { max-width: 760px; margin: 0 auto; padding: 0 28px; }
  .pv-draft { margin: 0 0 28px; padding: 14px 18px; border-radius: 14px; border: 1px solid rgba(255,209,102,.35); background: rgba(255,209,102,.1);
    font-size: 14px; line-height: 1.6; font-weight: 600; color: var(--yellow); }
  h1 { font-size: 48px; line-height: 1.05; letter-spacing: -.03em; font-weight: 800; }
  .pv-lead { margin: 16px 0 0; font-size: 16px; line-height: 1.72; color: var(--muted); }
  .pv-back { display: inline-block; margin-top: 18px; font-size: 13.5px; font-weight: 700; text-decoration: none; }

  section { margin-top: 44px; scroll-margin-top: 96px; }   /* clear of the sticky nav */
  .pv-skip { position: absolute; left: 12px; top: -60px; z-index: 100; padding: 10px 16px; border-radius: 10px; background: var(--yellow); color: #1d1214; font-weight: 700; text-decoration: none; }
  .pv-skip:focus { top: 12px; color: #1d1214; }
  .pv-tel { white-space: nowrap; }
  h2 { font-size: 22px; font-weight: 800; letter-spacing: -.02em; padding-bottom: 12px; border-bottom: 1px solid var(--line); }
  section p, section li, address { font-size: 15.5px; line-height: 1.72; }
  section p { margin: 14px 0 0; max-width: 68ch; }
  section ul { margin: 14px 0 0; padding-left: 22px; max-width: 68ch; }
  section li + li { margin-top: 6px; }
  strong { color: #fff; }
  address { margin-top: 14px; font-style: normal; }
  .pv-updated { margin-top: 48px; padding-top: 16px; border-top: 1px solid var(--line); font-size: 13.5px; color: var(--muted-2); }

  .pv-footer { border-top: 1px solid var(--line); background: rgba(8,3,4,.35); }
  .pv-footer .pv-wrap { padding-top: 18px; padding-bottom: 22px; font-size: 12.5px; color: var(--muted-2); }

  @media (max-width: 720px) {
    .pv-wrap { padding: 0 16px; }
    main { padding: 40px 0 60px; }
    h1 { font-size: 34px; }
    h2 { font-size: 20px; }
  }
</style>
</head>
<body>
<a class="pv-skip" href="#pv-main">Skip to content</a>
@include('partials.customer-nav', ['navMode' => 'solid'])

<main id="pv-main" tabindex="-1">
  <div class="pv-wrap">
    <p class="pv-draft">Draft for the VENUSeP project. This notice has not yet been reviewed by the USeP University Data Privacy Office.</p>

    <h1>Privacy notice</h1>
    <p class="pv-lead">What personal information VENUSeP collects when you book, why, who can see it, and what you can do about it.</p>
    <a class="pv-back" href="{{ route('customer.venues') . (counter_mode() ? '?counter=1' : '') }}">&larr; Back to venue booking</a>

    <section aria-labelledby="pv-who">
      <h2 id="pv-who">Who we are</h2>
      <p>VENUSeP is the booking system for Bahay Alumni, USeP Venues and the USeP Hostel. It is run under the University of Southeastern Philippines (USeP).</p>
      <p>This notice follows the University Data Privacy Statement and the Data Privacy Act of 2012 (R.A.&nbsp;10173).</p>
    </section>

    <section aria-labelledby="pv-what">
      <h2 id="pv-what">What we collect</h2>
      <ul>
        <li>Your name, email address and mobile number.</li>
        <li>Your valid ID, to confirm who you are and whether you get the USeP discount.</li>
        <li>Your booking and payment records.</li>
        <li>The GCash receipt images you upload.</li>
        <li>Your Google account name and email, if you sign in with Google.</li>
        <li>Sign-in records and your IP address.</li>
        <li>The cookies needed to keep you signed in.</li>
      </ul>
    </section>

    <section aria-labelledby="pv-why">
      <h2 id="pv-why">Why we collect it</h2>
      <ul>
        <li>To confirm who you are and whether you belong to USeP.</li>
        <li>To process and manage your bookings, payments, refunds and receipts.</li>
        <li>To contact you about your booking.</li>
        <li>To keep the system secure.</li>
        <li>To produce office reports.</li>
      </ul>
    </section>

    <section aria-labelledby="pv-see">
      <h2 id="pv-see">Who can see it</h2>
      <p>Only the staff and admins of the office you booked with, and the system's super admin for technical work.</p>
      <p>We never sell your information. We do not share it with anyone outside USeP without your consent, unless the law requires it.</p>
    </section>

    <section aria-labelledby="pv-protect">
      <h2 id="pv-protect">How we protect it</h2>
      <ul>
        <li>Valid ID files are stored encrypted.</li>
        <li>Each person can see only what their role allows.</li>
        <li>Admins sign in with two-step verification.</li>
        <li>Every time someone opens one of your documents, it is logged.</li>
      </ul>
    </section>

    <section aria-labelledby="pv-keep">
      <h2 id="pv-keep">How long we keep it</h2>
      <p>Your valid ID is deleted once your booking's process is finished.</p>
      <p>Other records are kept only as long as they are needed for your booking, the office's records, or the law. After that, they are disposed of securely.</p>
    </section>

    <section aria-labelledby="pv-rights">
      <h2 id="pv-rights">Your rights</h2>
      <p>Under R.A.&nbsp;10173 you have the right:</p>
      <ul>
        <li>to be informed about how your information is used;</li>
        <li>to access your information;</li>
        <li>to correct it (you can edit your own details in your profile);</li>
        <li>to object to its use;</li>
        <li>to have it erased or blocked;</li>
        <li>to get a copy of it in a form you can take elsewhere (data portability);</li>
        <li>to file a complaint with the National Privacy Commission.</li>
      </ul>
      <p>You can also withdraw your consent at any time.</p>
    </section>

    <section aria-labelledby="pv-contact">
      <h2 id="pv-contact">Contact</h2>
      <p>For questions, requests or complaints about your personal information, contact:</p>
      <address>
        <strong>University Data Privacy Office</strong><br>
        Data Protection Officer: Atty. Edward Caesar J. Bedro<br>
        Iñigo St., Bo. Obrero, Davao City, Davao del Sur 8000<br>
        Phone: <a class="pv-tel" href="tel:+63822278192">+6382 227 8192</a>
      </address>
      <p>The University's privacy manual and forms: <a href="https://usep.edu.ph/data-privacy-manual-and-forms/" target="_blank" rel="noopener">usep.edu.ph/data-privacy-manual-and-forms</a></p>
    </section>

    <p class="pv-updated">Last updated: <time datetime="2026-11-05">5 November 2026</time></p>
  </div>
</main>

<footer class="pv-footer">
  <div class="pv-wrap">&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</div>
</footer>
</body>
</html>
