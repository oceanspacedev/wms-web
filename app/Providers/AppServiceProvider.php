<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('api-login', function (Request $request): Limit {
            $login = Str::lower($request->string('login')->toString().'|'.$request->string('whatsapp_number')->toString());

            return Limit::perMinute(5)->by(Str::transliterate($login.'|'.$request->ip()));
        });

        Gate::define('viewLogViewer', function ($user = null): bool {
            return (bool) (
                $user?->hasRole('super_admin')
                || $user?->can('ViewLogViewer')
                || $user?->can('view_log_viewer')
                || $user?->can('View:LogViewer')
            );
        });

        Gate::define('viewApiDocs', function ($user = null): bool {
            return (bool) (
                app()->environment('local', 'testing')
                || $user?->hasRole('super_admin')
                || $user?->can('ViewApiDocs')
                || $user?->can('view_api_docs')
                || $user?->can('View:ApiDocs')
                || $user?->can('ViewScramble')
                || $user?->can('view_scramble')
            );
        });
    }
}
