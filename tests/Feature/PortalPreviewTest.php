<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PortalPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithPortal(): array
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $other = $company->contacts()->create(['name' => 'Sam Lee', 'email' => 'sam@example.com']);
        $primary = $company->contacts()->create(['name' => 'Jo Park', 'email' => 'jo@example.com', 'is_primary' => true]);
        $other->forceFill(['portal_invited_at' => now()])->save();
        $primary->forceFill(['portal_invited_at' => now()])->save();

        return compact('company', 'project', 'primary');
    }

    public function test_a_manager_previews_the_portal_as_the_primary_contact_and_stays_signed_in(): void
    {
        ['company' => $company, 'primary' => $primary] = $this->clientWithPortal();
        $manager = User::factory()->create();

        $this->actingAs($manager)->get("/clients/{$company->id}/portal-preview")->assertRedirect('/portal');

        $this->assertSame($primary->id, Auth::guard('client')->id());
        $this->assertSame($manager->id, Auth::guard('web')->id());

        $this->get('/portal')->assertOk()->assertInertia(fn ($page) => $page
            ->where('portalPreview.contact', 'Jo Park')
            ->where('portalPreview.company', 'Alder & Finch Design'));
    }

    // The portal's own pages call the API with a same-site Referer, which
    // is what gives the request its session (Sanctum's stateful API) --
    // and the session is where the preview lives. (One call per test: the
    // test client doesn't carry that session on to a second API call.)
    private function startPreview(Company $company): void
    {
        $this->actingAs(User::factory()->create())->get("/clients/{$company->id}/portal-preview");
        $this->withHeader('Referer', 'http://localhost/portal');
    }

    public function test_messages_cannot_be_posted_during_a_preview(): void
    {
        ['company' => $company, 'project' => $project] = $this->clientWithPortal();
        $this->startPreview($company);

        $this->postJson("/api/portal/projects/{$project->id}/messages", ['subject' => 'Hi', 'body' => 'From the client?'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This is a staff preview of the portal, so nothing can be changed here.');

        $this->assertSame(0, $project->messages()->count());
    }

    public function test_tasks_cannot_be_added_during_a_preview(): void
    {
        ['company' => $company, 'project' => $project] = $this->clientWithPortal();
        $this->startPreview($company);

        $this->postJson("/api/portal/projects/{$project->id}/tasks", ['title' => 'New task'])->assertForbidden();

        $this->assertSame(0, $project->tasks()->count());
    }

    public function test_exiting_or_signing_out_ends_only_the_preview(): void
    {
        ['company' => $company] = $this->clientWithPortal();
        $manager = User::factory()->create();

        foreach (['/portal/preview/exit', '/portal/logout'] as $exit) {
            $this->actingAs($manager)->get("/clients/{$company->id}/portal-preview");

            $this->post($exit)->assertRedirect("/clients/{$company->id}");

            $this->assertNull(Auth::guard('client')->id());
            $this->assertSame($manager->id, Auth::guard('web')->id());
            $this->assertFalse(session()->has('portal_preview'));
        }
    }

    public function test_a_real_client_session_is_not_read_only(): void
    {
        ['project' => $project, 'primary' => $primary] = $this->clientWithPortal();

        $response = $this->actingAs($primary, 'client')
            ->withHeader('Referer', 'http://localhost/portal')
            ->postJson("/api/portal/projects/{$project->id}/messages", ['subject' => 'Hi', 'body' => 'A real question']);

        // Whatever else it says (here, that recipients are required), it's
        // not the preview's refusal.
        $this->assertNotSame(403, $response->status());
    }

    public function test_only_managers_can_preview_and_only_with_a_portal_contact(): void
    {
        ['company' => $company] = $this->clientWithPortal();
        $this->actingAs(User::factory()->teamMember()->create())->get("/clients/{$company->id}/portal-preview")->assertForbidden();

        $noPortal = Company::create(['name' => 'Quiet Co']);
        $noPortal->contacts()->create(['name' => 'Al', 'email' => 'al@example.com', 'is_primary' => true]);
        $this->actingAs(User::factory()->create())->get("/clients/{$noPortal->id}/portal-preview")->assertRedirect(route('clients.index'));
        $this->assertNull(Auth::guard('client')->id());
    }

    public function test_the_portal_lists_carry_what_their_cards_need(): void
    {
        ['company' => $company, 'project' => $project, 'primary' => $primary] = $this->clientWithPortal();
        $company->proposals()->create(['project_id' => $project->id, 'title' => 'Phase 2', 'body' => 'Scope', 'estimate_amount' => 5000, 'status' => 'sent']);
        $invoice = $company->invoices()->create(['project_id' => $project->id, 'status' => 'sent', 'issued_on' => today(), 'due_on' => today(), 'payment_terms' => 'net_30']);
        $invoice->items()->create(['description' => 'Design', 'amount' => 800, 'position' => 0]);
        $invoice->recordPayment('check', 800);

        $this->actingAs($primary, 'client')->get('/portal')
            ->assertInertia(fn ($page) => $page->where('awaitingProposals', 1));

        $this->actingAs($primary, 'client')->get('/portal/invoices')
            ->assertInertia(fn ($page) => $page->where('invoices.0.status', 'paid')->whereNot('invoices.0.paid_at', null));
    }
}
