<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Console\Command;

class NotifyExtractReminder extends Command
{
    protected $signature = 'notify:extract-reminder';
    protected $description = 'Send push notification reminding users to upload their bank statements (runs on Mondays)';

    public function handle(): void
    {
        if (now()->dayOfWeek !== 1) { // 1 = Monday
            $this->info('Not Monday, skipping.');
            return;
        }

        $pushService = new WebPushService();
        $userIds = PushSubscription::distinct()->pluck('user_id')->toArray();
        $count = 0;

        foreach ($userIds as $userId) {
            $pushService->notifyUser($userId, [
                'title' => 'Monein - Hora de atualizar!',
                'body' => 'Importe seu extrato da semana para manter suas finanças em dia.',
                'url' => '/import',
            ]);
            $count++;
        }

        $this->info("Notified {$count} users.");
    }
}
