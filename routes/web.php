<?php

use App\Http\Controllers\InvoiceDocumentController;
use App\Http\Controllers\TenantPortalController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\LoginController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('login', [LoginController::class, 'showLogin'])->name('login');
Route::post('login', [LoginController::class, 'login']);
Route::post('logout', [LoginController::class, 'logout'])->name('logout');

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
    Route::get('landlord/invoices/{invoice}/view', [InvoiceDocumentController::class, 'view'])->name('invoices.view');

    Route::post('landlord/simple-mode/toggle', function (\Illuminate\Http\Request $request) {
        $user = $request->user();

        abort_unless(\App\Support\SimpleLandlordMode::canUse($user), 403);

        $enabled = ! \App\Support\SimpleLandlordMode::enabledFor($user);

        $user->forceFill([
            'prefers_simple_landlord_mode' => $enabled,
        ])->save();

        return redirect()->route(
            $enabled
                ? 'filament.landlord.pages.simple'
                : 'filament.landlord.pages.dashboard',
        );
    })->name('landlord.simple-mode.toggle');
});

// ---------------------------------------------------------------------------
// Tenant portal — read-only invoice view. Guarded inline so it
// never collides with the Filament admin auth (which uses email + blocks tenants).
// ---------------------------------------------------------------------------
Route::prefix('portal')->name('portal.')->middleware([\App\Http\Middleware\SetLocale::class])->group(function () {
    // Guarded inside the controller (redirects guests to login).
    Route::get('/', [TenantPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('invoices/{invoice}', [TenantPortalController::class, 'invoice'])->name('invoice');
    Route::get('maintenance', [TenantPortalController::class, 'maintenanceIndex'])->name('maintenance.index');
    Route::get('maintenance/create', [TenantPortalController::class, 'maintenanceCreate'])->name('maintenance.create');
    Route::post('maintenance', [TenantPortalController::class, 'maintenanceStore'])->name('maintenance.store');
    Route::get('maintenance/{maintenanceRequest}', [TenantPortalController::class, 'maintenanceShow'])->name('maintenance.show');
    Route::post('maintenance/{maintenanceRequest}/replies', [TenantPortalController::class, 'maintenanceReply'])->name('maintenance.reply');
    Route::post('logout', [TenantPortalController::class, 'logout'])->name('logout');
});

Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, config('app.supported_locales', ['en']), true)) {
        session(['locale' => $locale]);
        cookie()->queue('locale', $locale, 60 * 24 * 365); // 1 year
    }

    return redirect()->back();
})->name('locale.switch');
