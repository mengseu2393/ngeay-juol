<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\Filament\LandlordPanelProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return $this->redirectUser(Auth::user());
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $loginField = filter_var($credentials['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $user = User::query()
            ->where($loginField, $credentials['login'])
            ->where('status', UserStatus::Active->value)
            ->first();

        if ($user instanceof User && Hash::check($credentials['password'], $user->password)) {
            Auth::login($user, $request->boolean('remember'));
            $request->session()->regenerate();

            return $this->redirectUser(Auth::user());
        }

        throw ValidationException::withMessages([
            'login' => [__('Invalid login credentials or inactive account.')],
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    protected function redirectUser($user)
    {
        $intended = session()->get('url.intended');
        if ($intended) {
            $path = parse_url($intended, PHP_URL_PATH) ?: '';
            $path = '/' . ltrim($path, '/');

            if ($user->hasAnyRole(['landlord', 'landlord_manager'])) {
                if ($path === '/admin' || str_starts_with($path, '/admin/')) {
                    session()->forget('url.intended');
                }
            } elseif ($user->hasRole('tenant')) {
                $panel = '/'.LandlordPanelProvider::PATH;
                if ($path === '/admin' || str_starts_with($path, '/admin/') || $path === $panel || str_starts_with($path, $panel.'/')) {
                    session()->forget('url.intended');
                }
            }
        }

        if ($user->isPlatformStaff()) {
            return redirect()->intended(route('filament.admin.pages.dashboard'));
        }

        if ($user->hasAnyRole(['landlord', 'landlord_manager'])) {
            return redirect()->intended(route('filament.landlord.pages.dashboard'));
        }

        if ($user->hasRole('tenant')) {
            return redirect()->intended(route('portal.dashboard'));
        }

        return redirect()->intended('/');
    }
}
