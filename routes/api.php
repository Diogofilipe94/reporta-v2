<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportDetailController;
use App\Http\Middleware\JwtMiddleware;
use Illuminate\Support\Facades\Route;

// Rotas de acesso públicas
Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

Route::get('address', [AddressController::class, 'index']);
Route::post('address', [AddressController::class, 'store']);


// Rotas protegidas por JWT
Route::middleware(JwtMiddleware::class)->group(function () {
    // Reports - CRUD
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


    Route::get('/report-image/{filename}', function ($filename) {
        // Procura a imagem no diretório storage/app/public/reports
        $path = storage_path('app/public/reports/' . $filename);

        if (file_exists($path)) {
            return response()->file($path);
        }

        // Também verifica no volume, se estiver configurado
        $volumePath = env('RAILWAY_VOLUME_MOUNT_PATH');
        if ($volumePath && file_exists($volumePath . '/reports/' . $filename)) {
            return response()->file($volumePath . '/reports/' . $filename);
        }

        // Tenta buscar por outros caminhos possíveis
        $alternativePaths = [
            public_path('storage/reports/' . $filename),
            base_path('storage/app/public/reports/' . $filename),
            '/app/storage/app/public/reports/' . $filename,
        ];

        foreach ($alternativePaths as $altPath) {
            if (file_exists($altPath)) {
                return response()->file($altPath);
            }
        }

        // Nenhuma imagem encontrada
        return response()->json(['error' => 'Image not found: ' . $filename], 404);
    })->where('filename', '[A-Za-z0-9\.]+');


    Route::get('/debug', function () {
    return [
        'storage_path' => storage_path('app/public/reports'),
        'public_path' => public_path('storage/reports'),
        'disk_public_exists' => config('filesystems.disks.public') ? 'sim' : 'não',
        'storage_link_exists' => file_exists(public_path('storage')) ? 'sim' : 'não',
        'sample_files' => Storage::disk('public')->files('reports'),
        'report_dir_exists' => is_dir(storage_path('app/public/reports')) ? 'sim' : 'não',
        'php_version' => PHP_VERSION,
        'laravel_version' => app()->version(),
    ];
});
});
