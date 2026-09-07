<?php
/* The ONE source of the GCash accounts. This page is the screen that edits it. */
include __DIR__ . '/../includes/payment-settings.php';
?>
<!DOCTYPE html>
<!-- ==================================================================
  PAYMENT SETTINGS — VENUSeP merged system
  ==================================================================
  MAP OF THIS FILE — Ctrl+F the [n] tag to jump to a section:

    [0] SHELL CSS     team header + sidebar styles (same on every page)
    [1] PAGE CSS      this page's own styles
    [2] HEADER BAR    team top bar (same on every page)
    [3] SIDEBAR       team dark menu w/ logo (same on every page)
    [4] PAGE CONTENT  one card per venue: GCash name + number
    [6] PAGE SCRIPT   edit / save / cancel, live preview of what changes

  WHY THIS PAGE EXISTS
  --------------------
  The GCash number is used in TWO places that must never disagree:
    1. the payment screen TELLS the customer where to send the money
    2. the receipt checker VERIFIES the money landed there
  Both read includes/payment-settings.php. Change the number here and both
  move together. If they ever diverged, the customer would pay exactly what
  they were shown and the checker would auto-reject it as "receiver
  mismatch" — a bug that looks like OCR failing and is nearly unfindable.

  THREE ACCOUNTS, ONE PER VENUE. Bahay Alumni and USeP Venues used to share
  one; they are separate now, so a receipt paid to the wrong venue's account
  is correctly rejected.

  [SIM] nothing persists — Save updates the page only. Wire to a
  `payment_settings` table keyed by venue when the database exists.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | Payment Settings</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />

    <!-- ============================================================
         [0] SHELL CSS — the TEAM header bar + sidebar + layout.
         Same block on every page.
         ============================================================ -->
    <style>
      :root {
        --venusep-black: #1f1e1e;
        --venusep-cream: #ffffff;
        --venusep-border: #ddd7ce;
        --venusep-text: #050505;
        --venusep-sidebar-width: 235px;
        --venusep-header-height: 58px;
      }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body {
        background: #fff;
        color: var(--venusep-text);
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        overflow-x: hidden;
      }
      /* (header + sidebar styles live in ../includes/ — the shared includes) */
      .app-main {
        margin-left: var(--venusep-sidebar-width);
        padding-top: var(--venusep-header-height);
        min-height: 100vh;
        background: #fff;
      }
      .container-fluid { width: 100%; padding-inline: 30px; } /* uniform content inset — 30px sides on every admin page */
      .app-content-header { padding: 26px 0 0; }              /* 26px top, same as other pages */
      .app-content { padding: 0.25rem 0 3rem; }
      @media (max-width: 767.98px) {
        :root { --venusep-sidebar-width: 0px; --venusep-header-height: 56px; }
        .sidebar { transform: translateX(-100%); }
        .app-header { left: 0; }
        .app-main { margin-left: 0; }
        .user-name { display: none; }
      }
    </style>

    <!-- ============================================================
         [1] PAGE CSS — same tokens and the same neutral-surface look
         as Venue Management (hairlines, no filled tint boxes).
         ============================================================ -->
    <style>
      :root {
        --ps-border: #e5e5e5;
        --ps-muted: #6b675f;
        --ps-text: #1c1b19;
        --ps-dark: #1f1e1e;
        --ps-warn: #ff6b12;
        --ps-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
      }
      .ps-header { align-items: flex-start; display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; margin-bottom: 1.4rem; }
      .ps-heading h1 { font-size: 1.6rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 0.25rem; }
      .ps-heading p { color: var(--ps-muted); font-size: 0.88rem; margin: 0; }

      /* the "why this matters" note — a plain hairline panel, not a tint box */
      .ps-why {
        border: 1px solid var(--ps-border);
        border-radius: 12px;
        color: var(--ps-muted);
        font-size: 0.82rem;
        line-height: 1.6;
        margin-bottom: 1.4rem;
        max-width: 780px;
        padding: 0.85rem 1rem;
      }
      .ps-why strong { color: var(--ps-text); font-weight: 650; }

      .ps-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); }

      .ps-card {
        background: #fff;
        border: 1px solid var(--ps-border);
        border-radius: 14px;
        box-shadow: var(--ps-shadow);
        display: flex;
        flex-direction: column;
        padding: 1.1rem 1.15rem 1rem;
      }
      .ps-venue { align-items: center; display: flex; gap: 0.55rem; margin-bottom: 0.15rem; }
      .ps-venue h2 { font-size: 1rem; font-weight: 680; letter-spacing: -0.01em; margin: 0; }
      .ps-kind {
        border: 1px solid var(--ps-border);
        border-radius: 999px;
        color: var(--ps-muted);
        font-size: 0.68rem;
        font-weight: 600;
        padding: 0.12rem 0.5rem;
      }
      .ps-note { color: var(--ps-muted); font-size: 0.76rem; line-height: 1.55; margin: 0.35rem 0 0.9rem; }

      /* label-over-value, open — no grey filled boxes */
      .ps-kv { border-top: 1px solid #f0efec; padding: 0.6rem 0; }
      .ps-kv .k { color: #a5a19a; font-size: 0.66rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; }
      .ps-kv .v { color: var(--ps-text); font-size: 0.95rem; font-weight: 640; margin-top: 0.15rem; }
      .ps-kv .v.mono { font-variant-numeric: tabular-nums; letter-spacing: 0.02em; }

      .ps-control {
        background: #fff;
        border: 1px solid rgba(0, 0, 0, 0.14);
        border-radius: 8px;
        font: inherit;
        font-size: 0.9rem;
        height: 38px;
        margin-top: 0.2rem;
        padding: 0 0.6rem;
        width: 100%;
      }
      .ps-control:focus { outline: 2px solid rgba(31, 42, 68, 0.35); outline-offset: 0; }
      .ps-err { color: #b23a3a; font-size: 0.72rem; margin-top: 0.3rem; }

      .ps-foot { align-items: center; display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 0.9rem; padding-top: 0.8rem; border-top: 1px solid #f0efec; }
      .ps-btn {
        background: #fff;
        border: 1px solid var(--ps-border);
        border-radius: 9px;
        color: var(--ps-text);
        cursor: pointer;
        font: inherit;
        font-size: 0.8rem;
        font-weight: 600;
        padding: 0.45rem 0.85rem;
        text-decoration: none;
      }
      .ps-btn:hover { background: #fbfbfa; }
      .ps-btn-primary { background: var(--ps-dark); border-color: var(--ps-dark); color: #fff; }
      .ps-btn-primary:hover { background: #343333; }

      /* what changing this number actually does — asked, not decorated */
      .ps-effect { align-items: flex-start; display: flex; gap: 0.5rem; font-size: 0.74rem; color: var(--ps-muted); line-height: 1.5; margin-top: 0.55rem; }
      .ps-effect::before { background: var(--ps-warn); border-radius: 50%; content: ''; flex: none; height: 6px; margin-top: 0.35rem; width: 6px; }
      .ps-saved { color: #1c7a4f; font-size: 0.74rem; font-weight: 600; margin-right: auto; }
    </style>
  </head>

  <body>
    <div class="app-wrapper">
<!-- ==========================================================
           [2] HEADER BAR — the ONE shared top bar.
           ========================================================== -->
      <?php include __DIR__ . '/../includes/header.php'; ?>

<!-- ==========================================================
           [3] SIDEBAR — the ONE shared sidebar.
           ========================================================== -->
      <?php $active = 'Payment Settings'; include __DIR__ . '/../includes/sidebar.php'; ?>

<!-- ==========================================================
           [4] PAGE CONTENT — one card per venue. Rendered from PHP so
           the cards and the booking pages read the same array.
           ========================================================== -->
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="ps-header">
              <div class="ps-heading">
                <h1>Payment Settings</h1>
                <p>The GCash account each venue's payments go to.</p>
              </div>
            </div>

            <div class="ps-why">
              Each number below is used <strong>twice</strong>: the booking page tells the customer where to send the money, and the receipt checker verifies it landed there. Both read the same value, so they cannot drift apart — change it here and both move together. <strong>A wrong number does not fail loudly:</strong> the customer pays exactly what they were shown and every receipt is auto-rejected as “receiver mismatch”, which looks like the scanner is broken.
            </div>
          </div>
        </div>

        <div class="app-content">
          <div class="container-fluid">
            <div class="ps-grid" id="psGrid">
<?php foreach ($gcAccounts as $venue => $acct):
        $isHostel = $venue === 'USeP Hostel'; ?>
              <div class="ps-card" data-venue="<?php echo htmlspecialchars($venue); ?>">
                <div class="ps-venue">
                  <h2><?php echo htmlspecialchars($venue); ?></h2>
                  <span class="ps-kind"><?php echo $isHostel ? 'Staff account' : 'Business account'; ?></span>
                </div>
                <p class="ps-note"><?php echo htmlspecialchars($acct['note']); ?></p>

                <div class="ps-kv">
                  <div class="k">GCash account name</div>
                  <div class="v" data-f="name"><?php echo htmlspecialchars($acct['name']); ?></div>
                </div>
                <div class="ps-kv">
                  <div class="k">GCash number</div>
                  <div class="v mono" data-f="number"><?php echo htmlspecialchars(gcFormatNumber($acct['number'])); ?></div>
                </div>

                <div class="ps-foot">
                  <button type="button" class="ps-btn" onclick="psEdit(this)">Edit</button>
                </div>
              </div>
<?php endforeach; ?>
            </div>
          </div>
        </div>
      </main>
    </div>

<!-- ============================================================
         [6] PAGE SCRIPT — edit in place. [SIM] Save updates the card
         only; nothing persists and no booking page is affected until
         includes/payment-settings.php is backed by a database.
         ============================================================ -->
    <script>
      /* The checker compares DIGITS, never the pretty form — so a number is
         valid on its digits alone. 11 digits starting 09 is the PH mobile
         format GCash uses; anything else would silently fail every receipt. */
      function psDigits(s) { return String(s).replace(/\D+/g, ''); }
      function psPretty(s) { return psDigits(s).replace(/^(\d{4})(\d{3})(\d{4})$/, '$1 $2 $3'); }
      function psValid(s) { const d = psDigits(s); return d.length === 11 && d.startsWith('09'); }
      function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

      function psField(card, f) { return card.querySelector('[data-f="' + f + '"]'); }

      function psEdit(btn) {
        const card = btn.closest('.ps-card');
        if (card.querySelector('.ps-control')) return;          // already editing
        const name = psField(card, 'name'), number = psField(card, 'number');
        card.dataset.oldName = name.textContent;
        card.dataset.oldNumber = number.textContent;

        name.innerHTML = '<input class="ps-control" data-i="name" value="' + esc(card.dataset.oldName) + '" placeholder="Name exactly as it shows on GCash">';
        number.innerHTML = '<input class="ps-control" data-i="number" value="' + esc(card.dataset.oldNumber) + '" placeholder="09xx xxx xxxx" inputmode="numeric">'
          + '<div class="ps-err" data-e hidden></div>';

        card.querySelector('.ps-foot').innerHTML =
          '<span class="ps-saved" data-s hidden>Saved</span>'
          + '<button type="button" class="ps-btn" onclick="psCancel(this)">Cancel</button>'
          + '<button type="button" class="ps-btn ps-btn-primary" onclick="psSave(this)">Save</button>';

        const eff = document.createElement('div');
        eff.className = 'ps-effect';
        eff.dataset.eff = '1';
        eff.textContent = 'Changing this changes what customers are told to pay AND what the checker accepts. Existing unpaid bookings for this venue will expect the new account.';
        card.querySelector('.ps-foot').before(eff);
        card.querySelector('[data-i="name"]').focus();
      }

      function psCancel(btn) {
        const card = btn.closest('.ps-card');
        psRestore(card, card.dataset.oldName, card.dataset.oldNumber, false);
      }

      function psSave(btn) {
        const card = btn.closest('.ps-card');
        const name = card.querySelector('[data-i="name"]').value.trim();
        const raw = card.querySelector('[data-i="number"]').value;
        const err = card.querySelector('[data-e]');

        /* Refuse to save something the checker could never match. Saving a
           malformed number would not error anywhere — it would just reject
           every receipt for this venue, quietly. */
        if (!name) { err.textContent = 'The account name cannot be empty — the checker matches it when the number is masked on a receipt.'; err.hidden = false; return; }
        if (!psValid(raw)) { err.textContent = 'Needs 11 digits starting with 09. The checker compares digits, so a number in any other shape rejects every receipt.'; err.hidden = false; return; }
        err.hidden = true;
        psRestore(card, name, psPretty(raw), true);
      }

      function psRestore(card, name, number, saved) {
        psField(card, 'name').textContent = name;
        psField(card, 'number').textContent = number;
        const eff = card.querySelector('[data-eff]');
        if (eff) eff.remove();
        card.querySelector('.ps-foot').innerHTML =
          (saved ? '<span class="ps-saved">Saved <span style="font-weight:400;color:#a5a19a">· [SIM] not persisted</span></span>' : '')
          + '<button type="button" class="ps-btn" onclick="psEdit(this)">Edit</button>';
      }
    </script>
  </body>
</html>
