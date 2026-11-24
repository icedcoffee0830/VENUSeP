<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Queries\AccountQueries;
use Throwable;

/* "Enter your own password to confirm": the signed-in admin retypes theirs before resetting
   someone's password or revoking a customer (Aron, 2026-11-24). The check is reauth_verify()
   (legacy auth.php), the one lockout every password re-check shares: 5 wrong in a row, then
   a lock that grows. Run it before the action's own transaction: it commits its own. The
   using controller has reply(int $status, array $data). */
trait ConfirmsOwnPassword
{
    /* Null = the right password (the wrong tries are wiped); otherwise the refusal to send back. */
    private function ownPasswordRefusal(string $password)
    {
        if ($password === '') {
            return $this->reply(400, ['ok' => false, 'error' => 'no_password', 'field' => 'password', 'message' => 'Enter your own password to confirm.']);
        }
        $pdo = venusep_db_or_fail();
        $pdo->beginTransaction();
        try {
            $me = AccountQueries::lockForPasswordCheck((int) session('user_id'));
            if ($me === null) {
                $pdo->rollBack();
                return $this->reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
            }
            $no = reauth_verify($pdo, $me, $password);   // a wrong try is committed there, so it counts
            if ($no === null) {
                $pdo->commit();
                return null;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return isset($no['locked'])
            ? $this->reply(423, ['ok' => false, 'error' => 'locked', 'field' => 'password', 'seconds' => $no['locked'],
                'message' => 'Too many wrong passwords. Try again in ' . $no['locked'] . ' seconds.'])
            : $this->reply(401, ['ok' => false, 'error' => 'wrong_password', 'field' => 'password', 'attemptsLeft' => $no['attemptsLeft'],
                'message' => 'Wrong password. ' . $no['attemptsLeft'] . ' attempt(s) left before a temporary lock.']);
    }
}
