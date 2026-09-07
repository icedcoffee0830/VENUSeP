<?php ?>
<!DOCTYPE html>
<!-- ==================================================================
  FAQ — customer help page (self-contained, no JS)
  Linked from USeP Room Reservation.html; the accordions are native
  details/summary elements, so no script is needed. Sections: booking /
  GCash / cash walk-in / after you book & refunds.
  ================================================================== -->
<html lang="en">
<head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VENUSeP | FAQ</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  body{margin:0;background:#fff;color:#1c1b19;font-family:'Inter',system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
  a{color:#1f2a44;text-decoration:none}
  a:hover{color:#2f3f66}

  /* accordion (native <details>, flat like the reservation page) */
  .faq details{border-bottom:1px solid rgba(0,0,0,.08)}
  .faq summary{cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:14px;padding:15px 0;font-size:14.5px;font-weight:600;color:#1c1b19}
  .faq summary::-webkit-details-marker{display:none}
  .faq summary::after{content:'+';font-size:18px;font-weight:400;color:#8a857d;flex:none;line-height:1}
  .faq details[open] summary::after{content:'\2013'}
  .faq .a{margin:0 0 15px;font-size:13.5px;line-height:1.65;color:#4a463f;max-width:72ch}
  .faq h2{margin:0 0 4px;font-size:13px;font-weight:600;color:#7a766f;text-transform:uppercase;letter-spacing:.05em}
  .faq section{margin-bottom:30px}
</style>
</head>
<body>

<header style="position:sticky;top:0;z-index:40;background:rgba(255,255,255,.92);backdrop-filter:blur(8px);border-bottom:1px solid rgba(0,0,0,.08)">
  <div style="padding:14px 40px;display:flex;align-items:center;gap:16px">
    <a href="venusep_venue_booking.php" style="display:flex;align-items:center;gap:11px">
      <span style="width:34px;height:34px;border-radius:8px;background:#1f2a44;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;letter-spacing:.02em">US</span>
      <span style="line-height:1.15;font-weight:650;font-size:14.5px;color:#1c1b19">VENUSeP</span>
    </a>
    <div style="flex:1"></div>
    <a href="venusep_venue_booking.php" style="font-size:13.5px;font-weight:600">← Back to venue booking</a>
  </div>
</header>

<main class="faq" style="max-width:760px;margin:0 auto;padding:32px 24px 72px">
  <h1 style="margin:0 0 6px;font-size:24px;font-weight:700;letter-spacing:-.01em">Frequently asked questions</h1>
  <p style="margin:0 0 28px;font-size:14px;color:#7a766f;max-width:70ch">Everything about booking a room, paying, and what happens after you submit.</p>

  <section>
    <h2>Booking a room</h2>
    <details>
      <summary>How early do I need to book?</summary>
      <p class="a">Reservations must start <strong>at least 12 hours from the moment you book</strong>, to give venue staff time to prepare. If you pick a date or time that is too soon, the system tells you the earliest start you can choose.</p>
    </details>
    <details>
      <summary>Why does it say a date is "not available"?</summary>
      <p class="a">That date already has a reservation for the room, and a booked date cannot be reserved again. Pick a different date — or, in a multi-day booking, just keep your range: the booked date is left out automatically.</p>
    </details>
    <details>
      <summary>Can I reserve several days at once?</summary>
      <p class="a">Yes. Pick a start and end date, then set the hours for each day (or use "Apply Day 1 to all"). If a date in the middle of your range is already booked, it is excluded automatically — you keep the rest of the days and are <strong>not charged</strong> for the unavailable one.</p>
    </details>
    <details>
      <summary>How is the reservation fee computed?</summary>
      <p class="a">Each room has a fee per day. Your total is that fee times the number of days you actually book — excluded (already-booked) days are never counted. The exact total is always shown before you pay.</p>
    </details>
    <details>
      <summary>What if my group is bigger than the room capacity?</summary>
      <p class="a">You can still submit the request, but the system warns you and venue staff may reject an over-capacity booking. Consider a larger room — each room card on the venue page shows its capacity.</p>
    </details>
    <details>
      <summary>Why do I need to upload a valid ID?</summary>
      <p class="a">Staff verify your identity before approving any reservation. Attach a photo of your <strong>USeP ID or any government-issued ID</strong> when you submit the request. Your booking stays <strong>pending</strong> — and the payment step stays locked — until a coordinator approves both your ID and the reservation.</p>
    </details>
    <details>
      <summary>When do I have to pay?</summary>
      <p class="a">Payment opens only <strong>after your request is approved</strong>. You must pay at least <strong>1 day before your event</strong> — bookings made closer to the event than that pay immediately upon approval. Reservations left unpaid past the deadline may be released back to availability.</p>
    </details>
  </section>

  <section>
    <h2>Paying with GCash</h2>
    <details>
      <summary>How do I pay with GCash?</summary>
      <p class="a">Once staff approve your ID and reservation, the payment step unlocks. Send the amount shown to the GCash account on screen, take a screenshot of your GCash receipt, and upload it. The system reads the receipt instantly and tells you on the spot whether it was accepted.</p>
    </details>
    <details>
      <summary>Why must I send the EXACT amount?</summary>
      <p class="a">The automatic check compares the amount on your receipt with the reservation fee — <strong>any other amount, higher or lower, is rejected</strong>. This avoids partial payments and refund complications.</p>
    </details>
    <details>
      <summary>What is the "Reference to include" (USEP-######)?</summary>
      <p class="a">That is your <strong>booking code</strong> in this system. Type it into the message/note field when you send the GCash payment so staff can match your payment to your booking. It is different from GCash's own 13-digit reference number, which GCash prints on the receipt automatically.</p>
    </details>
    <details>
      <summary>What does the system check on my receipt?</summary>
      <p class="a">That the image is a real GCash send-money receipt; the GCash reference number; the <strong>exact amount</strong>; that the money went to the correct account; and the receipt date. It also rejects a receipt that was already submitted before (same image or same reference number).</p>
    </details>
    <details>
      <summary>My receipt says "needs manual review" — is my booking lost?</summary>
      <p class="a">No. It means some details could not be read clearly from the screenshot (or the receipt looks older than usual), so a staff member will verify it by hand. Your booking is submitted and simply waits for that review.</p>
    </details>
    <details>
      <summary>My receipt was rejected. What do I do?</summary>
      <p class="a">Check the reasons listed under the result — usually a wrong amount, money sent to a different account, or a receipt that was already used. Fix the issue (send the correct payment if needed), then upload a new screenshot with the reference number and amount readable. You have a <strong>48-hour resubmission window</strong> (within your payment deadline) before the slot may be released.</p>
    </details>
  </section>

  <section>
    <h2>Paying in cash (walk-in)</h2>
    <details>
      <summary>Can I pay in cash instead of GCash?</summary>
      <!-- [SIM] Office details below mirror CASH_PAY in "USeP Room Reservation.html" —
           still a PLACEHOLDER; keep both in sync when the real location is decided. -->
      <p class="a">Yes. Choose <strong>"Cash — walk-in"</strong> at the payment step. Your reservation is submitted and held, and you pay in person at <strong>USeP Cashier — Venue Reservations Window</strong> (Ground Floor, Administration Building, USeP Obrero Campus, Davao City · Mon–Fri, 8:00 AM – 5:00 PM). Quote your booking reference and bring a valid ID. The booking is confirmed once the cashier records your payment — pay before your event date, or the slot may be released.</p>
    </details>
  </section>

  <section>
    <h2>After you book · refunds</h2>
    <details>
      <summary>What happens after I submit my reservation?</summary>
      <p class="a">Two stages. First, staff review your <strong>ID and reservation</strong> — until they approve, your request is pending and payment is locked. Once approved, you pay (GCash or cash), and staff then verify the payment itself — the GCash reference in the business account, or the cashier record — before the booking is finally confirmed. You are notified at each step.</p>
    </details>
    <details>
      <summary>How do refunds work?</summary>
      <p class="a">A refund requires <strong>both</strong> receipts: the system transaction receipt <strong>and</strong> your GCash receipt (for cash payments, the official receipt from the counter). A request missing either document cannot be processed, so keep both safe.</p>
    </details>
  </section>

  <div style="background:#fff;border:1px solid rgba(0,0,0,.09);border-radius:12px;padding:15px 16px;font-size:13px;color:#4a463f;line-height:1.6">Still need help? Reach the venue coordination office through your USeP account email, or visit the USeP Cashier — Venue Reservations Window during office hours.</div>
</main>

</body>
</html>
