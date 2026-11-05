<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;

/* The PUBLIC landing page (was customer/venusep_venue_booking.php). No guard:
   the session only tells the nav whether someone is logged in.

   Both room lists come from ONE shared source each (legacy venue-rooms /
   hostel-rooms), so this page can never drift from the booking pages or admin
   Venue Management. Who is looking + the discount rule decide whether a USeP
   account sees its price with the full price crossed out (a PREVIEW: the ID
   decides the discount at approval — legacy pricing.php). */
class VenueBookingController extends Controller
{
    use CapturesLegacyOutput;

    public function __invoke()
    {
        extract(legacy('auth', 'offices', 'venue-rooms', 'hostel-rooms', 'room-photos', 'venue-photos', 'customer-bookings'));
        self::legacyOutput('pricing');   /* PHP helpers now; the old page captured its JS block and never printed it */
        extract(legacy('pricing'));
        $isUsep = usep_is_account($customerContact['email']);

        /* An office the customer is blocked at is not listed (the modal says why): DB-DECISIONS 28 */
        $lpBlocked = BookingRules::blockedOfficeNames();
        $venueRooms = array_values(array_filter($venueRooms, fn ($r) => !in_array($r['venue'], $lpBlocked, true)));
        $lpHostelBlocked = in_array($HOSTEL_VENUE, $lpBlocked, true);
        if ($lpHostelBlocked) {
            $hostelRooms = [];
        }

        $lpVenueNames = array_values(array_unique(array_column($venueRooms, 'venue')));
        /* COUNTER MODE (staff booking for a walk-in): every link keeps counter=1, and
           the links that only mean something to a signed-in customer — My bookings,
           Transaction history, refunds — are hidden (2026-10-27). */
        $lpCounter = counter_mode();
        /* The lists above are already narrowed to a staff session's offices (staff_sees_venue);
           the hostel card and section follow the same rule (DB-DECISIONS 28). */
        $lpShowHostel = office_hostel_shown() && !$lpHostelBlocked;
        $lpNoOffice = $lpCounter && staff_venue_scope() === [];
        $lpCount = count($lpVenueNames) + ($lpShowHostel ? 1 : 0);
        /* "Alumni halls, university venues and hostel beds", for the places shown only */
        $what = array_keys(array_filter([
            'alumni halls'      => in_array('Bahay Alumni', $lpVenueNames, true),
            'university venues' => in_array('USeP Venues', $lpVenueNames, true),
            'hostel beds'       => $lpShowHostel,
        ]));
        $last = array_pop($what);
        $lpWhat = ucfirst(($what ? implode(', ', $what) . ' and ' : '') . $last);

        $lpOffices = array_values(array_filter(office_all(), fn ($o) => staff_sees_venue($o['id'])));

        return view('customer.venue-booking', [
            'venueRooms'         => $venueRooms,
            'hostelRooms'        => $hostelRooms,
            'HOSTEL_VENUE'       => $HOSTEL_VENUE,
            'HOSTEL_CR_LABEL'    => $HOSTEL_CR_LABEL,
            'DISCOUNT_PERCENT'   => $DISCOUNT_PERCENT,
            'POSTPAY_GRACE_DAYS' => $POSTPAY_GRACE_DAYS,
            'isUsep'             => $isUsep,
            'lpDiscountLabel'    => office_discount_label(),
            'lpToday'            => date('Y-m-d'),
            /* The three places. Names must match the `venue` field in venue-rooms
               and $HOSTEL_VENUE in hostel-rooms. The blurbs are copy for this page
               only; the data has no venue description field. */
            'lpVenueBlurb' => [
                'Bahay Alumni' => 'The alumni house — ballroom, function room, boardroom and garden pavilion.',
                'USeP Venues'  => 'University halls — the gymnasium, the CIC audio-visual room and conference spaces.',
            ],
            'lpVenueArt'   => ['Bahay Alumni' => 'hall', 'USeP Venues' => 'gym'],
            'lpVenueSlug'  => ['Bahay Alumni' => 'venue-bahay-alumni', 'USeP Venues' => 'venue-usep-venues'],
            'lpVenueNames' => $lpVenueNames,
            'lpVenueCount' => $lpCount,
            'lpPlaces'     => ([1 => 'One place', 2 => 'Two places', 3 => 'Three places'][$lpCount] ?? $lpCount . ' places') . ' on campus',
            'lpWhat'       => $lpWhat,
            'lpShowHostel' => $lpShowHostel,
            /* counter mode: no office yet, or another office's booking page was refused (BookingAccess) */
            'lpCounterNotice' => $lpNoOffice ? 'You have no office yet. Ask an admin to assign one.' : ($lpCounter ? session('counter_refused') : null),
            'lpSpaceCount' => count($venueRooms) + count($hostelRooms),
            'lpBedCount'   => array_sum(array_column($hostelRooms, 'beds')),
            /* Footer: each office's own contact info and hours (Office Settings, DB-DECISIONS 28) */
            'lpOffices'     => $lpOffices,
            /* the offices taking refund requests on new bookings, and the others (DB-DECISIONS 28) */
            'lpRefundOn'    => array_column(array_filter($lpOffices, fn ($o) => $o['refunds']), 'name'),
            'lpRefundOff'   => array_column(array_filter($lpOffices, fn ($o) => !$o['refunds']), 'name'),
            'lpCounter'     => $lpCounter,
            'overdueNotice' => BookingRules::overdueNoticeForPage(),   /* an Overdue booking blocks new ones at its office (2026-11-01) */
            'lpQ'           => $lpCounter ? '?counter=1' : '',
        ]);
    }

    /* ---- PRESENTATION ONLY: how the shared data is DRAWN (old lp_* functions) ---- */

    /* The room's cover photo: the first photo uploaded via admin Venue Management,
       or a manually-dropped assets/img/venues/<id>.<ext>. Null draws the placeholder.
       $venueName set = this is a VENUE card — venues.cover_photo is checked first. */
    public static function photo($id, $venueName = null)
    {
        if ($venueName !== null) {
            $venuePhoto = vp_cover_url_by_name($venueName);
            if ($venuePhoto) {
                return $venuePhoto;
            }
        }
        return rp_cover_url($id);
    }

    /* One drawn placeholder per KIND of space, reused: hall | gym | bunk | private */
    public static function art($kind)
    {
        return '<svg class="lp-ph-art" viewBox="0 0 400 250" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><use href="#lp-art-' . $kind . '"/></svg>';
    }

    /* The picture area of a card: the real photo if one exists, else the drawing;
       a scrim over both; any badge on top. Every card draws its picture here. */
    public static function photoBlock($id, $kind, $badge = '', $venueName = null)
    {
        $photo = self::photo($id, $venueName);
        $inner = $photo
            ? '<img class="lp-ph-img" loading="lazy" decoding="async" src="' . htmlspecialchars($photo) . '" alt="">'
            : self::art($kind);
        $tag = '';   /* the "Photo slot" tag is off: the drawings stand as illustrations until real photos land */
        return '<div class="lp-ph"><div class="lp-ph-in">' . $inner . '<div class="lp-ph-scrim"></div></div>' . $tag . $badge . '</div>';
    }

    /* Which drawing suits a venue room — a UI guess from the capacity, nothing more. */
    public static function roomKind(array $room)
    {
        return $room['capacity'] >= 500 ? 'gym' : 'hall';
    }
}
