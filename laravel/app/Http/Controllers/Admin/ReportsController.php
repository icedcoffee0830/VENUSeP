<?php

namespace App\Http\Controllers\Admin;

use App\Queries\ReportQueries;
use App\Support\Letterhead;
use Illuminate\Http\Request;

/* Quarterly Reports (was admin/Quarterly_Reports.php). Route middleware: staff.
   One page, one set of queries (ReportQueries), for the office choice in ?office=
   (DB-DECISIONS 28). The choices come from who is signed in, never from the URL:
   a choice they may not have falls back to their default one. */
class ReportsController
{
    public function __invoke(Request $request)
    {
        legacy('auth');

        $rpChoices = $this->choices();
        $rpOffice = $request->query('office');
        if (!is_string($rpOffice) || !isset($rpChoices[$rpOffice])) {
            $rpOffice = array_key_last($rpChoices);          // the widest choice; null = no office at all
        }
        $offices = $rpOffice === null ? [] : $rpChoices[$rpOffice]['offices'];
        $ids = array_column($offices, 'id');
        $kinds = array_unique(array_column($offices, 'venue_type'));
        $rpHalls = in_array('event', $kinds, true);
        $rpHostel = in_array('hostel', $kinds, true);
        /* several offices: the charts compare the offices; one office: its halls or rooms */
        $rpBy = count($offices) > 1 ? 'Office' : ($rpHostel ? 'Room' : 'Hall');

        $rpQuarters = ReportQueries::quarters($ids, 4);
        $rpByQuarter = [];
        foreach ($rpQuarters as $q) {
            $rpByQuarter[] = $q + ['rooms' => ReportQueries::rooms($ids, $q['year'], $q['quarter'])];
        }
        /* If there are no quarters at all, the charts fall back to the all-time breakdown. */
        $allTimeRooms = ReportQueries::rooms($ids);

        /* Today's quarter comes from the server, not the viewer's clock. */
        $currentQuarter = 'Q' . (int) ceil((int) date('n') / 3) . ' ' . date('Y');
        $rpLetterhead = Letterhead::header($offices);     // the printed header names the report's office

        return view('admin.reports', compact('rpChoices', 'rpOffice', 'rpBy', 'rpHalls', 'rpHostel',
            'rpQuarters', 'rpByQuarter', 'allTimeRooms', 'currentQuarter', 'rpLetterhead'));
    }

    /* What this person may report on, narrowest first, key => [label, offices]: each of
       their offices; each group they have two or more offices of ("USeP CEDU (both)"); and
       for the super admin, every office. So a normal admin or staff member with one office
       has one choice (no toggle), and nobody gets an office staff_venue_scope() leaves out. */
    private function choices(): array
    {
        $offices = ReportQueries::offices(staff_venue_scope());
        $choices = [];
        $groups = [];
        foreach ($offices as $o) {
            $choices[strtolower($o['venue_code'])] = ['label' => $o['name'], 'offices' => [$o]];
            if ($o['office_group'] !== null) {
                $groups[$o['office_group']][] = $o;
            }
        }
        foreach ($groups as $group => $list) {
            if (count($list) > 1) {
                $choices[$group] = ['label' => (OFFICE_GROUPS[$group] ?? $group) . ' (both)', 'offices' => $list];
            }
        }
        if (admin_is_super() && count($offices) > 1) {
            $choices['all'] = ['label' => 'All offices', 'offices' => $offices];
        }
        return $choices;
    }
}
