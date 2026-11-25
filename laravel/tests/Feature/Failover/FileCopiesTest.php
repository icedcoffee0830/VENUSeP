<?php

namespace Tests\Feature\Failover;

use App\Failover\FileCopies;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/* Photos, IDs and receipts copied between the laptops (App\Failover\FileCopies). The private
   folder is pointed at a temporary one; the inbox is called in-process with real encryption. */
class FileCopiesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = str_replace('\\', '/', sys_get_temp_dir()) . '/venusep-files-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/ids', 0777, true);
        config(['venusep.doc_root' => $this->root]);
        @unlink(storage_path('app/failover/deleted.json'));
    }

    protected function tearDown(): void
    {
        legacy('backup');
        backup_rmtree($this->root);
        @rmdir($this->root);
        @unlink(storage_path('app/failover/deleted.json'));
        parent::tearDown();
    }

    private function inbox(array $msg, ?string $key = null)
    {
        $body = $key ? (new Encrypter($key, config('app.cipher')))->encryptString(json_encode($msg)) : Crypt::encryptString(json_encode($msg));
        return $this->call('POST', route('failover.inbox'), [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    public function test_only_paths_inside_the_backed_up_folders_are_accepted(): void
    {
        $this->assertSame($this->root . '/ids/a.jpg', FileCopies::path('private/ids/a.jpg'));
        $this->assertNotNull(FileCopies::path('photos/rooms/r1/a.jpg'));
        foreach (['private/../x', 'private/backups/b.tar.gz', '/etc/passwd', 'C:/Windows/x', 'nowhere/a.jpg', 'private/', "private/a\0.jpg"] as $bad) {
            $this->assertNull(FileCopies::path($bad), $bad);
        }
    }

    public function test_the_plan_sends_what_is_missing_or_older_and_removes_what_was_deleted_since(): void
    {
        $mine = ['private/a' => [10, 100], 'private/b' => [10, 200], 'private/c' => [10, 300]];
        $theirs = ['private/b' => [10, 200], 'private/c' => [9, 250], 'private/old' => [5, 50], 'private/newer' => [5, 500]];
        $tombs = ['private/old' => 60, 'private/newer' => 400];
        $this->assertSame(['put' => ['private/a', 'private/c'], 'del' => ['private/old']], FileCopies::plan($mine, $theirs, $tombs),
            'b is the same; "newer" was saved there after it was deleted here, so it stays');
    }

    public function test_the_inbox_takes_only_messages_in_our_key_and_fresh_ones(): void
    {
        $put = ['type' => 'put', 'path' => 'private/ids/x.bin', 'mtime' => 1_700_000_000, 'data' => base64_encode('ID BYTES'), 'sent' => time()];
        $this->inbox($put, Encrypter::generateKey(config('app.cipher')))->assertStatus(403);   // another key
        $this->inbox(['sent' => time() - 600] + $put)->assertStatus(403);                       // replayed later
        $this->inbox(['path' => 'private/backups/x'] + $put)->assertStatus(422);
        $this->assertFileDoesNotExist($this->root . '/ids/x.bin');

        $this->inbox($put)->assertOk();
        $this->assertSame('ID BYTES', file_get_contents($this->root . '/ids/x.bin'));
        $this->assertSame(1_700_000_000, filemtime($this->root . '/ids/x.bin'), 'the date travels with it');

        $list = json_decode(Crypt::decryptString($this->inbox(['type' => 'list', 'sent' => time()])->getContent()), true);
        $this->assertSame([8, 1_700_000_000], $list['files']['private/ids/x.bin']);

        $this->inbox(['type' => 'del', 'path' => 'private/ids/x.bin', 'at' => 1_600_000_000, 'sent' => time()])->assertOk();
        $this->assertFileExists($this->root . '/ids/x.bin', 'a tombstone older than the file leaves it');
        $this->inbox(['type' => 'del', 'path' => 'private/ids/x.bin', 'at' => 1_700_000_001, 'sent' => time()])->assertOk();
        $this->assertFileDoesNotExist($this->root . '/ids/x.bin');
    }

    /* the two-laptop test: a laptop that never heard of a switch kept using its own locked copy */
    public function test_a_switch_on_another_laptop_points_this_laptops_website_at_the_new_main(): void
    {
        $file = sys_get_temp_dir() . '/venusep-server-' . bin2hex(random_bytes(4)) . '.json';
        putenv('VENUSEP_SERVER_FILE=' . $file);
        $_ENV['VENUSEP_SERVER_FILE'] = $_SERVER['VENUSEP_SERVER_FILE'] = $file;
        try {
            file_put_contents($file, json_encode(['version' => 3, 'db_host' => '10.82.16.12', 'laptops' => ['10.82.16.12']]));
            $this->inbox(['type' => 'server', 'db_host' => '127.0.0.1;dbname=x', 'sent' => time()])->assertStatus(422);
            $this->inbox(['type' => 'server', 'db_host' => '10.82.16.115', 'laptops' => ['10.82.16.115', '10.82.16.12'], 'sent' => time()])->assertOk();
            $this->assertSame('10.82.16.115', \App\Failover\ServerFile::dbHost());
            $this->assertSame(['10.82.16.12', '10.82.16.115'], \App\Failover\ServerFile::laptops());
        } finally {
            foreach (glob($file . '*') as $f) {
                @unlink($f);
            }
            putenv('VENUSEP_SERVER_FILE=no-server-file-in-tests');
            $_ENV['VENUSEP_SERVER_FILE'] = $_SERVER['VENUSEP_SERVER_FILE'] = 'no-server-file-in-tests';
        }
    }

    public function test_a_file_deleted_here_is_not_taken_back_from_a_laptop_that_still_has_it(): void
    {
        @mkdir(storage_path('app/failover'), 0775, true);
        file_put_contents(storage_path('app/failover/deleted.json'), json_encode(['private/ids/gone.bin' => 1_700_000_100]));
        $res = $this->inbox(['type' => 'put', 'path' => 'private/ids/gone.bin', 'mtime' => 1_700_000_000, 'data' => base64_encode('x'), 'sent' => time()]);
        $this->assertSame(['ok' => false, 'deleted' => true], json_decode(Crypt::decryptString($res->getContent()), true));
        $this->assertFileDoesNotExist($this->root . '/ids/gone.bin');
    }
}
