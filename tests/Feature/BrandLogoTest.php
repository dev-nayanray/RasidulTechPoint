<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Brand logo regression tests.
 *
 * The header and footer used to load uploads/logos/rtp-logo.jpg. public/uploads
 * is gitignored, so the file was missing on every fresh deploy and the header
 * showed a broken image. The logo is now committed under public/assets/img.
 */
final class BrandLogoTest extends TestCase
{
    private function home(): string
    {
        return (new \App\Controllers\Storefront\HomeController())->index()->body();
    }

    public function testLogoAssetsAreCommittedAndValidSvg(): void
    {
        foreach (['logo-mark.svg', 'logo-full.svg', 'logo-full-light.svg', 'favicon.svg'] as $f) {
            $path = BASE_PATH . '/public/assets/img/' . $f;
            $this->assertTrue(is_file($path), $f . ' must exist');
            $xml = simplexml_load_file($path);
            $this->assertTrue($xml !== false, $f . ' must be well-formed XML');
            $this->assertSame('svg', $xml->getName());
            $this->assertStringNotContainsString('<script', strtolower((string) file_get_contents($path)));
        }
    }

    public function testHomepageHeaderAndFooterUseCommittedLogo(): void
    {
        $html = $this->home();
        $this->assertSame(2, substr_count($html, 'assets/img/logo-mark.svg'), 'header + footer');
        $this->assertStringContainsString('rtp-logo--light', $html);
        $this->assertStringContainsString('rtp-logo--dark', $html);
        $this->assertStringContainsString('assets/img/favicon.svg', $html);
    }

    public function testNoViewDependsOnGitignoredUploadedLogo(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/resources/views', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $this->assertStringNotContainsString('uploads/logos/rtp-logo', (string) file_get_contents($file->getPathname()), $file->getFilename());
        }
    }

    public function testLogoPartialVariantsAreSafe(): void
    {
        $dark = \App\Support\View::partial('layouts/_logo', ['variant' => 'dark']);
        $odd = \App\Support\View::partial('layouts/_logo', ['variant' => '"><script>x</script>']);
        $this->assertStringContainsString('rtp-logo--dark', $dark);
        $this->assertStringContainsString('rtp-logo--light', $odd);
        $this->assertStringNotContainsString('<script', $odd);
    }
}
