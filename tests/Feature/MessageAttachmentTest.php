<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeProjectWithPeople(): array
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $manager = User::factory()->create();
        $teammate = User::factory()->teamMember()->create();
        $project->users()->attach($teammate->id, ['assigned_at' => now()]);

        $client = $company->contacts()->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);
        $client->forceFill(['portal_invited_at' => now()])->save();

        return compact('company', 'project', 'manager', 'teammate', 'client');
    }

    public function test_a_message_can_be_sent_with_an_image_attachment_and_no_body(): void
    {
        Storage::fake('local');
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate] = $this->makeProjectWithPeople();

        $response = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Logo options',
            'recipients' => ["user:{$teammate->id}"],
            'attachments' => [UploadedFile::fake()->image('logo.jpg', 800, 600)],
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('message_attachments', 1);
        $attachment = $response->json('attachments.0');
        $this->assertSame('logo.jpg', $attachment['original_name']);
        $this->assertSame(800, $attachment['width']);
        // QUEUE_CONNECTION=sync in tests, so the thumbnail job already ran.
        $this->assertSame('ready', $attachment['thumbnail_status']);
        Storage::disk('local')->assertExists($attachment['thumbnail_path']);
    }

    public function test_a_completely_empty_message_is_rejected(): void
    {
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate] = $this->makeProjectWithPeople();

        $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Nothing to say',
            'recipients' => ["user:{$teammate->id}"],
        ])->assertUnprocessable();
    }

    public function test_more_than_ten_attachments_are_rejected(): void
    {
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate] = $this->makeProjectWithPeople();

        $files = array_map(fn ($i) => UploadedFile::fake()->create("file{$i}.txt", 10, 'text/plain'), range(1, 11));

        $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Too many',
            'body' => 'See attached',
            'recipients' => ["user:{$teammate->id}"],
            'attachments' => $files,
        ])->assertUnprocessable();
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate] = $this->makeProjectWithPeople();

        $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Big file',
            'body' => 'See attached',
            'recipients' => ["user:{$teammate->id}"],
            'attachments' => [UploadedFile::fake()->create('huge.zip', 30000, 'application/zip')],
        ])->assertUnprocessable();
    }

    public function test_a_disallowed_file_type_is_rejected_by_real_mime_not_extension(): void
    {
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate] = $this->makeProjectWithPeople();

        // Renamed extension, but the real (fake-test) MIME is an executable --
        // the mimetypes: rule checks detected content type, not the name.
        $disguised = UploadedFile::fake()->create('totally-a-photo.jpg', 10, 'application/x-msdownload');

        $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Sneaky',
            'body' => 'See attached',
            'recipients' => ["user:{$teammate->id}"],
            'attachments' => [$disguised],
        ])->assertUnprocessable();
    }

    public function test_a_project_team_member_can_download_an_attachment(): void
    {
        Storage::fake('local');
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate] = $this->makeProjectWithPeople();

        $thread = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Logo options',
            'body' => 'See attached',
            'recipients' => ["user:{$teammate->id}"],
            'attachments' => [UploadedFile::fake()->create('brief.pdf', 100, 'application/pdf')],
        ])->json();

        $attachmentId = $thread['attachments'][0]['id'];

        $this->actingAs($teammate)->get("/api/attachments/{$attachmentId}")->assertOk();
    }

    public function test_someone_off_the_project_cannot_download_an_attachment(): void
    {
        Storage::fake('local');
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate] = $this->makeProjectWithPeople();
        $outsider = User::factory()->teamMember()->create();

        $thread = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Logo options',
            'body' => 'See attached',
            'recipients' => ["user:{$teammate->id}"],
            'attachments' => [UploadedFile::fake()->create('brief.pdf', 100, 'application/pdf')],
        ])->json();

        $attachmentId = $thread['attachments'][0]['id'];

        $this->actingAs($outsider)->get("/api/attachments/{$attachmentId}")->assertForbidden();
    }

    public function test_an_invited_client_can_download_an_attachment_via_the_portal(): void
    {
        Storage::fake('local');
        ['project' => $project, 'manager' => $manager, 'client' => $client] = $this->makeProjectWithPeople();

        $thread = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Logo options',
            'body' => 'See attached',
            'recipients' => ["contact:{$client->id}"],
            'attachments' => [UploadedFile::fake()->create('brief.pdf', 100, 'application/pdf')],
        ])->json();

        $attachmentId = $thread['attachments'][0]['id'];

        $this->actingAs($client, 'client')->get("/api/portal/attachments/{$attachmentId}")->assertOk();
    }

    public function test_a_client_at_a_different_company_cannot_download_an_attachment(): void
    {
        Storage::fake('local');
        ['project' => $project, 'manager' => $manager, 'teammate' => $teammate] = $this->makeProjectWithPeople();

        $otherCompany = Company::create(['name' => 'Globex']);
        $otherClient = $otherCompany->contacts()->create(['name' => 'Other Client', 'email' => 'other@example.com']);
        $otherClient->forceFill(['portal_invited_at' => now()])->save();

        $thread = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Logo options',
            'body' => 'See attached',
            'recipients' => ["user:{$teammate->id}"],
            'attachments' => [UploadedFile::fake()->create('brief.pdf', 100, 'application/pdf')],
        ])->json();

        $attachmentId = $thread['attachments'][0]['id'];

        $this->actingAs($otherClient, 'client')->get("/api/portal/attachments/{$attachmentId}")->assertForbidden();
    }
}
