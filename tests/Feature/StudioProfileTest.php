<?php

namespace Tests\Feature;

use App\Models\StudioProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudioProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_studio_profile_is_a_singleton_created_on_first_access(): void
    {
        $this->assertDatabaseCount('studio_profiles', 0);

        $profile = StudioProfile::current();

        $this->assertDatabaseCount('studio_profiles', 1);
        $this->assertSame($profile->id, StudioProfile::current()->id);
    }

    public function test_the_studio_profile_can_be_updated(): void
    {
        $user = User::factory()->create();
        StudioProfile::current(); // matches real usage: the settings page creates the row on first load

        $response = $this->actingAs($user)->patchJson('/api/studio-profile', [
            'name' => 'Madhouse Studio',
            'address' => "123 Main St\nPortland, OR 97201",
            'email' => 'hello@madhouse.studio',
            'phone' => '555-0100',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('studio_profiles', [
            'name' => 'Madhouse Studio',
            'email' => 'hello@madhouse.studio',
            'phone' => '555-0100',
        ]);
    }

    public function test_a_name_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/studio-profile', ['name' => ''])
            ->assertUnprocessable();
    }

    public function test_payment_instructions_can_be_set(): void
    {
        $user = User::factory()->create();
        StudioProfile::current();

        $response = $this->actingAs($user)->patchJson('/api/studio-profile', [
            'name' => 'Madhouse Studio',
            'payment_instructions' => 'For ACH or check, email hello@madhouse.studio for details.',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('studio_profiles', [
            'payment_instructions' => 'For ACH or check, email hello@madhouse.studio for details.',
        ]);
    }
}
