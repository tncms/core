<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use TheNguyen\CMS\Mail\MailConfigurationBridge;
use TheNguyen\CMS\Mail\MailConnectionTester;
use TheNguyen\CMS\Mail\MailSettingsRepository;
use TheNguyen\CMS\Mail\MailSettingsValidator;

/**
 * System Settings → Mail (Core Mail Platform · CORE-MAIL-1 · ADR-CORE-MAIL-001/002/003).
 *
 * A thin admin surface over the Core Mail Platform. It reads/writes ONLY through
 * {@see MailSettingsRepository} (which persists via the canonical SettingsManager/cms_settings
 * and encrypts the SMTP password), validates structurally via {@see MailSettingsValidator},
 * re-applies the config through {@see MailConfigurationBridge} on save, and runs diagnostics
 * via {@see MailConnectionTester}. The page holds no configuration, owns no secret, and never
 * displays the stored password — a blank password on save keeps the existing secret.
 */
class MailSettingsPage extends Page
{
    protected static ?string $slug = 'mail-settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|\UnitEnum|null $navigationGroup = 'CMS';

    protected string $view = 'filament.admin.pages.mail-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return cms_can('settings.manage');
    }

    public static function getNavigationLabel(): string
    {
        return tn_trans('Mail');
    }

    public function getTitle(): string
    {
        return tn_trans('Mail Settings');
    }

    public function mount(): void
    {
        $this->form->fill($this->repository()->formState());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(tn_trans('General'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('enabled')
                            ->label(tn_trans('Use these mail settings'))
                            ->helperText(tn_trans('When off, the CMS uses the framework mail configuration (.env).')),
                        Select::make('mailer')
                            ->label(tn_trans('Mailer'))
                            ->options(array_combine(MailSettingsValidator::ALLOWED_MAILERS, MailSettingsValidator::ALLOWED_MAILERS))
                            ->native(false)
                            ->required(),
                    ]),
                Section::make(tn_trans('SMTP'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('host')->label(tn_trans('Host')),
                        TextInput::make('port')->label(tn_trans('Port'))->numeric(),
                        TextInput::make('username')->label(tn_trans('Username'))->autocomplete(false),
                        TextInput::make('password')
                            ->label(tn_trans('Password'))
                            ->password()
                            ->revealable(false)
                            ->autocomplete('new-password')
                            ->placeholder(fn (): string => $this->repository()->hasPassword() ? tn_trans('•••••••• (unchanged)') : '')
                            ->helperText(tn_trans('Leave blank to keep the stored password.')),
                        Toggle::make('clear_password')
                            ->label(tn_trans('Remove the stored password')),
                        Select::make('encryption')
                            ->label(tn_trans('Encryption'))
                            ->options(['' => tn_trans('None'), 'tls' => 'TLS', 'ssl' => 'SSL'])
                            ->native(false),
                        TextInput::make('timeout')->label(tn_trans('Timeout (seconds)'))->numeric(),
                    ]),
                Section::make(tn_trans('Identity'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('from_address')->label(tn_trans('From email'))->email()->required(),
                        TextInput::make('from_name')->label(tn_trans('From name')),
                        TextInput::make('reply_to_address')->label(tn_trans('Reply-to email'))->email(),
                        TextInput::make('reply_to_name')->label(tn_trans('Reply-to name')),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $errors = app(MailSettingsValidator::class)->validate($state);
        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                $this->addError('data.' . $field, $message);
            }

            Notification::make()->title(tn_trans('Please fix the highlighted fields.'))->danger()->send();

            return;
        }

        $this->repository()->save($state);
        $this->repository()->recordValidation();

        // Re-apply persisted settings into Laravel Mail immediately for this process.
        app(MailConfigurationBridge::class)->apply();

        // Reset the transient password field so the secret is never left in component state.
        $this->form->fill($this->repository()->formState());

        Notification::make()->title(tn_trans('Mail settings saved.'))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label(tn_trans('Test connection'))
                ->icon('heroicon-o-signal')
                ->action(function (): void {
                    app(MailConfigurationBridge::class)->apply();
                    $result = app(MailConnectionTester::class)->testConnection();

                    Notification::make()
                        ->title($result->summary)
                        ->status($result->ok ? 'success' : 'danger')
                        ->send();
                }),
            Action::make('sendTest')
                ->label(tn_trans('Send test email'))
                ->icon('heroicon-o-paper-airplane')
                ->form([
                    TextInput::make('to')
                        ->label(tn_trans('Recipient'))
                        ->email()
                        ->required()
                        ->default((string) settings()->get('general.admin_email', '')),
                ])
                ->action(function (array $data): void {
                    app(MailConfigurationBridge::class)->apply();
                    $result = app(MailConnectionTester::class)->sendTest((string) $data['to']);

                    Notification::make()
                        ->title($result->summary)
                        ->status($result->ok ? 'success' : 'danger')
                        ->send();
                }),
        ];
    }

    private function repository(): MailSettingsRepository
    {
        return app(MailSettingsRepository::class);
    }
}
