<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
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

        return view('admin.payment-settings', compact(
            'gcAccounts', 'POSTPAY_GRACE_DAYS', 'rsDetails', 'rsDbOk', 'rsEnabled', 'rsIsAdmin', 'rsCsrf',
            'rsStayRefundable', 'rsOpenRefunds', 'rsLockSeconds', 'rsJustSaved'
        ));
    }
}
