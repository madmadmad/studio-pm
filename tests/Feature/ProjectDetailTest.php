<?php

namespace Tests\Feature;

use App\Mail\ProjectMessageMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProjectDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_invoice_can_be_attributed_directly_to_a_project(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'project_id' => $project->id,
            'items' => [['description' => 'Monthly retainer', 'amount' => 2000]],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('invoices', ['id' => $response->json('id'), 'project_id' => $project->id]);
    }

    public function test_a_project_id_from_another_company_is_rejected(): void
    {
        $user = User::factory()->create();
        $companyA = Company::create(['name' => 'Alder & Finch Design']);
        $companyB = Company::create(['name' => 'Marsh Grove Bakery']);
        $otherProject = $companyB->projects()->create(['name' => 'Menu redesign']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$companyA->id}/invoices", [
            'project_id' => $otherProject->id,
            'items' => [['description' => 'Work', 'amount' => 500]],
        ]);

        $response->assertUnprocessable();
    }

    public function test_an_expense_can_be_logged_against_a_project(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $this->actingAs($user)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 45.50,
            'occurred_on' => now()->toDateString(),
            'project_id' => $project->id,
        ])->assertCreated();

        $this->assertSame(1, $project->fresh('transactions')->transactions->count());
    }

    public function test_sending_a_project_message_emails_the_client_and_records_the_thread(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'client@example.com', 'is_primary' => true]);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => 'Excited to get started!',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('messages', [
            'project_id' => $project->id,
            'to_email' => 'client@example.com',
            'subject' => 'Kickoff',
        ]);
        Mail::assertSent(ProjectMessageMail::class, fn ($mail) => $mail->hasTo('client@example.com'));
    }

    public function test_sending_a_message_without_a_client_email_on_file_fails(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => 'Excited to get started!',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('messages', 0);
        Mail::assertNothingSent();
    }

    public function test_sending_a_message_falls_back_to_any_contact_with_an_email_when_none_is_primary(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Kickoff',
            'body' => 'Excited to get started!',
        ]);

        $response->assertCreated();
        Mail::assertSent(ProjectMessageMail::class, fn ($mail) => $mail->hasTo('rosa@alderfinch.co'));
    }

    public function test_notes_can_be_added_and_listed_for_a_project(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $this->actingAs($user)->postJson("/api/projects/{$project->id}/notes", [
            'body' => 'Kickoff call went well.',
        ])->assertCreated();

        $response = $this->actingAs($user)->getJson("/api/projects/{$project->id}/notes");
        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_a_blank_note_can_be_created_with_no_title_or_body(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/notes", []);

        $response->assertCreated();
        $this->assertDatabaseHas('notes', ['project_id' => $project->id, 'title' => null, 'body' => null]);
    }

    public function test_a_note_title_and_body_can_be_updated_and_deleted(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $note = $project->notes()->create([]);

        $this->actingAs($user)->patchJson("/api/notes/{$note->id}", [
            'title' => 'Kickoff notes',
            'body' => '<p>Great call.</p>',
        ])->assertOk();

        $this->assertDatabaseHas('notes', [
            'id' => $note->id,
            'title' => 'Kickoff notes',
            'body' => '<p>Great call.</p>',
        ]);

        $this->actingAs($user)->deleteJson("/api/notes/{$note->id}")->assertNoContent();
        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }
}
