<?php

namespace App\Console\VenusepCheck;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;

/* WHAT venusep:check requests, and AS WHOM.
   Routes: every GET route the porters own (names admin.*, customer.*, root.*).
   Roles:  the four the site knows, signed in with the same session keys
           tests/TestCase.php uses (LARAVEL-PORT.md, "Session contract"). */
final class Targets
{
    public const ROLES = ['guest', 'customer', 'staff', 'admin'];

    public const EMAILS = [
        'customer' => 'customer@gmail.com',
        'staff' => 'staff@gmail.com',
        'admin' => 'admin@gmail.com',
    ];

    /** @return array<string, Route> name => route, in URI order */
    public static function routes(string $only = ''): array
    {
        $out = [];
        foreach (Router::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (!preg_match('/^(admin|customer|root)\./', $name) || !in_array('GET', $route->methods(), true)) {
                continue;
            }
            if ($only !== '' && !str_starts_with($name, $only)) {
                continue;
            }
            $out[$name] = $route;
        }
        uasort($out, fn (Route $a, Route $b) => strcmp($a->uri(), $b->uri()));

        return $out;
    }

    /* Admins need two-step verification on, or the staff guard signs them out
       (DB-DECISIONS #20). Null = ready; otherwise why the admin role is skipped.
       Writes a test key ONLY on a venusep_laravel_test* database. */
    public static function prepareAdmin(string $database): ?string
    {
        $email = self::EMAILS['admin'];
        $row = DB::table('users')->where('email', $email)->first(['id', 'totp_secret']);
        if ($row === null) {
            return "no {$email} in this database";
        }
        if ($row->totp_secret !== null) {
            return null;
        }
        if (!str_starts_with(strtolower($database), 'venusep_laravel_test')) {
            return 'admin needs 2FA set up';
        }
        DB::update("UPDATE users SET totp_secret = 'JBSWY3DPEHPK3PXP', totp_enabled_at = NOW()
                     WHERE email = ? AND totp_secret IS NULL", [$email]);

        return null;
    }

    /** The session a request is signed in with, or a string: why the role cannot be. */
    public static function session(string $role): array|string
    {
        if ($role === 'guest') {
            return [];
        }
        $email = self::EMAILS[$role];
        if ($role === 'customer') {
            $row = DB::table('users as u')->join('customers as c', 'c.user_id', '=', 'u.id')
                ->where('u.email', $email)->first(['u.id', 'c.id as customer_id', 'c.full_name', 'u.email']);
            if ($row === null) {
                return "no customer {$email} in this database";
            }

            return [
                'user_id' => (int) $row->id,
                'customer_id' => (int) $row->customer_id,
                'account_type' => 'customer',
                'customer_name' => $row->full_name,
                'customer_email' => $row->email,
                'auth_at' => time(),
            ];
        }
        $id = DB::table('users')->where('email', $email)->where('account_type', $role)->value('id');
        if ($id === null) {
            return "no {$role} {$email} in this database";
        }
        $session = ['user_id' => (int) $id, 'account_type' => $role];
        if ($role === 'admin') {
            $session['tfa_passed'] = true;
        }

        return $session;
    }
}
