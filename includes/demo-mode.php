<?php
/* =====================================================================
   DEMO MODE — a showcase that changes nothing.

   THE REQUIREMENT, in the user's words: run the same demo ten times and
   the tenth is identical to the first. Not "clean up afterwards" — never
   write in the first place.

   HOW: reads stay real (rooms, rates, availability, accounts, the
   discount are all the live database). Transactional WRITES go into
   $_SESSION instead, and the customer's own views merge that overlay on
   top so the flow behaves exactly as it would for real. Ending the
   showcase, or simply closing the browser, evaporates it.

   ---------------------------------------------------------------------
   THE LINE: TRANSACTIONS vs CONFIGURATION
   ---------------------------------------------------------------------
   INTERCEPTED (a showcase must not leave these behind):
     customer/booking-submit.php   submitting a booking
     customer/payment-submit.php   recording a payment or receipt
     customer/refund-submit.php    filing / withdrawing / resubmitting
     admin/booking-action.php      every staff action, refunds included

   NEVER INTERCEPTED (setting the system up is not a showcase, and
   blocking it would stop an admin working while demo mode is on):
     profile-save.php, register-submit.php, staff-save.php,
     venue-save.php, room-save.php, gcash-account-save.php,
     discount-save.php, refund-switch.php, faq-save.php

   Registration is the deliberate exception: an account that vanishes is
   not an account, so it writes for real even here.

   ---------------------------------------------------------------------
   WHY A SESSION AND NOT A FLAGGED ROW
   ---------------------------------------------------------------------
   Marking demo rows in the database and deleting them later was
   considered and rejected: it means a schema column for a feature that
   is expected to be removed, and "delete them later" is exactly the
   promise that gets forgotten. Nothing written is nothing to clean up.

   THE COST, accepted: a booking made during a demo never reaches the
   staff queue, because it was never written. Demo that cross-portal
   moment with a seeded booking instead.

   ⚠️ DANGER: demo mode left ON in production means customers book and
   nothing is recorded — they get a reference for a booking that does not
   exist. That is why the toggle is admin-only, password-confirmed, and
   why every page shows a banner while it is on.
   ===================================================================== */

require_once __DIR__ . '/db.php';

/* Is the showcase switch on? One read per request. */
function demo_mode_on()
{
    static $on = null;
    if ($on !== null) {
        return $on;
    }
    $on = false;
    $pdo = venusep_db();
    if ($pdo === null) {
        return $on;                 // unreachable database: behave normally, not demo-ly
    }
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'demo_mode' LIMIT 1");
        $stmt->execute();
        $on = $stmt->fetchColumn() === '1';
    } catch (PDOException $e) {
        $on = false;                // FAIL SAFE: a hiccup must never silently start discarding writes
    }
    return $on;
}

/* Who last flipped the switch and when — the Demo Mode card shows it for the
   same reason the refund card does: a setting this consequential should never
   be anonymous. null = the database could not be read. */
function demo_setting_details()
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    $pdo = venusep_db();
    if ($pdo === null) {
        return $cache;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT s.setting_value, s.updated_at, u.email AS updated_by_email
               FROM system_settings s
               LEFT JOIN users u ON u.id = s.updated_by_user_id
              WHERE s.setting_key = 'demo_mode'
              LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row) {
            $cache = [
                'enabled'    => $row['setting_value'] === '1',
                'updated_at' => $row['updated_at'],
                'updated_by' => $row['updated_by_email'],   // null = never changed since setup
            ];
        }
    } catch (PDOException $e) {
        $cache = null;
    }
    return $cache;
}

/* ---------------------------------------------------------------------
   THE OVERLAY — everything a demo "wrote", for this browser only.
   --------------------------------------------------------------------- */

function demo_store()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        venusep_session_start();
    }
    if (!isset($_SESSION['demo']) || !is_array($_SESSION['demo'])) {
        $_SESSION['demo'] = ['bookings' => [], 'seq' => 0];
    }
    return $_SESSION['demo'];
}

function demo_save(array $store)
{
    $_SESSION['demo'] = $store;
}

/* Wipes the showcase. Called by "End showcase" and whenever demo mode is
   switched off, so the next person does not inherit someone else's props. */
function demo_reset()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        venusep_session_start();
    }
    unset($_SESSION['demo']);
}

/* A reference for a pretend booking. Deliberately NOT the real
   'VB-<year>-<id>' shape: a demo reference must never be mistaken for a real
   one in a screenshot, a support email or a database search. The D says so. */
function demo_reference($seq)
{
    return 'VB-' . date('Y') . '-D' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
}

/* Record a booking the demo "made". $row is the same shape the read path
   produces (see booking_shape()), so pages render it without special cases. */
function demo_add_booking(array $row)
{
    $store = demo_store();
    $store['seq']++;
    $row['id']        = -$store['seq'];          // negative: can never collide with a real id
    $row['bookingId'] = demo_reference($store['seq']);
    $row['isDemo']    = true;
    $store['bookings'][$row['bookingId']] = $row;
    demo_save($store);
    return $row;
}

/* Change a demo booking in place — a payment recorded, a refund filed. */
function demo_update_booking($reference, array $changes)
{
    $store = demo_store();
    if (!isset($store['bookings'][$reference])) {
        return null;
    }
    $store['bookings'][$reference] = array_merge($store['bookings'][$reference], $changes);
    demo_save($store);
    return $store['bookings'][$reference];
}

function demo_get_booking($reference)
{
    $store = demo_store();
    return isset($store['bookings'][$reference]) ? $store['bookings'][$reference] : null;
}

/* Everything this browser has pretended to book, newest first — merged into
   the customer's own history, ledger and calendar so the flow looks whole. */
function demo_bookings()
{
    $store = demo_store();
    return array_reverse(array_values($store['bookings']));
}

/* Is this reference one of ours? Lets an endpoint route a payment or a refund
   to the overlay without re-reading the switch. */
function demo_owns($reference)
{
    return demo_mode_on() && demo_get_booking($reference) !== null;
}

/* ---------------------------------------------------------------------
   THE BANNER — printed by includes/header.php and customer-nav.php.
   Loud on purpose. A quiet demo mode is a trap: customers would book and
   receive a reference for something that was never recorded.
   --------------------------------------------------------------------- */
function demo_banner_html()
{
    if (!demo_mode_on()) {
        return '';
    }
    return '<div role="status" style="position:sticky;top:0;z-index:2000;display:flex;align-items:center;'
         . 'justify-content:center;gap:.6rem;padding:.45rem .9rem;background:#8a5a12;color:#fff;'
         . 'font:600 13px/1.4 Inter,system-ui,sans-serif;text-align:center">'
         . '<span aria-hidden="true">&#9888;</span>'
         . '<span>DEMO MODE — bookings, payments and refunds are <strong>not being saved</strong>. '
         . 'Nothing on this screen reaches the database.</span>'
         . '</div>';
}
