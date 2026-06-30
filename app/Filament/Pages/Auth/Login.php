<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Component;
use Filament\Pages\Auth\Login as BaseLogin;

class Login extends BaseLogin
{
    protected function getRememberFormComponent(): Component
    {
        return Checkbox::make('remember')
            ->label(__('filament-panels::pages/auth/login.form.remember.label'))
            ->default(true);
    }
}
