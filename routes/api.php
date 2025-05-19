<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportDetailController;
use App\Http\Controllers\NotificationController;
use App\Http\Middleware\JwtMiddleware;
use Illuminate\Support\Facades\Route;

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

Route::get('address', [AddressController::class, 'index']);
Route::post('address', [AddressController::class, 'store']);


Route::middleware(JwtMiddleware::class)->group(function () {
    Route::get('reports', [ReportController::class, 'index']);
    Route::post('reports', [ReportController::class, 'store']);
    Route::get('reports/{id}', [ReportController::class, 'show']);
    Route::put('reports/{id}', [ReportController::class, 'update']);
    Route::delete('reports/{id}', [ReportController::class, 'destroy']);

    Route::get('categories', [CategoryController::class, 'index']);

    Route::post('reports/{id}/details', [ReportDetailController::class, 'store']);
    Route::get('reports/{id}/details', [ReportDetailController::class, 'show']);
    Route::patch('reports/{id}/details', [ReportDetailController::class, 'update']);

    Route::patch('reports/{id}/status', [ReportController::class, 'updateStatus']);

    Route::get('user/reports', [ReportController::class, 'getUserOwnReports']);
    Route::get('user/points', [ReportController::class, 'getPoints']);

    Route::put('user', [AuthController::class, 'updateUser']);
    Route::get('user', [AuthController::class, 'getUser']);

    Route::get('photos/{filename}', [App\Http\Controllers\PhotoController::class, 'show']);

    Route::post('notifications/token', [NotificationController::class, 'registerToken']);
    Route::delete('notifications/token', [NotificationController::class, 'deleteToken']);
    Route::get('notifications/tokens', [NotificationController::class, 'getUserTokens']);
    Route::post('notifications/test', [NotificationController::class, 'testNotification']);


    Route::get('debug/notification-setup', function() {
        // Verificar setup dos observers
        $appServiceProvider = new \App\Providers\AppServiceProvider(app());
        $appServiceProvider->boot();

        // Verificar tokens existentes
        $tokens = \App\Models\DeviceToken::with('user')->get();

        // Testar serviço de notificações diretamente
        $testResult = null;
        if ($tokens->count() > 0) {
            $notificationService = app(\App\Services\NotificationService::class);
            $firstToken = $tokens->first();
            $testResult = $notificationService->sendToUser(
                $firstToken->user,
                'Teste de Diagnóstico',
                'Esta é uma notificação de teste da rota de diagnóstico',
                ['type' => 'diagnostic_test']
            );
        }

        return response()->json([
            'observers_registered' => true,
            'notification_service_loaded' => app()->has(\App\Services\NotificationService::class),
            'device_tokens_count' => $tokens->count(),
            'tokens_by_user' => $tokens->groupBy('user_id')->map(function($groupTokens) {
                return [
                    'count' => $groupTokens->count(),
                    'tokens' => $groupTokens->pluck('token')->toArray()
                ];
            }),
            'test_notification_result' => $testResult
        ]);
    });

        Route::get('debug/force-status-update/{reportId}/{statusId}', function($reportId, $statusId) {
        \Log::info('Debug route: force-status-update called', [
            'report_id' => $reportId,
            'status_id' => $statusId
        ]);

        $report = \App\Models\Report::find($reportId);
        if (!$report) {
            return response()->json(['error' => 'Report not found'], 404);
        }

        $status = \App\Models\Status::find($statusId);
        if (!$status) {
            return response()->json(['error' => 'Status not found'], 404);
        }

        $oldStatusId = $report->status_id;
        $oldStatus = \App\Models\Status::find($oldStatusId);

        // Atualizando o status diretamente
        $report->status_id = $statusId;
        \Log::info('Before save - isDirty check', [
            'is_dirty' => $report->isDirty('status_id'),
            'original' => $report->getOriginal('status_id'),
            'current' => $report->status_id
        ]);
        $report->save();

        // Testando notificação diretamente
        $notificationService = app(\App\Services\NotificationService::class);
        $notificationResult = $notificationService->sendToUser(
            $report->user,
            'Atualização Forçada de Status',
            "Seu relatório em '{$report->location}' foi atualizado para '{$status->status}'",
            [
                'type' => 'debug_status_update',
                'report_id' => $report->id,
                'new_status' => $status->status
            ]
        );

        return response()->json([
            'message' => 'Status update forced',
            'report_id' => $report->id,
            'old_status' => $oldStatus ? $oldStatus->status : 'unknown',
            'new_status' => $status->status,
            'notification_result' => $notificationResult,
            'user_id' => $report->user_id,
            'tokens' => $report->user->deviceTokens()->pluck('token')->toArray()
        ]);
    });

    Route::get('debug/create-test-token/{userId}', function($userId) {
        $user = \App\Models\User::find($userId);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Token de exemplo do Expo
        $testToken = 'ExponentPushToken[example' . rand(1000, 9999) . ']';

        \App\Models\DeviceToken::create([
            'user_id' => $user->id,
            'token' => $testToken,
            'platform' => 'ios',
            'last_used_at' => now()
        ]);

        return response()->json([
            'message' => 'Test token created',
            'user_id' => $user->id,
            'token' => $testToken,
            'all_tokens' => $user->deviceTokens()->pluck('token')->toArray()
        ]);
    });
});
