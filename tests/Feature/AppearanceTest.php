<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_dark_until_someone_chooses_otherwise(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')->assertSee('data-theme="dark"', false);

        $this->actingAs($user)->patchJson('/api/profile/appearance', ['theme' => 'light'])->assertOk();
        $this->actingAs($user)->get('/profile')->assertSee('data-theme="light"', false);

        $this->actingAs($user)->patchJson('/api/profile/appearance', ['theme' => 'system'])->assertOk();
        $this->actingAs($user)->get('/profile')->assertSee('data-theme="system"', false);
    }

    public function test_only_the_three_themes_are_accepted(): void
    {
        $this->actingAs(User::factory()->create())->patchJson('/api/profile/appearance', ['theme' => 'neon'])->assertUnprocessable();
    }

    public function test_the_login_page_is_dark(): void
    {
        $this->get('/login')->assertSee('data-theme="dark"', false);
    }

    public function test_clients_choose_their_own_appearance_in_the_portal(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Jo Park', 'email' => 'jo@example.com', 'is_primary' => true]);
        $contact->forceFill(['portal_invited_at' => now()])->save();

        $this->actingAs($contact, 'client')->patchJson('/api/portal/profile/appearance', ['theme' => 'light'])->assertOk();

        $this->assertSame('light', $contact->fresh()->theme);
        $this->actingAs($contact, 'client')->get('/portal')->assertSee('data-theme="light"', false)
            ->assertInertia(fn ($page) => $page->where('auth.user.theme', 'light'));
    }
}
