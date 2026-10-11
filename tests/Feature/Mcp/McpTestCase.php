<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\StudioServer;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Tests\TestCase;

// The studio's MCP server (App\Mcp\Servers\StudioServer): a client with a
// project Sam is on and one Sam isn't, plus an admin who can do anything.
abstract class McpTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $sam;

    protected Company $acme;

    protected Project $mine;

    protected Project $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['name' => 'Ada Admin']);
        $this->sam = User::factory()->teamMember()->create(['name' => 'Sam Staff', 'permissions' => []]);
        $this->acme = Company::create(['name' => 'Acme']);
        $this->mine = $this->acme->projects()->create(['name' => 'Rebrand', 'status' => 'active']);
        $this->theirs = $this->acme->projects()->create(['name' => 'Secret', 'status' => 'active']);
        $this->mine->users()->attach($this->sam->id, ['assigned_at' => now()]);
    }

    protected function as(User $user): PendingTestResponse
    {
        return StudioServer::actingAs($user, 'api');
    }
}
