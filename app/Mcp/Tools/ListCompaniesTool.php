<?php

namespace App\Mcp\Tools;

use App\Models\Company;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_companies')]
#[Title('List clients')]
#[Description('Lists the studio\'s clients (companies), alphabetically, with how many contacts and projects each has. Use it to find a client\'s ID for list_contacts, list_projects or create_proposal_draft. Needs the Clients permission.')]
#[IsReadOnly]
#[IsIdempotent]
class ListCompaniesTool extends StudioTool
{
    public function handle(Request $request): ResponseFactory
    {
        Gate::forUser($this->actor($request))->authorize('viewAny', Company::class);

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $companies = Company::query()
            ->withCount(['contacts', 'projects'])
            ->when($data['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->limit($data['limit'] ?? 100)
            ->get();

        return Response::structured([
            'count' => $companies->count(),
            'companies' => $companies->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status,
                'phone' => $c->phone,
                'city' => $c->city,
                'state' => $c->state,
                'contacts' => $c->contacts_count,
                'projects' => $c->projects_count,
                'url' => $this->link("/clients/{$c->id}"),
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Part of the client\'s name.'),
            'status' => $schema->string()->enum(['active', 'inactive'])->description('Only active or inactive clients.'),
            'limit' => $schema->integer()->description('At most this many (1–200, default 100).'),
        ];
    }
}
