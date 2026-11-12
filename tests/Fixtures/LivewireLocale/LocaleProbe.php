<?php

declare(strict_types=1);

namespace Tests\Fixtures\LivewireLocale;

use Livewire\Component;

/**
 * CORE-P12-4 fixture: a plugin-style frontend Livewire component on an account
 * route. Each render reports the locale context the update request saw.
 */
final class LocaleProbe extends Component
{
    public int $count = 0;

    public string $name = '';

    public function bump(): void
    {
        $this->count++;
    }

    public function submit(): void
    {
        $this->validate(['name' => 'required|min:3']);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div data-probe-livewire>
                <span data-k="locale">{{ current_locale() }}</span>
                <span data-k="app-locale">{{ app()->getLocale() }}</span>
                <span data-k="user">{{ auth()->id() ?? 'guest' }}</span>
                <span data-k="count">{{ $count }}</span>
                <span data-k="profile-label">{{ $profileLabel }}</span>
                <span data-k="profile-url">{{ $profileUrl }}</span>
                <span data-k="error">{{ $errors->first('name') }}</span>
                <button type="button" wire:click="bump">bump</button>
            </div>
            BLADE;
    }

    public function rendering($view): void
    {
        $profile = collect(app('cms.account')->navigationItems(auth()->user(), request()))->firstWhere('key', 'profile');
        $view->with(['profileLabel' => $profile?->label ?? '', 'profileUrl' => $profile ? (string) parse_url($profile->url, PHP_URL_PATH) : '']);
    }
}
