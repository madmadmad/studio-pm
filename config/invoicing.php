<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Terms
    |--------------------------------------------------------------------------
    |
    | Used for a new invoice whenever the client (Company) has no
    | default_payment_terms of its own set. One of App\Enums\PaymentTerms'
    | values, excluding 'custom' (a firm-wide default has to resolve to an
    | actual day count).
    |
    */

    'default_payment_terms' => env('INVOICING_DEFAULT_PAYMENT_TERMS', 'net_30'),

    /*
    |--------------------------------------------------------------------------
    | Automatic Reminders
    |--------------------------------------------------------------------------
    |
    | 'reminders_default_enabled' is the app-wide fallback used whenever a
    | client (Company) has no reminders_enabled override of its own; an
    | invoice-level override (also nullable) wins over both.
    |
    | 'reminder_offsets' are day counts relative to the due date: negative
    | means before the due date, 0 is the due date itself, positive means
    | overdue. After the last (largest) entry, reminders repeat every
    | 'reminder_repeat_interval_days' days while the invoice remains
    | unpaid, up to 'max_overdue_reminders' total overdue reminders
    | (counting the positive entries in 'reminder_offsets' itself).
    |
    */

    'reminders_default_enabled' => env('INVOICING_REMINDERS_DEFAULT_ENABLED', true),

    'reminder_offsets' => [-3, 0, 7, 14],

    'reminder_repeat_interval_days' => 14,

    'max_overdue_reminders' => env('INVOICING_MAX_OVERDUE_REMINDERS', 6),

    /*
    |--------------------------------------------------------------------------
    | Automated Sends Kill Switch
    |--------------------------------------------------------------------------
    |
    | Pauses both scheduled-send dispatch and automatic reminders without a
    | deploy. Manual sends from the UI are never affected by this.
    |
    */

    'automated_sends_enabled' => env('INVOICING_AUTOMATED_SENDS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Billing Reply-To
    |--------------------------------------------------------------------------
    |
    | Where a client's reply to an invoice email actually lands. Falls back
    | to StudioProfile::current()->email when unset.
    |
    */

    'billing_reply_to' => env('INVOICING_REPLY_TO_ADDRESS'),

    /*
    |--------------------------------------------------------------------------
    | Default Email Template
    |--------------------------------------------------------------------------
    |
    | Prefilled into the Send Invoice modal's message field. Supports
    | :contact_first_name, :invoice_number, :amount_due, :due_date, and
    | :firm_name placeholders, filled in when the modal opens.
    |
    */

    'email_template' => env(
        'INVOICING_EMAIL_TEMPLATE',
        'Please find attached the invoice for the services provided by :firm_name. If you have any questions or would like to discuss, feel free to reach out. We appreciate your business.'
    ),

    'email_subject_template' => env('INVOICING_EMAIL_SUBJECT_TEMPLATE', ':firm_name sent you invoice #:invoice_number.'),

];
