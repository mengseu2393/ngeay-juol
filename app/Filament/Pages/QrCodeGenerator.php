<?php

namespace App\Filament\Pages;

use App\Enums\UserStatus;
use App\Models\QrLoginToken;
use App\Models\User;
use App\Services\QrLoginTokenService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Admin-only page to generate QR codes for landlord quick-login.
 *
 * The admin picks a landlord and a single-use, 15-minute login link is minted.
 * The QR encodes an opaque signed token — never a credential — so generating a
 * code no longer requires (or exposes) the landlord's password, and a
 * photographed code is dead the moment it is scanned once.
 */
class QrCodeGenerator extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.qr-code-generator';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** The generated login URL (passed to the Blade view for QR rendering). */
    public string $qrUrl = '';

    /** The landlord name shown beneath the QR code. */
    public string $landlordName = '';

    /** The login identifier shown beneath the QR code. */
    public string $landlordLogin = '';

    /** Human-readable expiry of the generated link, shown beneath the QR code. */
    public string $expiresAt = '';

    public static function getNavigationLabel(): string
    {
        return __('QR Code Login');
    }

    public function getTitle(): string
    {
        return __('QR Code Generator');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Generate Login QR Code'))
                    ->description(__('Select a landlord. The generated QR code is a single-use login link that expires in :minutes minutes — no password required.', ['minutes' => QrLoginToken::LIFETIME_MINUTES]))
                    ->icon('heroicon-o-qr-code')
                    ->schema([
                        Forms\Components\Select::make('landlord_id')
                            ->label(__('Landlord'))
                            ->searchable()
                            ->preload()
                            ->options(function () {
                                return User::query()
                                    ->whereHas('roles', fn ($q) => $q->where('name', 'landlord'))
                                    ->where('status', UserStatus::Active)
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (User $u) => [
                                        $u->id => $u->name.($u->email ? " ({$u->email})" : ($u->username ? " ({$u->username})" : '')),
                                    ]);
                            })
                            ->required()
                            ->helperText(__('Choose the landlord account to generate a QR code for.'))
                            ->live(),
                    ])->columns(1),
            ])
            ->statePath('data');
    }

    /**
     * Mint a single-use login token for the selected landlord and expose its
     * signed URL to the view for QR rendering.
     */
    public function generate(): void
    {
        $state = $this->form->getState();

        $landlord = User::findOrFail($state['landlord_id']);

        // Determine which login identifier to display (email first, then username).
        $loginValue = $landlord->email ?: $landlord->username;

        if ($landlord->status !== UserStatus::Active) {
            Notification::make()
                ->danger()
                ->title(__('This account is not active, so it cannot be signed in.'))
                ->send();

            return;
        }

        $issued = app(QrLoginTokenService::class)->issue($landlord, auth()->user());

        $this->qrUrl = $issued['url'];
        $this->landlordName = $landlord->name;
        $this->landlordLogin = (string) $loginValue;
        $this->expiresAt = $issued['token']->expires_at->timezone(config('app.timezone'))->format('Y-m-d H:i');

        Notification::make()
            ->success()
            ->title(__('QR code generated successfully'))
            ->body(__('This link works once and expires in :minutes minutes.', ['minutes' => QrLoginToken::LIFETIME_MINUTES]))
            ->send();
    }
}
