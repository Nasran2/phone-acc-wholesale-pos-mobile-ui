<?php

namespace App\Providers;

use App\Actions\StorePasskeyCustom;
use App\Actions\VerifyPasskeyCustom;
use App\Http\Requests\PasskeyRegistrationRequestCustom;
use App\Http\Requests\PasskeyVerificationRequestCustom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(StorePasskey::class, StorePasskeyCustom::class);
        $this->app->bind(VerifyPasskey::class, VerifyPasskeyCustom::class);

        $this->app->bind(PasskeyRegistrationRequest::class, PasskeyRegistrationRequestCustom::class);
        $this->app->bind(PasskeyVerificationRequest::class, PasskeyVerificationRequestCustom::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
