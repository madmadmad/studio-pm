<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_contact_per_company_can_be_primary(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $first = $company->contacts()->create(['name' => 'Rosa Alder', 'is_primary' => true]);

        $this->actingAs($user)->postJson("/api/companies/{$company->id}/contacts", [
            'name' => 'Priya Sen',
            'is_primary' => true,
        ])->assertCreated();

        $this->assertFalse($first->fresh()->is_primary);
    }

    public function test_only_one_contact_per_company_can_be_billing(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $first = $company->contacts()->create(['name' => 'Rosa Alder', 'is_billing' => true]);
        $second = $company->contacts()->create(['name' => 'Priya Sen']);

        $this->actingAs($user)->patchJson("/api/contacts/{$second->id}", [
            'is_billing' => true,
        ])->assertOk();

        $this->assertFalse($first->fresh()->is_billing);
        $this->assertTrue($second->fresh()->is_billing);
    }

    public function test_primary_and_billing_flags_are_independent_per_company(): void
    {
        $user = User::factory()->create();
        $companyA = Company::create(['name' => 'Alder & Finch Design']);
        $companyB = Company::create(['name' => 'Marsh Grove Bakery']);
        $companyA->contacts()->create(['name' => 'Rosa Alder', 'is_primary' => true]);
        $contactB = $companyB->contacts()->create(['name' => 'Tomas Marsh']);

        $this->actingAs($user)->patchJson("/api/contacts/{$contactB->id}", [
            'is_primary' => true,
        ])->assertOk();

        $this->assertTrue($companyA->contacts()->first()->is_primary);
        $this->assertTrue($contactB->fresh()->is_primary);
    }
}
