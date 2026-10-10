<?php

namespace Tests\Feature;

use App\Models\StudioProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudioLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_a_png_logo_is_used_everywhere_including_emails_and_pdfs(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->post('/api/studio-profile/logo/light', ['logo' => UploadedFile::fake()->image('logo.png', 600, 200)])
            ->assertOk()
            ->assertJsonPath('custom_light', true);

        $profile = StudioProfile::current();
        Storage::disk('public')->assertExists($profile->logo_path);
        $this->assertSame($profile->logo_path, $profile->logo_png_path);
        $this->assertStringContainsString('/storage/branding/', $profile->logoPngUrl());
        // PDFs get the image itself, so it needn't be on this server's disk.
        $this->assertStringStartsWith('data:image/png;base64,', $profile->logoPngSrc());
        $this->assertSame(Storage::disk('public')->get($profile->logo_png_path), base64_decode(substr($profile->logoPngSrc(), strlen('data:image/png;base64,'))));
        // No dark version yet: dark backgrounds use the uploaded logo.
        $this->assertSame($profile->logoUrl(), $profile->logoDarkUrl());
    }

    public function test_an_svg_with_a_script_is_refused(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->post('/api/studio-profile/logo/light', ['logo' => $svg], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo');
        $this->assertNull(StudioProfile::current()->logo_path);
    }

    public function test_use_default_removes_the_upload(): void
    {
        $manager = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($manager)->post('/api/studio-profile/logo/dark', ['logo' => UploadedFile::fake()->image('dark.png')])->assertOk();
        $path = StudioProfile::current()->logo_dark_path;

        $this->actingAs($manager)->deleteJson('/api/studio-profile/logo/dark')->assertOk()->assertJsonPath('logo_dark', StudioProfile::DEFAULT_LOGO_DARK);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_team_members_cant_change_the_logo(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'team_member']))
            ->post('/api/studio-profile/logo/light', ['logo' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
