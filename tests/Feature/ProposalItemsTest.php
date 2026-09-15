<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_proposal_with_line_items_computes_the_estimate_from_them(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/proposals", [
            'title' => 'Marketing Support',
            'body' => '<p>Scope</p>',
            'items' => [
                ['description' => 'Production Art (5 decks)', 'quantity' => 15, 'rate' => 130],
                ['description' => 'Development', 'quantity' => 4, 'rate' => 130],
                ['description' => 'Project Management', 'quantity' => 2, 'rate' => 130],
            ],
        ]);

        $response->assertCreated();
        // (15 + 4 + 2) * 130 = 2730
        $response->assertJsonPath('estimate_amount', 2730);
        $this->assertCount(3, $response->json('items'));
    }

    public function test_a_manual_estimate_amount_is_kept_when_no_items_are_given(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/proposals", [
            'title' => 'Flat quote',
            'body' => '<p>Scope</p>',
            'estimate_amount' => 500,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('estimate_amount', 500);
        $this->assertCount(0, $response->json('items'));
    }
}
