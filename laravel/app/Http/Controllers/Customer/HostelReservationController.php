<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/* Hostel bed booking (was customer/hostel-reservation.php). Route middleware
   booking.access: a customer booking for themselves, or staff booking for a
   guest at the front desk (?counter=1 with a staff session).

   The hostel's rooms + availability model live in ONE include (hostel-rooms),
   shared with the landing page and admin Venue Management. */
class HostelReservationController extends Controller
{
    use CapturesLegacyOutput;

    public function __invoke(Request $request)
    {
        extract(legacy('auth', 'hostel-rooms', 'room-photos', 'payment-settings', 'customer-bookings'));
        $pricingJs = self::legacyOutput('pricing');          /* USeP discount rate + the account check */
        $checkerJs = self::legacyOutput('gcash-checker');
        $counterBookerJs = self::legacyOutput('counter-booker');
        extract(legacy('pricing'));

        /* "More rooms" — the other hostel rooms, same CR type first, four at most. */
        $room = $request->query('room');
        $mrCurrent = is_string($room) ? $room : '';
        $mrHere = null;
        foreach ($hostelRooms as $r) {
            if ($r['id'] === $mrCurrent) {
                $mrHere = $r;
                break;
            }
        }
        $mrOthers = array_values(array_filter($hostelRooms, function ($r) use ($mrCurrent) { return $r['id'] !== $mrCurrent; }));
        if ($mrHere) {
            usort($mrOthers, function ($a, $b) use ($mrHere) { return ($b['cr_type'] === $mrHere['cr_type']) - ($a['cr_type'] === $mrHere['cr_type']); });
        }

        /* photoUrls/panorama merged in from room-photos (admin Venue Management uploads) */
        $mrRoomsWithPhotos = array_map(function ($r) {
            $r['photoUrls'] = rp_gallery_urls($r['id']);
            $r['panorama']  = rp_pano_url($r['id']);
            return $r;
        }, $hostelRooms);

        return view('customer.hostel-reservation', [
            'pricingJs'         => $pricingJs,
            'checkerJs'         => $checkerJs,
            'counterBookerJs'   => $counterBookerJs,
            'mrOthers'          => array_slice($mrOthers, 0, 4),
            'mrUsep'            => usep_is_account($customerContact['email']),   /* the price line previews the USeP rate */
            'mrToday'           => date('Y-m-d'),
            'mrRoomsWithPhotos' => $mrRoomsWithPhotos,
            'gcAccounts'        => $gcAccounts,
            'HOSTEL_RATES'      => $HOSTEL_RATES,
            'HOSTEL_CR_LABEL'   => $HOSTEL_CR_LABEL,
            'HOSTEL_VENUE'      => $HOSTEL_VENUE,
            'REFUNDS_ENABLED'   => $REFUNDS_ENABLED,
            'DISCOUNT_PERCENT'  => $DISCOUNT_PERCENT,
            'customerContact'   => $customerContact,
        ]);
    }
}
