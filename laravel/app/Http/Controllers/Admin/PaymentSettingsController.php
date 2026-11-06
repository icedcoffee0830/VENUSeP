<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Queries\AccountQueries;
use App\Queries\OfficeSettingsQueries;
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
            $rsOpen = OfficeSettingsQueries::openRefundsByOffice();
            foreach (OfficeSettingsQueries::refundSwitchChanges() as $h) {
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
                $rsLockSeconds = AccountQueries::passwordLockSeconds((int) session('user_id'));
            } catch (PDOException $e) { sql_failed($e); $rsLockSeconds = 0; }
        }
        $rsJustSaved = $request->query('refunds') === 'saved' ? ($rsOffices[(int) $request->query('office')] ?? null) : null;

        /* An active venue with no current account (one just added in Venue
           Management) still gets a card — otherwise nobody could ever set where
           it is paid, and its customers could never pay by GCash. */
        $psCards = $gcAccounts;
        try {
            foreach (OfficeSettingsQueries::officesWithoutGcash() as $psVenue) {
                $psCards[$psVenue] = ['name' => '', 'number' => '',
                    'note' => 'No GCash account yet: customers cannot pay this venue by GCash until one is set.'];
            }
        } catch (PDOException $e) { sql_failed($e); /* the venues that have one still show */ }

        /* Which cards are the hostel's (a staff GCash account): by venue_type, not
           by name — a renamed hostel stays the hostel. Cards are keyed by name. */
        $psHostelVenues = [];
        try {
            $psHostelVenues = OfficeSettingsQueries::hostelNames();
        } catch (PDOException $e) { sql_failed($e); /* every card reads as a business account */ }

        return live_view('admin.payment-settings', compact(
            'psCards', 'psHostelVenues', 'psCanEdit','rsOffices', 'rsDbOk', 'rsIsAdmin', 'rsCsrf', 'rsLockSeconds', 'rsJustSaved'
        ));
    }
}
