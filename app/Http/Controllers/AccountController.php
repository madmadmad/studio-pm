<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Adding and editing ledger accounts (Bookkeeping > Chart of accounts).
// An account goes under a group heading and takes its type; it's never
// deleted, only deactivated -- and the accounts the app posts to on its
// own (checking, the card...) stay active.
class AccountController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:accounts,code'],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['required', 'integer', Rule::exists('accounts', 'id')->whereNull('parent_id')],
            'description' => ['nullable', 'string', 'max:1000'],
        ], ['parent_id.exists' => 'Pick a group heading.']);

        $heading = Account::findOrFail($data['parent_id']);

        return Account::create([...$data, 'type' => $heading->type])->refresh();
    }

    public function update(Request $request, Account $account)
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('accounts', 'code')->ignore($account->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            // Another heading of the same type; headings stay headings.
            'parent_id' => ['sometimes', 'integer', Rule::exists('accounts', 'id')->whereNull('parent_id')->where('type', $account->type)],
            'is_active' => ['sometimes', 'boolean'],
        ], ['parent_id.exists' => 'Pick a group heading of the same type.']);

        abort_if(isset($data['parent_id']) && $account->parent_id === null, 422, 'A group heading can\'t be moved under another.');
        abort_if(($data['is_active'] ?? true) === false && $account->system_key, 422, "The app posts to {$account->name} on its own, so it can't be deactivated.");

        // A code that's been edited is ours now, not the placeholder.
        if (isset($data['code']) && $data['code'] !== $account->code) {
            $data['code_is_placeholder'] = false;
        }

        $account->update($data);

        return $account;
    }
}
