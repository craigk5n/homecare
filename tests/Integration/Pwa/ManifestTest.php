<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Pwa;

use PHPUnit\Framework\TestCase;

/**
 * Validates the shape of the project's `manifest.json` against the
 * subset of the W3C Web App Manifest spec that browsers actually
 * enforce for "Add to Home Screen" eligibility:
 *   - name + short_name (Android requires both)
 *   - start_url (relative)
 *   - display: standalone
 *   - theme_color + background_color
 *   - At least one 192px and one 512px icon, both PNG
 *   - At least one icon with purpose=maskable for Android
 *   - All icon files referenced actually exist on disk
 */
final class ManifestTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $manifest;

    public static function setUpBeforeClass(): void
    {
        $path = __DIR__ . '/../../../manifest.json';
        $raw = file_get_contents($path);
        self::assertNotFalse($raw, "manifest.json missing at {$path}");

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'manifest must be a JSON object');
        /** @var array<string,mixed> $decoded */
        self::$manifest = $decoded;
    }

    public function testRequiredTopLevelFields(): void
    {
        foreach (['name', 'short_name', 'start_url', 'display', 'icons'] as $key) {
            $this->assertArrayHasKey($key, self::$manifest, "manifest missing '{$key}'");
        }
    }

    public function testDisplayIsStandalone(): void
    {
        // standalone is what makes the app open without browser chrome
        // when launched from the home screen.
        $this->assertSame('standalone', self::$manifest['display']);
    }

    public function testThemeAndBackgroundColors(): void
    {
        $this->assertArrayHasKey('theme_color', self::$manifest);
        $this->assertArrayHasKey('background_color', self::$manifest);

        $theme = self::$manifest['theme_color'];
        $this->assertIsString($theme);
        // Hex shape; lets the splash screen render correctly on Android.
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{3,8}$/', $theme);
    }

    public function testStartUrlIsRelative(): void
    {
        $startUrl = self::$manifest['start_url'];
        $this->assertIsString($startUrl);
        $this->assertStringStartsNotWith('http://', $startUrl);
        $this->assertStringStartsNotWith('https://', $startUrl);
    }

    /**
     * @return list<array<array-key,mixed>>
     */
    private static function icons(): array
    {
        $icons = self::$manifest['icons'];
        self::assertIsArray($icons);
        $out = [];
        foreach ($icons as $icon) {
            self::assertIsArray($icon);
            $out[] = $icon;
        }

        return $out;
    }

    public function testIconsIncludeRequired192And512Sizes(): void
    {
        $icons = self::icons();
        $this->assertNotEmpty($icons);

        $sizes = [];
        foreach ($icons as $icon) {
            $this->assertArrayHasKey('src', $icon);
            $this->assertArrayHasKey('sizes', $icon);
            $this->assertArrayHasKey('type', $icon);
            $size = $icon['sizes'];
            $this->assertIsString($size);
            $sizes[] = $size;
        }

        $this->assertContains('192x192', $sizes, 'a 192px icon is required for Android install');
        $this->assertContains('512x512', $sizes, 'a 512px icon is required for the splash screen');
    }

    public function testAtLeastOneMaskableIcon(): void
    {
        $hasMaskable = false;
        foreach (self::icons() as $icon) {
            $purpose = $icon['purpose'] ?? null;
            if (is_string($purpose) && str_contains($purpose, 'maskable')) {
                $hasMaskable = true;
                break;
            }
        }
        $this->assertTrue($hasMaskable, 'maskable icon required for Android adaptive launchers');
    }

    public function testEveryIconFileExistsOnDisk(): void
    {
        $root = __DIR__ . '/../../..';
        foreach (self::icons() as $icon) {
            $src = $icon['src'] ?? null;
            $this->assertIsString($src);
            $path = $root . '/' . $src;
            $this->assertFileExists($path, "manifest references missing icon: {$src}");
            $this->assertGreaterThan(0, filesize($path), "icon {$src} is empty");
        }
    }

    public function testServiceWorkerExists(): void
    {
        $sw = __DIR__ . '/../../../sw.js';
        $this->assertFileExists($sw);
        $contents = file_get_contents($sw);
        $this->assertNotFalse($contents);
        // Sanity: must register the install/fetch handlers, otherwise
        // the offline shell does nothing.
        $this->assertMatchesRegularExpression(
            '/addEventListener\(\s*[\'"]install[\'"]/',
            $contents
        );
        $this->assertMatchesRegularExpression(
            '/addEventListener\(\s*[\'"]fetch[\'"]/',
            $contents
        );
    }

    public function testManifestLinkAndRegistrationInPrintHeader(): void
    {
        // Cheap textual check that init.php's print_header() actually
        // emits the manifest link + SW registration. Avoids spinning up
        // the full PHP stack for a one-line sanity test.
        $init = file_get_contents(__DIR__ . '/../../../includes/init.php');
        $this->assertNotFalse($init);
        $this->assertStringContainsString('<link rel="manifest" href="manifest.json">', $init);
        $this->assertStringContainsString('navigator.serviceWorker.register("sw.js")', $init);
        $this->assertStringContainsString('<meta name="theme-color"', $init);
    }
}
