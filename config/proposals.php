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

    /*
    |--------------------------------------------------------------------------
    | Default disclaimer
    |--------------------------------------------------------------------------
    |
    | The scope note a new proposal starts with, shown between its scope of
    | work and its services. The starting default only -- it's edited in
    | Settings (studio_profiles), and on each proposal.
    |
    */

    'default_disclaimer' => env(
        'PROPOSALS_DEFAULT_DISCLAIMER',
        'This estimate outlines the scope of work for the proposed project, detailing the services, deliverables, and associated costs required to achieve the outlined objectives. Services outside of this scope will be estimated and billed separately upon request.'
    ),

    /*
    |--------------------------------------------------------------------------
    | About section
    |--------------------------------------------------------------------------
    |
    | The studio blurb closing every proposal (heading: the studio's name).
    | The starting default only -- it's edited in Settings > Proposals, and
    | each proposal can leave it out.
    |
    */

    'default_about' => '<p>We specialize in crafting original and professionally designed branding, digital and creative solutions that tell your story. Our team pushes creative boundaries, delivering work that is both strategically aligned and visually compelling. We believe the smallest details make the biggest impact, which is why we pour passion and precision into every aspect of our projects. From the initial concept to the final deliverable, we sweat the details—ensuring that every pixel is thoughtfully considered and executed to perfection.</p>',

    // How long a proposal's pricing holds, from the day it's sent -- shown
    // on it as "Valid until".
    'valid_days' => (int) env('PROPOSALS_VALID_DAYS', 90),

    'email_subject_template' => env('PROPOSALS_EMAIL_SUBJECT_TEMPLATE', ':firm_name sent you a proposal: :proposal_title'),

    // The starting default only -- the message is edited in Settings
    // (studio_profiles), and before each send.
    'email_template' => env(
        'PROPOSALS_EMAIL_TEMPLATE',
        "Thank you for considering :firm_name for your project.\n\nIf you have any questions or would like to discuss the proposal further, please don’t hesitate to reach out. We’d be happy to schedule a meeting or provide any additional details.\n\nWe’re excited about the opportunity to collaborate and look forward to speaking with you."
    ),

];
