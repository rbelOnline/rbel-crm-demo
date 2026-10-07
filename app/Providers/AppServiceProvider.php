<?php

namespace App\Providers;

use App\Models\User;
use App\Services\MailSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // Catch N+1 queries and mass-assignment mistakes during development.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Role-based authorization. Admins and advisors manage (delete, send
        // emails); assistants can view, create and edit but not delete.
        Gate::define('manage', fn (User $user) => $user->canManage());
        Gate::define('admin', fn (User $user) => $user->isAdmin());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('emails', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        // Mail server settings saved in the app (Profile) override .env.
        try {
            $this->app->make(MailSettings::class)->apply();
        } catch (QueryException) {
            // app_settings not migrated yet (fresh install): keep the .env settings.
        }
    }
}
