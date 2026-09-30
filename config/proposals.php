<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proposal email
    |--------------------------------------------------------------------------
    |
    | The subject and message the Send Proposal dialog starts from; both can
    | be edited before sending. Placeholders: :firm_name, :proposal_title,
    | :contact_first_name.
    |
    */

    'email_subject_template' => env('PROPOSALS_EMAIL_SUBJECT_TEMPLATE', ':firm_name sent you a proposal: :proposal_title'),

    'email_template' => env(
        'PROPOSALS_EMAIL_TEMPLATE',
        "Hi :contact_first_name,\n\nHere's our proposal for :proposal_title. You can review the full scope and accept it online using the link below, and it's attached as a PDF too. Let us know if you have any questions."
    ),

];
