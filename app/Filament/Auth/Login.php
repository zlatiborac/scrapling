<?php

namespace App\Filament\Auth;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

class Login extends \Filament\Auth\Pages\Login
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Username')
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'username' => $data['email'],
            'password' => $data['password'],
        ];
    }
}
