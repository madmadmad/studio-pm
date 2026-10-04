<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Project $assigned;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $this->assigned = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active', 'budget' => 12000]);
        $this->other = $company->projects()->create(['name' => 'Website', 'status' => 'active', 'budget' => 30000]);
    }

    private function member(array $permissions = []): User
    {
        $user = User::factory()->teamMember()->create();
        $user->forceFill(['permissions' => $permissions ?: null])->save();
        $user->projects()->attach($this->assigned->id, ['assigned_at' => now()]);

        return $user;
    }

    // Each permission's page: shut without it, open with it.
    public static function pages(): array
    {
        return [
            'clients' => ['clients', '/clients'],
            'invoices' => ['invoices', '/invoices'],
            'proposals' => ['proposals', '/proposals'],
            'bookkeeping' => ['bookkeeping', '/bookkeeping'],
            'expenses' => ['expenses', '/expenses'],
            'services' => ['services', '/services'],
            'archive' => ['all_projects', '/projects/archived'],
        ];
    }

    #[DataProvider('pages')]
    public function test_each_page_needs_its_permission(string $permission, string $url): void
    {
        $this->actingAs($this->member())->get($url)->assertForbidden();
        $this->actingAs($this->member([$permission]))->get($url)->assertOk();
    }

    public function test_settings_and_team_are_super_admin_only_and_cant_be_granted(): void
    {
        $member = $this->member();
        $member->forceFill(['permissions' => ['settings', 'team']])->save();

        $this->actingAs($member)->get('/settings')->assertForbidden();
        $this->actingAs($member)->get('/users')->assertForbidden();

        $admin = User::factory()->create();
        $this->actingAs($admin)->patchJson("/api/users/{$member->id}", ['permissions' => ['settings']])->assertUnprocessable();
        $this->actingAs($admin)->get('/settings')->assertOk();
    }

    public function test_a_super_admin_grants_permissions_but_not_to_themselves(): void
    {
        $admin = User::factory()->create();
        $member = $this->member();

        $this->actingAs($admin)->patchJson("/api/users/{$member->id}", ['permissions' => ['invoices', 'clients']])->assertOk();
        $this->assertTrue($member->fresh()->hasPermission('invoices'));
        $this->assertFalse($member->fresh()->hasPermission('bookkeeping'));

        $this->actingAs($admin)->patchJson("/api/users/{$admin->id}", ['role' => 'team_member'])->assertUnprocessable();
        $this->assertTrue($admin->fresh()->isSuperAdmin());
    }

    public function test_without_all_projects_only_assigned_projects_show(): void
    {
        $this->actingAs($this->member())->get("/projects/{$this->other->id}")->assertForbidden();
        $this->actingAs($this->member(['all_projects']))->get("/projects/{$this->other->id}")->assertOk();
    }

    public function test_everyone_on_a_project_sees_its_approved_proposals_in_full(): void
    {
        $company = $this->assigned->company;
        $approved = $company->proposals()->create(['title' => 'Approved', 'body' => '<p>Scope</p>', 'status' => 'accepted', 'project_id' => $this->assigned->id, 'accepted_at' => now()]);
        $approved->items()->create(['description' => 'Design', 'quantity' => 10, 'rate' => 150]);
        $company->proposals()->create(['title' => 'Draft', 'body' => '<p>Scope</p>', 'status' => 'draft', 'project_id' => $this->assigned->id]);

        $this->actingAs($this->member())->get("/projects/{$this->assigned->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.proposals', fn ($proposals) => collect($proposals)->pluck('title')->all() === ['Approved']
                    && collect($proposals)->first()['items'][0]['rate'] == 150)
                ->where('can.proposals', false));

        $this->actingAs($this->member(['proposals']))->get("/projects/{$this->assigned->id}")
            ->assertInertia(fn (Assert $page) => $page->where('project.proposals', fn ($proposals) => count($proposals) === 2));
    }

    // The budget never reaches a browser without Invoices -- not the project
    // page, not the project list.
    public function test_project_budgets_stay_with_invoices(): void
    {
        $this->actingAs($this->member())->get("/projects/{$this->assigned->id}")
            ->assertInertia(fn (Assert $page) => $page->missing('project.budget')->missing('project.invoices'));
        $this->actingAs($this->member())->get('/projects')
            ->assertInertia(fn (Assert $page) => $page->missing('projects.0.budget'));

        $this->actingAs($this->member(['invoices']))->get("/projects/{$this->assigned->id}")
            ->assertInertia(fn (Assert $page) => $page->where('project.budget', fn ($budget) => (float) $budget === 12000.0));
    }

    public function test_changing_a_project_takes_manage_projects(): void
    {
        $this->actingAs($this->member())->patchJson("/api/projects/{$this->assigned->id}", ['status' => 'archived'])->assertForbidden();
        $this->assertSame('active', $this->assigned->fresh()->status);

        $this->actingAs($this->member(['manage_projects']))->patchJson("/api/projects/{$this->assigned->id}", ['status' => 'completed'])->assertOk();
        $this->assertSame('completed', $this->assigned->fresh()->status);
    }

    public function test_invoice_alerts_go_to_whoever_has_invoices(): void
    {
        $admin = User::factory()->create();
        $billing = $this->member(['invoices']);
        $other = $this->member();

        $ids = User::withPermission('invoices')->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$admin->id, $billing->id], $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_the_sidebar_knows_what_each_person_can_do(): void
    {
        $this->actingAs($this->member(['clients']))->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.permissions', ['clients']));
    }
}
