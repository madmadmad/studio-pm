<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyContactFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_structured_address_can_be_set_on_a_company(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $response = $this->actingAs($user)->patchJson("/api/companies/{$company->id}", [
            'address_line1' => '9686 Oak Haven Ct.',
            'city' => 'Perrysburg',
            'state' => 'OH',
            'postal_code' => '43551',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'address_line1' => '9686 Oak Haven Ct.',
            'city' => 'Perrysburg',
            'state' => 'OH',
            'postal_code' => '43551',
        ]);
    }

    public function test_a_company_can_be_created_with_just_a_name_and_phone(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/companies', [
            'name' => 'Alder & Finch Design',
            'phone' => '555-0100',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('companies', ['name' => 'Alder & Finch Design', 'phone' => '555-0100']);
    }

    public function test_email_and_default_hourly_rate_are_no_longer_company_fields(): void
    {
        $this->assertFalse(Schema::hasColumn('companies', 'email'));
        $this->assertFalse(Schema::hasColumn('companies', 'default_hourly_rate'));
    }
}
