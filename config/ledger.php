<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Capitalizing equipment
    |--------------------------------------------------------------------------
    |
    | An expense of at least `threshold` dollars in one of these accounts is
    | an asset, not an expense: it posts to `asset` instead (CPA, Oct 2026).
    | Our CPA depreciates it at year end. Billed-to-client expenses are
    | never capitalized.
    |
    */

    'capitalize' => [
        'threshold' => 2500,
        'accounts' => ['electronics_furniture'],
        'asset' => 'equipment',
    ],

];
