<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\CreateProposalDraftTool;
use App\Models\Company;
use App\Models\Proposal;
use Illuminate\Testing\Fluent\AssertableJson;

class CreateProposalDraftToolTest extends McpTestCase
{
    public function test_it_saves_a_draft_on_a_project_with_its_estimate(): void
    {
        $this->as($this->admin)->tool(CreateProposalDraftTool::class, [
            'company_id' => $this->acme->id,
            'project_id' => $this->mine->id,
            'title' => 'Website redesign',
            'body' => "## Scope\n\n- New homepage\n- **Fast** pages",
            'items' => [
                ['description' => 'Design', 'quantity' => 20, 'rate' => 150],
                ['description' => 'Build', 'quantity' => 1, 'rate' => 4000, 'details' => 'Laravel *and* React'],
            ],
        ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('created', true)
                ->where('proposal.status', 'draft')
                ->where('proposal.estimate', 7000)
                ->where('proposal.project.name', 'Rebrand')
                ->where('proposal.url', fn ($url) => str_starts_with($url, url('/proposals/')) && str_ends_with($url, '/edit'))
                ->etc());

        $proposal = Proposal::sole();
        $this->assertSame('draft', $proposal->status);
        $this->assertNull($proposal->sent_at);
        $this->assertSame('<h2>Scope</h2><ul><li>New homepage</li><li><strong>Fast</strong> pages</li></ul>', str_replace("\n", '', $proposal->body));
        $this->assertSame('<p>Laravel <em>and</em> React</p>', trim($proposal->items[1]->details));
    }

    public function test_it_can_make_a_new_project_for_it(): void
    {
        $this->as($this->admin)->tool(CreateProposalDraftTool::class, [
            'company_id' => $this->acme->id, 'new_project_name' => 'Phase 2', 'title' => 'Phase 2', 'body' => 'Next steps.',
        ])->assertOk();

        $this->assertSame('Phase 2', Proposal::sole()->project->name);
        $this->assertSame('leads', Proposal::sole()->project->status);
    }

    public function test_html_in_the_body_never_gets_through(): void
    {
        $this->as($this->admin)->tool(CreateProposalDraftTool::class, [
            'company_id' => $this->acme->id, 'project_id' => $this->mine->id, 'title' => 'X',
            'body' => 'Hello <script>alert(1)</script> <img src=x onerror=alert(1)> [link](javascript:alert(1))',
        ])->assertOk();

        $body = Proposal::sole()->body;
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('onerror', $body);
        $this->assertStringNotContainsString('javascript:', $body);
    }

    public function test_it_needs_the_proposals_permission(): void
    {
        $this->as($this->sam)->tool(CreateProposalDraftTool::class, [
            'company_id' => $this->acme->id, 'project_id' => $this->mine->id, 'title' => 'X', 'body' => 'Y',
        ])->assertHasErrors(['This action is unauthorized.']);

        $this->assertSame(0, Proposal::count());
    }

    public function test_the_project_must_be_the_clients(): void
    {
        $other = Company::create(['name' => 'Zeta'])->projects()->create(['name' => 'Theirs']);

        $this->as($this->admin)->tool(CreateProposalDraftTool::class, [
            'company_id' => $this->acme->id, 'project_id' => $other->id, 'title' => 'X', 'body' => 'Y',
        ])->assertHasErrors(['project id']);
    }
}
