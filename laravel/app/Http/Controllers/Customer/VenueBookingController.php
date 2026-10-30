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
        extract(legacy('auth', 'venue-rooms', 'hostel-rooms', 'room-photos', 'venue-photos', 'customer-bookings'));
        self::legacyOutput('pricing');   /* PHP helpers now; the old page captured its JS block and never printed it */
        extract(legacy('pricing'));
        $isUsep = usep_is_account($customerContact['email']);

        $lpVenueNames = array_values(array_unique(array_column($venueRooms, 'venue')));
        /* COUNTER MODE (staff booking for a walk-in): every link keeps counter=1, and
           the links that only mean something to a signed-in customer — My bookings,
           Transaction history, refunds — are hidden (2026-10-27). */
        $lpCounter = counter_mode();

        return view('customer.venue-booking', [
            'venueRooms'         => $venueRooms,
            'hostelRooms'        => $hostelRooms,
            'HOSTEL_VENUE'       => $HOSTEL_VENUE,
            'HOSTEL_CR_LABEL'    => $HOSTEL_CR_LABEL,
            'DISCOUNT_PERCENT'   => $DISCOUNT_PERCENT,
            'REFUNDS_ENABLED'    => $REFUNDS_ENABLED,
            'POSTPAY_GRACE_DAYS' => $POSTPAY_GRACE_DAYS,
            'isUsep'             => $isUsep,
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
            'lpVenueCount' => count($lpVenueNames) + 1,                          /* + the hostel */
            'lpSpaceCount' => count($venueRooms) + count($hostelRooms),
            'lpBedCount'   => array_sum(array_column($hostelRooms, 'beds')),
            /* Footer facts. Confirm these with the venue office before go-live. */
            'lpOfficeWhere' => 'USeP Tagum–Mabini Campus, Apokon, Tagum City',
            'lpOfficeHours' => 'Monday to Friday, 8:00 AM – 5:00 PM',
            'lpCounter'     => $lpCounter,
            'overdueNotice' => BookingRules::overdueNoticeForPage(),   /* an Overdue booking blocks new ones (2026-10-30) */
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
