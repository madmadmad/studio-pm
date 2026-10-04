<?php

/*
|--------------------------------------------------------------------------
| Notification emails
|--------------------------------------------------------------------------
|
| The wording of each notification email (App\Services\EmailTemplates),
| editable in Settings; these are the defaults a blank field falls back
| to. Each has a subject, a heading, the message above the button (blank
| lines split paragraphs), the button's label, and a note below it.
| :placeholders are filled in when the email is sent -- each lists its
| own. (The proposal and invoice emails' messages are separate Settings.)
|
*/

return [

    'message_new' => [
        'label' => 'New message',
        'audience' => 'Staff and clients on the thread',
        'placeholders' => ['first_name', 'sender', 'project', 'subject', 'snippet', 'studio'],
        'defaults' => [
            'subject' => 'New message on :project: :subject',
            'heading' => 'New message from :sender on :project',
            'message' => ":subject\n\n:snippet",
            'button' => 'View and reply',
            'note' => '',
        ],
    ],

    'message_reply' => [
        'label' => 'Message reply',
        'audience' => 'Staff and clients on the thread',
        'placeholders' => ['first_name', 'sender', 'project', 'subject', 'snippet', 'studio'],
        'defaults' => [
            'subject' => 'New reply on :project: :subject',
            'heading' => ':sender replied on :project',
            'message' => ":subject\n\n:snippet",
            'button' => 'View and reply',
            'note' => '',
        ],
    ],

    'proposal_accepted' => [
        'label' => 'Proposal accepted',
        'audience' => 'Managers',
        'placeholders' => ['first_name', 'client', 'proposal', 'estimate', 'studio'],
        'defaults' => [
            'subject' => 'Proposal accepted: :proposal',
            'heading' => ':client accepted :proposal',
            'message' => ":client just accepted \":proposal\".\n\nEstimate: :estimate",
            'button' => 'View proposal',
            'note' => '',
        ],
    ],

    'staff_invite' => [
        'label' => 'Staff invitation',
        'audience' => 'New team members',
        'placeholders' => ['first_name', 'studio', 'expiry'],
        'defaults' => [
            'subject' => "You've been invited to :studio",
            'heading' => 'Hi :first_name,',
            'message' => "You've been invited to join the :studio workspace.",
            'button' => 'Set your password',
            'note' => "This invite link expires in :expiry. If you weren't expecting this, you can ignore this email.",
        ],
    ],

    'client_invite' => [
        'label' => 'Client hub invitation',
        'audience' => 'Client contacts',
        'placeholders' => ['first_name', 'studio', 'expiry'],
        'defaults' => [
            'subject' => 'Welcome to your :studio client hub',
            'heading' => 'Hi :first_name,',
            'message' => "You've been invited to the client hub, where you can follow your project's tasks, proposals, and invoices.",
            'button' => 'Sign in',
            'note' => 'This link expires in :expiry and can only be used once. After that, you can request a new one from the sign-in page.',
        ],
    ],

    'client_sign_in' => [
        'label' => 'Client sign-in link',
        'audience' => 'Client contacts who ask for one',
        'placeholders' => ['first_name', 'studio', 'expiry'],
        'defaults' => [
            'subject' => 'Your :studio sign-in link',
            'heading' => 'Hi :first_name,',
            'message' => "Here's the sign-in link you requested.",
            'button' => 'Sign in',
            'note' => "This link expires in :expiry and can only be used once. If you didn't request this, you can ignore this email.",
        ],
    ],

    'password_reset' => [
        'label' => 'Password reset',
        'audience' => 'Staff',
        'placeholders' => ['first_name', 'studio', 'expiry'],
        'defaults' => [
            'subject' => 'Reset your :studio password',
            'heading' => 'Hi :first_name,',
            'message' => 'We received a request to reset the password for your account.',
            'button' => 'Reset password',
            'note' => "This link expires in :expiry. If you didn't ask to reset your password, you can ignore this email.",
        ],
    ],

];
