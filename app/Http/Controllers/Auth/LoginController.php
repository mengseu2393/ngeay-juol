<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Auth\Concerns\RedirectsAuthenticatedUsers;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    use RedirectsAuthenticatedUsers;

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

        $user = $this->findLoginUser($credentials['login'], $credentials['password']);

        if ($user instanceof User) {
            Auth::login($user, $request->boolean('remember'));
            $request->session()->regenerate();

            return $this->redirectUser(Auth::user());
        }

        throw ValidationException::withMessages([
            'login' => [__('Invalid login credentials or inactive account.')],
        ]);
    }

    /**
     * Resolve the `login` field (email, username or phone number) to the active
     * user whose password matches. Phone numbers are compared in normalized form
     * (PhoneNumber::normalize()) because stored numbers predate any formatting
     * rule; phone_number isn't unique either, so every candidate gets a password
     * check rather than just the first row.
     */
    protected function findLoginUser(string $login, string $password): ?User
    {
        $active = fn () => User::query()->where('status', UserStatus::Active->value);

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $candidates = $active()->where('email', $login)->get();
        } else {
            $candidates = $active()->where('username', $login)->get();

            if (PhoneNumber::looksLikePhone($login)) {
                $candidates = $candidates->merge(PhoneNumber::matchingUsers($active(), $login));
            }
        }

        return $candidates->first(fn (User $user) => Hash::check($password, $user->password));
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
