<?php

namespace Tests\Unit;

use App\Support\BrandPalette;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrandPaletteTest extends TestCase
{
    public static function colors(): array
    {
        return array_map(fn ($c) => [$c], [
            'studio red' => '#CF0034', 'yellow' => '#FFD400', 'blue' => '#0057FF', 'green' => '#00A36C',
            'near black' => '#111111', 'white' => '#FFFFFF', 'mid grey' => '#7A7A7A', 'navy' => '#14213D',
            'pastel pink' => '#F7C6D9', 'orange' => '#FF6B00',
        ]);
    }

    #[DataProvider('colors')]
    public function test_everything_drawn_in_any_color_passes_contrast(string $color): void
    {
        $p = BrandPalette::for($color);
        $rgb = fn (string $key) => BrandPalette::rgb($p[$key]);
        $darkRaised = [35.36, 38.36, 43.56]; // --color-surface-raised, dark
        $lightSurface = BrandPalette::rgb(BrandPalette::LIGHT_SURFACE);

        $this->assertSame(strtoupper($color), $p['--brand'], 'the fill is the color itself');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($rgb('--brand-text-dark'), $darkRaised));
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($rgb('--brand-text-light'), $lightSurface));
        $this->assertGreaterThanOrEqual(3.0, BrandPalette::contrast($rgb('--brand-icon-dark'), $darkRaised));
        $this->assertGreaterThanOrEqual(3.0, BrandPalette::contrast($rgb('--brand-icon-light'), $lightSurface));

        // White on the fill when it passes; otherwise whichever reads better.
        $onWhite = BrandPalette::contrast($rgb('--brand'), [255, 255, 255]);
        $onDark = BrandPalette::contrast($rgb('--brand'), BrandPalette::rgb(BrandPalette::ON_DARK));
        $expected = $onWhite >= 4.5 || $onWhite >= $onDark ? '#FFFFFF' : BrandPalette::ON_DARK;
        $this->assertSame($expected, $p['--brand-on']);
        $this->assertGreaterThanOrEqual(max(3.0, min($onWhite, $onDark) * 0.9),
            BrandPalette::contrast($rgb('--brand-hover'), BrandPalette::rgb($p['--brand-on'])), 'hover keeps its text readable');
    }

    public function test_a_color_that_already_passes_is_left_alone(): void
    {
        // Mid blue passes 4.5:1 on white and 3:1 on dark as it is.
        $p = BrandPalette::for('#0057FF');
        $this->assertSame('#0057FF', $p['--brand-text-light']);
        $this->assertSame('#0057FF', $p['--brand-icon-light']);
    }

    public function test_a_missing_or_malformed_color_falls_back_to_the_studio_red(): void
    {
        $this->assertSame(BrandPalette::for(BrandPalette::DEFAULT), BrandPalette::for(null));
        $this->assertSame(BrandPalette::for(BrandPalette::DEFAULT), BrandPalette::for('red; background: url(x)'));
        $this->assertFalse(BrandPalette::isValid('#FFF'));
        $this->assertTrue(BrandPalette::isValid('#a1b2c3'));
    }

    public function test_the_scss_defaults_are_the_studio_reds_palette(): void
    {
        $scss = file_get_contents(__DIR__.'/../../resources/scss/base/_tokens.scss');
        $value = function (string $name) use ($scss) {
            preg_match('/^\s*'.preg_quote($name).':\s*(#[0-9A-Fa-f]{6});/m', $scss, $m);

            return $m[1] ?? null;
        };

        foreach (BrandPalette::for(BrandPalette::DEFAULT) as $name => $hex) {
            $this->assertSame($hex, $value($name), "{$name} in _tokens.scss");
            // Danger is the same red, fixed (it has no icon variants).
            if (! str_contains($name, 'icon')) {
                $this->assertSame($hex, $value(str_replace('--brand', '--danger', $name)), str_replace('--brand', '--danger', $name).' in _tokens.scss');
            }
        }
    }
}
