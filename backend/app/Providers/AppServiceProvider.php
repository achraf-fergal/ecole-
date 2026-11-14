<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        // Configure strict password security policy.
        Password::defaults(function () {
            return Password::min(12)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised();
        });

        // Configure frontend password reset URL.
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');

            return "{$frontendUrl}/reset-password?token=".urlencode($token).'&email='.urlencode($user->email);
        });
    }
}
