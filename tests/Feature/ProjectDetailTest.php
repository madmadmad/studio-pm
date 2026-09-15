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
        $company = Company::create(['name' => 'Alder & Finch Design', 'email' => 'client@example.com']);
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
}
