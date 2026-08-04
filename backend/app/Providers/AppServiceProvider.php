<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // HawkHost may use a MySQL/MariaDB engine with a 1,000-byte index
        // limit. Under utf8mb4, indexed VARCHAR(255) columns can require
        // 1,020 bytes, so use the long-established 191-character safe limit.
        Schema::defaultStringLength(191);
    }
}
