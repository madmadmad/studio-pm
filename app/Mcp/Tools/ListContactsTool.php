<?php

namespace App\Mcp\Tools;

use App\Models\Contact;
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

#[Name('list_contacts')]
#[Title('List client contacts')]
#[Description('Lists the people at the studio\'s clients — name, email, phone, role, and whether they\'re the primary or billing contact. Filter by client or search by name or email. Use it to find a contact ID for a proposal. Needs the Clients permission.')]
#[IsReadOnly]
#[IsIdempotent]
class ListContactsTool extends StudioTool
{
    public function handle(Request $request): ResponseFactory
    {
        Gate::forUser($this->actor($request))->authorize('viewAny', Contact::class);

        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $contacts = Contact::with('company:id,name')
            ->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($data['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->limit($data['limit'] ?? 100)
            ->get();

        return Response::structured([
            'count' => $contacts->count(),
            'contacts' => $contacts->map(fn (Contact $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'role' => $c->role,
                'is_primary' => (bool) $c->is_primary,
                'is_billing' => (bool) $c->is_billing,
                'company' => $c->company ? ['id' => $c->company->id, 'name' => $c->company->name] : null,
                // Contacts are shown on their client's page.
                'url' => $this->link("/clients/{$c->company_id}"),
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->integer()->description('Only this client\'s contacts (an ID from list_companies).'),
            'search' => $schema->string()->description('Part of the contact\'s name or email.'),
            'limit' => $schema->integer()->description('At most this many (1–200, default 100).'),
        ];
    }
}
