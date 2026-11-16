<?php

namespace Tests\Feature\Failover;

use App\Failover\ServerFile;
use PDO;
use Tests\TestCase;

/* The server file is the one place besides .env the database address comes from. */
class ServerFileTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/venusep-server-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_the_file_names_the_main_database_and_anything_else_falls_back_to_env(): void
    {
        $this->assertNull(ServerFile::dbHost($this->file), 'no file');

        file_put_contents($this->file, json_encode(['db_host' => '192.168.43.10', 'version' => 1]));
        $this->assertSame('192.168.43.10', ServerFile::dbHost($this->file));

        file_put_contents($this->file, '{"db_host": "192.168.43.10"');   // cut off mid-write
        $this->assertNull(ServerFile::dbHost($this->file));

        file_put_contents($this->file, json_encode(['db_host' => "127.0.0.1;dbname=other"]));
        $this->assertNull(ServerFile::dbHost($this->file), 'only an IP or a host name becomes the PDO host');

        /* tests point VENUSEP_SERVER_FILE at nothing, so the live connection is .env's */
        $this->assertSame(env('DB_HOST', '127.0.0.1'), config('database.connections.mysql.host'));
        $this->assertSame(3, config('database.connections.mysql.options')[PDO::ATTR_TIMEOUT], 'an unreachable server fails in 3 s, not 20');
    }
}
