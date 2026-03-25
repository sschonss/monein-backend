<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function vapidKey()
    {
        return response()->json([
            'vapid_key' => config('services.webpush.public_key'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.auth' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
        ]);

        $userId = $request->user()->id;

        PushSubscription::where('user_id', $userId)
            ->where('endpoint', '!=', $validated['endpoint'])
            ->delete();

        PushSubscription::updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'user_id' => $userId,
                'auth' => $validated['keys']['auth'],
                'p256dh' => $validated['keys']['p256dh'],
            ]
        );

        return response()->json(['message' => 'Inscrito com sucesso.']);
    }

    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint', $validated['endpoint'])
            ->delete();

        return response()->json(['message' => 'Desinscrito com sucesso.']);
    }

    public function test(Request $request)
    {
        $user = $request->user();
        $count = PushSubscription::where('user_id', $user->id)->count();

        if ($count === 0) {
            return response()->json(['message' => 'Nenhuma inscrição encontrada.'], 404);
        }

        $pushService = new \App\Services\WebPushService();
        $pushService->notifyUser($user->id, [
            'title' => 'Monein - Teste',
            'body' => 'Notificações estão funcionando!',
            'url' => '/dashboard',
        ]);

        return response()->json(['message' => "Notificação enviada para {$count} dispositivo(s)."]);
    }
}
