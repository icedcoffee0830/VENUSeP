{{-- Terms and Conditions — public, static, no database. Linked from the two sign-up checkboxes.
     Says only what the system really does: check a rule in the code before adding it here.
     Same look as the privacy page (partials/legal-page-style). --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="darkreader-lock">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#7d1120">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VENUSeP | Terms and Conditions</title>
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
    <p class="pv-draft">Draft for the VENUSeP project. These terms have not yet been reviewed by the USeP offices.</p>

    <h1>Terms and Conditions</h1>
    <p class="pv-lead">The rules for booking a venue or a hostel bed through VENUSeP, in plain words.</p>
    <a class="pv-back" href="{{ route('customer.venues') . (counter_mode() ? '?counter=1' : '') }}">&larr; Back to venue booking</a>

    <section aria-labelledby="tc-booking">
      <h2 id="tc-booking">Booking and approval</h2>
      <ul>
        <li>A booking starts as a request. It holds nothing final until the office's staff check your valid ID and your details and approve it.</li>
        <li>You can book up to {{ (int) config('venusep.max_days_ahead') }} days ahead, at every office. The last day of your booking, or your check-out day, must fall inside that window.</li>
        <li>A venue booking must start far enough ahead. Each office sets this notice; the usual notice is 12 hours from the moment you book.</li>
        <li>The price comes from the room's current rate. If the rate changes while you are booking, your request is stopped and you are shown the new price before you submit again.</li>
        <li>You can hold up to {{ (int) config('venusep.max_unpaid') }} unpaid upcoming bookings at one office. Pay or cancel one before booking there again.</li>
        <li>The USeP discount is only a claim until staff verify your ID. Once your booking is approved, the rate on it does not change.</li>
      </ul>
    </section>

    <section aria-labelledby="tc-deadlines">
      <h2 id="tc-deadlines">Payment deadlines</h2>
      <p>Payment opens only after your booking is approved. When it is due depends on where you booked:</p>
      <ul>
        <li><strong>A venue at an office that takes refund requests:</strong> pay by 11:59 PM on the day before your first booked day. If it is still unpaid then, your date is released.</li>
        <li><strong>A venue at an office that does not take refund requests:</strong> you pay after your event. Payment opens the day after your last booked day, and your booking shows the date to pay by.</li>
        <li><strong>The USeP Hostel:</strong> you pay before you use the bed, by the end of your check-in day. The whole stay is paid at once. A stay still unpaid by then is released.</li>
      </ul>
      <p>A pay-after-your-event booking still unpaid past its deadline becomes Overdue. It can no longer be paid online, so please contact the office. Until it is settled you cannot book online with that office. Other offices are not affected.</p>
    </section>

    <section aria-labelledby="tc-paying">
      <h2 id="tc-paying">Paying by GCash or cash</h2>
      <ul>
        <li><strong>GCash:</strong> send the exact amount to the account shown on the payment page, then upload your receipt. The system checks it first, and staff confirm every GCash payment. You have 5 uploads per booking.</li>
        <li><strong>Cash:</strong> choose cash on the payment page and pay in person at the office, or at the front desk for the hostel. GCash stays closed while cash is chosen. You can cancel that choice until staff record your cash.</li>
        <li><strong>At the counter:</strong> staff can take your booking in person. A hostel stay taken at the counter is booked only once the cash is received.</li>
        <li><strong>POS:</strong> the hostel's POS is paperwork that staff record when it is issued. It does not hold up your payment or your check-in.</li>
      </ul>
    </section>

    <section aria-labelledby="tc-receipts">
      <h2 id="tc-receipts">Receipts</h2>
      <ul>
        <li><strong>Transaction Receipt:</strong> VENUSeP issues this once your payment is confirmed. You will find it in My bookings as your System Receipt. It is not an Official Receipt.</li>
        <li><strong>Official Receipt:</strong> this comes from the University Cashier after staff hand over your payment. It can arrive after your booking is paid, and your booking does not wait for it.</li>
      </ul>
    </section>

    <section aria-labelledby="tc-cancel">
      <h2 id="tc-cancel">Cancellations</h2>
      <ul>
        <li>You can cancel a request yourself in My bookings while it is still pending.</li>
        <li>Once a booking is approved, you cannot cancel it yourself. Please contact the office.</li>
        <li>If your request is rejected, you can send a new ID from My bookings while your date has not yet come.</li>
      </ul>
    </section>

    <section aria-labelledby="tc-refunds">
      <h2 id="tc-refunds">Refunds</h2>
      <ul>
        <li>Each office decides whether it takes refund requests. Your booking keeps the rule that applied on the day you made it, even if the office changes it later.</li>
        <li>If the rule was off when you booked, your booking is final and cannot be refunded through VENUSeP for any reason. You are asked to confirm this before you submit.</li>
        <li>If the rule was on, you can ask for a refund from My bookings while your booking is approved and paid and its date has not been held. Staff decide the request, and your booking stays yours while they do.</li>
      </ul>
    </section>

    <section aria-labelledby="tc-id">
      <h2 id="tc-id">Valid ID</h2>
      <ul>
        <li>Every online booking needs a valid ID. Staff use it to confirm who you are and whether the USeP discount applies.</li>
        <li>At the counter, staff look at your ID in person.</li>
        <li>Your uploaded ID is removed once all processes for the booking are finished.</li>
      </ul>
    </section>

    <section aria-labelledby="tc-blocks">
      <h2 id="tc-blocks">Office blocks</h2>
      <ul>
        <li>An office can stop an account from making new bookings at that office, online or at the counter. You can still sign in, and other offices are not affected.</li>
        <li>Bookings that are already approved stay as they are.</li>
        <li>A pending request is not approved while the block stands. If the block is still there when the request's start time comes, the request is cancelled.</li>
        <li>You will see the office's contact details. Please contact the office about a block.</li>
      </ul>
    </section>

    <section aria-labelledby="tc-hostel">
      <h2 id="tc-hostel">Hostel stays</h2>
      <ul>
        <li>Check-in is from 2:00 PM and check-out is by 12:00 noon.</li>
        <li>A stay is counted in nights. Your check-out day is not charged.</li>
        <li>Every bed is booked under a guest's name. Show the staff the valid ID you submitted when you check in.</li>
        <li>Check-in needs a paid stay. A GCash receipt still under review is confirmed by staff first.</li>
      </ul>
    </section>

    <section aria-labelledby="tc-accounts">
      <h2 id="tc-accounts">Accounts</h2>
      <ul>
        <li>You need a customer account to book online. You can sign up with your email or with Google.</li>
        <li>Two-step verification is optional for customers.</li>
        <li>When staff book at the counter for your account, you confirm it with your password, or they check your ID if your account has no password.</li>
        <li>A disabled account cannot sign in.</li>
      </ul>
    </section>

    <p class="pv-updated">Last updated: <time datetime="2026-11-05">5 November 2026</time></p>
  </div>
</main>

<footer class="pv-footer">
  <div class="pv-wrap">&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</div>
</footer>
</body>
</html>
