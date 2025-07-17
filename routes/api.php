<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportDetailController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Middleware\JwtMiddleware;
use Illuminate\Support\Facades\Route;

// ===== ROTAS PÚBLICAS =====
Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);
Route::get('address', [AddressController::class, 'index']);
Route::post('address', [AddressController::class, 'store']);

// ===== ROTAS PROTEGIDAS =====
Route::middleware(JwtMiddleware::class)->group(function () {

    // === GESTÃO DE REPORTS ===
    Route::get('reports', [ReportController::class, 'index']);
    Route::post('reports', [ReportController::class, 'store']);
    Route::get('reports/{id}', [ReportController::class, 'show']);
    Route::put('reports/{id}', [ReportController::class, 'update']);
    Route::delete('reports/{id}', [ReportController::class, 'destroy']);
    Route::patch('reports/{id}/status', [ReportController::class, 'updateStatus']);

    // === REPORTS DO UTILIZADOR ===
    Route::get('user/reports', [ReportController::class, 'getUserOwnReports']);
    Route::get('user/points', [ReportController::class, 'getPoints']);

    // === CATEGORIAS ===
    Route::get('categories', [CategoryController::class, 'index']);

    // === DETALHES DE REPORTS ===
    Route::post('reports/{id}/details', [ReportDetailController::class, 'store']);
    Route::get('reports/{id}/details', [ReportDetailController::class, 'show']);
    Route::patch('reports/{id}/details', [ReportDetailController::class, 'update']);

    // === GESTÃO DE UTILIZADORES ===
    Route::put('user', [AuthController::class, 'updateUser']);
    Route::get('user', [AuthController::class, 'getUser']);

    // === FOTOS ===
    Route::get('photos/{filename}', [App\Http\Controllers\PhotoController::class, 'show']);

    // === NOTIFICAÇÕES ===
    Route::post('notifications/token', [NotificationController::class, 'registerToken']);
    Route::delete('notifications/token', [NotificationController::class, 'deleteToken']);
    Route::get('notifications/tokens', [NotificationController::class, 'getUserTokens']);
    Route::post('/notifications/token/unregister', [NotificationController::class, 'unregisterToken']);

    // === ADMINISTRAÇÃO DE UTILIZADORES ===
    Route::get('admin/users', [AuthController::class, 'getUserRoles']);
    Route::put('admin/users/{id}/role', [AuthController::class, 'updateUserRole']);
    Route::delete('admin/users/{id}', [AuthController::class, 'deleteUser']);

    // ===== DASHBOARD ADMINISTRATIVO - SISTEMA COMPLETO =====
    Route::prefix('admin/dashboard')->group(function () {

        // === MÉTRICAS PRINCIPAIS ===
        Route::get('overview', [AdminDashboardController::class, 'getOverviewMetrics']);
        Route::get('resolution', [AdminDashboardController::class, 'getResolutionMetrics']);
        Route::get('categories', [AdminDashboardController::class, 'getCategoryMetrics']);
        Route::get('users', [AdminDashboardController::class, 'getUserMetrics']);
        Route::get('financial', [AdminDashboardController::class, 'getFinancialMetrics']);
        Route::get('priority', [AdminDashboardController::class, 'getPriorityMetrics']);

        // === EXPORTS ESPECIALIZADOS ===
        Route::get('export', [AdminDashboardController::class, 'getExportData']);
        Route::get('export-top-users', [AdminDashboardController::class, 'exportTopUsersForAwards']);

        // === DEBUG E TROUBLESHOOTING ===
        Route::get('debug', [AdminDashboardController::class, 'getDebugInfo']);
        Route::get('debug-table-structure', [AdminDashboardController::class, 'debugTableStructure']);

    });
});
