<?php

namespace App\Http\Controllers;

use App\Models\StudioProfile;
use App\Services\EmailTemplates;
use Illuminate\Http\Request;

class StudioProfileController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
            'payment_instructions' => ['nullable', 'string'],
            // New proposals start with it; blank for none.
            'proposal_disclaimer' => ['nullable', 'string'],
            // The Send Proposal dialog starts with it; blank for the default.
            'proposal_email_message' => ['nullable', 'string'],
            // The Send Invoice dialog starts with it; blank for the default.
            'invoice_email_message' => ['nullable', 'string'],
            // Notification emails' wording, by template then field; blank
            // fields fall back to config/email_templates.php.
            'email_templates' => ['sometimes', 'nullable', 'array'],
            'email_templates.*' => ['array'],
            'email_templates.*.*' => ['nullable', 'string', 'max:5000'],
            // Charged on invoices with "Charge Tax" on; no rate, no tax.
            'sales_tax_name' => ['nullable', 'string', 'max:255'],
            'sales_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if (array_key_exists('email_templates', $data)) {
            $data['email_templates'] = $this->changedTemplates($data['email_templates'] ?? []);
        }

        $profile = StudioProfile::current();
        $profile->update($data);

        return $profile;
    }

    // Only known templates and fields, and only what differs from the
    // default -- so improving a default later reaches everyone who left it.
    private function changedTemplates(array $templates): ?array
    {
        $changed = [];
        foreach (EmailTemplates::definitions() as $key => $definition) {
            foreach (EmailTemplates::FIELDS as $field) {
                if (! array_key_exists($field, $templates[$key] ?? [])) {
                    continue;
                }
                $value = trim((string) $templates[$key][$field]);
                $default = $definition['defaults'][$field];
                // A cleared heading or note stays cleared; a cleared subject,
                // message or button goes back to the default.
                $cleared = $value === '' && $default !== '' && in_array($field, EmailTemplates::OPTIONAL, true);
                if ($cleared || ($value !== '' && $value !== $default)) {
                    $changed[$key][$field] = $value;
                }
            }
        }

        return $changed ?: null;
    }
}
