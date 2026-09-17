<?php
/* =====================================================================
   REFUND POLICY — is the customer refund module switched on?
   THE ONE SOURCE for the switch, read from system_settings.refunds_enabled.

   Included by:
     includes/customer-bookings.php  (so every page that knows the customer's
                                      bookings also knows the policy)
     admin/payment-settings.php      (the switch itself)

   DECIDED 2026-09-16 (USeP does not do refunds):
     · ONE global switch, admin-only, DEFAULT OFF.
     · It covers CUSTOMER-REQUESTED refunds only. A closure by USeP
       (maintenance etc.) is never affected: those customers are offered a
       replacement room or a new date, and refunded if they decline both.
     · Each booking snapshots the switch when it is MADE
       (bookings.refunds_allowed). Flipping it later never grants or removes
       refunds on an existing booking — customers are held to the policy
       they agreed to.
     · Open requests are finished by staff even after the switch goes OFF.

   FAIL SAFE: if the database cannot be reached this reports OFF. Wrongly
   saying "non-refundable" is recoverable; wrongly accepting a refund
   request the school will not honour is not.

   This is the ONE part of the refund flow that is real. Bookings themselves
   are still [SIM] (includes/customer-bookings.php).
   ===================================================================== */
require_once __DIR__ . '/db.php';

/* Full details for the admin card; null when the database is unreachable. */
function refund_setting_details()
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
              WHERE s.setting_key = 'refunds_enabled'
              LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row) {
            $cache = [
                'enabled' => $row['setting_value'] === '1',
                'updated_at' => $row['updated_at'],
                'updated_by' => $row['updated_by_email'],   // null = never changed since setup
            ];
        }
    } catch (PDOException $e) {
        $cache = null;
    }
    return $cache;
}

function refunds_enabled()
{
    $d = refund_setting_details();
    return $d !== null && $d['enabled'];
}

$REFUNDS_ENABLED = refunds_enabled();
