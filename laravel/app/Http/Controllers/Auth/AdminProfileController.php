<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use PDOException;

/* admin/venusep_profile.php — THE SIGNED-IN STAFF MEMBER'S OWN ACCOUNT (saves to
   profile-save), the demo-mode card (posts to admin.demo-mode-switch) and, for
   admins, the two-step verification card. Guarded by the `staff` middleware. */
class AdminProfileController
{
    public function __invoke(Request $request)
    {
        legacy('auth', 'db', 'demo-mode', 'two-factor', 'two-factor-views');
        $uid = (int) session('user_id');

        $vpRow = [];
        try {
            $vpStmt = venusep_db_or_fail()->prepare(
                'SELECT u.email, u.username, s.full_name, s.phone, s.position_role, s.employee_no, s.photo_path
                   FROM users u LEFT JOIN staff s ON s.user_id = u.id
                  WHERE u.id = :u'
            );
            $vpStmt->execute([':u' => $uid]);
            $vpRow = $vpStmt->fetch() ?: [];
        } catch (PDOException $e) {
            $vpRow = [];
        }
        /* The seeded admin has no staff row: the name falls back to the username. */
        $vpName  = (string) (($vpRow['full_name'] ?? null) ?: ($vpRow['username'] ?? ''));
        $vpParts = preg_split('/\s+/', trim($vpName), 2);
        $vpFirst = $vpParts[0] ?? '';
        $vpLast  = $vpParts[1] ?? '';
        $vpEmail = (string) ($vpRow['email'] ?? '');
        $vpPhone = (string) ($vpRow['phone'] ?? '');
        $vpRole  = (string) ($vpRow['position_role'] ?? '');
        $vpEmp   = (string) ($vpRow['employee_no'] ?? '');
        /* THE PROFILE CARD: photo or initials, name, role (admin: every venue; staff: one). */
        $vpPhoto   = (string) ($vpRow['photo_path'] ?? '');
        $vpIsAdmin = session('account_type', '') === 'admin';
        $vpVenue   = '';
        if (!$vpIsAdmin) {
            try {
                $vpV = venusep_db_or_fail()->prepare('SELECT v.name FROM staff s JOIN venues v ON v.id = s.venue_id WHERE s.user_id = :u');
                $vpV->execute([':u' => $uid]);
                $vpVenue = (string) $vpV->fetchColumn();
            } catch (PDOException $e) {
                $vpVenue = '';
            }
        }
        $vpRoleLine = $vpIsAdmin
            ? 'Administrator' . ($vpRole !== '' ? ' · ' . $vpRole : '') . ' · All venues'
            : ($vpRole !== '' ? $vpRole : 'Staff') . ($vpVenue !== '' ? ' · ' . $vpVenue : '');
        $vpParts2 = preg_split('/\s+/', trim($vpName));
        $vpInitials = strtoupper(mb_substr($vpParts2[0] ?? '', 0, 1) . mb_substr($vpParts2[1] ?? '', 0, 1)) ?: '?';   // vp_initials()
        $vpCsrf = csrf_token();
        $vpMustChange = (bool) session('must_change_password');   // staff whose password the admin typed (EnforceTwoFactor holds them here)

        /* DEMO MODE — the card is the refund switch's twin; admin.demo-mode-switch re-checks everything. */
        $dmDetails = demo_setting_details();        // null = the database cannot be read
        $dmDbOk    = $dmDetails !== null;
        $dmOn      = $dmDbOk ? $dmDetails['enabled'] : demo_mode_on();
        $dmIsAdmin = admin_is_admin();

        /* A lock still running from an earlier wrong password — the countdown resumes after a reload. */
        $dmLockSeconds = 0;
        if ($dmDbOk && $dmIsAdmin) {
            try {
                $dmStmt = venusep_db()->prepare(
                    'SELECT CASE WHEN reauth_locked_until > NOW() THEN TIMESTAMPDIFF(SECOND, NOW(), reauth_locked_until) ELSE 0 END
                       FROM users WHERE id = :id'
                );
                $dmStmt->execute([':id' => $uid]);
                $dmLockSeconds = (int) $dmStmt->fetchColumn();
            } catch (PDOException $e) {
                $dmLockSeconds = 0;
            }
        }
        $dmJustSaved = $request->query('demo') === 'saved';

        /* Two-step verification (#20): required for admins, so this card only moves it
           or renews codes. Staff do not see it. null = could not be read. */
        $vpTfaShow = tfa_required_for((string) session('account_type', ''));
        $vpTfa = null;
        if ($vpTfaShow) {
            try {
                $vpTfa = tfa_status(venusep_db_or_fail(), $uid);
            } catch (PDOException $e) {
                $vpTfa = null;
            }
        }
        $vpTfaLow = $vpTfa !== null && $vpTfa['enabled'] && $vpTfa['codes_left'] <= TFA_CODES_LOW;

        return view('auth.admin-profile', compact(
            'vpName', 'vpFirst', 'vpLast', 'vpEmail', 'vpPhone', 'vpRole', 'vpEmp', 'vpPhoto', 'vpRoleLine',
            'vpInitials', 'vpCsrf', 'dmDetails', 'dmDbOk', 'dmOn', 'dmIsAdmin', 'dmLockSeconds', 'dmJustSaved',
            'vpTfaShow', 'vpTfa', 'vpTfaLow', 'vpMustChange'
        ));
    }
}
