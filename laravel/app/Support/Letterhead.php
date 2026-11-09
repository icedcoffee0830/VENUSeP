<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/* The printed letterhead (assets/css/print-letterhead.css): which USeP header goes on the paper.
   The office line under the university's name names the office group (DB-DECISIONS 28):
   Bahay Alumni, or CEDU for USeP Venues and USeP Hostel. Offices from both groups (the
   super admin's "All offices") and a customer's own printout get the university's name alone. */
final class Letterhead
{
    /* [image, alt] */
    private const HEADERS = [
        'bahay_alumni' => ['assets/img/reports/print-header-bahay-alumni.png', 'University of Southeastern Philippines — Bahay Alumni'],
        'cedu'         => ['assets/img/reports/print-header-cedu.png', 'University of Southeastern Philippines — CEDU'],
        'usep'         => ['assets/img/reports/print-header-usep.png', 'University of Southeastern Philippines'],
    ];

    /* $offices: venues rows with office_group (ReportQueries::offices). Returns [image, alt]. */
    public static function header(array $offices): array
    {
        $groups = array_unique(array_column($offices, 'office_group'));
        $key = count($groups) === 1 && isset(self::HEADERS[$groups[0] ?? '']) ? $groups[0] : 'usep';
        return self::HEADERS[$key];
    }

    /* The university's name alone, with no office line: a customer's own printout. */
    public static function university(): array
    {
        return self::HEADERS['usep'];
    }

    /* Who is printing, as the top bar names them: the staff row's full name, else the username. */
    public static function printedBy(): string
    {
        $row = DB::selectOne(
            'SELECT COALESCE(s.full_name, u.username, u.email) AS who FROM users u LEFT JOIN staff s ON s.user_id = u.id WHERE u.id = ?',
            [(int) session('user_id')]);
        return (string) ($row->who ?? '');
    }
}
