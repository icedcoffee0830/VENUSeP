<?php

namespace App\Http\Controllers\Admin;

/* Quarterly Reports (was admin/Quarterly_Reports.php). Route middleware: staff.
   Computed from `bookings` (legacy bookings.php), so the reports describe
   whatever the business actually did. */
class ReportsController
{
    public function __invoke()
    {
        legacy('bookings');

        $rpQuarters = report_quarters(4);
        $rpVenues   = report_by_venue();          // all time — the default view

        $venueRow = function ($v) {
            return ['venue' => $v['venue'], 'events' => (int) $v['events'],
                    'discounted' => (int) $v['discounted'], 'cancelled' => (int) $v['cancelled'],
                    'revenue' => (float) $v['revenue']];
        };

        /* The venue breakdown for EACH quarter in the window, so the Reporting Period
           dropdown can narrow the per-venue charts and the stat tiles without a page
           reload. The TREND charts deliberately keep showing all four quarters. */
        $rpByQuarter = [];
        foreach ($rpQuarters as $rpQ) {
            if (!preg_match('/^Q(\d) (\d{4})$/', $rpQ['label'], $m)) {
                continue;
            }
            $rpByQuarter[] = [
                'label'     => $rpQ['label'],
                'revenue'   => (float) $rpQ['revenue'],
                'avg'       => (float) $rpQ['avg'],
                'bookings'  => (int) $rpQ['bookings'],
                'cancelled' => (int) $rpQ['cancelled'],
                'venues'    => array_map($venueRow, report_by_venue((int) $m[2], (int) $m[1])),
            ];
        }

        /* If there are no quarters at all (a brand-new database), the charts fall
           back to the all-time breakdown. */
        $allTimeVenues = array_map($venueRow, $rpVenues);

        /* Today's quarter comes from the server, not the viewer's clock. */
        $currentQuarter = 'Q' . (int) ceil((int) date('n') / 3) . ' ' . date('Y');

        return view('admin.reports', compact('rpQuarters', 'rpByQuarter', 'allTimeVenues', 'currentQuarter'));
    }
}
