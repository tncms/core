<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Admin\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use TheNguyen\CMS\Services\TrustedExternalScriptRegistry;
use TheNguyen\CMS\Services\TrustedScriptApprovalStore;
use TheNguyen\CMS\Support\TrustedScripts\TrustedScriptDeclaration;

/**
 * Plugins → Trusted Scripts (CORE-EXTENSION-TRUST S1, ADR D-2).
 *
 * Lists every `trusted_scripts` declaration of the ACTIVE plugins with the exact
 * URL, the normalised CSP origins it needs, its purpose and fingerprint, and the
 * approval state. An administrator holding `trusted_scripts.manage` (checked
 * strictly — never fail-open) approves or revokes each one explicitly.
 *
 * Approval is bound to the fingerprint shown on screen: if the manifest changed
 * after the page was rendered, the approve action refuses and asks for a fresh
 * review. Every change is written with an audit event by the
 * {@see TrustedScriptApprovalStore}. Core-owned, registered by
 * {@see \TheNguyen\CMS\Filament\PluginResourceRegistrar}.
 */
class TrustedScriptsPage extends Page implements HasTable
{
    use InteractsWithTable;

    public const PERMISSION = 'trusted_scripts.manage';

    /**
     * URLs, origins and slugs contain no spaces, so a table cell cannot shrink
     * below their full length and pushes the action column off-screen
     * (S1-R1 Gate C). Letting these cells break anywhere inside a long token
     * keeps the table within its container. Inline because the admin theme's
     * Tailwind build does not scan Core package views; scoped to these cells.
     */
    private const BREAK_LONG_TOKENS = ['style' => 'overflow-wrap:anywhere;word-break:break-word;min-width:8rem'];

    protected static ?string $slug = 'plugins/trusted-scripts';

    protected static string|\UnitEnum|null $navigationGroup = 'Plugins';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 15;

    protected string $view = 'cms::filament.pages.trusted-scripts-page';

    public static function getNavigationLabel(): string
    {
        return tn_trans('Trusted Scripts');
    }

    public function getTitle(): string
    {
        return tn_trans('Trusted Scripts');
    }

    public function getSubheading(): ?string
    {
        return tn_trans('Review and approve third-party scripts declared by active plugins.');
    }

    public static function canAccess(): bool
    {
        return self::authorized();
    }

    public static function authorized(): bool
    {
        return app('cms.permission')->userCanStrict(auth()->user(), self::PERMISSION);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => collect($this->rows())->keyBy('id'))
            ->columns([
                // Key and short fingerprint under the plugin name keep the row
                // narrow enough that status + actions stay visible at 1440px.
                TextColumn::make('owner')
                    ->label(tn_trans('Plugin'))
                    ->weight(FontWeight::Bold)
                    ->wrap()
                    ->extraCellAttributes(self::BREAK_LONG_TOKENS)
                    ->description(fn (array $record): string => $record['key'].' · '.substr($record['fingerprint'], 0, 12))
                    ->tooltip(fn (array $record): string => tn_trans('Fingerprint').': '.$record['fingerprint']),

                TextColumn::make('url')
                    ->label(tn_trans('Script URL'))
                    ->wrap()
                    ->extraCellAttributes(self::BREAK_LONG_TOKENS)
                    ->copyable()
                    ->description(fn (array $record): string => $record['purpose']),

                TextColumn::make('csp')
                    ->label(tn_trans('Required CSP origins'))
                    ->listWithLineBreaks()
                    ->wrap()
                    ->extraCellAttributes(self::BREAK_LONG_TOKENS)
                    ->state(fn (array $record): array => $record['csp']),

                TextColumn::make('state')
                    ->label(tn_trans('Status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        TrustedScriptApprovalStore::STATE_APPROVED => 'success',
                        TrustedScriptApprovalStore::STATE_CHANGED => 'warning',
                        TrustedScriptApprovalStore::STATE_REVOKED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        TrustedScriptApprovalStore::STATE_APPROVED => tn_trans('Approved'),
                        TrustedScriptApprovalStore::STATE_CHANGED => tn_trans('Re-approval required'),
                        TrustedScriptApprovalStore::STATE_REVOKED => tn_trans('Revoked'),
                        default => tn_trans('Awaiting approval'),
                    }),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(tn_trans('Approve'))
                    ->icon('heroicon-m-check-badge')
                    ->color('primary')
                    ->link()
                    ->visible(fn (array $record): bool => $record['state'] !== TrustedScriptApprovalStore::STATE_APPROVED && self::authorized())
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalHeading(tn_trans('Approve third-party script?'))
                    ->modalDescription(fn (array $record): string => tn_trans('This allows the plugin :plugin to load JavaScript from :url on your public pages. Third-party JavaScript runs with full access to those pages and their visitors. Approve only a script you trust.', [
                        'plugin' => $record['owner'],
                        'url' => $record['url'],
                    ]))
                    ->modalSubmitActionLabel(tn_trans('Approve script'))
                    ->action(fn (array $record) => $this->approveRecord($record)),

                Action::make('revoke')
                    ->label(tn_trans('Revoke'))
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->link()
                    ->visible(fn (array $record): bool => $record['state'] === TrustedScriptApprovalStore::STATE_APPROVED && self::authorized())
                    ->requiresConfirmation()
                    ->modalHeading(tn_trans('Revoke trusted script?'))
                    ->modalDescription(tn_trans('The script stops loading on the next request.'))
                    ->modalSubmitActionLabel(tn_trans('Revoke script'))
                    ->action(fn (array $record) => $this->revokeRecord($record)),
            ])
            ->paginated(false)
            ->stackedOnMobile()
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading(tn_trans('No trusted scripts declared.'))
            ->emptyStateDescription(tn_trans('Active plugins have not declared any third-party scripts.'));
    }

    /**
     * Table rows, one per valid declaration of an active plugin.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $approvals = $this->approvals();

        return array_map(function (TrustedScriptDeclaration $declaration) use ($approvals): array {
            $origins = [];

            foreach ($declaration->csp as $directive => $sources) {
                foreach ($sources as $source) {
                    $origins[] = $directive.' '.$source;
                }
            }

            return [
                // The fingerprint is part of the record key: an action submitted
                // for a declaration that has since changed finds no record.
                'id' => $declaration->owner.'/'.$declaration->key.'@'.$declaration->fingerprint,
                'owner' => $declaration->owner,
                'key' => $declaration->key,
                'url' => $declaration->url,
                'purpose' => $declaration->purpose,
                'csp' => $origins,
                'fingerprint' => $declaration->fingerprint,
                'state' => $approvals->state($declaration),
            ];
        }, $this->registry()->declarations());
    }

    /**
     * Declarations rejected by the manifest parser (shown below the table).
     *
     * @return list<array{owner: string, key: ?string, reason: string}>
     */
    public function invalidDeclarations(): array
    {
        return $this->registry()->invalidDeclarations();
    }

    /** @param  array<string, mixed>  $record */
    public function approveRecord(array $record): void
    {
        abort_unless(self::authorized(), 403);

        $declaration = $this->registry()->declaration((string) $record['owner'], (string) $record['key']);

        // Bind approval to what the administrator reviewed: refuse if the
        // declaration changed (or disappeared) since this page was rendered.
        if ($declaration === null || ! hash_equals((string) $record['fingerprint'], $declaration->fingerprint)) {
            Notification::make()
                ->title(tn_trans('The declaration changed. Review it again before approving.'))
                ->warning()
                ->send();

            return;
        }

        $this->approvals()->approve($declaration, $this->actorId());
        // The table resolved (and cached) its records to find this row before the
        // action ran; drop them so this render shows the new state.
        $this->flushCachedTableRecords();

        Notification::make()
            ->title(tn_trans('Script approved.'))
            ->success()
            ->send();
    }

    /** @param  array<string, mixed>  $record */
    public function revokeRecord(array $record): void
    {
        abort_unless(self::authorized(), 403);

        $this->approvals()->revoke((string) $record['owner'], (string) $record['key'], $this->actorId());
        $this->flushCachedTableRecords();

        Notification::make()
            ->title(tn_trans('Script approval revoked.'))
            ->success()
            ->send();
    }

    private function actorId(): ?int
    {
        $id = auth()->id();

        return is_numeric($id) ? (int) $id : null;
    }

    private function registry(): TrustedExternalScriptRegistry
    {
        return app('cms.trusted_scripts');
    }

    private function approvals(): TrustedScriptApprovalStore
    {
        return app('cms.trusted_script_approvals');
    }
}
