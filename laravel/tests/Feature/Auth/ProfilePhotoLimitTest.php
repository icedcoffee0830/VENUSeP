<?php

namespace Tests\Feature\Auth;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Profile photo: 7 MB, the one upload limit (Aron, 2026-10-30) — a bigger file
   is refused with the clear message, and both profile pages say the limit. */
class ProfilePhotoLimitTest extends TestCase
{
    use MakesAccounts;

    private function pngOfSize(int $bytes): UploadedFile
    {
        /* a real 1x1 PNG padded with trailing bytes (still a valid image) */
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $tmp = tempnam(sys_get_temp_dir(), 'av');
        file_put_contents($tmp, $png . str_repeat("\0", $bytes - strlen($png)));

        return new UploadedFile($tmp, 'me.png', 'image/png', null, true);
    }

    public function test_a_photo_over_7_mb_is_refused_with_the_clear_message(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer')
            ->post(route('root.profile-save'), ['action' => 'photo', 'photo' => $this->pngOfSize(7 * 1024 * 1024 + 1)])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'This file is over 7 MB. Choose a smaller photo or a screenshot.']);
        $this->assertNull(DB::table('customers')->where('user_id', $c->id)->value('photo_path'));
    }

    public function test_a_photo_of_exactly_7_mb_is_accepted(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer')
            ->post(route('root.profile-save'), ['action' => 'photo', 'photo' => $this->pngOfSize(7 * 1024 * 1024)])
            ->assertOk()->assertJson(['ok' => true]);
        $this->post(route('root.profile-save'), ['action' => 'photo', 'remove' => '1'])->assertOk();   // leave no file behind
    }

    public function test_both_profile_pages_state_the_limit(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer')->get(route('customer.customer-profile'))->assertSee('up to 7 MB');
        $this->flushSession();
        $this->actingAsStaff()->get(route('admin.profile'))->assertSee('up to 7 MB');
    }
}
