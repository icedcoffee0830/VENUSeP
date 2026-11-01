<?php

namespace Tests\Feature\CustomerBooking;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/* Upload cap (Aron 2026-10-30: 7 MB for every upload). The booking pages take the
   number from documents.php's DOC_MAX_BYTES — the size the server refuses — show
   it in the ID and GCash receipt hints, and refuse a bigger ID before upload with
   "This file is over N MB. Choose a smaller photo or a screenshot." */
class UploadLimitTest extends TestCase
{
    public static function pages(): array
    {
        return [['customer.room-reservation', 'r1'], ['customer.hostel-reservation', 'h1']];
    }

    #[DataProvider('pages')]
    public function test_the_hints_and_the_id_check_use_the_servers_cap(string $route, string $room): void
    {
        $html = $this->actingAsCustomer('aronerfe0830@gmail.com')->get(route($route, ['room' => $room]))->assertOk()
            ->assertSee('up to ${UPLOAD_MAX_MB} MB', false)->getContent();
        $mb = intdiv(DOC_MAX_BYTES, 1048576);
        $this->assertStringContainsString("const UPLOAD_MAX_MB = $mb;", $html);
        $this->assertSame(2, substr_count($html, 'up to ${UPLOAD_MAX_MB} MB'), 'ID + GCash receipt hints');

        /* the page's own uploadId(), run in Node with a file one byte over the cap, then one under */
        $this->assertSame(1, preg_match('/^function uploadId\(input\)\{.*?^\}$/ms', $html, $m), 'no uploadId()');
        $file = tempnam(sys_get_temp_dir(), 'upl') . '.js';
        file_put_contents($file, "const UPLOAD_MAX_MB = $mb; let state = { idFile: null, submitError: null }; function render(){}\n"
            . "const URL = { createObjectURL: () => 'blob:x', revokeObjectURL(){} };\n" . $m[0] . "\n"
            . "uploadId({ files: [{ name: 'big.jpg', size: UPLOAD_MAX_MB*1048576 + 1 }], value: 'x' });\n"
            . "const big = [state.idFile, state.submitError];\n"
            . "uploadId({ files: [{ name: 'ok.jpg', size: 1000 }], value: 'x' });\n"
            . "console.log(JSON.stringify([big, state.idFile && state.idFile.name, state.submitError]));");
        $out = trim((string) shell_exec('node ' . escapeshellarg($file) . ' 2>&1'));
        @unlink($file);
        $this->assertSame(json_encode([[null, "This file is over $mb MB. Choose a smaller photo or a screenshot."], 'ok.jpg', null]), $out);
    }
}
