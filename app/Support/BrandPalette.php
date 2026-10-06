<?php

namespace App\Support;

// One brand color in, every primary token out (base/_tokens.scss reads
// them as --brand-*): the fill, its hover, the text on it, and the color
// as text and as icons on dark and on light surfaces -- each one nudged
// lighter or darker only as far as it takes to pass contrast, so whatever
// color someone picks, everything drawn in it stays readable.
//
// A person's own color themes the app for them (users.brand_color); a
// client's (companies.brand_color) themes what that client sees -- the
// portal and their invoice/proposal links. Unset, it's the studio red.
//
// The --brand-* defaults in _tokens.scss are this class's output for
// DEFAULT (BrandPaletteTest keeps them in step).
class BrandPalette
{
    const DEFAULT = '#CF0034';

    // WCAG: 4.5:1 for text, 3:1 for icons and other marks.
    const TEXT_CONTRAST = 4.5;

    const MARK_CONTRAST = 3.0;

    // The surfaces it's measured against -- the hardest case of each theme
    // (_tokens.scss): a drawer or modal in dark mode, a card in light.
    const DARK_BG = '#1B1E23';

    const LIGHT_SURFACE = '#F7F8F8';

    // Text on a fill: white when it passes, else near-black (the app's own
    // --color-secondary-on).
    const ON_LIGHT = '#FFFFFF';

    const ON_DARK = '#121418';

    public static function isValid(?string $hex): bool
    {
        return is_string($hex) && preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1;
    }

    // [css variable => value] for a color (null or invalid: the default).
    public static function for(?string $hex): array
    {
        $base = self::rgb(self::isValid($hex) ? $hex : self::DEFAULT);

        $darkRaised = self::mix(self::rgb(self::DARK_BG), self::rgb('#EBEFF9'), 0.96); // --color-surface-raised
        $lightSurface = self::rgb(self::LIGHT_SURFACE);

        $on = self::contrast($base, self::rgb(self::ON_LIGHT)) >= self::TEXT_CONTRAST
            || self::contrast($base, self::rgb(self::ON_LIGHT)) >= self::contrast($base, self::rgb(self::ON_DARK))
            ? self::ON_LIGHT : self::ON_DARK;

        return [
            '--brand' => self::hex($base),
            '--brand-hover' => self::hex(self::hover($base, $on)),
            '--brand-on' => $on,
            // On its own soft tint too (--color-primary-soft: 16% over dark,
            // 10% over white), where badges set it.
            '--brand-text-dark' => self::hex(self::passing($base, self::TEXT_CONTRAST, [$darkRaised, self::mix($base, $darkRaised, 0.16)])),
            '--brand-text-light' => self::hex(self::passing($base, self::TEXT_CONTRAST, [$lightSurface, self::mix($base, self::rgb('#FFFFFF'), 0.10)])),
            '--brand-icon-dark' => self::hex(self::passing($base, self::MARK_CONTRAST, [$darkRaised])),
            '--brand-icon-light' => self::hex(self::passing($base, self::MARK_CONTRAST, [$lightSurface])),
        ];
    }

    // For <html style="...">.
    public static function style(array $variables): string
    {
        return implode(' ', array_map(fn ($name, $value) => "{$name}: {$value};", array_keys($variables), $variables));
    }

    public static function contrast(array $a, array $b): float
    {
        [$lighter, $darker] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public static function rgb(string $hex): array
    {
        return array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    }

    // A step deeper under white text, or a step lighter under dark text --
    // the direction that keeps the text on it passing.
    private static function hover(array $base, string $on): array
    {
        [$h, $s, $l] = self::hsl($base);
        $step = 0.06;

        return self::fromHsl($h, $s, $on === self::ON_LIGHT || $l + $step > 0.97 ? max(0, $l - $step) : $l + $step);
    }

    // The color itself if it already passes against every surface; else
    // the nearest lightness that does, moving away from the surfaces
    // (lighter on dark, darker on light). The ends -- white, black -- pass
    // against any of the app's surfaces, so this always finds one.
    private static function passing(array $color, float $ratio, array $surfaces): array
    {
        $passes = fn (array $c) => collect($surfaces)->every(fn ($s) => self::contrast($c, $s) >= $ratio);
        if ($passes($color)) {
            return $color;
        }

        [$h, $s, $l] = self::hsl($color);
        $lighten = self::luminance($surfaces[0]) < 0.18;
        for ($step = 1; $step <= 200; $step++) {
            $candidate = self::fromHsl($h, $s, max(0, min(1, $l + ($lighten ? 1 : -1) * $step * 0.005)));
            if ($passes($candidate)) {
                return $candidate;
            }
        }

        return $lighten ? [255, 255, 255] : [0, 0, 0];
    }

    private static function luminance(array $rgb): float
    {
        [$r, $g, $b] = array_map(function ($channel) {
            $c = $channel / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    // `$weight` of $a, the rest $b -- CSS color-mix(in srgb, a w%, b).
    private static function mix(array $a, array $b, float $weight): array
    {
        return array_map(fn ($x, $y) => $x * $weight + $y * (1 - $weight), $a, $b);
    }

    private static function hex(array $rgb): string
    {
        return '#'.implode('', array_map(fn ($c) => sprintf('%02X', (int) round(max(0, min(255, $c)))), $rgb));
    }

    private static function hsl(array $rgb): array
    {
        [$r, $g, $b] = array_map(fn ($c) => $c / 255, $rgb);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return [0.0, 0.0, $l];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => fmod(($g - $b) / $d + 6, 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        } / 6;

        return [$h, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): array
    {
        if ($s == 0) {
            return array_fill(0, 3, round($l * 255));
        }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $channel = function (float $t) use ($p, $q) {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);

            return match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };
        };

        // Rounded here, so contrast is checked on the hex that's actually used.
        return [round($channel($h + 1 / 3) * 255), round($channel($h) * 255), round($channel($h - 1 / 3) * 255)];
    }
}
