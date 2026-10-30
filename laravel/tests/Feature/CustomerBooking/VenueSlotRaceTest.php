<?php

namespace Tests\Feature\CustomerBooking;

use PDO;
use Tests\TestCase;

/* Venue double booking under real concurrency (migration 14). Two database
   sessions book the same room a moment apart: this test's session books and
   holds its transaction open, a second PROCESS (venue-slot-session.php) books
   while it is open. Before migration 14 sp_add_venue_slot released its lock
   when it returned and counted conflicts from a stale snapshot, so both
   bookings were saved. Bookings the test creates are deleted again. */
class VenueSlotRaceTest extends TestCase
{
    private ?PDO $a = null;
    private array $made = [];

    protected function tearDown(): void
    {
        if ($this->a && $this->a->inTransaction()) {
            $this->a->rollBack();
        }
        if ($this->made) {
            $this->pdo()->prepare('DELETE FROM bookings WHERE id IN (' . implode(',', array_map('intval', $this->made)) . ')')
                ->execute();
        }
        parent::tearDown();
    }

    private function cfg(): array
    {
        $c = config('database.connections.mysql');
        return ['dsn' => "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4",
                'user' => $c['username'], 'pass' => $c['password']];
    }

    private function pdo(): PDO
    {
        $c = $this->cfg();
        return new PDO($c['dsn'], $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /* Session A: book and leave the transaction OPEN. */
    private function bookAndHold(int $customer, int $room, string $day, string $start, string $end): int
    {
        $this->a = $this->pdo();
        $this->a->beginTransaction();
        $this->a->prepare("INSERT INTO bookings (customer_id, room_id, booking_type, reservation_status, payment_status,
                             payment_method, is_usep_affiliated, affiliation_verified, room_price, discount_percent,
                             discount_amount, total_amount, refunds_allowed)
                           VALUES (?, ?, 'venue', 'pending', 'locked', 'gcash', 0, 0, 100, 0, 0, 100, 0)")
            ->execute([$customer, $room]);
        $id = (int) $this->a->lastInsertId();
        $this->made[] = $id;
        $call = $this->a->prepare('CALL sp_add_venue_slot(?, ?, ?, ?)');
        $call->execute([$id, $day, $start, $end]);
        $call->closeCursor();
        return $id;
    }

    /* Session B in its own process; A commits while B is (or would be) waiting. */
    private function secondSessionWhileFirstOpen(int $customer, int $room, string $day, string $start, string $end): array
    {
        $arg = base64_encode(json_encode($this->cfg() + compact('customer', 'room', 'day', 'start', 'end')));
        $proc = proc_open([PHP_BINARY, __DIR__ . '/venue-slot-session.php', $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        sleep(2);                                   /* B reaches its slot call while A is open */
        $stillWaiting = proc_get_status($proc)['running'];
        $this->a->commit();
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($proc);
        $res = json_decode($out, true);
        $this->assertIsArray($res, 'second session printed: ' . $out);
        if (!empty($res['booking'])) {
            $this->made[] = (int) $res['booking'];
        }
        return $res + ['waited' => $stillWaiting];
    }

    private function liveSlots(int $room, string $day): int
    {
        $st = $this->pdo()->prepare("SELECT COUNT(*) FROM venue_booking_slots s JOIN bookings b ON b.id = s.booking_id
                                      WHERE s.room_id = ? AND s.slot_date = ? AND s.released_at IS NULL
                                        AND b.reservation_status IN ('pending','approved')");
        $st->execute([$room, $day]);
        return (int) $st->fetchColumn();
    }

    private function fixture(): array
    {
        $pdo = $this->pdo();
        $room = (int) $pdo->query("SELECT r.id FROM rooms r JOIN event_room_details e ON e.room_id = r.id
                                    WHERE r.room_type = 'event' AND r.is_active = 1 ORDER BY r.id LIMIT 1")->fetchColumn();
        $customer = (int) $pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
        $day = date('Y-m-d', strtotime('+' . random_int(1500, 3000) . ' days'));   /* a far, free date */
        return [$customer, $room, $day];
    }

    public function test_the_same_slot_booked_a_moment_apart_is_refused_to_the_second_session(): void
    {
        [$customer, $room, $day] = $this->fixture();
        $this->bookAndHold($customer, $room, $day, '08:00:00', '12:00:00');

        $b = $this->secondSessionWhileFirstOpen($customer, $room, $day, '08:00:00', '12:00:00');

        $this->assertFalse($b['ok'], 'the second booking of the same slot was saved: ' . json_encode($b));
        $this->assertSame('45000', $b['code'], $b['message']);
        $this->assertStringContainsString('cleanup gap', $b['message']);
        $this->assertTrue($b['waited'], 'the second session must wait for the first one to commit');
        $this->assertSame(1, $this->liveSlots($room, $day));
    }

    public function test_a_later_time_in_the_same_room_still_books_once_the_first_commits(): void
    {
        [$customer, $room, $day] = $this->fixture();
        $this->bookAndHold($customer, $room, $day, '08:00:00', '12:00:00');

        /* 15:00 keeps the 60-minute cleanup gap after 12:00 */
        $b = $this->secondSessionWhileFirstOpen($customer, $room, $day, '15:00:00', '18:00:00');

        $this->assertTrue($b['ok'], 'refused: ' . json_encode($b));
        $this->assertSame(2, $this->liveSlots($room, $day));
    }
}
