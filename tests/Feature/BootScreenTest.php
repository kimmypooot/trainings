<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The IMIS boot screen's styles are inlined at the top of the page, so it
 * paints before app.css arrives, and the card IMIS hands over to carries IMIS's
 * logo.
 */
class BootScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_boot_preloader_styles_are_inlined_before_the_stylesheet(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('class="imis-preloader"', $html);
        $this->assertStringContainsString('window.__bootAt', $html);
        $this->assertStringContainsString('imis-boot-in', $html);
        // The inlined rules come before the Vite stylesheet, which is the point.
        $this->assertLessThan(strpos($html, 'app.css') ?: PHP_INT_MAX, strpos($html, '.imis-preloader{'));
    }

    public function test_the_opening_card_from_imis_uses_the_imis_logo_and_no_boot_timer(): void
    {
        config(['services.imis.sso_url' => 'https://imis.test/api/v1/sso/validate']);

        $this->get('/sso?sso_token=abc')
            ->assertOk()
            ->assertSee('data-instant', false)
            ->assertSee('/images/imis/imis-logo.png', false)
            ->assertDontSee('class="imis-preloader"', false)
            ->assertDontSee('window.__bootAt', false);
    }
}
