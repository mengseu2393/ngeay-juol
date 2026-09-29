<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RedirectsAuthenticatedUsers;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LandlordRegistrationService;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Landlord self-registration — landlords only; tenants are still created by
 * their landlord. Switched off by Admin Settings → Landlord Registration.
 */
class RegisterController extends Controller
{
    use RedirectsAuthenticatedUsers;

    public function show()
    {
        abort_unless(LandlordRegistrationService::isOpen(), 404);

        if (Auth::check()) {
            return $this->redirectUser(Auth::user());
        }

        return view('auth.register');
    }

    public function register(Request $request)
    {
        abort_unless(LandlordRegistrationService::isOpen(), 404);

        if (Auth::check()) {
            return $this->redirectUser(Auth::user());
        }

        // A session can outlive its user (account deleted while still signed in):
        // Auth::check() is false, but Auth::id() falls back to the raw session id,
        // which SubscriptionService would stamp as created_by_id and fail the FK.
        $request->session()->forget(Auth::guard()->getName());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => [
                'nullable', 'required_without:email', 'string', 'max:30',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! PhoneNumber::looksLikePhone($value)) {
                        $fail(__('Enter a valid phone number.'));
                    } elseif ($this->phoneTaken(PhoneNumber::normalize($value))) {
                        $fail(__('This phone number is already registered. Please sign in instead.'));
                    }
                },
            ],
            'email' => ['nullable', 'required_without:phone_number', 'string', 'email', 'max:255', 'unique:users,email'],
            'company_name' => ['nullable', 'string', 'max:255'],
            // Deliberately no strength rule (product decision): landlords may use any
            // password, even their phone number. Brute force is capped by throttle:login.
            'password' => ['required', 'string', 'max:255', 'confirmed'],
            // Honeypot: hidden from people, filled in by bots.
            'website' => ['nullable', 'max:0'],
        ], [
            'email.unique' => __('This email is already registered. Please sign in instead.'),
            'phone_number.required_without' => __('Enter a phone number or an email.'),
            'email.required_without' => __('Enter a phone number or an email.'),
        ]);

        try {
            $user = app(LandlordRegistrationService::class)->register($data);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['register' => $e->getMessage()]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('filament.landlord.pages.setup');
    }

    /** phone_number isn't normalized in older rows, so compare in normalized form. */
    protected function phoneTaken(string $phone): bool
    {
        return PhoneNumber::matchingUsers(User::query(), $phone)->isNotEmpty();
    }
}
