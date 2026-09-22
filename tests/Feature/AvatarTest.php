<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
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
}
