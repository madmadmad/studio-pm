<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OverviewTest extends TestCase
{
    use RefreshDatabase;

    private Project $mine;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $this->mine = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $this->other = $company->projects()->create(['name' => 'Website', 'status' => 'active']);

        Task::create(['project_id' => $this->mine->id, 'title' => 'Logo sketches', 'status' => 'todo']);
        Task::create(['project_id' => $this->other->id, 'title' => 'Sitemap', 'status' => 'todo']);
        Message::create(['project_id' => $this->mine->id, 'subject' => 'Kickoff', 'body' => 'Hi', 'sent_at' => now()]);
        $invoice = Invoice::create(['company_id' => $company->id, 'project_id' => $this->mine->id, 'status' => 'sent', 'issued_on' => today(), 'due_on' => today()->addDays(30), 'sent_at' => now()]);
        $invoice->items()->create(['description' => 'Design', 'amount' => 500]);
    }

    public function test_managers_see_every_project_and_the_money(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('can.invoices', true)
                ->where('metrics.outstanding.amount', 500)
                ->whereNot('income', null)
                ->where('activity', fn ($events) => collect($events)->pluck('type')->contains('invoice_sent')
                    && collect($events)->pluck('subject')->contains('Sitemap')));
    }

    // Staging: a proposal accepted through its shared link, never emailed --
    // no "sent" events to merge the "accepted" ones into.
    public function test_an_accepted_proposal_that_was_never_sent_shows(): void
    {
        $this->mine->company->proposals()->create(['title' => 'Logo', 'body' => '<p>Scope</p>', 'status' => 'accepted', 'accepted_at' => now(), 'project_id' => $this->mine->id]);

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('activity', fn ($events) => collect($events)->pluck('type')->contains('proposal_accepted')));
    }

    public function test_team_members_see_only_their_projects_and_nothing_financial(): void
    {
        $member = User::factory()->create(['role' => 'team_member']);
        $member->projects()->attach($this->mine->id, ['assigned_at' => now()]);

        $this->actingAs($member)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('can.invoices', false)
                ->where('income', null)
                ->missing('metrics.outstanding')
                ->where('activity', function ($events) {
                    $events = collect($events);

                    return $events->pluck('subject')->contains('Logo sketches')
                        && ! $events->pluck('subject')->contains('Sitemap')
                        && $events->whereIn('type', ['invoice_sent', 'payment', 'proposal_sent', 'proposal_accepted'])->isEmpty()
                        && $events->whereNotNull('amount')->isEmpty();
                })
                ->where('projects', fn ($projects) => collect($projects)->pluck('name')->all() === ['Brand refresh'])
                ->where('threads.0.subject', 'Kickoff'));
    }
}
