<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteAuthorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_note_records_the_signed_in_user_as_its_author(): void
    {
        $user = User::factory()->create();
        $project = Company::create(['name' => 'Alder & Finch Design'])->projects()->create(['name' => 'Brand refresh']);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/notes", ['title' => 'Kickoff']);

        $response->assertCreated()->assertJsonPath('user.id', $user->id);
        $this->assertDatabaseHas('notes', ['id' => $response->json('id'), 'user_id' => $user->id]);
    }

    public function test_the_author_cannot_be_set_from_the_request(): void
    {
        $user = User::factory()->create();
        $someoneElse = User::factory()->create();
        $project = Company::create(['name' => 'Alder & Finch Design'])->projects()->create(['name' => 'Brand refresh']);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/notes", [
            'title' => 'Kickoff',
            'user_id' => $someoneElse->id,
        ]);

        $this->assertDatabaseHas('notes', ['id' => $response->json('id'), 'user_id' => $user->id]);
    }

    public function test_the_project_page_includes_each_notes_author(): void
    {
        $user = User::factory()->create(['name' => 'Kim Se-jeong']);
        $project = Company::create(['name' => 'Alder & Finch Design'])->projects()->create(['name' => 'Brand refresh']);
        $this->actingAs($user)->postJson("/api/projects/{$project->id}/notes", ['title' => 'Kickoff']);

        $response = $this->actingAs($user)->get("/projects/{$project->id}");

        $response->assertInertia(fn ($page) => $page->where('project.notes.0.user.name', 'Kim Se-jeong'));
    }
}
