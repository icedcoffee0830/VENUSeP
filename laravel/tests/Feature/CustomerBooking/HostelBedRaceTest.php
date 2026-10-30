<?php

namespace Tests\Feature\CustomerBooking;

use App\Http\Controllers\Customer\BookingRules;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/* Two hostel stays submitted at once both pick the same first free bed; the
   UNIQUE(active_bed_id, night_date) index refuses the second insert (1062). The
   loser was refused ("not enough free beds") although other beds were free — it
   now takes the next free bed (BookingRules::assignBeds, used by booking-submit
   and the counter).

   The race window (between "which bed is free?" and sp_assign_bed) is replayed
   exactly: the rival's bed is committed, and the first "free bed" answer is the
   stale one the loser saw before that commit. */
class HostelBedRaceTest extends TestCase
{
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $id) {
            DB::delete('DELETE FROM bookings WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    /** a committed pending stay with one named guest and no bed: [booking id, occupant id] */
    private function stay(int $roomId, string $email, string $in, string $out): array
    {
        $id = DB::table('bookings')->insertGetId([
            'customer_id' => (int) DB::table('customers as c')->join('users as u', 'u.id', '=', 'c.user_id')->where('u.email', $email)->value('c.id'),
            'room_id' => $roomId, 'booking_type' => 'hostel', 'reservation_status' => 'pending', 'payment_status' => 'locked',
            'is_usep_affiliated' => 0, 'affiliation_verified' => 0, 'room_price' => 350, 'discount_percent' => 0,
            'discount_amount' => 0, 'total_amount' => 350, 'refunds_allowed' => 0,
        ]);
        $this->made[] = $id;
        DB::table('hostel_booking_details')->insert(['booking_id' => $id, 'check_in_date' => $in, 'check_out_date' => $out, 'checked_in' => 0]);
        $occ = DB::table('hostel_occupants')->insertGetId(['booking_id' => $id, 'full_name' => 'Race Guest', 'gender' => 'female', 'is_primary_guest' => 1]);

        return [$id, $occ];
    }

    public function test_a_stay_that_loses_its_bed_to_a_simultaneous_one_takes_the_next_free_bed(): void
    {
        $roomId = (int) DB::table('rooms')->where('room_code', 'h1')->value('id');
        $in = date('Y-m-d', strtotime('+1 day'));
        $out = date('Y-m-d', strtotime('+2 days'));
        $freeBeds = array_map(fn ($r) => (int) $r->id, DB::select(
            'SELECT hb.id FROM hostel_beds hb WHERE hb.room_id = ? AND hb.is_active = 1
                AND NOT EXISTS (SELECT 1 FROM bed_reservation_nights n WHERE n.bed_id = hb.id AND n.released_at IS NULL
                                AND n.night_date >= ? AND n.night_date < ?)
              ORDER BY hb.display_order, hb.id', [$roomId, $in, $out]));
        if (count($freeBeds) < 2) {
            $this->markTestSkipped('Hostel Room 1 has fewer than two free beds tomorrow in this database.');
        }

        /* the winner commits the first free bed... */
        [$rival, $rivalOcc] = $this->stay($roomId, 'mesionaalexa@gmail.com', $in, $out);
        DB::statement('CALL sp_assign_bed(?, ?, ?)', [$rival, $rivalOcc, $freeBeds[0]]);
        /* ...while the loser had already read that bed as free: its first answer is that stale one */
        [$mine, $myOcc] = $this->stay($roomId, 'aronerfe0830@gmail.com', $in, $out);
        $cfg = config('database.connections.mysql');
        $pdo = new class("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4", (string) $cfg['username'], (string) $cfg['password'], $freeBeds[0]) extends PDO {
            public function __construct(string $dsn, string $u, string $p, private int $stale)
            {
                parent::__construct($dsn, $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $this->exec('SET @race_try = 0');
            }

            public function prepare(string $sql, array $options = []): \PDOStatement|false
            {
                if (str_starts_with(trim($sql), 'SELECT hb.id FROM hostel_beds')) {
                    $sql = 'SELECT IF((@race_try := @race_try + 1) = 1, ' . $this->stale . ', (' . $sql . ')) AS id';
                }
                return parent::prepare($sql, $options);
            }
        };

        BookingRules::assignBeds($pdo, $roomId, $mine, [$myOcc], $in, $out);

        $this->assertSame([$freeBeds[0]], DB::table('bed_reservation_nights')->where('booking_id', $rival)->pluck('bed_id')->map(fn ($b) => (int) $b)->all());
        $this->assertSame([$freeBeds[1]], DB::table('bed_reservation_nights')->where('booking_id', $mine)->pluck('bed_id')->map(fn ($b) => (int) $b)->all());
        /* the lost attempt left no half-written assignment behind (each bed is its own transaction, migration 15) */
        $this->assertSame([$freeBeds[1]], DB::table('bed_reservations')->where('booking_id', $mine)->pluck('bed_id')->map(fn ($b) => (int) $b)->all());
        $this->assertSame('2', (string) $pdo->query('SELECT @race_try')->fetchColumn());   /* the stale answer, then the retry */
    }
}
