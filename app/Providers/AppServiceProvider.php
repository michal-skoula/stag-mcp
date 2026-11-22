<?php

namespace App\Providers;

use App\Contracts\IssueReportDestination;
use App\Services\Reports\IssueReportManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(IssueReportManager::class);
        $this->app->bind(IssueReportDestination::class, fn ($app) => $app->make(IssueReportManager::class)->driver());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
