<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 개발 서버(SITE_NOINDEX=true)는 검색 엔진에 수집·노출되지 않는다 */
class NoindexTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_server_blocks_search_engines(): void
    {
        config(['siren.noindex' => true]);

        $this->get('/robots.txt')->assertOk()->assertSee("Disallow: /\n", false);
        $this->get('/')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false);
    }

    public function test_normal_site_allows_search_engines(): void
    {
        config(['siren.noindex' => false]);

        $this->get('/robots.txt')->assertOk()->assertSee("Disallow:\n", false)->assertDontSee('Disallow: /');
        $this->get('/')->assertOk()->assertHeaderMissing('X-Robots-Tag')->assertDontSee('name="robots"', false);
    }
}
