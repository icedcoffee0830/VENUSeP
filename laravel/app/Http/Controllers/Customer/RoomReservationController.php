<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/* Venue room booking (was customer/room-reservation.php). Route middleware
   booking.access: a customer booking for themselves, or staff booking for a
   walk-in at the counter (?counter=1 with a staff session).

   Almost all of the page is its script; this only hands it the shared data
   the old page read from the legacy includes. */
class RoomReservationController extends Controller
{
    use CapturesLegacyOutput;

    public function __invoke(Request $request)
    {
        /* The GCash account per venue (payment-settings), the rooms (venue-rooms),
           their photos (room-photos), who is looking (customer-bookings). */
        extract(legacy('auth', 'payment-settings', 'venue-rooms', 'room-photos', 'customer-bookings'));
        $pricingJs = self::legacyOutput('pricing');          /* USeP discount rate + the account check */
        $checkerJs = self::legacyOutput('gcash-checker');
        $counterBookerJs = self::legacyOutput('counter-booker');
        extract(legacy('pricing'));
        /* legacy refund-policy.php's payment_policy_js() reads $GLOBALS['REFUNDS_ENABLED'];
           legacy() loads files inside a function, so publish it before calling. */
        $GLOBALS['REFUNDS_ENABLED'] = $REFUNDS_ENABLED;

        $room = $request->query('room');

        /* the nav highlights the venue this room belongs to */
        $navHere = '';
        foreach ($venueRooms as $navRoom) {
            if ($room !== null && $navRoom['id'] === $room) {
                $navHere = 'venue-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($navRoom['venue']));
                break;
            }
        }

        /* "More rooms" — the other event spaces, same venue first, four at most. */
        $mrCurrent = is_string($room) ? $room : '';
        $mrHere = null;
        foreach ($venueRooms as $r) {
            if ($r['id'] === $mrCurrent) {
                $mrHere = $r;
                break;
            }
        }
        $mrOthers = array_values(array_filter($venueRooms, function ($r) use ($mrCurrent) { return $r['id'] !== $mrCurrent; }));
        if ($mrHere) {
            usort($mrOthers, function ($a, $b) use ($mrHere) { return ($b['venue'] === $mrHere['venue']) - ($a['venue'] === $mrHere['venue']); });
        }

        /* photoUrls/panorama merged in from room-photos — whatever was uploaded on
           admin Venue Management (Edit Room -> Edit photos / 360°). */
        $mrRoomsWithPhotos = array_map(function ($r) {
            $r['photoUrls'] = rp_gallery_urls($r['id']);
            $r['panorama']  = rp_pano_url($r['id']);
            return $r;
        }, $venueRooms);

        return view('customer.room-reservation', [
            'navHere'          => $navHere,
            'pricingJs'        => $pricingJs,
            'checkerJs'        => $checkerJs,
            'counterBookerJs'  => $counterBookerJs,
            'paymentPolicyJs'  => payment_policy_js(),
            'mrOthers'         => array_slice($mrOthers, 0, 4),
            'mrUsep'           => usep_is_account($customerContact['email']),   /* the price line previews the USeP rate */
            'mrToday'          => date('Y-m-d'),
            'mrRoomsWithPhotos' => $mrRoomsWithPhotos,
            'gcAccounts'       => $gcAccounts,
            'REFUNDS_ENABLED'  => $REFUNDS_ENABLED,
            'DISCOUNT_PERCENT' => $DISCOUNT_PERCENT,
            'customerContact'  => $customerContact,
        ]);
    }

    /* A "More rooms" card's picture: the room's cover photo, else the drawing
       (old mr_pic(); the hostel page draws its cards with it too). */
    public static function morePic($id, $kind, $badge = '')
    {
        $ph = rp_cover_url($id);
        $in = $ph ? '<img class="bk-more-img" loading="lazy" src="' . htmlspecialchars($ph) . '" alt="">'
                  : '<svg class="bk-more-art" viewBox="0 0 400 250" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><use href="#bk-art-' . $kind . '"/></svg>';
        $tag = '';   /* "Photo slot" tag off — the drawings stand as illustrations until real photos land */
        return '<div class="bk-more-ph"><div class="bk-more-ph-in">' . $in . '<div class="bk-more-scrim"></div></div>' . $tag . $badge . '</div>';
    }
}
