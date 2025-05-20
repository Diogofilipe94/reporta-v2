<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Registra ou atualiza um token de dispositivo para o utilizador atual
     */
    public function registerToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'platform' => 'required|string|in:android,ios',
        ]);

        $user = auth()->user();
        $token = $request->token;
        $platform = $request->platform;

        // Procurar se o token já existe para este utilizador
        $deviceToken = DeviceToken::where('token', $token)
            ->where('user_id', $user->id)
            ->first();

        if ($deviceToken) {
            // Atualiza o token existente
            $deviceToken->update([
                'platform' => $platform,
                'last_used_at' => now(),
            ]);
        } else {
            // Cria um novo registro
            DeviceToken::create([
                'user_id' => $user->id,
                'token' => $token,
                'platform' => $platform,
                'last_used_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Token registado com sucesso',
        ]);
    }

    /**
     * Remove um token de dispositivo
     */
    public function deleteToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $user = auth()->user();
        $token = $request->token;

        DeviceToken::where('token', $token)
            ->where('user_id', $user->id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Token removido com sucesso',
        ]);
    }

    public function unregisterToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'platform' => 'required|string|in:android,ios',
        ]);

        $user = auth()->user();
        $token = $request->token;

        $deviceToken = DeviceToken::where('token', $token)
            ->where('user_id', $user->id)
            ->first();

        if ($deviceToken) {
            $deviceToken->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

            \Log::info('Token desativado com sucesso', [
                'user_id' => $user->id,
                'token' => $token
            ]);
        } else {
            \Log::warning('Tentativa de desativar token não encontrado', [
                'user_id' => $user->id,
                'token' => $token
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Token desativado com sucesso',
        ]);
    }
}
