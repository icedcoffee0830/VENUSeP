<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use PDO;
use PDOException;

/* Payment Settings (was admin/payment-settings.php). Route middleware: staff
   (staff see the refund card read-only; every write re-checks on the server:
   RefundSwitchController, GcashAccountSaveController). */
class PaymentSettingsController
{
    public function __invoke(Request $request)
    {
        /* The ONE source of the GCash accounts. This page is the screen that edits it. */
        /* EVERY booking, not the signed-in person's: the OFF warning counts how many
           bookings keep their refund right, a question about the whole system. */
        extract(legacy('payment-settings', 'bookings'));   // $gcAccounts, $POSTPAY_GRACE_DAYS

        $rsDetails  = refund_setting_details();          // null = database unreachable
        $rsDbOk     = $rsDetails !== null;
        $rsEnabled  = $rsDbOk && $rsDetails['enabled'];
        $rsIsAdmin  = admin_is_admin();                  // staff see the card read-only
        $rsCsrf     = csrf_token();
        $rsStayRefundable = count(array_filter(bookings_all(), function ($b) { return $b['refundable']; }));

        /* Refund requests still open — finished by staff even after the switch goes
           OFF (DB-DECISIONS #16), so this is a warning, not a blocker. */
        $rsOpenRefunds = 0;
        try {
            $rsOpenRefunds = (int) venusep_db()->query(
                "SELECT COUNT(*) FROM refunds WHERE refund_status IN ('requested','under_review','returned_for_correction','approved')"
            )->fetchColumn();
        } catch (PDOException $e) { $rsOpenRefunds = 0; }

        /* A lock still running from earlier (the countdown resumes after a reload). */
        $rsLockSeconds = 0;
        if ($rsDbOk && $rsIsAdmin) {
            try {
                $rsStmt = venusep_db()->prepare(
                    'SELECT CASE WHEN reauth_locked_until > NOW() THEN TIMESTAMPDIFF(SECOND, NOW(), reauth_locked_until) ELSE 0 END
                       FROM users WHERE id = :id'
                );
                $rsStmt->execute([':id' => (int) session('user_id')]);
                $rsLockSeconds = (int) $rsStmt->fetchColumn();
            } catch (PDOException $e) { $rsLockSeconds = 0; }
        }
        $rsJustSaved = $request->query('refunds') === 'saved';

        /* An active venue with no current account (one just added in Venue
           Management) still gets a card — otherwise nobody could ever set where
           it is paid, and its customers could never pay by GCash. */
        $psCards = $gcAccounts;
        try {
            foreach (venusep_db()->query(
                "SELECT v.name FROM venues v
                  WHERE v.is_active = 1" . staff_venue_sql('v.id = %d', 'AND') . "
                    AND NOT EXISTS (SELECT 1 FROM gcash_accounts g
                                     WHERE g.venue_id = v.id AND g.is_active = 1 AND g.valid_until IS NULL)
                  ORDER BY v.id"
            )->fetchAll(PDO::FETCH_COLUMN) as $psVenue) {
                $psCards[$psVenue] = ['name' => '', 'number' => '',
                    'note' => 'No GCash account yet: customers cannot pay this venue by GCash until one is set.'];
            }
        } catch (PDOException $e) { /* the venues that have one still show */ }

        /* Which cards are the hostel's (a staff GCash account): by venue_type, not
           by name — a renamed hostel stays the hostel. Cards are keyed by name. */
        $psHostelVenues = [];
        try {
            $psHostelVenues = venusep_db()->query("SELECT name FROM venues WHERE venue_type = 'hostel'")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) { /* every card reads as a business account */ }

        return view('admin.payment-settings', compact(
            'psCards', 'psHostelVenues','POSTPAY_GRACE_DAYS', 'rsDetails', 'rsDbOk', 'rsEnabled', 'rsIsAdmin', 'rsCsrf',
            'rsStayRefundable', 'rsOpenRefunds', 'rsLockSeconds', 'rsJustSaved'
        ));
    }
}
