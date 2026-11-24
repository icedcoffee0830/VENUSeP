<?php

namespace Tests\Unit\Failover;

use App\Failover\Servers;
use PHPUnit\Framework\TestCase;

/* The replication arithmetic behind Test and Switch, with positions the labs really saw
   (_tools/lab/two and four LAB-REPORT.md). */
class GtidTest extends TestCase
{
    public function test_seq_reads_domain_zero(): void
    {
        $this->assertSame(2550, Servers::seq('0-11-2550'));
        $this->assertSame(919, Servers::seq('1-3-7,0-11-919'));
        $this->assertSame(0, Servers::seq(''));
    }

    public function test_own_changes_is_a_difference_between_applied_and_received(): void
    {
        $this->assertFalse(Servers::ownChanges('0-11-2550', '0-11-2550'));
        $this->assertFalse(Servers::ownChanges('1-3-7,0-11-919', '0-11-919,1-3-7'), 'order does not matter');
        $this->assertTrue(Servers::ownChanges('0-2-46', '0-1-45'), 'lab two §7: a write made on the copy itself');
    }

    public function test_the_emergency_switch_takes_the_copy_with_the_most_changes(): void
    {
        $reports = [
            ['ip' => '192.168.43.11', 'role' => 'copy', 'slavePos' => '0-11-2550'],
            ['ip' => '192.168.43.12', 'role' => 'copy', 'slavePos' => '0-12-2650'],
            ['ip' => '192.168.43.13', 'role' => null, 'slavePos' => ''],
        ];
        $this->assertSame('192.168.43.12', Servers::furthest($reports));
        $this->assertNull(Servers::furthest([['ip' => 'x', 'role' => null, 'slavePos' => '']]));
    }
}
