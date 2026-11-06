<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Staff actions check, then save. Two people on one booking at the same moment could both pass
   the check: cash recorded twice is two payment rows for one payment. So an action takes the
   booking's turn first. The other person is a real second process (other-staff-session.php). */
class TwoPeopleActingTest extends TestCase
{
    use SeedsBookings;

    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $id) {
            DB::delete('DELETE FROM system_receipts WHERE booking_id = ?', [$id]);
            DB::delete('DELETE FROM payments WHERE booking_id = ?', [$id]);
            DB::delete('DELETE FROM bookings WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    private function lockName(int $id): string
    {
        return DB::connection()->getDatabaseName() . '.booking.' . $id;
    }

    private function cashStay(): array
    {
        [$id, $ref] = $this->seedHostelBooking('approved', 'await_cash', 'cash', false, true);
        $this->made[] = $id;

        return [$id, $ref];
    }

    private function cashReceived(string $ref)
    {
        return $this->actingAsAdmin()->postWithToken(route('admin.booking-action'), ['action' => 'cash_received', 'booking' => $ref]);
    }

    public function test_the_second_person_waits_then_sees_what_the_first_did(): void
    {
        [$id, $ref] = $this->cashStay();
        $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk();   // warm: the request below must reach its read at once
        $flag = storage_path('framework/other-staff-' . $id . '.flag');
        $c = config('database.connections.mysql');
        $arg = base64_encode(json_encode(['dsn' => "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4",
            'user' => $c['username'], 'pass' => $c['password'], 'lock' => $this->lockName($id), 'booking' => $id, 'flag' => $flag]));
        $other = proc_open([PHP_BINARY, __DIR__ . '/other-staff-session.php', $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        for ($i = 0; $i < 200 && !is_file($flag); $i++) {
            usleep(20000);   // until the other person holds the turn
        }
        $this->assertFileExists($flag);
        unlink($flag);

        $res = $this->cashReceived($ref);
        stream_get_contents($pipes[1]);
        proc_close($other);

        $res->assertStatus(409)->assertJson(['ok' => false, 'message' => 'This stay is not waiting on a payment.']);
        $this->assertSame(1, DB::table('payments')->where('booking_id', $id)->count());
        $this->assertSame(0, DB::table('system_receipts')->where('booking_id', $id)->count());
    }

    public function test_someone_who_never_lets_go_gets_a_sentence_and_changes_nothing(): void
    {
        [$id, $ref] = $this->cashStay();
        config(['database.connections.rival' => config('database.connections.mysql')]);
        DB::connection('rival')->selectOne('SELECT GET_LOCK(?, 1) AS got', [$this->lockName($id)]);
        try {
            $this->cashReceived($ref)->assertStatus(409)
                ->assertExactJson(['ok' => false, 'message' => 'Someone else is working on this booking. Reload it and try again.']);
            $this->assertSame('await_cash', DB::table('bookings')->where('id', $id)->value('payment_status'));
        } finally {
            DB::connection('rival')->disconnect();
        }
    }

    public function test_the_turn_is_given_back_when_the_action_ends(): void
    {
        [$id, $ref] = $this->cashStay();
        $this->cashReceived($ref)->assertOk();
        $this->assertSame('paid_cash', DB::table('bookings')->where('id', $id)->value('payment_status'));
        $this->assertNull(DB::selectOne('SELECT IS_USED_LOCK(?) AS who', [$this->lockName($id)])->who);
    }
}
