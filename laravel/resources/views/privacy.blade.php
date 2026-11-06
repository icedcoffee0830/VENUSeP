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
@include('partials.legal-page-style')
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
