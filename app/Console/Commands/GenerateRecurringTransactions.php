<?php

namespace App\Console\Commands;

use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Services\CurrencyService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateRecurringTransactions extends Command
{
    protected $signature = 'recurring:generate';

    protected $description = 'Generate transactions from active recurring transactions that are due';

    public function handle(CurrencyService $currencyService): int
    {
        $due = RecurringTransaction::where('is_active', true)
            ->where('next_due_date', '<=', Carbon::today())
            ->get();

        $count = 0;

        foreach ($due as $recurring) {
            $currency = $recurring->currency ?? 'BRL';

            if (strtoupper($currency) === 'BRL') {
                $exchangeRate = null;
                $amountBrl = $recurring->amount;
            } else {
                $result = $currencyService->convert($recurring->amount, $currency, 'BRL');
                $exchangeRate = $result['rate'];
                $amountBrl = $result['converted'] ?? $recurring->amount;
            }

            Transaction::create([
                'user_id' => $recurring->user_id,
                'category_id' => $recurring->category_id,
                'type' => $recurring->type,
                'description' => $recurring->description,
                'amount' => $recurring->amount,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'amount_brl' => $amountBrl,
                'date' => $recurring->next_due_date,
            ]);

            $nextDate = match ($recurring->frequency) {
                'weekly' => Carbon::parse($recurring->next_due_date)->addDays(7),
                'biweekly' => Carbon::parse($recurring->next_due_date)->addDays(14),
                'monthly' => Carbon::parse($recurring->next_due_date)->addMonth(),
                'yearly' => Carbon::parse($recurring->next_due_date)->addYear(),
            };

            $recurring->update([
                'next_due_date' => $nextDate,
                'last_generated_at' => Carbon::today(),
            ]);

            $count++;
        }

        $this->info("Generated {$count} transaction(s) from recurring transactions.");

        return Command::SUCCESS;
    }
}
