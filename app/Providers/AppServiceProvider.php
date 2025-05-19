<?php

namespace App\Providers;

use App\Models\Report;
use App\Observers\ReportObserver;
use App\Services\NotificationService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationService::class, function ($app) {
            return new NotificationService();
        });
    }

    public function boot(): void
    {
        \Log::info('AppServiceProvider boot method called');
        Report::observe(ReportObserver::class);
        \Log::info('Report observer registered');
    }
}
