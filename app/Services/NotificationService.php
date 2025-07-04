<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function sendToUser(User $user, string $title, string $body, array $data = [])
    {
        \Log::info('sendToUser called', [
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body
        ]);

        // Obter apenas tokens ativos
        $tokens = $user->deviceTokens()
            ->where('is_active', true)
            ->pluck('token')
            ->toArray();

        \Log::info('User device tokens', [
            'count' => count($tokens),
            'tokens' => $tokens
        ]);

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    public function sendToUsers(array $userIds, string $title, string $body, array $data = [])
    {
        $tokens = DeviceToken::whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->pluck('token')
            ->toArray();

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    public function sendToReportOwner(int $reportId, string $title, string $body, array $data = [])
    {
        $report = \App\Models\Report::find($reportId);
        if (!$report) {
            return [
                'success' => false,
                'message' => 'Relatório não encontrado',
            ];
        }

        return $this->sendToUser($report->user, $title, $body, $data);
    }

    private function sendToTokens(array $tokens, string $title, string $body, array $data = [])
    {
        if (empty($tokens)) {
            \Log::warning('No tokens to send notifications to');
            return [
                'success' => true,
                'message' => 'Nenhum token para enviar',
                'count' => 0,
            ];
        }

        $messages = [];
        foreach ($tokens as $token) {
            $messages[] = [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'sound' => 'default',
            ];
        }

        \Log::info('Sending notifications to Expo API', [
            'messages_count' => count($messages)
        ]);

        try {
            $response = Http::post('https://exp.host/--/api/v2/push/send', $messages);

            \Log::info('Expo API response', [
                'status' => $response->status(),
                'body' => $response->json()
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Notificações enviadas com sucesso',
                    'count' => count($tokens),
                ];
            }

            \Log::error('Erro ao enviar notificações push', [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao enviar notificações',
                'error' => $response->json(),
            ];
        } catch (\Exception $e) {
            \Log::error('Exceção ao enviar notificações push', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Exceção ao enviar notificações',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function sendToAdminsAndCurators(string $title, string $body, array $data = [])
    {
        // Obter IDs de todos os administradores e curadores
        $adminRoleIds = \App\Models\Role::whereIn('role', ['admin', 'curator'])->pluck('id')->toArray();
        $adminUserIds = \App\Models\User::whereIn('role_id', $adminRoleIds)->pluck('id')->toArray();

        return $this->sendToUsers($adminUserIds, $title, $body, $data);
    }
}
