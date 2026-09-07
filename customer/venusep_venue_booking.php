<?php
/* Both room lists come from ONE shared source each, so this landing page can
   never drift from the booking pages or admin Venue Management. */
include __DIR__ . '/../includes/venue-rooms.php';
include __DIR__ . '/../includes/hostel-rooms.php';
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
  <style>
    :root {
      --venusep-black: #1f1e1e;
      --venusep-cream: #ffffff;
      --venusep-border: #ddd7ce;
      --venusep-text: #050505;
      --bg: #ffffff;
      --surface: var(--venusep-cream);
      --surface-2: #fbf7f2;
      --primary: var(--venusep-black);
      --accent: #8a8a8a;
      --text: var(--venusep-text);
      --muted: #6e6a64;
      --radius: 24px;
      --shadow: 0 20px 60px rgba(31, 77, 157, 0.12);
      font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      background: var(--bg);
      color: var(--text);
    }

    .page {
      max-width: 1240px;
      margin: 0 auto;
      padding: 32px 24px 48px;
    }

    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      margin-bottom: 20px;
    }

    .brand {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: var(--venusep-black);
      text-decoration: none;
    }

    .brand-mark {
      width: 120px;
      height: 120px;
      border-radius: 0;
      background: transparent;
      display: grid;
      place-items: center;
      overflow: hidden;
    }

    .brand-mark img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      display: block;
    }

    .user-profile {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      padding: 10px 18px;
      border-radius: 18px;
      background: var(--surface-2);
      border: 1px solid var(--venusep-border);
      text-decoration: none;
      color: inherit;
    }

    .user-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: var(--venusep-black);
      color: var(--venusep-cream);
      display: grid;
      place-items: center;
      font-weight: 700;
    }

    .user-info {
      display: grid;
      gap: 2px;
      line-height: 1.2;
    }

    .user-info span {
      font-weight: 700;
      font-size: 0.95rem;
    }

    .user-info small {
      color: var(--muted);
      font-size: 0.8rem;
    }

    .hero {
      display: grid;
      grid-template-columns: 1.2fr 0.8fr;
      gap: 32px;
      align-items: center;
      padding: 36px 36px 42px;
      background: var(--surface);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      margin-bottom: 40px;
    }

    .hero-copy {
      max-width: 620px;
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 16px;
      background: rgba(138, 138, 138, 0.12);
      color: var(--primary);
      border-radius: 999px;
      font-size: 0.95rem;
      font-weight: 600;
      letter-spacing: 0.02em;
      margin-bottom: 18px;
    }

    .hero h1 {
      margin: 0;
      font-size: clamp(3rem, 2.6vw, 4.25rem);
      line-height: 0.95;
      letter-spacing: -0.03em;
    }

    .hero p {
      margin: 24px 0 0;
      max-width: 520px;
      line-height: 1.75;
      color: var(--muted);
      font-size: 1rem;
    }

    .search-panel {
      margin-top: 30px;
      display: grid;
      gap: 16px;
    }

    .search-row {
      display: grid;
      grid-template-columns: repeat(3, minmax(180px, 1fr));
      gap: 12px;
      align-items: end;
    }

    .search-field {
      background: var(--surface-2);
      border: 1px solid var(--venusep-border);
      border-radius: 14px;
      padding: 12px 14px;
      display: flex;
      flex-direction: column;
      gap: 6px;
      min-height: 66px;
      color: var(--text);
      font-size: 0.95rem;
    }

    .search-field strong {
      display: inline-block;
      color: var(--primary);
      font-size: 0.95rem;
    }

    .search-field span {
      color: var(--muted);
    }

    .search-cta {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: var(--primary);
      color: white;
      border-radius: 18px;
      font-weight: 700;
      letter-spacing: 0.02em;
      cursor: pointer;
      min-height: 62px;
      padding: 0 28px;
      border: none;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .search-cta:hover {
      transform: translateY(-1px);
      box-shadow: 0 14px 32px rgba(31, 77, 157, 0.18);
    }

    .hero-visual {
      position: relative;
      min-height: 470px;
      background: linear-gradient(180deg, #f5f2ef 0%, #ffffff 100%);
      border-radius: 32px;
      overflow: hidden;
      display: grid;
      place-items: center;
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.42);
    }

    .hero-visual::before {
      content: "";
      position: absolute;
      inset: 0;
      background-image: radial-gradient(circle at top left, rgba(138, 138, 138, 0.12), transparent 28%),
        radial-gradient(circle at bottom right, rgba(138, 138, 138, 0.10), transparent 20%);
    }

    .hero-card {
      position: relative;
      width: 100%;
      max-width: 380px;
      border-radius: 28px;
      padding: 28px;
      background: rgba(255, 255, 255, 0.9);
      box-shadow: 0 32px 70px rgba(31, 77, 157, 0.15);
      z-index: 1;
    }

    .hero-card h2 {
      margin: 0 0 14px;
      font-size: 1.35rem;
    }

    .hero-card p {
      margin: 0;
      color: var(--muted);
      line-height: 1.75;
      font-size: 0.98rem;
    }

    .section-heading {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      margin-bottom: 20px;
    }

    .section-heading h3 {
      margin: 0;
      font-size: 1.5rem;
    }

    .section-heading small {
      color: var(--muted);
    }

    .featured-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 20px;
      margin-bottom: 40px;
    }

    .venue-card {
      position: relative;
      border-radius: 24px;
      overflow: hidden;
      min-height: 220px;
      background: linear-gradient(180deg, #fbf7f2 0%, var(--surface) 100%);
      border: 1px solid var(--venusep-border);
      display: flex;
      align-items: flex-end;
      padding: 18px;
      color: var(--venusep-black);
      font-weight: 700;
      font-size: 0.95rem;
    }

    .venue-card::before {
      content: "Image";
      position: absolute;
      inset: 0;
      display: grid;
      place-items: center;
      color: rgba(31, 77, 157, 0.26);
      font-size: 1.3rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      background: repeating-linear-gradient(135deg, rgba(255,255,255,0.12), rgba(255,255,255,0.12) 1px, transparent 1px, transparent 20px), linear-gradient(180deg, rgba(255,255,255,0.08), transparent 45%);
    }

    .venue-card span {
      position: relative;
      z-index: 1;
    }

    .listing-placeholder {
      padding: 30px;
      border-radius: 24px;
      background: var(--surface);
      box-shadow: var(--shadow);
      border: 1px solid rgba(31, 77, 157, 0.08);
    }

    .listing-placeholder h4 {
      margin: 0 0 16px;
      font-size: 1.2rem;
    }

    .listing-placeholder p {
      margin: 0;
      color: var(--muted);
      line-height: 1.8;
    }

    .listing-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 20px;
      margin-top: 20px;
    }

    .listing-item {
      border-radius: 22px;
      padding: 22px;
      background: var(--surface-2);
      border: 1px dashed rgba(31, 77, 157, 0.12);
      min-height: 170px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      color: var(--muted);
      font-size: 0.98rem;
    }

    a.listing-item { text-decoration: none; cursor: pointer; transition: border-color 0.2s ease, transform 0.2s ease; }
    a.listing-item:hover { border-color: var(--venusep-black); transform: translateY(-2px); }

    /* USeP Hostel: the two CR types are shown apart, because which bathroom you
       get is the actual choice a guest makes (and it is what the price turns on). */
    .hostel-type-head {
      display: flex;
      align-items: baseline;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 28px;
      padding-bottom: 10px;
      border-bottom: 1px solid rgba(0, 0, 0, 0.08);
    }
    .hostel-type-name { color: var(--text); font-size: 1rem; font-weight: 700; }
    .hostel-type-note { color: var(--muted); font-size: 0.85rem; }
    /* free beds tonight — a counted FACT, which is why it names the night it
       refers to. Occupancy is a room x night thing; no single label is true. */
    .hostel-free {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      margin-top: 12px;
      color: var(--text);
      font-size: 0.85rem;
      font-weight: 600;
    }
    .hostel-free::before {
      content: '';
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #2f9e63;
      flex: none;
    }
    .hostel-free.is-none { color: var(--muted); font-weight: 500; }
    .hostel-free.is-none::before { background: #b23a3a; }

    .listing-item strong {
      margin-bottom: 10px;
      display: block;
      color: var(--text);
      font-size: 1.05rem;
    }

    footer {
      margin-top: 64px;
      background: var(--bg);
    }

    .footer-bottom {
      max-width: 1240px;
      margin: 0 auto;
      padding: 24px 24px 0;
      padding-top: 48px;
      border-top: 1px solid var(--venusep-border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.9rem;
      color: var(--muted);
    }

    @media (max-width: 960px) {
      .hero {
        grid-template-columns: 1fr;
      }

      .featured-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .listing-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 620px) {
      .page {
        padding: 24px 18px 32px;
      }

      .hero {
        padding: 28px;
      }

      .search-row {
        grid-template-columns: 1fr;
      }

      .hero-visual {
        min-height: 360px;
      }
    }
  </style>
</head>
<body>
  <main class="page">
    <div class="topbar">
      <a class="brand" href="#">
        <div class="brand-mark">
          <img src="../logo/Logo Header 3.png" alt="Venusep logo" />
        </div>
      </a>
      <a class="user-profile" href="customer-profile.php">
        <div class="user-avatar">JD</div>
        <div class="user-info">
          <span>Jane Doe</span>
          <small>View Profile</small>
        </div>
      </a>
    </div>
    <section class="hero">
      <div class="hero-copy">
        <span class="eyebrow">Ready to book</span>
        <h1>Venue booking for USeP events</h1>
        <p>Discover the best halls, gardens, and event spaces across campus with a fresh online booking experience crafted for students and organizers.</p>

        <div class="search-panel">
          <div class="search-row">
            <div class="search-field"><strong>Type</strong><span>FTC Hall</span></div>
            <div class="search-field"><strong>Location</strong><span>USeP Campus</span></div>
            <div class="search-field"><strong>Date</strong><span>Nov 12, 2026</span></div>
          </div>
          <button class="search-cta" type="button" onclick="document.getElementById('venue-listings').scrollIntoView({ behavior: 'smooth' })">Find venue</button>
        </div>
      </div>

      <div class="hero-visual">
        <div class="hero-card">
          <h2>Plan your next campus gathering</h2>
          <p>Browse venues by capacity, amenities, and availability. Reserve ahead and keep your event organized in one place.</p>
        </div>
      </div>
    </section>

    <section>
      <div class="section-heading">
        <div>
          <h3>Venues in USeP</h3>
          <small>Top campus spaces ready for booking</small>
        </div>
        <small>4 featured picks</small>
      </div>

      <div class="featured-grid">
        <div class="venue-card"><span>Bahay Alumni</span></div>
        <div class="venue-card"><span>PECC Gym</span></div>
        <div class="venue-card"><span>FTC Hall</span></div>
        <!-- the only one of these tiles that leads anywhere yet -->
        <a class="venue-card" href="#hostel-listings"><span>USeP Hostel</span></a>
      </div>
    </section>

    <section class="listing-placeholder" id="venue-listings">
      <h4>Rooms you can reserve</h4>
      <p>Pick a room to open its booking page — dates, times, and payment happen there.</p>
      <!-- Venue rooms come from the ONE shared source (includes/venue-rooms.php),
           the same data the booking page and admin Venue Management read. -->
      <div class="listing-grid">
<?php foreach ($venueRooms as $room): ?>
        <a class="listing-item" href="room-reservation.php?room=<?php echo urlencode($room['id']); ?>"><strong><?php echo htmlspecialchars($room['name']); ?></strong><?php echo htmlspecialchars($room['venue']); ?> · up to <?php echo (int) $room['capacity']; ?> guests · ₱<?php echo number_format($room['fee']); ?> per day</a>
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
    <section class="listing-placeholder" id="hostel-listings">
      <h4>USeP Hostel — book a bed</h4>
      <p>You reserve <strong>beds</strong>, not rooms. Others may book the remaining beds in the same room — book all six and it's yours. Priced per head, per night.</p>

<?php foreach (['communal', 'private'] as $crType):
        $rooms = hostelRoomsByType($hostelRooms, $crType);
        if (!$rooms) continue; ?>
      <div class="hostel-type-head">
        <span class="hostel-type-name"><?php echo htmlspecialchars($HOSTEL_CR_LABEL[$crType]); ?></span>
        <span class="hostel-type-note"><?php echo $crType === 'private' ? 'Bathroom inside the room' : 'Shared bathroom outside the room'; ?> · ₱<?php echo number_format($HOSTEL_RATES[$crType]); ?> per head, per night</span>
      </div>
      <div class="listing-grid">
<?php foreach ($rooms as $room):
          /* Tonight's free beds — COUNTED from the roster, never a stored number.
             It is a room x night fact, so the card says which night it means. */
          $tonight = date('Y-m-d');
          $free    = hostelBedsFree($room, $tonight);
          $mt      = $room['maintenance'];
          $closed  = $mt && $mt['blocks'] && hostelMaintCovers($mt, $tonight); ?>
        <a class="listing-item" href="hostel-reservation.php?room=<?php echo urlencode($room['id']); ?>">
          <strong><?php echo htmlspecialchars($room['name']); ?></strong>
          <?php echo (int) $room['beds']; ?> beds · ₱<?php echo number_format($HOSTEL_RATES[$room['cr_type']]); ?> per head, per night
          <span class="hostel-free<?php echo ($closed || !$free) ? ' is-none' : ''; ?>">
            <?php
              if ($closed)      echo 'Closed for maintenance tonight';
              elseif (!$free)   echo 'No beds free tonight';
              else              echo $free . ' of ' . (int) $room['beds'] . ' beds free tonight';
            ?>
          </span>
        </a>
<?php endforeach; ?>
      </div>
<?php endforeach; ?>
    </section>
    <footer>
      <div class="footer-bottom">
        <span>&copy; 2026 VENUSEP. All rights reserved.</span>
      </div>
    </footer>
  </main>
</body>
</html>
