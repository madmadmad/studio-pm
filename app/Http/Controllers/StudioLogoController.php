<?php

namespace App\Http\Controllers;

use App\Models\StudioProfile;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Settings' logo: upload (SVG or PNG) or remove the version for light
// backgrounds or the one for dark. The light one also gets a PNG copy for
// emails and PDFs -- itself when it's a PNG, rendered from it when it's an
// SVG (where the server can; otherwise emails keep the PNG they had).
class StudioLogoController extends Controller
{
    private const VARIANTS = ['light' => 'logo_path', 'dark' => 'logo_dark_path'];

    public function store(Request $request, string $variant)
    {
        abort_unless(isset(self::VARIANTS[$variant]), 404);
        $request->validate(['logo' => ['required', 'file', 'max:2048', 'mimes:svg,png']]);
        $file = $request->file('logo');
        $isSvg = $this->isSvg($file);
        if ($isSvg) {
            $this->assertSafeSvg($file);
        }

        $profile = StudioProfile::current();
        $column = self::VARIANTS[$variant];
        $path = $file->storeAs('branding', "logo-{$variant}-".Str::random(8).($isSvg ? '.svg' : '.png'), 'public');
        $this->forget($profile->{$column});
        $profile->{$column} = $path;

        $pngWarning = null;
        if ($variant === 'light') {
            $png = $isSvg ? $this->renderPng($path) : $path;
            if ($png) {
                if ($profile->logo_png_path !== $path) {
                    $this->forget($profile->logo_png_path);
                }
                $profile->logo_png_path = $png;
            } else {
                $pngWarning = 'Emails and PDFs keep their current logo -- upload a PNG to update them too.';
            }
        }
        $profile->save();

        return [...$this->logos($profile), 'warning' => $pngWarning];
    }

    // Back to the bundled lockup for that version.
    public function destroy(string $variant)
    {
        abort_unless(isset(self::VARIANTS[$variant]), 404);
        $profile = StudioProfile::current();
        $column = self::VARIANTS[$variant];
        $this->forget($profile->{$column});
        $profile->{$column} = null;
        if ($variant === 'light') {
            $this->forget($profile->logo_png_path);
            $profile->logo_png_path = null;
        }
        $profile->save();

        return $this->logos($profile);
    }

    private function logos(StudioProfile $profile): array
    {
        return [
            'logo' => $profile->logoUrl(),
            'logo_dark' => $profile->logoDarkUrl(),
            'custom_light' => (bool) $profile->logo_path,
            'custom_dark' => (bool) $profile->logo_dark_path,
        ];
    }

    private function isSvg(UploadedFile $file): bool
    {
        return in_array(strtolower($file->getClientOriginalExtension()), ['svg'], true)
            || str_contains((string) $file->getMimeType(), 'svg');
    }

    // The SVG is served from this site, so nothing in it may run.
    private function assertSafeSvg(UploadedFile $file): void
    {
        $svg = (string) file_get_contents($file->getRealPath());
        if (preg_match('/<script|<foreignObject|\son\w+\s*=|javascript:|<iframe|<embed|<object/i', $svg)) {
            throw ValidationException::withMessages(['logo' => 'That SVG has scripts or embedded content in it; export a plain SVG or a PNG.']);
        }
    }

    // A 900px-wide PNG of an SVG, for emails and PDFs; null when the
    // server can't render SVG.
    private function renderPng(string $svgPath): ?string
    {
        if (! class_exists(\Imagick::class)) {
            return null;
        }
        try {
            $image = new \Imagick;
            $image->setBackgroundColor(new \ImagickPixel('transparent'));
            $image->setResolution(300, 300);
            $image->readImageBlob(Storage::disk('public')->get($svgPath));
            $image->setImageFormat('png32');
            $image->resizeImage(900, 0, \Imagick::FILTER_LANCZOS, 1);
            $png = preg_replace('/\.svg$/', '.png', $svgPath);
            Storage::disk('public')->put($png, $image->getImageBlob());

            return $png;
        } catch (\Throwable) {
            return null;
        }
    }

    private function forget(?string $path): void
    {
        if ($path && str_starts_with($path, 'branding/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
