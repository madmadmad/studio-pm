<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Proposal;
use App\Models\StudioProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

// A new proposal, always a draft -- the proposal editor
// (ProposalController::store) or the MCP server's create_proposal_draft
// tool. It goes on an existing project of the client's, or a new one made
// for it; its estimate is what its services add up to.
class CreateProposalDraft
{
    public static function rules(Company $company): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'], // rich text HTML
            'disclaimer' => ['nullable', 'string'],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('company_id', $company->id)],
            'project_id' => ['required_without:new_project_name', 'nullable', Rule::exists('projects', 'id')->where('company_id', $company->id)],
            'new_project_name' => ['required_without:project_id', 'nullable', 'string', 'max:255'],
            ...self::itemRules(),
            ...self::teamRules(),
        ];
    }

    public static function itemRules(): array
    {
        return [
            'items' => ['nullable', 'array'],
            'items.*.description' => ['required_with:items', 'string'],
            'items.*.details' => ['nullable', 'string'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.rate' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.service_id' => ['nullable', 'exists:services,id'],
        ];
    }

    public static function teamRules(): array
    {
        return [
            'team_user_ids' => ['sometimes', 'nullable', 'array'],
            'team_user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNot('role', User::ROLE_ACCOUNTANT)],
            'team_heading' => ['sometimes', 'nullable', 'string', 'max:255'],
            'show_about' => ['sometimes', 'boolean'],
        ];
    }

    public static function teamFields(array $data): array
    {
        return collect(['team_user_ids', 'team_heading', 'show_about'])
            ->filter(fn ($key) => array_key_exists($key, $data))
            ->mapWithKeys(fn ($key) => [$key => match ($key) {
                'team_user_ids' => array_map('intval', array_values($data[$key] ?? [])) ?: null,
                'show_about' => (bool) $data[$key],
                default => $data[$key] ?: null,
            }])
            ->all();
    }

    // A proposal's estimate: its services' total, or empty with none.
    public static function estimateFor(Proposal $proposal): ?float
    {
        $proposal->load('items');

        return $proposal->items->isEmpty() ? null : $proposal->itemsTotal();
    }

    // `$data` is validated against rules($company).
    public function handle(User $actor, Company $company, array $data): Proposal
    {
        Gate::forUser($actor)->authorize('create', Proposal::class);

        return DB::transaction(function () use ($data, $company) {
            $projectId = $data['project_id'] ?? null;

            if (! $projectId && ! empty($data['new_project_name'])) {
                $projectId = $company->projects()->create([
                    'name' => $data['new_project_name'],
                    'contact_id' => $data['contact_id'] ?? null,
                ])->id;
            }

            $proposal = $company->proposals()->create([
                'project_id' => $projectId,
                'contact_id' => $data['contact_id'] ?? null,
                'title' => $data['title'],
                'body' => $data['body'],
                // A new proposal starts from the default unless one's sent.
                'disclaimer' => array_key_exists('disclaimer', $data) ? $data['disclaimer'] : StudioProfile::current()->proposal_disclaimer,
                ...self::teamFields($data),
                'status' => 'draft',
            ]);

            // The estimate is always what the services add up to -- never
            // entered by hand -- and empty until there are any.
            if (! empty($data['items'])) {
                $proposal->items()->createMany($data['items']);
            }
            $proposal->update(['estimate_amount' => self::estimateFor($proposal)]);

            return $proposal;
        });
    }
}
