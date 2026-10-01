<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

// The few Plaid API calls the bank feed needs, against the environment in
// config/services.php (sandbox while testing -- free, with test banks).
class PlaidClient
{
    private function post(string $path, array $body = []): array
    {
        $env = config('services.plaid.env');

        return Http::baseUrl("https://{$env}.plaid.com")
            ->acceptJson()
            ->timeout(30)
            ->post($path, [
                'client_id' => config('services.plaid.client_id'),
                'secret' => config('services.plaid.secret'),
                ...$body,
            ])
            ->throw()
            ->json();
    }

    // Plaid's error code ("ITEM_LOGIN_REQUIRED", ...) from a failed call.
    public static function errorCode(RequestException $e): ?string
    {
        return $e->response->json('error_code');
    }

    public function isConfigured(): bool
    {
        return filled(config('services.plaid.client_id')) && filled(config('services.plaid.secret'));
    }

    // What Plaid Link opens with.
    public function createLinkToken(string $userId, string $clientName): string
    {
        return $this->post('/link/token/create', [
            'client_name' => $clientName,
            'user' => ['client_user_id' => $userId],
            'products' => ['transactions'],
            'country_codes' => ['US'],
            'language' => 'en',
        ])['link_token'];
    }

    /**
     * @return array{access_token: string, item_id: string}
     */
    public function exchangePublicToken(string $publicToken): array
    {
        $response = $this->post('/item/public_token/exchange', ['public_token' => $publicToken]);

        return ['access_token' => $response['access_token'], 'item_id' => $response['item_id']];
    }

    // One page of changes since `cursor` (null for the first sync).
    public function syncTransactions(string $accessToken, ?string $cursor): array
    {
        return $this->post('/transactions/sync', array_filter([
            'access_token' => $accessToken,
            'cursor' => $cursor,
            'count' => 500,
        ]));
    }

    public function removeItem(string $accessToken): void
    {
        $this->post('/item/remove', ['access_token' => $accessToken]);
    }

    // Sandbox only: a charge on a `user_transactions_dynamic` test bank,
    // for trying a sync that picks up something new.
    public function createSandboxTransaction(string $accessToken, float $amount, string $description): void
    {
        $this->post('/sandbox/transactions/create', [
            'access_token' => $accessToken,
            'transactions' => [[
                'amount' => $amount,
                'description' => $description,
                'date_transacted' => today()->toDateString(),
                'date_posted' => today()->toDateString(),
            ]],
        ]);
    }
}
