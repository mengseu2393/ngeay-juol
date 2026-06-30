<?php

use App\Http\Controllers\InvoiceDocumentController;
use App\Http\Controllers\TenantPortalController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ---------------------------------------------------------------------------
// Invoice documents — PDF (A4 / A5 / thermal receipt) + Excel export. Behind
// 'auth'; the LandlordScope on Invoice scopes the binding so cross-landlord
// access 404s. The /pdf|/excel suffix doesn't collide with Filament's
// /landlord/invoices/{record}. Lives under /landlord (landlords' panel) now that
// landlords no longer use /admin; the route names are unchanged so callers stay put.
// ---------------------------------------------------------------------------
// SetLocale makes the documents render in the user's chosen language (Khmer when
// selected) — it otherwise only runs inside the Filament panel, not on web routes.
Route::middleware(['auth', \App\Http\Middleware\SetLocale::class])->group(function () {
    Route::get('landlord/invoices/{invoice}/pdf', [InvoiceDocumentController::class, 'pdf'])->name('invoices.pdf');
    Route::get('landlord/invoices/{invoice}/excel', [InvoiceDocumentController::class, 'excel'])->name('invoices.excel');
});

// ---------------------------------------------------------------------------
// Tenant portal — username login, read-only invoice view. Guarded inline so it
// never collides with the Filament admin auth (which uses email + blocks tenants).
// ---------------------------------------------------------------------------
Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('login', [TenantPortalController::class, 'showLogin'])->name('login');
    Route::post('login', [TenantPortalController::class, 'login'])->middleware('throttle:6,1')->name('login.attempt');
    // Guarded inside the controller (redirects guests to portal.login).
    Route::get('/', [TenantPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('invoices/{invoice}', [TenantPortalController::class, 'invoice'])->name('invoice');
    Route::post('logout', [TenantPortalController::class, 'logout'])->name('logout');
});

Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, config('app.supported_locales', ['en']), true)) {
        session(['locale' => $locale]);
    }

    return redirect()->back();
})->name('locale.switch');
