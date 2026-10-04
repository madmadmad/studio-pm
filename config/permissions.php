<?php

/*
|--------------------------------------------------------------------------
| Staff permissions
|--------------------------------------------------------------------------
|
| Two roles: a super admin has every permission below plus the two that
| are theirs alone (settings, team); a team member works on their assigned
| projects -- tasks, files, notes, schedule, messages, their own time, and
| approved proposals -- plus whichever of these a super admin switches on
| for them on the Team page. Checked with $user->hasPermission('...'),
| the `permission:` route middleware, and on the front end from
| auth.user.permissions.
|
*/

return [

    'grantable' => [
        'all_projects' => [
            'label' => 'All projects',
            'description' => 'Every project, not just assigned ones, and the archive.',
        ],
        'manage_projects' => [
            'label' => 'Manage projects',
            'description' => "Create and delete projects, set who's on them, change status and details, edit anyone's time.",
        ],
        'clients' => [
            'label' => 'Clients',
            'description' => 'Clients and contacts, client hub invites and preview.',
        ],
        'proposals' => [
            'label' => 'Proposals',
            'description' => 'Write, edit and send proposals (everyone sees approved ones).',
        ],
        'invoices' => [
            'label' => 'Invoices',
            'description' => 'Invoices and project billing, budgets, send alerts.',
        ],
        'expenses' => [
            'label' => 'Expenses',
            'description' => 'Expenses, their categories and taxes.',
        ],
        'bookkeeping' => [
            'label' => 'Bookkeeping',
            'description' => 'Reports, profit & loss, exports, the income chart.',
        ],
        'services' => [
            'label' => 'Services & rates',
            'description' => 'The services list and hourly rates.',
        ],
    ],

    // Never granted: whoever has these could give themselves the rest.
    'super_admin_only' => ['settings', 'team'],

];
