<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessageEditAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private function threadWithFileAndLink(): array
    {
        Notification::fake();
        Storage::fake('local');
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $author = User::factory()->teamMember()->create();
        $other = User::factory()->teamMember()->create();
        $project->users()->attach([$author->id, $other->id], ['assigned_at' => now()]);

        $thread = $this->actingAs($author)->post("/api/projects/{$project->id}/messages", [
            'subject' => 'Files', 'body' => 'Here are the files',
            'recipients' => ["user:{$other->id}"],
            'attachments' => [UploadedFile::fake()->create('brief.pdf', 20, 'application/pdf')],
            'links' => ['https://www.dropbox.com/scl/fo/abc/Old_Assets.zip'],
        ], ['Accept' => 'application/json'])->assertCreated()->json();

        return compact('project', 'author', 'other', 'thread');
    }

    public function test_an_edit_can_take_off_a_file_and_link_and_add_new_ones(): void
    {
        ['author' => $author, 'thread' => $thread] = $this->threadWithFileAndLink();
        $oldFile = $thread['attachments'][0];
        $oldLink = $thread['links'][0];

        $updated = $this->actingAs($author)->post("/api/messages/{$thread['id']}", [
            '_method' => 'PATCH',
            'body' => 'Updated files',
            'remove_attachment_ids' => [$oldFile['id']],
            'remove_link_ids' => [$oldLink['id']],
            'attachments' => [UploadedFile::fake()->create('brief-v2.pdf', 20, 'application/pdf')],
            'links' => ['https://www.dropbox.com/scl/fo/xyz/New_Assets.zip'],
        ], ['Accept' => 'application/json'])->assertOk()->json();

        $this->assertSame('Updated files', $updated['body']);
        $this->assertSame(['brief-v2.pdf'], array_column($updated['attachments'], 'original_name'));
        $this->assertSame(['https://www.dropbox.com/scl/fo/xyz/New_Assets.zip'], array_column($updated['links'], 'url'));
        Storage::disk('local')->assertMissing($oldFile['path']);
    }

    public function test_an_edit_must_leave_something_and_can_only_touch_its_own_files(): void
    {
        ['author' => $author, 'other' => $other, 'project' => $project, 'thread' => $thread] = $this->threadWithFileAndLink();

        // Clearing everything is refused.
        $this->actingAs($author)->patchJson("/api/messages/{$thread['id']}", [
            'body' => '', 'remove_attachment_ids' => [$thread['attachments'][0]['id']], 'remove_link_ids' => [$thread['links'][0]['id']],
        ])->assertUnprocessable()->assertJsonValidationErrors('body');

        // Another message's file id is ignored, not deleted.
        $otherThread = $this->actingAs($other)->post("/api/projects/{$project->id}/messages", [
            'subject' => 'Mine', 'recipients' => ["user:{$author->id}"],
            'attachments' => [UploadedFile::fake()->create('mine.pdf', 20, 'application/pdf')],
        ], ['Accept' => 'application/json'])->json();

        $this->actingAs($author)->patchJson("/api/messages/{$thread['id']}", [
            'body' => 'Still here', 'remove_attachment_ids' => [$otherThread['attachments'][0]['id']],
        ])->assertOk();

        $this->assertDatabaseHas('message_attachments', ['id' => $otherThread['attachments'][0]['id']]);
    }

    public function test_a_text_only_edit_still_works(): void
    {
        ['author' => $author, 'thread' => $thread] = $this->threadWithFileAndLink();

        $this->actingAs($author)->patchJson("/api/messages/{$thread['id']}", ['body' => 'New words'])
            ->assertOk()
            ->assertJsonPath('body', 'New words')
            ->assertJsonCount(1, 'attachments')
            ->assertJsonCount(1, 'links');
    }
}
