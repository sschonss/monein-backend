<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CleanupInvestmentTransactions extends Command
{
    protected $signature = 'user:cleanup-investments {email}';

    protected $description = 'Remove all investment-type transactions for a user by email, so they can re-import clean';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("User not found: {$email}");
            return 1;
        }

        $count = $user->transactions()->where('type', 'investment')->count();

        if ($count === 0) {
            $this->info("No investment transactions found for {$email}");
            return 0;
        }

        if (!$this->confirm("Delete {$count} investment transactions for {$user->name} ({$email})?")) {
            $this->info('Cancelled.');
            return 0;
        }

        $deleted = $user->transactions()->where('type', 'investment')->delete();

        $this->info("Deleted {$deleted} investment transactions for {$email}");

        // Also clean up investment-type categories with no remaining transactions
        $orphanCategories = $user->categories()
            ->where('type', 'investment')
            ->whereDoesntHave('transactions')
            ->get();

        if ($orphanCategories->isNotEmpty()) {
            $names = $orphanCategories->pluck('name')->join(', ');
            if ($this->confirm("Also delete {$orphanCategories->count()} orphan investment categories ({$names})?")) {
                $orphanCategories->each->delete();
                $this->info("Deleted orphan categories: {$names}");
            }
        }

        return 0;
    }
}
