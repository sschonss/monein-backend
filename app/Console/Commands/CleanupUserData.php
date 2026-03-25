<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CleanupUserData extends Command
{
    protected $signature = 'user:cleanup {email} {--force : Skip confirmation}';

    protected $description = 'Remove ALL transactions and investment accounts for a user, so they can re-import everything clean';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("User not found: {$email}");
            return 1;
        }

        $txCount = $user->transactions()->count();
        $accCount = $user->investmentAccounts()->count();

        $this->warn("User: {$user->name} ({$email})");
        $this->line("  Transactions: {$txCount}");
        $this->line("  Investment accounts (cofrinhos): {$accCount}");
        $this->line("  Categories and tags will NOT be deleted.");

        if (!$this->option('force') && !$this->confirm('Delete all transactions and investment accounts? This cannot be undone.')) {
            $this->info('Cancelled.');
            return 0;
        }

        $user->transactions()->delete();
        $this->info("Deleted {$txCount} transactions");

        $user->investmentAccounts()->delete();
        $this->info("Deleted {$accCount} investment accounts");

        $this->newLine();
        $this->info('All data cleaned. Ready to re-import.');

        return 0;
    }
}
