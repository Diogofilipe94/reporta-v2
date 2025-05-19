<?php

namespace App\Providers;

use App\Models\Report;
use App\Observers\ReportObserver;
use App\Services\NotificationService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(NotificationService::class, function ($app) {
            return new NotificationService();
        });
    }


    public function boot(): void
    {
        Report::observe(ReportObserver::class);
    }
}
