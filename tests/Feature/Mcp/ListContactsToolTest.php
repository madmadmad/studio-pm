<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\ListContactsTool;
use App\Models\Company;
use Illuminate\Testing\Fluent\AssertableJson;

class ListContactsToolTest extends McpTestCase
{
    public function test_it_lists_contacts_by_client_or_search(): void
    {
        $this->acme->contacts()->create(['name' => 'Pat Lee', 'email' => 'pat@acme.example', 'is_primary' => true]);
        Company::create(['name' => 'Zeta'])->contacts()->create(['name' => 'Kim', 'email' => 'kim@zeta.example']);

        $this->as($this->admin)->tool(ListContactsTool::class, ['company_id' => $this->acme->id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('count', 1)
                ->where('contacts.0.name', 'Pat Lee')
                ->where('contacts.0.is_primary', true)
                ->where('contacts.0.company.name', 'Acme')
                ->where('contacts.0.url', url("/clients/{$this->acme->id}"))
                ->etc());

        $this->as($this->admin)->tool(ListContactsTool::class, ['search' => 'zeta.example'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 1)->where('contacts.0.name', 'Kim')->etc());
    }

    public function test_it_needs_the_clients_permission(): void
    {
        $this->acme->contacts()->create(['name' => 'Pat Lee', 'email' => 'pat@acme.example']);

        $this->as($this->sam)->tool(ListContactsTool::class)
            ->assertHasErrors(['This action is unauthorized.'])
            ->assertDontSee('pat@acme.example');
    }
}
