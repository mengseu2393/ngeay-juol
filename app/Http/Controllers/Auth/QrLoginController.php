<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RedirectsAuthenticatedUsers;
use App\Http\Controllers\Controller;
use App\Services\QrLoginTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Redeems a QR login link minted by the admin QR Code Generator page.
 *
 * Every failure mode — unknown, tampered, expired, already-scanned token, or an
 * inactive account — funnels into the same generic error on the login screen, so
 * the endpoint leaks nothing about which tokens or accounts exist.
 */
class QrLoginController extends Controller
{
    use RedirectsAuthenticatedUsers;

    public function redeem(Request $request, string $token)
    {
        $user = app(QrLoginTokenService::class)->redeem($token);

        if ($user === null) {
            return redirect()->route('login')->withErrors([
                'login' => __('This login QR code is no longer valid. Please ask for a new one.'),
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return $this->redirectUser($user);
    }
}
