<?php

namespace App\Filament\Pages;

use App\Enums\UserStatus;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Admin-only page to generate QR codes for landlord quick-login.
 *
 * The admin selects a landlord, enters the plaintext password, and a QR
 * code is rendered client-side. When scanned, the QR opens the login
 * page with the email/username and password pre-filled.
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
                    ->description(__('Select a landlord and provide their password. The generated QR code will open the login page with credentials pre-filled.'))
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
                                        $u->id => $u->name . ($u->email ? " ({$u->email})" : ($u->username ? " ({$u->username})" : '')),
                                    ]);
                            })
                            ->required()
                            ->helperText(__('Choose the landlord account to generate a QR code for.'))
                            ->live(),

                        Forms\Components\TextInput::make('password')
                            ->label(__('Password'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->helperText(__('Enter the landlord\'s current plaintext password. This is needed because stored passwords cannot be reversed.')),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    /**
     * Generate the QR code URL from the selected landlord and password.
     */
    public function generate(): void
    {
        $state = $this->form->getState();

        $landlord = User::findOrFail($state['landlord_id']);

        // Determine which login identifier to use (email first, then username).
        $loginValue = $landlord->email ?: $landlord->username;

        if (! $loginValue) {
            \Filament\Notifications\Notification::make()
                ->danger()
                ->title(__('This landlord has no email or username configured.'))
                ->send();

            return;
        }

        $password = $state['password'];

        // Build the login URL with pre-fill query parameters.
        $this->qrUrl = url('/login') . '?' . http_build_query([
            'qr_login' => $loginValue,
            'qr_password' => $password,
        ]);

        $this->landlordName = $landlord->name;
        $this->landlordLogin = $loginValue;

        \Filament\Notifications\Notification::make()
            ->success()
            ->title(__('QR code generated successfully'))
            ->send();
    }
}
