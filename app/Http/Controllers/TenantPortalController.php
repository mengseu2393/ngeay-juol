<?php

namespace App\Http\Controllers;

use App\Enums\UserStatus;
use App\Models\Invoice;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-facing portal. Room accounts log in here by USERNAME (not email, and not
 * the Filament admin panel which blocks the tenant role). Read-only invoice view.
 */
class TenantPortalController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('portal.dashboard');
        }

        return view('portal.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $credentials = [
            'username' => $data['username'],
            'password' => $data['password'],
            'status' => UserStatus::Active->value,
        ];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'username' => __('Invalid username or password.'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('portal.dashboard'));
    }

    public function dashboard()
    {
        if (! Auth::check()) {
            return redirect()->route('portal.login');
        }

        $user = Auth::user();

        $unit = Unit::withoutGlobalScopes()
            ->with('property')
            ->where('account_user_id', $user->getKey())
            ->first();

        $invoices = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $user->getKey())
            ->orderByDesc('issue_date')
            ->get();

        return view('portal.dashboard', compact('user', 'unit', 'invoices'));
    }

    public function invoice(Invoice $invoice)
    {
        if (! Auth::check()) {
            return redirect()->route('portal.login');
        }

        // A tenant may only view their own room's invoices.
        abort_unless((int) $invoice->tenant_id === (int) Auth::id(), 403);

        $invoice->load(['lines', 'payments.recordedBy', 'rental.unit.property']);

        return view('portal.invoice', compact('invoice'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
