<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Notifications\StaffResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** 관리 화면 계정 비밀번호 찾기·설정 — 이관한 판매자는 운영사가 보낸 설정 메일로 첫 비밀번호를 정한다 */
class StaffPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $vendor = Vendor::create(['name' => '도본팡', 'state' => 'approved']);
        $this->seller = User::factory()->create(['role' => 'vendor', 'vendor_id' => $vendor->id, 'email' => 'seller@example.com']);
    }

    public function test_seller_resets_password_by_mail_link(): void
    {
        $this->get(route('login'))->assertSee('비밀번호를 잊었어요');
        $this->post(route('staff.password.send'), ['email' => 'seller@example.com'])->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($this->seller, StaffResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->get(route('staff.password.reset', ['token' => $token, 'email' => 'seller@example.com']))->assertOk()->assertSee('새 비밀번호 설정');
        // 약한 비밀번호는 거절
        $this->post(route('staff.password.update'), ['token' => $token, 'email' => 'seller@example.com', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->post(route('staff.password.update'), ['token' => $token, 'email' => 'seller@example.com', 'password' => 'dadamda2026', 'password_confirmation' => 'dadamda2026'])
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('dadamda2026', $this->seller->fresh()->password));
        $this->post(route('login'), ['email' => 'seller@example.com', 'password' => 'dadamda2026'])->assertRedirect(route('partner.board'));
    }

    public function test_unknown_email_gets_same_message_and_no_mail(): void
    {
        $this->post(route('staff.password.send'), ['email' => 'nobody@example.com'])->assertSessionHas('status');
        Notification::assertNothingSent();
    }

    public function test_bad_token_is_refused(): void
    {
        $this->post(route('staff.password.update'), ['token' => 'wrong', 'email' => 'seller@example.com', 'password' => 'dadamda2026', 'password_confirmation' => 'dadamda2026'])
            ->assertSessionHasErrors('email');
    }

    public function test_admin_sends_set_password_mail_to_vendor(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->get(route('admin.vendors.show', $this->seller->vendor_id))->assertSee('비밀번호 설정 메일 보내기');
        $this->post(route('admin.vendors.password-link', $this->seller->vendor_id))->assertSessionHas('status', fn ($s) => str_contains($s, 'seller@example.com'));
        Notification::assertSentTo($this->seller, StaffResetPassword::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vendor.password_link']);

        // 입점업체는 이 기능을 못 쓴다
        $this->actingAs($this->seller)->post(route('admin.vendors.password-link', $this->seller->vendor_id))->assertForbidden();
    }
}
