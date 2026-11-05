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

        /* The refund switch, one per office (DB-DECISIONS 28): a row for each office this
           person sees. Empty = the database is unreachable. */
        $rsOffices  = array_filter(office_all(), fn ($o) => staff_sees_venue($o['id']));
        $rsDbOk     = $rsOffices !== [];
        $rsIsAdmin  = admin_is_super();                  // the refund switch: the super admin only; others see it read-only
        $psCanEdit  = admin_is_admin() && !admin_is_super();   // GCash: each office's normal admin (DB-DECISIONS 28)
        $rsCsrf     = csrf_token();
        /* per office: bookings that keep their refund right if it goes OFF, refund requests
           still open (finished by staff even after it goes OFF, DB-DECISIONS #16: a warning,
           not a blocker), and the last change */
        $rsStay = array_count_values(array_column(array_filter(bookings_all(), fn ($b) => $b['refundable']), 'venueName'));
        $rsOpen = $rsLast = [];
        try {
            $rsOpen = venusep_db()->query(
                "SELECT r.venue_id, COUNT(*) FROM refunds f JOIN bookings b ON b.id = f.booking_id JOIN rooms r ON r.id = b.room_id
                  WHERE f.refund_status IN ('requested','under_review','returned_for_correction','approved') GROUP BY r.venue_id"
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach (venusep_db()->query(
                "SELECT h.venue_id, h.changed_at, u.email FROM venue_settings_history h LEFT JOIN users u ON u.id = h.changed_by_user_id
                  WHERE h.field = 'refunds' ORDER BY h.id"
            ) as $h) {
                $rsLast[(int) $h['venue_id']] = $h;   // the newest wins
            }
        } catch (PDOException $e) { sql_failed($e); /* the counts read zero, the switch still works */ }
        foreach ($rsOffices as $id => $o) {
            $rsOffices[$id] += ['stay' => $rsStay[$o['name']] ?? 0, 'open' => (int) ($rsOpen[$id] ?? 0), 'last' => $rsLast[$id] ?? null];
        }

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
            } catch (PDOException $e) { sql_failed($e); $rsLockSeconds = 0; }
        }
        $rsJustSaved = $request->query('refunds') === 'saved' ? ($rsOffices[(int) $request->query('office')] ?? null) : null;

        /* An active venue with no current account (one just added in Venue
           Management) still gets a card — otherwise nobody could ever set where
           it is paid, and its customers could never pay by GCash. */
        $psCards = $gcAccounts;
        try {
            foreach (venusep_db()->query(
                "SELECT v.name FROM venues v
                  WHERE v.is_active = 1" . staff_venue_sql('v.id IN (%s)', 'AND') . "
                    AND NOT EXISTS (SELECT 1 FROM gcash_accounts g
                                     WHERE g.venue_id = v.id AND g.is_active = 1 AND g.valid_until IS NULL)
                  ORDER BY v.id"
            )->fetchAll(PDO::FETCH_COLUMN) as $psVenue) {
                $psCards[$psVenue] = ['name' => '', 'number' => '',
                    'note' => 'No GCash account yet: customers cannot pay this venue by GCash until one is set.'];
            }
        } catch (PDOException $e) { sql_failed($e); /* the venues that have one still show */ }

        /* Which cards are the hostel's (a staff GCash account): by venue_type, not
           by name — a renamed hostel stays the hostel. Cards are keyed by name. */
        $psHostelVenues = [];
        try {
            $psHostelVenues = venusep_db()->query("SELECT name FROM venues WHERE venue_type = 'hostel'")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) { sql_failed($e); /* every card reads as a business account */ }

        return view('admin.payment-settings', compact(
            'psCards', 'psHostelVenues', 'psCanEdit','rsOffices', 'rsDbOk', 'rsIsAdmin', 'rsCsrf', 'rsLockSeconds', 'rsJustSaved'
        ));
    }
}
