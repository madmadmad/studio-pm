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

];
