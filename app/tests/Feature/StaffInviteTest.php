<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** 새 서버 관리 계정: 비밀번호 없이 만들고, 설정 링크로 본인이 정한다 */
class StaffInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_creates_super_admin_and_link_sets_password(): void
    {
        $this->artisan('staff:invite', ['email' => 'Ops@Example.test'])
            ->expectsOutputToContain('슈퍼관리자')
            ->expectsOutputToContain('/password/reset/')
            ->assertSuccessful();

        $user = User::where('email', 'ops@example.test')->firstOrFail();
        $this->assertTrue($user->isSuperAdmin());

        $token = \Illuminate\Support\Facades\Password::broker('users')->createToken($user);
        $this->post(route('staff.password.update'), ['token' => $token, 'email' => 'ops@example.test',
            'password' => 'NewPass12345', 'password_confirmation' => 'NewPass12345'])->assertRedirect();
        $this->assertTrue(Hash::check('NewPass12345', $user->fresh()->password));
    }

    public function test_invite_vendor_account_and_rejects_unknown_vendor(): void
    {
        $vendor = Vendor::create(['name' => '빵집', 'state' => 'approved']);
        $this->artisan('staff:invite', ['email' => 'shop@example.test', '--vendor' => $vendor->id])->assertSuccessful();
        $this->assertSame($vendor->id, User::where('email', 'shop@example.test')->value('vendor_id'));

        $this->artisan('staff:invite', ['email' => 'x@example.test', '--vendor' => 999])->assertFailed();
        $this->assertFalse(User::where('email', 'x@example.test')->exists());
    }
}
