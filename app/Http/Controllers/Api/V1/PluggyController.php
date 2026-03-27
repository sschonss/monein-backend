<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\PluggyItem;
use App\Models\Transaction;
use App\Services\CurrencyService;
use App\Services\PluggyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PluggyController extends Controller
{
    public function __construct(
        private PluggyService $pluggy,
    ) {}

    /**
     * Generate a connect token for the Pluggy Connect Widget.
     */
    public function createConnectToken(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $connectToken = $this->pluggy->createConnectToken((string) $user->id);

            return response()->json([
                'accessToken' => $connectToken,
            ]);
        } catch (\Exception $e) {
            Log::error('Pluggy connect token error', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Erro ao gerar token de conexão'], 500);
        }
    }

    /**
     * Save item after user connects a bank via widget.
     */
    public function storeItem(Request $request): JsonResponse
    {
        $request->validate([
            'itemId' => 'required|string',
        ]);

        $user = $request->user();

        // Check if already saved
        $existing = PluggyItem::where('user_id', $user->id)
            ->where('pluggy_item_id', $request->itemId)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Conexão já existe',
                'item' => $existing,
            ]);
        }

        try {
            $itemData = $this->pluggy->getItem($request->itemId);

            $item = PluggyItem::create([
                'user_id' => $user->id,
                'pluggy_item_id' => $request->itemId,
                'connector_name' => $itemData['connector']['name'] ?? 'Banco',
                'connector_logo' => $itemData['connector']['imageUrl'] ?? null,
                'status' => $itemData['status'] ?? 'UPDATED',
            ]);

            return response()->json([
                'message' => 'Banco conectado com sucesso',
                'item' => $item,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Pluggy store item error', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Erro ao salvar conexão'], 500);
        }
    }

    /**
     * List user's connected banks.
     */
    public function listConnections(Request $request): JsonResponse
    {
        $items = PluggyItem::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($items);
    }

    /**
     * Sync transactions from a connected bank.
     */
    public function syncTransactions(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $pluggyItem = PluggyItem::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        try {
            // Refresh item status
            $itemData = $this->pluggy->getItem($pluggyItem->pluggy_item_id);
            $pluggyItem->update(['status' => $itemData['status'] ?? $pluggyItem->status]);

            if (($itemData['status'] ?? '') === 'UPDATING') {
                return response()->json([
                    'message' => 'O banco está atualizando os dados. Tente novamente em alguns segundos.',
                    'status' => 'UPDATING',
                ], 202);
            }

            // Get all accounts for this item
            $accounts = $this->pluggy->getAccounts($pluggyItem->pluggy_item_id);

            $imported = 0;
            $skipped = 0;

            // Determine sync date range (last sync or last 90 days)
            $from = $pluggyItem->last_sync_at
                ? $pluggyItem->last_sync_at->format('Y-m-d')
                : now()->subDays(90)->format('Y-m-d');

            $currencyService = new CurrencyService();

            foreach ($accounts as $account) {
                $accountId = $account['id'];
                $page = 1;

                do {
                    $transactionsData = $this->pluggy->getTransactions($accountId, $from, null, $page);
                    $transactions = $transactionsData['results'] ?? [];

                    foreach ($transactions as $tx) {
                        $pluggyTxId = $tx['id'];

                        // Skip if already imported
                        if (Transaction::where('pluggy_transaction_id', $pluggyTxId)->exists()) {
                            $skipped++;
                            continue;
                        }

                        // Determine type
                        $amount = abs($tx['amount'] ?? 0);
                        $type = ($tx['type'] ?? '') === 'CREDIT' ? 'income' : 'expense';

                        // Determine currency and convert to BRL
                        $currency = strtoupper($tx['currencyCode'] ?? $account['currencyCode'] ?? 'BRL');
                        $amountBrl = $amount;
                        $exchangeRate = null;

                        if ($currency !== 'BRL') {
                            $rate = $currencyService->getExchangeRate($currency, 'BRL');
                            if ($rate) {
                                $exchangeRate = $rate;
                                $amountBrl = round($amount * $rate, 2);
                            }
                        }

                        // Find or create category
                        $categoryId = $this->resolveCategory($user, $tx, $type);

                        // Build description
                        $description = $tx['descriptionRaw'] ?? $tx['description'] ?? 'Transação';

                        $user->transactions()->create([
                            'type' => $type,
                            'description' => $description,
                            'amount' => $amount,
                            'currency' => $currency,
                            'exchange_rate' => $exchangeRate,
                            'amount_brl' => $amountBrl,
                            'date' => substr($tx['date'] ?? now()->toDateString(), 0, 10),
                            'category_id' => $categoryId,
                            'notes' => 'Importado via Open Finance - ' . $pluggyItem->connector_name,
                            'pluggy_transaction_id' => $pluggyTxId,
                        ]);

                        $imported++;
                    }

                    $page++;
                    $totalPages = $transactionsData['totalPages'] ?? 1;
                } while ($page <= $totalPages);
            }

            $pluggyItem->update(['last_sync_at' => now()]);

            return response()->json([
                'message' => 'Sincronização concluída',
                'imported' => $imported,
                'skipped' => $skipped,
            ]);
        } catch (\Exception $e) {
            Log::error('Pluggy sync error', ['error' => $e->getMessage(), 'itemId' => $pluggyItem->pluggy_item_id]);
            return response()->json(['message' => 'Erro ao sincronizar transações: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Delete a bank connection.
     */
    public function deleteConnection(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $pluggyItem = PluggyItem::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        try {
            $this->pluggy->deleteItem($pluggyItem->pluggy_item_id);
        } catch (\Exception $e) {
            Log::warning('Failed to delete Pluggy item remotely', ['error' => $e->getMessage()]);
        }

        $pluggyItem->delete();

        return response()->json(['message' => 'Conexão removida']);
    }

    /**
     * Try to match a Pluggy transaction to an existing category.
     */
    private function resolveCategory($user, array $tx, string $type): ?int
    {
        $description = ($tx['descriptionRaw'] ?? '') . ' ' . ($tx['description'] ?? '');
        $pluggyCategory = $tx['category'] ?? null;

        // Map Pluggy categories to Monein categories
        $categoryMapping = [
            'Restaurants' => 'Alimentação',
            'Food and Groceries' => 'Mercado',
            'Supermarkets' => 'Mercado',
            'Groceries' => 'Mercado',
            'Transportation' => 'Transporte',
            'Gas Stations' => 'Combustível',
            'Health' => 'Saúde',
            'Pharmacy' => 'Saúde',
            'Bills and Utilities' => 'Contas Fixas',
            'Utilities' => 'Contas Fixas',
            'Insurance' => 'Seguro',
            'Taxes' => 'Impostos',
            'Credit Card' => 'Cartão de Crédito',
            'Donations' => 'Doações',
            'Shopping' => 'Compras',
            'Services' => 'Serviços',
            'Salary' => 'Salário',
            'Income' => 'Salário',
            'Investments' => 'Rendimentos',
            'Transfer' => 'Transferência',
            'Loan' => 'Empréstimo',
            'Education' => 'Educação',
            'Entertainment' => 'Lazer',
            'Travel' => 'Viagem',
        ];

        $categoryName = null;

        // First, try Pluggy's category
        if ($pluggyCategory && isset($categoryMapping[$pluggyCategory])) {
            $categoryName = $categoryMapping[$pluggyCategory];
        }

        if (!$categoryName) {
            // Fallback: try to match using description patterns (reuse PicPay rules)
            $rules = [
                ['pattern' => '/Supermercado|Atacarejo|Mercado/i', 'name' => 'Mercado'],
                ['pattern' => '/Restaurante|Lanchonete|Padaria|Acai|Pizza/i', 'name' => 'Alimentação'],
                ['pattern' => '/Uber|99|Taxi|Estaciona/i', 'name' => 'Transporte'],
                ['pattern' => '/Posto|Combusti|Shell|Ipiranga|BR\s/i', 'name' => 'Combustível'],
                ['pattern' => '/Farmacia|Hospital|Clinica|Medic/i', 'name' => 'Saúde'],
                ['pattern' => '/ENERGISA|COPEL|SANEPAR|Internet|Telecom/i', 'name' => 'Contas Fixas'],
                ['pattern' => '/Segur/i', 'name' => 'Seguro'],
                ['pattern' => '/Imposto|DARF|IPTU|IPVA|Governo/i', 'name' => 'Impostos'],
                ['pattern' => '/Fatura|Cartão/i', 'name' => 'Cartão de Crédito'],
                ['pattern' => '/Salario|Folha|FGTS/i', 'name' => 'Salário'],
            ];

            foreach ($rules as $rule) {
                if (preg_match($rule['pattern'], $description)) {
                    $categoryName = $rule['name'];
                    break;
                }
            }
        }

        if (!$categoryName) {
            $categoryName = $type === 'income' ? 'Outros (Receitas)' : 'Outros (Despesas)';
        }

        // Find or create category
        $category = Category::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('is_default', true);
        })->where('name', $categoryName)->first();

        if (!$category) {
            $colors = [
                'income' => '#16a34a',
                'expense' => '#ef4444',
                'investment' => '#f59e0b',
            ];

            $category = Category::create([
                'name' => $categoryName,
                'type' => $type,
                'color' => $colors[$type] ?? '#6b7280',
                'icon' => 'tag',
                'user_id' => $user->id,
            ]);
        }

        return $category->id;
    }
}
