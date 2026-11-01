<?php

namespace App\Http\Controllers\Auth;

use PDOException;

/* customer/customer-profile.php — the session customer's own record; every
   form saves to profile-save (JSON). Guarded by the `customer` middleware. */
class CustomerProfileController
{
    public function __invoke()
    {
        extract(legacy('auth', 'customer-bookings', 'google-auth', 'two-factor', 'two-factor-views'));

        $cpRow = [];
        try {
            $cpStmt = venusep_db_or_fail()->prepare(
                "SELECT c.full_name, c.phone, c.address, c.university_id_no, c.photo_path,
                        u.email, DATE_FORMAT(c.created_at, '%M %Y') AS member_since,
                        u.password_hash IS NOT NULL AS has_password, u.google_sub IS NOT NULL AS has_google
                   FROM customers c LEFT JOIN users u ON u.id = c.user_id
                  WHERE c.id = :id"
            );
            $cpStmt->execute([':id' => $customerContact['id']]);
            $cpRow = $cpStmt->fetch() ?: [];
        } catch (PDOException $e) {
            $cpRow = [];
        }

        $customerProfile = [
            'name'         => (string) ($cpRow['full_name'] ?? ''),
            'email'        => (string) ($cpRow['email'] ?? ''),
            'phone'        => (string) ($cpRow['phone'] ?? ''),
            'address'      => (string) ($cpRow['address'] ?? ''),
            'universityId' => (string) ($cpRow['university_id_no'] ?? ''),
            'photo'        => (string) ($cpRow['photo_path'] ?? ''),
            'memberSince'  => (string) ($cpRow['member_since'] ?? ''),
        ];
        $cpCsrf = csrf_token();

        /* Ways of signing in (#23). A Google-made account has no password until
           its owner sets one, after confirming with Google (the reauth proof). */
        $cpHasPassword = (bool) ($cpRow['has_password'] ?? true);
        $cpHasGoogle   = (bool) ($cpRow['has_google'] ?? false);
        $cpGoogleOn    = google_enabled();
        $cpReauthOk    = !$cpHasPassword && google_proof_valid('google_reauth', (int) session('user_id'));
        $cpNotice      = is_string(session('profile_notice')) ? session('profile_notice') : '';
        session()->forget('profile_notice');

        /* Two-step verification (#20): optional for customers. null = could not be read. */
        try {
            $cpTfa = tfa_status(venusep_db_or_fail(), (int) session('user_id'));
        } catch (PDOException $e) {
            $cpTfa = null;
        }

        return view('auth.customer-profile', compact(
            'customerContact', 'customerProfile', 'cpCsrf', 'cpHasPassword', 'cpHasGoogle',
            'cpGoogleOn', 'cpReauthOk', 'cpNotice', 'cpTfa'
        ));
    }
}
