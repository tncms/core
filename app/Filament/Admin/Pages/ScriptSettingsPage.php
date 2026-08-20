<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use TheNguyen\CMS\Services\ScriptSettingsRegistrar;
use TheNguyen\CMS\Support\Scripts\ScriptAsset;

/**
 * Settings → Scripts (v1.0.0-beta.7.1.13.2).
 *
 * Admin UI for the existing Global Script Manager. Stores configuration in
 * cms_settings under the scripts.* keys; nothing here executes — every value is
 * validated by ScriptManager at render time (see ScriptSettingsRegistrar). No
 * raw PHP, no callbacks, no bypass of ScriptManager validation.
 */
class ScriptSettingsPage extends Page
{
    protected static ?string $slug = 'settings/scripts';

    protected static string|\UnitEnum|null $navigationGroup = 'CMS';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-code-bracket';

    protected static ?int $navigationSort = 21;

    protected string $view = 'filament.admin.pages.script-settings-page';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return cms_can('settings.manage');
    }

    public static function getNavigationLabel(): string
    {
        return tn_trans('Scripts');
    }

    public function getTitle(): string
    {
        return tn_trans('Script Settings');
    }

    public function mount(): void
    {
        $s = app('cms.settings');

        $verifications = is_array($v = $s->get('scripts.verifications', [])) ? $v : [];

        $values = ['enabled' => (bool) $s->get('scripts.enabled', true)];

        foreach (ScriptSettingsRegistrar::VERIFICATION_PROVIDERS as $provider) {
            $values['verify_'.$provider] = (string) ($verifications[$provider] ?? '');
        }

        $values['json_ld'] = $this->rows($s->get('scripts.json_ld', []));
        $values['head_inline'] = $this->rows($s->get('scripts.head_inline', []));
        $values['footer_inline'] = $this->rows($s->get('scripts.footer_inline', []));
        $values['embeds'] = $this->rows($s->get('scripts.embeds', []));

        // The two external keys merge into one repeater carrying a position.
        $external = [];
        foreach ($this->rows($s->get('scripts.head_external', [])) as $row) {
            $external[] = ['position' => ScriptAsset::POSITION_HEAD] + $row;
        }
        foreach ($this->rows($s->get('scripts.footer_external', [])) as $row) {
            $external[] = ['position' => ScriptAsset::POSITION_FOOTER] + $row;
        }
        $values['external'] = $external;

        $this->data = $values;
        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('scripts')
                    ->columnSpanFull()
                    ->tabs([
                        $this->enableTab(),
                        $this->verificationTab(),
                        $this->jsonLdTab(),
                        $this->headScriptsTab(),
                        $this->footerScriptsTab(),
                        $this->externalScriptsTab(),
                        $this->embedsTab(),
                        $this->diagnosticsTab(),
                    ]),
            ])
            ->statePath('data');
    }

    private function enableTab(): Tab
    {
        return Tab::make('Enable')
            ->label(tn_trans('Enable'))
            ->icon('heroicon-o-power')
            ->schema([
                Toggle::make('enabled')
                    ->label(tn_trans('Enable settings-managed scripts'))
                    ->helperText(tn_trans('When off, none of the scripts configured on this page render. Scripts a plugin registers directly through the Script API are unaffected.')),
                Placeholder::make('scripts_diagnostics')
                    ->label(tn_trans('Diagnostics'))
                    ->content(fn (): HtmlString => $this->diagnosticsHtml()),
            ])
            ->columns(1);
    }

    private function verificationTab(): Tab
    {
        return Tab::make('Verification')
            ->label(tn_trans('Verification'))
            ->icon('heroicon-o-check-badge')
            ->schema([
                Placeholder::make('verify_note')
                    ->hiddenLabel()
                    ->content(new HtmlString('<div style="font-size:.8rem;opacity:.7;">'.e(tn_trans('Paste the verification value only (not the full meta tag). Any HTML is stripped.')).'</div>')),
                TextInput::make('verify_google')->label(tn_trans('Google'))->maxLength(255),
                TextInput::make('verify_bing')->label(tn_trans('Bing'))->maxLength(255),
                TextInput::make('verify_yandex')->label(tn_trans('Yandex'))->maxLength(255),
                TextInput::make('verify_facebook')->label(tn_trans('Facebook'))->maxLength(255),
                TextInput::make('verify_pinterest')->label(tn_trans('Pinterest'))->maxLength(255),
                TextInput::make('verify_baidu')->label(tn_trans('Baidu'))->maxLength(255),
            ])
            ->columns(1);
    }

    private function jsonLdTab(): Tab
    {
        return Tab::make('JSON-LD')
            ->label(tn_trans('JSON-LD'))
            ->icon('heroicon-o-document-text')
            ->schema([
                Repeater::make('json_ld')
                    ->label(tn_trans('JSON-LD blocks'))
                    ->schema([
                        TextInput::make('key')->label(tn_trans('Key'))->required()->maxLength(64),
                        Toggle::make('enabled')->label(tn_trans('Enabled'))->default(true),
                        Textarea::make('json')->label(tn_trans('JSON'))->rows(5)->helperText(tn_trans('Must be valid JSON. Invalid JSON is skipped and never rendered.')),
                    ])
                    ->columns(1)
                    ->addActionLabel(tn_trans('Add JSON-LD block'))
                    ->default([]),
            ])
            ->columns(1);
    }

    private function headScriptsTab(): Tab
    {
        return Tab::make('Head Scripts')
            ->label(tn_trans('Head Scripts'))
            ->icon('heroicon-o-code-bracket-square')
            ->schema([$this->inlineRepeater('head_inline', tn_trans('Head inline scripts'), tn_trans('Add head script'))])
            ->columns(1);
    }

    private function footerScriptsTab(): Tab
    {
        return Tab::make('Footer Scripts')
            ->label(tn_trans('Footer Scripts'))
            ->icon('heroicon-o-code-bracket-square')
            ->schema([$this->inlineRepeater('footer_inline', tn_trans('Footer inline scripts'), tn_trans('Add footer script'))])
            ->columns(1);
    }

    private function externalScriptsTab(): Tab
    {
        return Tab::make('External Scripts')
            ->label(tn_trans('External Scripts'))
            ->icon('heroicon-o-globe-alt')
            ->schema([
                Placeholder::make('external_note')
                    ->hiddenLabel()
                    ->content(new HtmlString('<div style="font-size:.8rem;opacity:.7;">'.e(tn_trans('Only URLs on the trusted host allowlist are rendered; untrusted URLs are skipped.')).'</div>')),
                Repeater::make('external')
                    ->label(tn_trans('External scripts'))
                    ->schema([
                        TextInput::make('key')->label(tn_trans('Key'))->required()->maxLength(64),
                        Toggle::make('enabled')->label(tn_trans('Enabled'))->default(true),
                        Select::make('position')
                            ->label(tn_trans('Position'))
                            ->options([ScriptAsset::POSITION_HEAD => tn_trans('Head'), ScriptAsset::POSITION_FOOTER => tn_trans('Footer')])
                            ->default(ScriptAsset::POSITION_HEAD)
                            ->native(false),
                        TextInput::make('priority')->label(tn_trans('Priority'))->numeric()->default(10),
                        TextInput::make('src')->label(tn_trans('Source URL'))->maxLength(2048),
                    ])
                    ->columns(2)
                    ->addActionLabel(tn_trans('Add external script'))
                    ->default([]),
            ])
            ->columns(1);
    }

    private function embedsTab(): Tab
    {
        return Tab::make('Trusted Embeds')
            ->label(tn_trans('Trusted Embeds'))
            ->icon('heroicon-o-window')
            ->schema([
                Placeholder::make('embed_note')
                    ->hiddenLabel()
                    ->content(new HtmlString('<div style="font-size:.8rem;opacity:.7;">'.e(tn_trans('Only iframe embeds from trusted hosts are allowed. Scripts, event handlers, and unsafe URLs are rejected.')).'</div>')),
                Repeater::make('embeds')
                    ->label(tn_trans('Trusted iframe embeds'))
                    ->schema([
                        TextInput::make('key')->label(tn_trans('Key'))->required()->maxLength(64),
                        Toggle::make('enabled')->label(tn_trans('Enabled'))->default(true),
                        Select::make('position')
                            ->label(tn_trans('Position'))
                            ->options([ScriptAsset::POSITION_HEAD => tn_trans('Head'), ScriptAsset::POSITION_FOOTER => tn_trans('Footer')])
                            ->default(ScriptAsset::POSITION_HEAD)
                            ->native(false),
                        TextInput::make('priority')->label(tn_trans('Priority'))->numeric()->default(10),
                        Textarea::make('html')->label(tn_trans('Iframe HTML'))->rows(3),
                    ])
                    ->columns(2)
                    ->addActionLabel(tn_trans('Add embed'))
                    ->default([]),
            ])
            ->columns(1);
    }

    /**
     * Read-only Script & Asset diagnostics (v1.0.0-beta.7.1.13.3). Passive,
     * metadata-only: registered/rendered/rejected counts, source/plugin/theme/
     * settings breakdowns, and short safe warnings. Never shows script contents,
     * URLs, JSON-LD, verification values, or embed HTML.
     */
    private function diagnosticsTab(): Tab
    {
        return Tab::make('Diagnostics')
            ->label(tn_trans('Diagnostics'))
            ->icon('heroicon-o-chart-bar')
            ->schema([
                Placeholder::make('script_diagnostics')
                    ->label(tn_trans('Script diagnostics'))
                    ->content(fn (): HtmlString => $this->scriptDiagnosticsHtml()),
                Placeholder::make('asset_diagnostics')
                    ->label(tn_trans('Asset diagnostics'))
                    ->content(fn (): HtmlString => $this->assetDiagnosticsHtml()),
            ])
            ->columns(1);
    }

    /** Render the settings-probe script diagnostics as a read-only panel. */
    private function scriptDiagnosticsHtml(): HtmlString
    {
        $snapshot = $this->registrar()->diagnostics()['snapshot'] ?? [];

        $html = '<div style="font-size:.85rem;line-height:1.6;">';
        $html .= $this->countsBlock([
            tn_trans('Registered') => (int) ($snapshot['registered'] ?? 0),
            tn_trans('Rendered') => (int) ($snapshot['rendered'] ?? 0),
            tn_trans('Rejected') => (int) ($snapshot['rejected'] ?? 0),
        ]);
        $html .= $this->breakdownBlock(tn_trans('Source breakdown'), $snapshot['sources'] ?? []);
        $html .= $this->breakdownBlock(tn_trans('Plugin breakdown'), $snapshot['plugins'] ?? []);
        $html .= $this->breakdownBlock(tn_trans('Theme breakdown'), $snapshot['themes'] ?? []);
        $html .= $this->breakdownBlock(tn_trans('Settings breakdown'), $snapshot['settings'] ?? []);
        $html .= $this->warningsBlock($snapshot['warnings'] ?? []);

        return new HtmlString($html.'</div>');
    }

    /** Render the live Asset Registry diagnostics as a read-only panel. */
    private function assetDiagnosticsHtml(): HtmlString
    {
        $snapshot = app('cms.assets')->diagnostics();
        $deps = $snapshot['dependencies'] ?? ['declared' => 0, 'missing' => 0];

        $html = '<div style="font-size:.85rem;line-height:1.6;">';
        $html .= $this->countsBlock([
            tn_trans('Registered') => (int) ($snapshot['registered'] ?? 0),
            tn_trans('Rendered') => (int) ($snapshot['rendered'] ?? 0),
            tn_trans('Rejected') => (int) ($snapshot['rejected'] ?? 0),
            tn_trans('Dependencies') => (int) ($deps['declared'] ?? 0),
            tn_trans('Missing deps') => (int) ($deps['missing'] ?? 0),
        ]);
        $html .= $this->breakdownBlock(tn_trans('Source breakdown'), $snapshot['sources'] ?? []);
        $html .= $this->breakdownBlock(tn_trans('Plugin breakdown'), $snapshot['plugins'] ?? []);
        $html .= $this->breakdownBlock(tn_trans('Theme breakdown'), $snapshot['themes'] ?? []);
        $html .= $this->warningsBlock($snapshot['warnings'] ?? []);

        return new HtmlString($html.'</div>');
    }

    /** @param array<string, int> $counts */
    private function countsBlock(array $counts): string
    {
        $parts = [];
        foreach ($counts as $label => $value) {
            $parts[] = '<strong>'.e((string) $label).':</strong> '.(int) $value;
        }

        return '<div style="margin-bottom:.5rem;">'.implode(' &nbsp;·&nbsp; ', $parts).'</div>';
    }

    /** @param array<string, int> $map */
    private function breakdownBlock(string $title, array $map): string
    {
        $map = array_filter($map, static fn ($v): bool => (int) $v > 0);

        if ($map === []) {
            return '';
        }

        $html = '<div style="margin:.35rem 0;"><strong>'.e($title).'</strong><ul style="margin:.2rem 0 0 1rem;">';
        foreach ($map as $key => $value) {
            $html .= '<li>'.e((string) $key).': '.(int) $value.'</li>';
        }

        return $html.'</ul></div>';
    }

    /** @param list<string> $warnings */
    private function warningsBlock(array $warnings): string
    {
        if ($warnings === []) {
            return '<div style="margin:.35rem 0;opacity:.7;">'.e(tn_trans('No warnings.')).'</div>';
        }

        $html = '<div style="margin:.35rem 0;"><strong>'.e(tn_trans('Warnings')).'</strong><ul style="margin:.2rem 0 0 1rem;">';
        foreach (array_slice($warnings, 0, 20) as $warning) {
            $html .= '<li>'.e((string) $warning).'</li>';
        }

        return $html.'</ul></div>';
    }

    private function inlineRepeater(string $key, string $label, string $addLabel): Repeater
    {
        return Repeater::make($key)
            ->label($label)
            ->schema([
                TextInput::make('key')->label(tn_trans('Key'))->required()->maxLength(64),
                Toggle::make('enabled')->label(tn_trans('Enabled'))->default(true),
                TextInput::make('priority')->label(tn_trans('Priority'))->numeric()->default(10),
                Textarea::make('code')->label(tn_trans('Code'))->rows(4)->helperText(tn_trans('Inline JavaScript (no <script> tags). Unsafe patterns are rejected.')),
            ])
            ->columns(1)
            ->addActionLabel($addLabel)
            ->default([]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $s = app('cms.settings');
        $opts = ['is_public' => false, 'autoload' => true];

        $s->set('scripts.enabled', (bool) ($state['enabled'] ?? true), 'boolean', $opts);

        // Verification: keep value only, stripped of markup; drop empties.
        $verifications = [];
        foreach (ScriptSettingsRegistrar::VERIFICATION_PROVIDERS as $provider) {
            $value = trim(strip_tags((string) ($state['verify_'.$provider] ?? '')));

            if ($value !== '') {
                $verifications[$provider] = $value;
            }
        }
        $s->set('scripts.verifications', $verifications, 'array', $opts);

        $s->set('scripts.json_ld', $this->cleanRows($state['json_ld'] ?? [], ['key', 'enabled', 'json']), 'array', $opts);
        $s->set('scripts.head_inline', $this->cleanRows($state['head_inline'] ?? [], ['key', 'enabled', 'priority', 'code']), 'array', $opts);
        $s->set('scripts.footer_inline', $this->cleanRows($state['footer_inline'] ?? [], ['key', 'enabled', 'priority', 'code']), 'array', $opts);
        $s->set('scripts.embeds', $this->cleanRows($state['embeds'] ?? [], ['key', 'enabled', 'position', 'priority', 'html']), 'array', $opts);

        // Split the merged external repeater back into head/footer keys.
        $head = $footer = [];
        foreach (is_array($state['external'] ?? null) ? $state['external'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clean = $this->cleanRows([$row], ['key', 'enabled', 'priority', 'src'])[0] ?? null;

            if ($clean === null) {
                continue;
            }

            if (((string) ($row['position'] ?? ScriptAsset::POSITION_HEAD)) === ScriptAsset::POSITION_FOOTER) {
                $footer[] = $clean;
            } else {
                $head[] = $clean;
            }
        }
        $s->set('scripts.head_external', $head, 'array', $opts);
        $s->set('scripts.footer_external', $footer, 'array', $opts);

        $s->clearCache();

        $diagnostics = $this->registrar()->diagnostics();

        if ($diagnostics['invalid'] > 0) {
            Notification::make()
                ->title(tn_trans('Scripts saved with warnings'))
                ->body(tn_trans(':count entr(y/ies) failed validation and will not render.', ['count' => $diagnostics['invalid']]))
                ->warning()
                ->send();

            return;
        }

        Notification::make()->title(tn_trans('Scripts saved'))->success()->send();
    }

    /**
     * Normalise a repeater state into a clean list keeping only the allowed
     * columns. Never trusts row shape.
     *
     * @param  list<string>  $allowed
     * @return list<array<string, mixed>>
     */
    private function cleanRows(mixed $value, array $allowed): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clean = [];
            foreach ($allowed as $col) {
                if ($col === 'enabled') {
                    $clean['enabled'] = (bool) ($row['enabled'] ?? true);
                } elseif ($col === 'priority') {
                    $clean['priority'] = (int) ($row['priority'] ?? 10);
                } else {
                    $clean[$col] = (string) ($row[$col] ?? '');
                }
            }

            $out[] = $clean;
        }

        return $out;
    }

    /**
     * Normalise a stored repeater value into a list of arrays for the form.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    private function diagnosticsHtml(): HtmlString
    {
        $d = $this->registrar()->diagnostics();

        $rows = '<div style="font-size:.85rem;line-height:1.6;">';
        $rows .= '<strong>'.e(tn_trans('Valid entries')).':</strong> '.(int) $d['valid'].'<br>';
        $rows .= '<strong>'.e(tn_trans('Invalid entries')).':</strong> '.(int) $d['invalid'];

        if ($d['skipped'] !== []) {
            $rows .= '<ul style="margin:.4rem 0 0 1rem;">';
            foreach (array_slice($d['skipped'], 0, 10) as $skip) {
                $rows .= '<li>'.e((string) ($skip['type'] ?? '')).' / '.e((string) ($skip['key'] ?? '')).' — '.e((string) ($skip['reason'] ?? '')).'</li>';
            }
            $rows .= '</ul>';
        }

        return new HtmlString($rows.'</div>');
    }

    private function registrar(): ScriptSettingsRegistrar
    {
        return app('cms.script_settings');
    }
}
