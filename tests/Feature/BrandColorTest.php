<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\BrandPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_can_set_and_clear_their_own_color(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/profile/brand-color', ['brand_color' => '#0057ff'])
            ->assertOk()
            ->assertJsonPath('brand_color', '#0057FF')
            ->assertJsonPath('palette.--brand', '#0057FF');
        $this->assertSame('#0057FF', $user->fresh()->brand_color);

        $this->actingAs($user)->patchJson('/api/profile/brand-color', ['brand_color' => null])->assertOk();
        $this->assertNull($user->fresh()->brand_color);
    }

    public function test_only_a_hex_color_is_accepted(): void
    {
        $user = User::factory()->create();

        foreach (['red', '#FFF', '#0057FF; background: url(x)', '0057FF'] as $bad) {
            $this->actingAs($user)->patchJson('/api/profile/brand-color', ['brand_color' => $bad])->assertUnprocessable();
        }
    }

    public function test_the_app_draws_in_the_persons_color_from_the_first_frame(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->forceFill(['brand_color' => '#FFD400'])->save();

        $response = $this->actingAs($user)->get('/profile')->assertOk();

        $response->assertSee('style="--brand: #FFD400;', false);
        // White won't pass on yellow, so text on it goes dark.
        $response->assertSee('--brand-on: #121418;', false);
        $this->assertSame(BrandPalette::for('#FFD400'), $response->viewData('page')['props']['brand']);
    }

    public function test_without_a_color_the_app_is_the_studio_red(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/profile')->assertOk();

        $this->assertSame(BrandPalette::for(BrandPalette::DEFAULT), $response->viewData('page')['props']['brand']);
    }

    public function test_a_client_color_is_set_on_their_company(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $this->actingAs(User::factory()->create())->patchJson("/api/companies/{$company->id}", ['brand_color' => '#00a36c'])->assertOk();
        $this->assertSame('#00A36C', $company->fresh()->brand_color);

        $this->actingAs(User::factory()->create())->patchJson("/api/companies/{$company->id}", ['brand_color' => 'green'])->assertUnprocessable();
    }

    public function test_a_client_sees_the_portal_in_their_companys_color(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $company->update(['brand_color' => '#00A36C']);
        $contact = $company->contacts()->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);
        $contact->forceFill(['portal_invited_at' => now()])->save();

        $response = $this->actingAs($contact, 'client')->get('/portal')->assertOk();

        $this->assertSame('#00A36C', $response->viewData('page')['props']['brand']['--brand']);
    }

    public function test_a_public_invoice_is_in_its_clients_color_whoever_opens_it(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $company->update(['brand_color' => '#0057FF']);
        $invoice = Invoice::create(['company_id' => $company->id, 'status' => 'sent', 'issued_on' => now(), 'due_on' => now()->addDays(14)]);

        $guest = $this->get("/i/{$invoice->public_token}")->assertOk();
        $this->assertSame('#0057FF', $guest->viewData('page')['props']['brand']['--brand']);

        // Staff with their own color still see the client's on the client's page.
        $staff = User::factory()->create();
        $staff->forceFill(['brand_color' => '#FFD400'])->save();
        $signedIn = $this->actingAs($staff)->get("/i/{$invoice->public_token}")->assertOk();
        $this->assertSame('#0057FF', $signedIn->viewData('page')['props']['brand']['--brand']);
    }
}
