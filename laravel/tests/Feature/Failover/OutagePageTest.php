<?php

namespace Tests\Feature\Failover;

use App\Failover\Outage;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/* One "database down" page (App\Failover\Outage): a browser gets the page with the super
   admin's way in, a fetch() gets JSON, and nobody waits more than the 3 s connect timeout. */
class OutagePageTest extends TestCase
{
    private string $host;
    private int $port;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = config('database.connections.mysql.host');
        $this->port = (int) config('database.connections.mysql.port');
    }

    protected function tearDown(): void
    {
        config(['database.connections.mysql.host' => $this->host, 'database.connections.mysql.port' => $this->port]);
        DB::purge('mysql');
        parent::tearDown();
    }

    private function databaseGone(): void
    {
        config(['database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 1]);   // nothing listens on port 1
        DB::purge('mysql');
    }

    public function test_an_unreachable_database_gets_the_outage_page_or_json_quickly(): void
    {
        $this->databaseGone();
        $t = microtime(true);
        $this->get('/', ['Accept' => 'text/html'])->assertStatus(503)->assertHeader('Retry-After', '30')
            ->assertSee('The system is switching servers')
            ->assertSee('Nothing you saved is lost.')
            ->assertSee('/server-emergency', false);
        $this->getJson(route('root.notifications'))->assertStatus(503)->assertJson(['ok' => false, 'error' => 'db_down']);
        $this->assertLessThan(7, microtime(true) - $t, 'two pages, one try each in the 3 s connect timeout');
    }

    public function test_a_save_refused_by_a_read_only_server_says_it_was_not_saved(): void
    {
        $readOnly = new QueryException('mysql', 'UPDATE x SET y = 1', [],
            new PDOException('SQLSTATE[HY000]: General error: 1290 The MariaDB server is running with the --read-only option'));
        $readOnly->errorInfo = ['HY000', 1290, 'read-only'];
        $html = Request::create('/x', 'POST', server: ['HTTP_ACCEPT' => 'text/html']);

        $page = Outage::render($readOnly, $html);
        $this->assertSame(503, $page->getStatusCode());
        $this->assertStringContainsString('was <strong>not</strong> saved', $page->getContent());

        $lost = new QueryException('mysql', 'SELECT 1', [], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
        $this->assertSame(503, Outage::render($lost, $html)->getStatusCode());

        $plain = new QueryException('mysql', 'SELECT nope', [], new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'nope'"));
        $this->assertNull(Outage::render($plain, $html), 'an ordinary SQL error is not an outage');
    }
}
