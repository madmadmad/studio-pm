<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// A proposal's disclaimer: starts from the default, can be changed or
// cleared per proposal, and is shown to the client.
class ProposalDisclaimerTest extends TestCase
{
    use RefreshDatabase;

    private function create(User $user, Company $company, array $extra = [])
    {
        return $this->actingAs($user)->postJson("/api/companies/{$company->id}/proposals", [
            'title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'new_project_name' => 'Brand refresh', ...$extra,
        ])->assertCreated();
    }

    public function test_a_new_proposal_starts_with_the_default_disclaimer(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $this->create($user, $company)->assertJsonPath('disclaimer', config('proposals.default_disclaimer'));
    }

    public function test_the_disclaimer_can_be_written_or_cleared(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $id = $this->create($user, $company, ['disclaimer' => 'Rush work billed at 1.5x.'])
            ->assertJsonPath('disclaimer', 'Rush work billed at 1.5x.')->json('id');

        $this->actingAs($user)->patchJson("/api/proposals/{$id}", ['title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'disclaimer' => null])
            ->assertOk()->assertJsonPath('disclaimer', null);
    }

    public function test_saving_without_a_disclaimer_leaves_it_as_it_was(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $id = $this->create($user, $company, ['disclaimer' => 'Kept.'])->json('id');

        $this->actingAs($user)->patchJson("/api/proposals/{$id}", ['title' => 'Renamed', 'body' => '<p>Scope</p>'])
            ->assertOk()->assertJsonPath('disclaimer', 'Kept.');
    }

    public function test_the_client_sees_the_disclaimer(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $token = $this->create($user, $company, ['disclaimer' => 'Out-of-scope work is billed separately.'])->json('accept_token');

        $this->get("/p/{$token}")->assertOk()->assertSee('Out-of-scope work is billed separately.');
    }
}
