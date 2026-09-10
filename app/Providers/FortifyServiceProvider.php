<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
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
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn ($request) => view('auth.reset-password', ['request' => $request]));

        RateLimiter::for('login', function (Request $request) {
            // LoginController accepts email-or-username in a field named `login`;
            // Fortify::username() ('email') is only ever present on Fortify's own
            // login route. Falling back to it keeps both keyed per-account —
            // reading only Fortify's field would collapse the key to the bare IP
            // and throttle every landlord behind one office NAT together.
            $identifier = (string) ($request->input('login') ?? $request->input(Fortify::username()) ?? '');

            $throttleKey = Str::transliterate(Str::lower($identifier).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });

        // Password-reset request/consume. Keyed by the submitted email + IP so one
        // noisy address can't lock everyone out from behind a shared NAT.
        RateLimiter::for('password-reset', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // QR login redemption. The token is 64 random chars behind a signed URL, so
        // this is belt-and-braces against scripted replay of a photographed code.
        RateLimiter::for('qr-login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        $this->throttleFortifyPasswordResetRoutes();

        $this->configurePasswordDefaults();
    }

    /**
     * Fortify parameterises limiters for its login / two-factor / passkey routes
     * only — POST /forgot-password and POST /reset-password ship with none, and
     * both are unauthenticated endpoints that accept an email + token. Fortify
     * registers them from its own package route file, so the limiter is attached
     * to the finished Route objects once every provider has booted.
     */
    protected function throttleFortifyPasswordResetRoutes(): void
    {
        $this->app->booted(function () {
            $throttled = ['password.email', 'password.update'];

            // Scanned rather than looked up by name: Fortify names these routes
            // *after* registering them, so RouteCollection's name index is still
            // empty at this point in the boot cycle.
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if (in_array($route->getName(), $throttled, true)) {
                    $route->middleware('throttle:password-reset');
                }
            }
        });
    }

    /**
     * The app-wide password policy. `Password::default()` in
     * App\Actions\Fortify\PasswordValidationRules degrades to a bare `min:8`
     * unless defaults are registered — this is that registration.
     *
     * `uncompromised()` calls the HaveIBeenPwned API, so it is production-only:
     * local dev and the test suite must never depend on outbound network access.
     */
    protected function configurePasswordDefaults(): void
    {
        Password::defaults(function () {
            $rule = Password::min(12)->mixedCase()->numbers();

            return app()->isProduction() ? $rule->uncompromised() : $rule;
        });
    }
}
