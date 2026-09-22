<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessageEditDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function makeProjectWithPeople(): array
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $manager = User::factory()->create();
        $teammate = User::factory()->teamMember()->create();
        $other = User::factory()->teamMember()->create();
        $project->users()->attach([$teammate->id, $other->id], ['assigned_at' => now()]);

        return compact('company', 'project', 'manager', 'teammate', 'other');
    }

    public function test_an_author_can_edit_their_own_message(): void
    {
        ['project' => $project, 'teammate' => $teammate, 'other' => $other] = $this->makeProjectWithPeople();

        $thread = $this->actingAs($teammate)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => 'Original text',
            'recipients' => ["user:{$other->id}"],
        ])->json();

        $this->actingAs($teammate)->patchJson("/api/messages/{$thread['id']}", ['body' => 'Edited text'])
            ->assertOk()
            ->assertJsonPath('body', 'Edited text');
    }

    public function test_a_non_author_team_member_cannot_edit_someone_elses_message(): void
    {
        ['project' => $project, 'teammate' => $teammate, 'other' => $other] = $this->makeProjectWithPeople();

        $thread = $this->actingAs($teammate)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => 'Original text',
            'recipients' => ["user:{$other->id}"],
        ])->json();

        $this->actingAs($other)->patchJson("/api/messages/{$thread['id']}", ['body' => 'Hijacked'])->assertForbidden();
    }

    public function test_a_manager_can_delete_anyones_message_but_a_team_member_cannot(): void
    {
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate, 'other' => $other] = $this->makeProjectWithPeople();

        $thread = $this->actingAs($teammate)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => 'Original text',
            'recipients' => ["user:{$other->id}"],
        ])->json();

        $this->actingAs($other)->deleteJson("/api/messages/{$thread['id']}")->assertForbidden();
        $this->actingAs($manager)->deleteJson("/api/messages/{$thread['id']}")->assertNoContent();

        $this->assertSoftDeleted('messages', ['id' => $thread['id']]);
    }

    public function test_deleting_a_message_purges_its_attachment_files_from_storage(): void
    {
        Storage::fake('local');
        ['project' => $project, 'teammate' => $teammate, 'other' => $other] = $this->makeProjectWithPeople();

        $thread = $this->actingAs($teammate)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => 'See attached',
            'recipients' => ["user:{$other->id}"],
            'attachments' => [UploadedFile::fake()->image('photo.jpg', 400, 400)],
        ])->json();

        $attachment = $thread['attachments'][0];
        Storage::disk('local')->assertExists($attachment['path']);
        Storage::disk('local')->assertExists($attachment['thumbnail_path']);

        $this->actingAs($teammate)->deleteJson("/api/messages/{$thread['id']}")->assertNoContent();

        Storage::disk('local')->assertMissing($attachment['path']);
        Storage::disk('local')->assertMissing($attachment['thumbnail_path']);
        $this->assertDatabaseMissing('message_attachments', ['id' => $attachment['id']]);
    }

    public function test_a_soft_deleted_message_still_appears_in_the_thread_index(): void
    {
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate, 'other' => $other] = $this->makeProjectWithPeople();

        $thread = $this->actingAs($teammate)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => 'Original text',
            'recipients' => ["user:{$other->id}"],
        ])->json();

        $this->actingAs($manager)->deleteJson("/api/messages/{$thread['id']}")->assertNoContent();

        $threads = $this->actingAs($manager)->getJson("/api/projects/{$project->id}/messages")->json();

        $this->assertNotEmpty(array_filter($threads, fn ($t) => $t['id'] === $thread['id']));
    }
}
