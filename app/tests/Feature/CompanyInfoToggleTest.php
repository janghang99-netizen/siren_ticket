<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 화면 하단 운영사 사업자 정보 — 운영사 설정에서 보이기/가리기 (개발·시연 서버는 가린다) */
class CompanyInfoToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_footer_company_info_can_be_hidden_and_shown(): void
    {
        $this->get(route('policy', 'terms'))->assertOk()->assertSee('231-88-02240');

        $admin = User::factory()->create(['role' => 'super_admin']);
        $site = array_intersect_key(Setting::DEFAULTS, array_flip(Setting::SITE_KEYS));
        unset($site['company_info_visible']);   // 체크 해제 = 값 없이 보냄
        $this->actingAs($admin, 'web')->put(route('admin.settings.site'), $site)->assertRedirect();
        $this->assertSame('0', Setting::get('company_info_visible'));

        auth('web')->logout();
        $this->get(route('policy', 'terms'))->assertOk()
            ->assertDontSee('231-88-02240')->assertDontSee('태라글로벌')->assertDontSee('효령로')
            ->assertSee('이 사이트는 샘플 사이트입니다.');
        $this->get(route('home'))->assertOk()->assertSee('이 사이트는 샘플 사이트입니다.')
            ->assertDontSee('통신판매중개자')->assertDontSee('다담다은(는)');

        $this->actingAs($admin, 'web')->put(route('admin.settings.site'), $site + ['company_info_visible' => '1'])->assertRedirect();
        auth('web')->logout();
        $this->get(route('policy', 'terms'))->assertOk()->assertSee('231-88-02240');
    }
}
