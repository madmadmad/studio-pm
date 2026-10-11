<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\ListCompaniesTool;
use App\Models\Company;
use Illuminate\Testing\Fluent\AssertableJson;

class ListCompaniesToolTest extends McpTestCase
{
    public function test_it_lists_clients_with_counts_and_links(): void
    {
        Company::create(['name' => 'Zeta', 'status' => 'inactive']);
        $this->acme->contacts()->create(['name' => 'Pat', 'email' => 'pat@acme.example']);

        $this->as($this->admin)->tool(ListCompaniesTool::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('count', 2)
                ->where('companies.0.name', 'Acme')
                ->where('companies.0.contacts', 1)
                ->where('companies.0.projects', 2)
                ->where('companies.0.url', url("/clients/{$this->acme->id}"))
                ->etc());

        $this->as($this->admin)->tool(ListCompaniesTool::class, ['status' => 'inactive'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 1)->where('companies.0.name', 'Zeta')->etc());
    }

    public function test_it_needs_the_clients_permission(): void
    {
        $this->as($this->sam)->tool(ListCompaniesTool::class)
            ->assertHasErrors(['This action is unauthorized.'])
            ->assertDontSee('Acme');

        $this->sam->forceFill(['permissions' => ['clients']])->save();
        $this->as($this->sam->fresh())->tool(ListCompaniesTool::class)->assertOk();
    }
}
