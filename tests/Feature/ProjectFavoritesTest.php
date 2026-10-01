<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectFavoritesTest extends TestCase
{
    use RefreshDatabase;

    public function test_starring_is_personal_and_shows_on_the_projects_page(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $company->projects()->create(['name' => 'Website', 'status' => 'active']);
        $me = User::factory()->create();
        $colleague = User::factory()->create();

        $this->actingAs($me)->postJson("/api/projects/{$project->id}/favorite")->assertOk()->assertJson(['is_favorite' => true]);
        // Starring twice is harmless.
        $this->actingAs($me)->postJson("/api/projects/{$project->id}/favorite")->assertOk();

        $this->actingAs($me)->get('/projects')->assertInertia(fn ($page) => $page
            ->where('projects.0.name', 'Brand refresh')->where('projects.0.is_favorite', true)
            ->where('projects.1.is_favorite', false));

        $this->actingAs($colleague)->get('/projects')->assertInertia(fn ($page) => $page
            ->where('projects.0.is_favorite', false));

        $this->actingAs($me)->get("/projects/{$project->id}")->assertInertia(fn ($page) => $page->where('project.is_favorite', true));

        $this->actingAs($me)->deleteJson("/api/projects/{$project->id}/favorite")->assertOk()->assertJson(['is_favorite' => false]);
        $this->assertSame(0, $me->favoriteProjects()->count());
    }

    public function test_team_members_can_only_star_projects_they_can_see(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $mine = $company->projects()->create(['name' => 'Brand refresh']);
        $other = $company->projects()->create(['name' => 'Website']);
        $teammate = User::factory()->teamMember()->create();
        $mine->users()->attach($teammate->id, ['assigned_at' => now()]);

        $this->actingAs($teammate)->postJson("/api/projects/{$mine->id}/favorite")->assertOk();
        $this->actingAs($teammate)->postJson("/api/projects/{$other->id}/favorite")->assertForbidden();
    }
}
