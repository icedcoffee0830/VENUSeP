<?php

namespace Tests\Feature\CustomerBooking;

use PDO;
use PDOException;
use Tests\TestCase;

/* Migration 15: sp_assign_bed no longer runs START TRANSACTION / COMMIT /
   ROLLBACK. Its START TRANSACTION silently committed the caller's open
   transaction, so a hostel booking whose later step failed was left behind,
   bed and all; and its error handler's ROLLBACK threw the caller's whole
   transaction away. The caller owns the transaction now. Runs on its own
   connection; whatever a test leaves is deleted again. */
class AssignBedTransactionTest extends TestCase
{
    private ?PDO $pdo = null;
    private array $made = [];
    private array $closures = [];

    protected function tearDown(): void
    {
        if ($this->pdo && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        foreach ($this->made as $id) {
            $this->db()->prepare('DELETE FROM bookings WHERE id = ?')->execute([$id]);
        }
        foreach ($this->closures as $id) {
            $this->db()->prepare('DELETE FROM maintenance_windows WHERE id = ?')->execute([$id]);
        }
        parent::tearDown();
    }

    private function db(): PDO
    {
        if (!$this->pdo) {
            $c = config('database.connections.mysql');
            $this->pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4",
                (string) $c['username'], (string) $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        return $this->pdo;
    }

    private function one(string $sql, array $args = []): mixed
    {
        $st = $this->db()->prepare($sql);
        $st->execute($args);
        return $st->fetchColumn();
    }

    /* Inside an OPEN transaction: a hostel stay with one guest in Hostel Room 1,
       far ahead on free nights. Returns [booking id, occupant id, bed id, room id]. */
    private function stayInOpenTransaction(): array
    {
        $roomId = (int) $this->one("SELECT id FROM rooms WHERE room_code = 'h1'");
        $in = date('Y-m-d', strtotime('+' . random_int(2000, 4000) . ' days'));
        $out = date('Y-m-d', strtotime($in . ' +2 days'));
        $pdo = $this->db();
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO bookings (customer_id, room_id, booking_type, reservation_status, payment_status,
                         is_usep_affiliated, affiliation_verified, room_price, discount_percent, discount_amount,
                         total_amount, refunds_allowed)
                       VALUES ((SELECT MIN(id) FROM customers), ?, 'hostel', 'pending', 'locked', 0, 0, 700, 0, 0, 700, 0)")
            ->execute([$roomId]);
        $bookingId = (int) $pdo->lastInsertId();
        $this->made[] = $bookingId;
        $pdo->prepare('INSERT INTO hostel_booking_details (booking_id, check_in_date, check_out_date, checked_in) VALUES (?, ?, ?, 0)')
            ->execute([$bookingId, $in, $out]);
        $pdo->prepare("INSERT INTO hostel_occupants (booking_id, full_name, gender, is_primary_guest) VALUES (?, 'Txn Guest', 'female', 1)")
            ->execute([$bookingId]);
        $occId = (int) $pdo->lastInsertId();
        $bedId = (int) $this->one('SELECT id FROM hostel_beds WHERE room_id = ? AND is_active = 1 ORDER BY display_order, id LIMIT 1', [$roomId]);

        return [$bookingId, $occId, $bedId, $roomId, $in, $out];
    }

    private function assignBed(int $bookingId, int $occId, int $bedId): void
    {
        $call = $this->db()->prepare('CALL sp_assign_bed(?, ?, ?)');
        $call->execute([$bookingId, $occId, $bedId]);
        $call->closeCursor();
    }

    public function test_a_hostel_booking_whose_later_step_fails_leaves_nothing_behind(): void
    {
        [$bookingId, $occId, $bedId] = $this->stayInOpenTransaction();
        $this->assignBed($bookingId, $occId, $bedId);

        $this->assertSame('1', (string) $this->one('SELECT @@in_transaction'), 'sp_assign_bed committed the caller\'s transaction');
        $this->assertSame(2, (int) $this->one('SELECT COUNT(*) FROM bed_reservation_nights WHERE booking_id = ?', [$bookingId]));

        /* a later step (approval, payment, receipt...) fails: the caller rolls back */
        $this->db()->rollBack();

        $this->assertSame(0, (int) $this->one('SELECT COUNT(*) FROM bookings WHERE id = ?', [$bookingId]));
        $this->assertSame(0, (int) $this->one('SELECT COUNT(*) FROM bed_reservations WHERE booking_id = ?', [$bookingId]));
        $this->assertSame(0, (int) $this->one('SELECT COUNT(*) FROM bed_reservation_nights WHERE booking_id = ?', [$bookingId]));
    }

    /* A refusal (SIGNAL 45000) is unchanged, and it no longer rolls the
       caller's transaction back behind its back. */
    public function test_a_refusal_keeps_its_error_and_leaves_the_callers_transaction_to_the_caller(): void
    {
        [$bookingId, $occId, $bedId, $roomId, $in] = $this->stayInOpenTransaction();
        $this->db()->prepare("INSERT INTO maintenance_windows (room_id, from_date, until_date, reason, blocks_booking)
                              VALUES (?, ?, ?, 'test closure', 1)")->execute([$roomId, $in, $in]);
        $this->closures[] = (int) $this->db()->lastInsertId();

        try {
            $this->assignBed($bookingId, $occId, $bedId);
            $this->fail('a hard closure inside the stay was not refused');
        } catch (PDOException $e) {
            $this->assertSame('45000', $e->getCode());
            $this->assertStringContainsString('HARD maintenance falls during the requested stay.', $e->getMessage());
        }

        $this->assertSame('1', (string) $this->one('SELECT @@in_transaction'), 'the caller\'s transaction was rolled back by the procedure');
        $this->assertSame(1, (int) $this->one('SELECT COUNT(*) FROM bookings WHERE id = ?', [$bookingId]));
        $this->db()->rollBack();
        $this->assertSame(0, (int) $this->one('SELECT COUNT(*) FROM maintenance_windows WHERE id = ?', [$this->closures[0]]));
    }
}
