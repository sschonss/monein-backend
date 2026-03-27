<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PluggyService
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.pluggy.api_url'), '/');
        $this->clientId = config('services.pluggy.client_id');
        $this->clientSecret = config('services.pluggy.client_secret');
    }

    /**
     * Get API key (cached for 1.5 hours, valid for 2 hours).
     */
    public function getApiKey(): string
    {
        return Cache::remember('pluggy_api_key', 5400, function () {
            $response = Http::post("{$this->baseUrl}/auth", [
                'clientId' => $this->clientId,
                'clientSecret' => $this->clientSecret,
            ]);

            if (!$response->successful()) {
                Log::error('Pluggy auth failed', ['status' => $response->status(), 'body' => $response->body()]);
                throw new \RuntimeException('Falha na autenticação com o Pluggy');
            }

            return $response->json('apiKey');
        });
    }

    /**
     * Create a connect token for the Pluggy Connect Widget.
     */
    public function createConnectToken(string $clientUserId, ?string $itemId = null): string
    {
        $apiKey = $this->getApiKey();

        $payload = [
            'options' => [
                'clientUserId' => $clientUserId,
            ],
        ];

        // If updating an existing item, pass the itemId
        if ($itemId) {
            $payload['options']['itemId'] = $itemId;
        }

        $response = Http::withHeaders([
            'X-API-KEY' => $apiKey,
        ])->post("{$this->baseUrl}/connect_token", $payload);

        if (!$response->successful()) {
            Log::error('Pluggy connect token failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Falha ao criar token de conexão');
        }

        return $response->json('accessToken');
    }

    /**
     * Get item (connection) details.
     */
    public function getItem(string $itemId): array
    {
        $apiKey = $this->getApiKey();

        $response = Http::withHeaders([
            'X-API-KEY' => $apiKey,
        ])->get("{$this->baseUrl}/items/{$itemId}");

        if (!$response->successful()) {
            Log::error('Pluggy get item failed', ['itemId' => $itemId, 'status' => $response->status()]);
            throw new \RuntimeException('Falha ao buscar conexão no Pluggy');
        }

        return $response->json();
    }

    /**
     * Get accounts for an item.
     */
    public function getAccounts(string $itemId): array
    {
        $apiKey = $this->getApiKey();

        $response = Http::withHeaders([
            'X-API-KEY' => $apiKey,
        ])->get("{$this->baseUrl}/accounts", [
            'itemId' => $itemId,
        ]);

        if (!$response->successful()) {
            Log::error('Pluggy get accounts failed', ['itemId' => $itemId, 'status' => $response->status()]);
            throw new \RuntimeException('Falha ao buscar contas no Pluggy');
        }

        return $response->json('results', []);
    }

    /**
     * Get transactions for an account with pagination.
     */
    public function getTransactions(string $accountId, ?string $from = null, ?string $to = null, int $page = 1, int $pageSize = 500): array
    {
        $apiKey = $this->getApiKey();

        $params = [
            'accountId' => $accountId,
            'page' => $page,
            'pageSize' => $pageSize,
        ];

        if ($from) {
            $params['from'] = $from;
        }
        if ($to) {
            $params['to'] = $to;
        }

        $response = Http::withHeaders([
            'X-API-KEY' => $apiKey,
        ])->get("{$this->baseUrl}/transactions", $params);

        if (!$response->successful()) {
            Log::error('Pluggy get transactions failed', ['accountId' => $accountId, 'status' => $response->status()]);
            throw new \RuntimeException('Falha ao buscar transações no Pluggy');
        }

        return $response->json();
    }

    /**
     * Delete an item (disconnect a bank).
     */
    public function deleteItem(string $itemId): void
    {
        $apiKey = $this->getApiKey();

        $response = Http::withHeaders([
            'X-API-KEY' => $apiKey,
        ])->delete("{$this->baseUrl}/items/{$itemId}");

        if (!$response->successful()) {
            Log::error('Pluggy delete item failed', ['itemId' => $itemId, 'status' => $response->status()]);
            throw new \RuntimeException('Falha ao desconectar banco no Pluggy');
        }
    }
}
