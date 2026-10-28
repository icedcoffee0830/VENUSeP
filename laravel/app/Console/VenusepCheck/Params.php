<?php

namespace App\Console\VenusepCheck;

use Illuminate\Support\Facades\DB;

/* Query strings for the pages whose old version reads one (the $_GET names in
   the old admin/, customer/ and root pages), filled from the database so the
   page gets past its "not found". Scoped to what the role may see: a customer
   gets one of their own bookings, staff one at their venue, everyone else the
   latest overall. */
final class Params
{
    /* route name => [query key => what to look up] */
    private const NEEDS = [
        'admin.booking-request' => ['id' => 'ref'],
        'admin.booking-emails' => ['booking' => 'ref'],
        'admin.receipt' => ['booking' => 'receipt_ref'],
        'customer.receipt' => ['booking' => 'receipt_ref'],
        'root.receipt-pdf' => ['booking' => 'receipt_ref'],
        'customer.pay' => ['booking' => 'ref'],
        'customer.refund-request' => ['booking' => 'ref'],
        'root.booking-details' => ['booking' => 'ref'],
        'root.valid-id-view' => ['booking' => 'ref'],
        'root.document-view' => ['doc' => 'doc'],
        'admin.room-form' => ['id' => 'event_room'],
        'admin.hostel-room-form' => ['id' => 'hostel_room'],
        'admin.venue-form' => ['id' => 'venue'],
        'admin.room-photos-api' => ['action' => '=list', 'room_id' => 'event_room'],
        'admin.venue-photo-api' => ['action' => '=list', 'venue_id' => 'venue'],
        'customer.room-reservation' => ['room' => 'event_room'],
        'customer.hostel-reservation' => ['room' => 'hostel_room'],
        'admin.customer-search' => ['q' => '=a'],
        /* no real token can be inferred: this checks the "link expired" path */
        'customer.reset-password' => ['token' => '=venusep-check-invalid-token'],
    ];

    /* the booking pages staff open at the counter (booking_page_require_access) */
    private const COUNTER = ['customer.venues', 'customer.room-reservation', 'customer.hostel-reservation'];

    private array $cache = [];

    public function __construct(private readonly string $role, private readonly array $session)
    {
    }

    /** @return array{0: array<string, string>, 1: list<string>} the query, and the keys nothing was found for */
    public function for(string $routeName): array
    {
        $query = [];
        $missing = [];
        foreach (self::NEEDS[$routeName] ?? [] as $key => $what) {
            $value = str_starts_with($what, '=') ? substr($what, 1) : $this->lookup($what);
            if ($value === null) {
                $missing[] = $key;
            } else {
                $query[$key] = (string) $value;
            }
        }
        if ($this->role === 'staff' && in_array($routeName, self::COUNTER, true)) {
            $query['counter'] = '1';
        }

        return [$query, $missing];
    }

    private function lookup(string $what): ?string
    {
        if (!array_key_exists($what, $this->cache)) {
            try {
                $this->cache[$what] = $this->find($what);
            } catch (\Throwable $e) {
                $this->cache[$what] = null;
            }
        }

        return $this->cache[$what];
    }

    private function find(string $what): ?string
    {
        $venue = $this->staffVenue();
        $bookings = fn () => DB::table('bookings as b')->join('rooms as r', 'r.id', '=', 'b.room_id')
            ->when($this->role === 'customer', fn ($q) => $q->where('b.customer_id', $this->session['customer_id'] ?? 0))
            ->when($venue !== null, fn ($q) => $q->where('r.venue_id', $venue));
        $rooms = fn (string $type) => DB::table('rooms')->where('room_type', $type)->where('is_active', 1)
            ->when($venue !== null, fn ($q) => $q->where('venue_id', $venue))->orderBy('id')->value('room_code');

        switch ($what) {
            case 'ref':
                return self::reference($bookings()->orderByDesc('b.id')->first(['b.id', 'b.submitted_at']));
            case 'receipt_ref':
                return self::reference($bookings()->join('system_receipts as sr', 'sr.booking_id', '=', 'b.id')
                    ->orderByDesc('sr.id')->first(['b.id', 'b.submitted_at']));
            case 'doc':
                $id = $bookings()->join('booking_documents as d', 'd.booking_id', '=', 'b.id')->orderByDesc('d.id')->value('d.id');
                return $id === null ? null : (string) $id;
            case 'event_room':
                return $rooms('event');
            case 'hostel_room':
                return $rooms('hostel');
            case 'venue':
                $id = $venue ?? DB::table('venues')->orderBy('id')->value('id');
                return $id === null ? null : (string) $id;
        }

        return null;
    }

    private function staffVenue(): ?int
    {
        if ($this->role !== 'staff') {
            return null;
        }
        $venue = DB::table('staff')->where('user_id', $this->session['user_id'] ?? 0)->value('venue_id');

        return $venue === null ? null : (int) $venue;
    }

    /* Same format as booking_reference() in legacy/includes/bookings.php
       (not loaded here: the checker must not pre-load legacy state the pages
       under test would then inherit). */
    private static function reference(?object $row): ?string
    {
        if ($row === null) {
            return null;
        }
        $year = $row->submitted_at ? date('Y', strtotime($row->submitted_at)) : date('Y');

        return 'VB-' . $year . '-' . str_pad((string) (int) $row->id, 3, '0', STR_PAD_LEFT);
    }
}
