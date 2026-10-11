<?php

namespace App\Mcp\Tools;

use App\Actions\CreateProposalDraft;
use App\Models\Company;
use App\Models\Proposal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('create_proposal_draft')]
#[Title('Draft a proposal')]
#[Description('Saves a new proposal for a client as a DRAFT — it is never sent, and the client sees nothing until someone reviews and sends it in the app. Put it on one of the client\'s projects (project_id) or name a new project for it (new_project_name). The body is Markdown (headings, lists, bold, italic). Line items set the estimate: quantity × rate each. Needs the Proposals permission. Returns the draft with its ID and a link to finish it in the app.')]
class CreateProposalDraftTool extends StudioTool
{
    public function handle(Request $request, CreateProposalDraft $createDraft): ResponseFactory
    {
        $user = $this->actor($request);
        Gate::forUser($user)->authorize('create', Proposal::class);

        $input = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'body' => ['required', 'string'],
            'items' => ['nullable', 'array', 'max:100'],
        ]);
        $company = Company::findOrFail($input['company_id']);

        // The client's text as the editor's rich text, then the editor's own rules.
        $args = collect($request->all())->only(['title', 'contact_id', 'project_id', 'new_project_name'])->all();
        $args['body'] = $this->richText($input['body']);
        $args['items'] = collect($input['items'] ?? [])->map(fn ($item) => is_array($item) ? [
            ...collect($item)->only(['description', 'quantity', 'rate', 'service_id'])->all(),
            'details' => $this->richText($item['details'] ?? null),
        ] : $item)->all() ?: null;

        $data = Validator::make($args, CreateProposalDraft::rules($company))->validate();
        $proposal = $createDraft->handle($user, $company, $data)->load('items', 'project:id,name');

        return Response::structured([
            'created' => true,
            'proposal' => [
                'id' => $proposal->id,
                'title' => $proposal->title,
                'status' => $proposal->status,
                'estimate' => $proposal->estimate_amount !== null ? (float) $proposal->estimate_amount : null,
                'items' => $proposal->items->map(fn ($i) => [
                    'description' => $i->description,
                    'quantity' => (float) $i->quantity,
                    'rate' => (float) $i->rate,
                    'amount' => $i->amount(),
                ])->all(),
                'company' => ['id' => $company->id, 'name' => $company->name],
                'project' => $proposal->project ? ['id' => $proposal->project->id, 'name' => $proposal->project->name] : null,
                'url' => $this->link("/proposals/{$proposal->id}/edit"),
            ],
            'next' => 'Review it in the app and send it from there — this tool never sends proposals.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->integer()->description('The client it\'s for (an ID from list_companies).')->required(),
            'title' => $schema->string()->description('The proposal\'s title, e.g. "Website redesign".')->required(),
            'body' => $schema->string()->description('The proposal itself, in Markdown: scope, approach, timeline.')->required(),
            'project_id' => $schema->integer()->description('One of this client\'s projects to put it on (from list_projects). Give this or new_project_name.'),
            'new_project_name' => $schema->string()->description('Make a new project for it, with this name, instead of using project_id.'),
            'contact_id' => $schema->integer()->description('Who at the client it\'s addressed to (from list_contacts). Defaults to their primary contact when sent.'),
            'items' => $schema->array()->items($schema->object([
                'description' => $schema->string()->description('The line item, e.g. "Design".')->required(),
                'details' => $schema->string()->description('More about it, in Markdown.'),
                'quantity' => $schema->number()->description('How many (hours, pages…), at least 0.01.')->required(),
                'rate' => $schema->number()->description('Price per one, in dollars.')->required(),
                'service_id' => $schema->integer()->description('The studio service it is, if any.'),
            ]))->description('Priced line items; the estimate is their total.'),
        ];
    }
}
