<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\QrLoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ClientErrorController;
use App\Http\Controllers\InvoiceDocumentController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\TenantPortalController;
use App\Http\Controllers\UtilityExportController;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureMobileShellAvailable;
use App\Http\Middleware\IdleTimeout;
use App\Http\Middleware\RedirectToLandlordSetup;
use App\Http\Middleware\SetLocale;
use App\Livewire\Mobile;
use App\Livewire\Mobile\Invoices as MobileInvoices;
use App\Providers\Filament\LandlordPanelProvider;
use App\Support\SimpleLandlordMode;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('info', 'info')->name('info');

// The web app manifest follows the mobile shell's flag (start_url, shortcuts).
Route::get('manifest.json', ManifestController::class)->name('manifest');

Route::get('login', [LoginController::class, 'showLogin'])->name('login');
// throttle:login is the limiter defined in FortifyServiceProvider (5/min keyed by
// username + IP). This app authenticates through its own LoginController rather
// than Fortify's, so the limiter has to be attached here — Fortify only wires it
// onto the route it registers itself.
Route::post('login', [LoginController::class, 'login'])->middleware('throttle:login');
Route::post('logout', [LoginController::class, 'logout'])->name('logout');

// Landlord self-registration (step 1 of onboarding; the rest is /app/setup).
// Not Fortify's registration feature — that stays off (see config/fortify.php).
Route::get('register', [RegisterController::class, 'show'])->name('register');
Route::post('register', [RegisterController::class, 'register'])->middleware('throttle:register');

// ---------------------------------------------------------------------------
// QR quick-login redemption. The QR image carries a single-use, 15-minute,
// signed token — never a credential. 'signed' rejects tampered links before any
// DB work; the limiter caps token guessing; the controller fails closed on
// unknown/expired/used tokens and inactive accounts.
// ---------------------------------------------------------------------------
Route::get('qr-login/{token}', [QrLoginController::class, 'redeem'])
    ->middleware(['signed', 'throttle:qr-login'])
    ->name('qr-login.redeem');

// ---------------------------------------------------------------------------
// Mobile shell (/m) — Simple Mode rebuilt as a standalone phone app, outside
// Filament (resources/views/mobile, App\Livewire\Mobile). Behind
// config/mobile.php's flag: a 404 for anyone it doesn't let in. The rest is the
// landlord panel's own sign-in stack (session check, idle rule, subscription,
// setup wizard), since there's no panel here to supply it.
// ---------------------------------------------------------------------------
Route::prefix('m')->name('mobile.')->middleware([
    'auth',
    EnsureMobileShellAvailable::class,
    AuthenticateSession::class,
    IdleTimeout::class,
    EnsureActiveSubscription::class,
    RedirectToLandlordSetup::class,
])->group(function () {
    Route::get('/', MobileInvoices::class)->name('invoices');
    Route::get('billing', Mobile\Billing::class)->name('billing');

    Route::get('rooms', Mobile\Rooms::class)->name('rooms');
    Route::get('rooms/{unit}', Mobile\Room::class)->name('room');
    Route::get('rooms/{unit}/edit', Mobile\EditRoom::class)->name('room.edit');
    Route::get('rooms/{unit}/tenant', Mobile\AddTenant::class)->name('room.add-tenant');
    Route::get('rooms/{unit}/end', Mobile\EndTenancy::class)->name('room.end-tenancy');

    Route::get('tenants', Mobile\Tenants::class)->name('tenants');
    Route::get('tenants/{rental}', Mobile\Tenant::class)->name('tenant');

    Route::get('readings', Mobile\Readings::class)->name('readings');

    Route::get('settings', Mobile\Settings::class)->name('settings');
    Route::get('settings/utilities', Mobile\UtilityRates::class)->name('utility-rates');
    Route::get('settings/profile', Mobile\Profile::class)->name('profile');
    Route::get('settings/property', Mobile\PropertySettings::class)->name('property-settings');
    Route::get('settings/property/new', Mobile\AddProperty::class)->name('add-property');
});

// ---------------------------------------------------------------------------
// Invoice documents — PDF (A4 / A5 / thermal receipt) + Excel export. Behind
// 'auth'; the LandlordScope on Invoice scopes the binding so cross-landlord
// access 404s. The /pdf|/excel suffix doesn't collide with Filament's
// /app/invoices/{record}. Lives under the landlord panel prefix (/app) now that
// landlords no longer use /admin; the route names are unchanged so callers stay put.
// ---------------------------------------------------------------------------
// SetLocale makes the documents render in the user's chosen language (Khmer when
// selected) — it otherwise only runs inside the Filament panel, not on web routes.
Route::middleware(['auth', SetLocale::class])->group(function () {
    // Batch "print all" — registered before the {invoice} routes so 'batch'
    // never hits the model binding.
    Route::get(LandlordPanelProvider::PATH.'/invoices/batch/pdf', [InvoiceDocumentController::class, 'batchPdf'])->name('invoices.batch-pdf');
    Route::get(LandlordPanelProvider::PATH.'/invoices/{invoice}/pdf', [InvoiceDocumentController::class, 'pdf'])->name('invoices.pdf');
    Route::get(LandlordPanelProvider::PATH.'/invoices/{invoice}/excel', [InvoiceDocumentController::class, 'excel'])->name('invoices.excel');
    Route::get(LandlordPanelProvider::PATH.'/invoices/{invoice}/view', [InvoiceDocumentController::class, 'view'])->name('invoices.view');
    // Bare invoice-slip HTML fragment (no layout) — fetched and cached client-side
    // by Simple Mode's "View details" popup so a tap needs no server round-trip.
    Route::get(LandlordPanelProvider::PATH.'/invoices/{invoice}/slip', [InvoiceDocumentController::class, 'slip'])->name('invoices.slip');

    // Payment receipt — the slip a landlord hands over after taking cash. Payment
    // has no landlord_id and therefore no LandlordScope on the binding, so unlike
    // the invoice routes above this one is guarded entirely inside the controller
    // (through payment.invoice.landlord_id). The /receipt suffix under a segment
    // Filament doesn't register keeps it clear of the panel's own routing.
    Route::get(LandlordPanelProvider::PATH.'/payments/{payment}/receipt', [PaymentReceiptController::class, 'pdf'])->name('payments.receipt');

    // Browser-side failures (JS errors, failed Livewire requests) → Error Log.
    // 30/min per user: one broken page can fire the same error in a loop.
    Route::post('client-errors', [ClientErrorController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('client-errors.store');

    Route::post('api/properties/{property_id}/utility-usages/export', [UtilityExportController::class, 'export'])->name('exports.utility-usages');
    Route::get('api/exports/{file_id}/download', [UtilityExportController::class, 'download'])->name('exports.download');

    Route::post(LandlordPanelProvider::PATH.'/simple-mode/toggle', function (Request $request) {
        $user = $request->user();

        abort_unless(SimpleLandlordMode::canUse($user), 403);
        abort_if(SimpleLandlordMode::isLocked($request), 403);

        $enabled = ! SimpleLandlordMode::enabledFor($user);

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
Route::prefix('portal')->name('portal.')->middleware([SetLocale::class])->group(function () {
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

// ---------------------------------------------------------------------------
// Legacy /landlord/* → /app/* (301). The panel moved off the role-named prefix;
// this keeps existing bookmarks, installed PWA shortcuts and any cached service
// worker entries working. Registered last so it can never shadow a real route.
// ---------------------------------------------------------------------------
Route::any('landlord/{path?}', function (?string $path = null) {
    $target = '/'.LandlordPanelProvider::PATH.($path !== null && $path !== '' ? '/'.$path : '');

    return redirect()->to($target.(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301);
})->where('path', '.*')->name('landlord.legacy-redirect');
