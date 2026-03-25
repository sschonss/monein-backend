<?php

namespace App\Services;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    private WebPush $webPush;

    public function __construct()
    {
        $this->webPush = new WebPush([
            'VAPID' => [
                'subject' => config('app.url'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ]);
        $this->webPush->setAutomaticPadding(false);
    }

    public function notifyUser(int $userId, array $payload): void
    {
        $this->sendToUsers([$userId], $payload);
    }

    public function notifyAllUsers(array $payload): void
    {
        $userIds = PushSubscription::distinct()->pluck('user_id')->toArray();
        $this->sendToUsers($userIds, $payload);
    }

    private function sendToUsers(array $userIds, array $payload): void
    {
        $subscriptions = PushSubscription::whereIn('user_id', $userIds)->get();

        foreach ($subscriptions as $sub) {
            $this->webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys' => [
                        'auth' => $sub->auth,
                        'p256dh' => $sub->p256dh,
                    ],
                ]),
                json_encode($payload)
            );
        }

        foreach ($this->webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }
}
