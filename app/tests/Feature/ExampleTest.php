<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /** 공연도 매장도 없어도 홈은 상시 매장 주문 화면(빈 상태 안내)으로 열린다. */
    public function test_home_renders_empty_state(): void
    {
        $this->get('/')->assertOk()->assertSee('먼저 주문하고')->assertSee('지금 주문 받는 매장이 아직 없어요');
    }

    /** 시드 데이터로 주요 화면이 모두 열린다. */
    public function test_seeded_pages_render(): void
    {
        $this->seed();

        $this->get('/')->assertOk()->assertSee('아스테리아');
        $this->get('/concerts/asteria-2026-seoul')->assertOk()->assertSee('구역 고르기');
        $this->get('/concerts/asteria-2026-seoul/stores')->assertOk()->assertSee('아레나 커피');
        $this->get('/concerts/asteria-2026-seoul/stores/arena1')->assertOk();
        $this->get('/display/arena1')->assertOk();
        $this->get('/partner')->assertRedirect('/login');
        $this->get('/admin/events')->assertRedirect('/login');
    }
}
