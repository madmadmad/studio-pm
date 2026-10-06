<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\AvatarProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_upload_and_remove_their_own_avatar(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 900, 700),
        ])->assertOk();

        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        Storage::disk('local')->assertExists($user->avatar_path);
        $this->assertNotNull($user->avatar_url);

        $oldPath = $user->avatar_path;
        $this->actingAs($user)->deleteJson('/api/profile/avatar')->assertOk();

        $user->refresh();
        $this->assertNull($user->avatar_path);
        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_a_non_image_avatar_upload_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable();
    }

    public function test_a_contact_can_upload_their_own_avatar_via_the_portal(): void
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);
        $contact->forceFill(['portal_invited_at' => now()])->save();

        $this->actingAs($contact, 'client')->postJson('/api/portal/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 900, 700),
        ])->assertOk();

        $contact->refresh();
        $this->assertNotNull($contact->avatar_path);
        Storage::disk('local')->assertExists($contact->avatar_path);
    }

    public function test_uploading_a_new_avatar_replaces_and_deletes_the_old_file(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg', 900, 700),
        ])->assertOk();
        $firstPath = $user->refresh()->avatar_path;

        $this->actingAs($user)->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('second.jpg', 900, 700),
        ])->assertOk();
        $secondPath = $user->refresh()->avatar_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    public function test_an_authenticated_user_can_view_another_users_avatar(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $viewer = User::factory()->create();

        $this->actingAs($owner)->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 900, 700),
        ])->assertOk();

        $this->actingAs($viewer)->get(route('avatars.user', $owner->refresh()))->assertOk();
    }

    public function test_a_guest_cannot_view_an_avatar(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['avatar_path' => 'avatars/fake.jpg']);
        Storage::disk('local')->put('avatars/fake.jpg', 'fake-image-bytes');

        // No actingAs() at all -- a real guest request, not a logged-out
        // request in a test that was previously authenticated (actingAs()
        // persists for the rest of the test method's HTTP calls).
        $this->get(route('avatars.user', $owner))->assertForbidden();
    }

    public function test_an_avatar_is_stored_as_webp_and_served_from_a_versioned_cacheable_url(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 900, 700),
        ])->assertOk();
        $user->refresh();

        $this->assertStringEndsWith('.webp', $user->avatar_path);
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer(Storage::disk('local')->get($user->avatar_path)));
        $this->assertStringContainsString('?v=', $user->avatar_url);

        $response = $this->get($user->avatar_url)->assertOk();
        $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));

        // A new photo means a new URL, so the browser never shows a stale one.
        $firstUrl = $user->avatar_url;
        $this->actingAs($user)->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('again.jpg', 900, 700),
        ])->assertOk();
        $this->assertNotSame($firstUrl, $user->refresh()->avatar_url);
    }

    public function test_on_s3_an_avatar_redirects_to_the_same_signed_url_all_day(): void
    {
        // Presigning is local math -- no request ever reaches AWS here.
        config([
            'filesystems.private_disk' => 's3',
            'filesystems.disks.s3' => [
                'driver' => 's3', 'key' => 'test-key', 'secret' => 'test-secret',
                'region' => 'us-east-1', 'bucket' => 'studio-pm-test',
            ],
        ]);
        $owner = User::factory()->create(['avatar_path' => 'avatars/abc.webp']);
        $viewer = User::factory()->create();

        $this->travelTo(now()->startOfDay()->addHours(9));
        $morning = $this->actingAs($viewer)->get($owner->avatar_url)->assertRedirect();
        $this->travelTo(now()->addHours(8));
        $evening = $this->actingAs($viewer)->get($owner->avatar_url)->assertRedirect();

        $this->assertSame($morning->headers->get('Location'), $evening->headers->get('Location'));
        $this->assertStringContainsString('response-cache-control=', $morning->headers->get('Location'));
        // The redirect is cached until midnight: 15h left at 9am, 7h at 5pm.
        $this->assertStringContainsString('max-age=54000', $morning->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=25200', $evening->headers->get('Cache-Control'));
    }

    public function test_an_avatar_has_a_small_copy_served_at_size_sm(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 900, 700),
        ])->assertOk();
        $user->refresh();

        $small = AvatarProcessor::smallPath($user->avatar_path);
        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($small));
        $this->assertSame([96, 96], [$width, $height]);

        $response = $this->get($user->avatar_url.'&size=sm')->assertOk();
        $this->assertSame(Storage::disk('local')->get($small), $response->streamedContent());

        $this->actingAs($user)->deleteJson('/api/profile/avatar')->assertOk();
        Storage::disk('local')->assertMissing($small);
    }

    public function test_an_older_avatar_without_a_small_copy_falls_back_to_the_full_one(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('avatars/old.jpg', 'old-jpeg-bytes');
        $owner = User::factory()->create(['avatar_path' => 'avatars/old.jpg']);

        $response = $this->actingAs(User::factory()->create())->get($owner->avatar_url.'&size=sm')->assertOk();
        $this->assertSame('old-jpeg-bytes', $response->streamedContent());
    }

    public function test_the_backfill_makes_small_copies_for_older_photos(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('avatars/old.jpg', UploadedFile::fake()->image('a.jpg', 400, 400)->getContent());
        $disk->put('bio-photos/old.jpg', UploadedFile::fake()->image('b.jpg', 800, 1000)->getContent());
        User::factory()->create(['avatar_path' => 'avatars/old.jpg', 'bio_photo_path' => 'bio-photos/old.jpg']);

        $this->artisan('images:backfill')->assertSuccessful();

        $this->assertSame([96, 96], array_slice(getimagesizefromstring($disk->get('avatars/old-sm.webp')), 0, 2));
        $this->assertSame([400, 500], array_slice(getimagesizefromstring($disk->get('bio-photos/old-sm.webp')), 0, 2));
    }
}
