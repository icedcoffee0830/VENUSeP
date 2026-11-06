<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/* Run once after migration 16: IDs stored before it are still plain files. Safe to re-run. */
class VenusepEncryptIds extends Command
{
    protected $signature = 'venusep:encrypt-ids';
    protected $description = 'Encrypt the ID files stored before encryption was added';

    public function handle(): int
    {
        legacy('db', 'documents');
        $pdo = venusep_db();
        if ($pdo === null) {
            $this->error('The database is unreachable.');
            return self::FAILURE;
        }
        $done = 0;
        foreach ($pdo->query("SELECT id, file_path FROM booking_documents WHERE document_type = 'customer_id' AND encrypted = 0 AND removed_at IS NULL")->fetchAll() as $d) {
            if (doc_encrypt_row((int) $d['id'], (string) $d['file_path'])) {
                $done++;
            }
        }
        $this->info("Encrypted {$done} ID file(s).");
        return self::SUCCESS;
    }
}
