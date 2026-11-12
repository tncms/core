<?php

namespace App\Filament\Admin\Auth;

use Filament\Auth\Pages\Login as FilamentLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * Admin sign-in page (CORE-H-Z1-REMEDIATION-R3, ER-8, WCAG 1.3.5).
 *
 * Filament renders the e-mail field with autocomplete="on", which names no input purpose. In this
 * form the e-mail address is the account identifier, so it is marked "username" (the HTML token
 * for a sign-in identifier, which browsers and password managers pair with the password field's
 * "current-password"). Nothing else about the page or the authentication flow changes.
 */
class Login extends FilamentLogin
{
    protected function getEmailFormComponent(): Component
    {
        $component = parent::getEmailFormComponent();

        return $component instanceof TextInput
            ? $component->autocomplete('username')
            : $component;
    }
}
