<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use Illuminate\Console\Command;
use TheNguyen\CMS\Localization\Contracts\LanguageConfigurationContract;
use TheNguyen\CMS\Localization\Contracts\LocalizationStrategyContract;
use TheNguyen\CMS\Localization\LocaleSwitchTargetService;
use TheNguyen\CMS\Localization\LocalizationContext;
use TheNguyen\CMS\Localization\LocalizationStrategyRegistry;
use TheNguyen\CMS\Localization\LocalizedResourceResolverRegistry;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;

/**
 * CORE-L10N.1B — inspects the Localization Platform: active/registered strategies, registered
 * resolvers, language configuration, deprecated plugin config, and — for a given URL or the home
 * context — the selected resolver and the generated switch targets. Reports no secrets.
 */
final class DiagnoseLocalizationCommand extends Command
{
    protected $signature = 'cms:localization:diagnose {--url= : Resolve this internal path to a resolver + switch targets}';

    protected $description = 'Diagnose the TN CMS localization platform (strategies, resolvers, config, targets).';

    public function handle(
        LanguageConfigurationContract $config,
        LocalizationStrategyRegistry $strategies,
        LocalizedResourceResolverRegistry $resolvers,
        LocaleSwitchTargetService $targets,
    ): int {
        $this->components->info('TN CMS Localization Platform');

        $this->line('<comment>Configuration</comment>');
        $this->line('  multilingual enabled : '.$this->bool($config->multilingualEnabled()));
        $this->line('  enabled locales      : '.implode(', ', $config->enabledLocales()));
        $this->line('  default locale       : '.$config->defaultLocale());
        $this->line('  fallback locale      : '.$config->fallbackLocale());
        $this->line('  prefix default locale: '.$this->bool($config->prefixesDefaultLocale()));
        $prefixes = [];
        foreach ($config->enabledLocales() as $code) {
            $prefixes[] = $code.' => "'.$config->prefixFor($code).'"';
        }
        $this->line('  prefix map           : '.implode(', ', $prefixes));

        $active = $config->routingStrategy();
        $this->newLine();
        $this->line('<comment>Strategies</comment>');
        $this->line('  active               : '.$active.($strategies->has($active) ? '' : ' <fg=red>(NOT REGISTERED — fails closed)</>'));
        $this->line('  registered           : '.implode(', ', $strategies->keys()));

        $this->newLine();
        $this->line('<comment>Resolvers</comment>');
        $this->line('  registered           : '.implode(', ', $resolvers->keys()));

        $this->reportDeprecated();

        $url = $this->option('url');
        if (is_string($url) && $url !== '') {
            $this->reportUrl($url, $config, $targets);
        }

        return self::SUCCESS;
    }

    private function reportDeprecated(): void
    {
        $deprecated = [
            'ecommerce.localization.enabled',
            'ecommerce.localization.default_locale',
            'ecommerce.localization.persistence',
        ];

        $found = [];
        foreach ($deprecated as $key) {
            $value = config($key);
            if ($value !== null) {
                $found[] = $key.' = '.(is_bool($value) ? $this->bool($value) : (string) $value);
            }
        }

        $this->newLine();
        $this->line('<comment>Deprecated plugin localization config</comment>');
        if ($found === []) {
            $this->line('  none detected');

            return;
        }

        foreach ($found as $line) {
            $this->line('  <fg=yellow>'.$line.'</> (Core configuration wins; retire in P4)');
        }
    }

    private function reportUrl(string $url, LanguageConfigurationContract $config, LocaleSwitchTargetService $targets): void
    {
        $context = $this->contextForUrl($url, $config);

        $this->newLine();
        $this->line('<comment>URL</comment> '.$url);
        $this->line('  context type         : '.$context->type);
        $this->line('  selected resolver    : '.($targets->selectedResolverKey($context) ?? '<none — home/unknown fallback>'));

        $rows = [];
        foreach ($targets->targets($context) as $target) {
            $rows[] = [
                $target->locale,
                $target->active ? 'active' : '',
                $target->available ? 'yes' : 'no',
                $target->fallbackUsed ? 'home' : '',
                $target->method,
                $target->url,
            ];
        }

        $this->table(['locale', 'current', 'available', 'fallback', 'method', 'url'], $rows);
    }

    private function contextForUrl(string $url, LanguageConfigurationContract $config): LocalizationContext
    {
        $path = trim(parse_url($url, PHP_URL_PATH) ?: '/', '/');

        if ($path === '') {
            return new LocalizationContext('home');
        }

        $segments = explode('/', $path);

        // Strip a leading locale prefix if present.
        $locale = $config->defaultLocale();
        if ($config->isEnabled($segments[0])) {
            $locale = $config->normalize($segments[0]);
            array_shift($segments);
        }

        $slug = (string) end($segments);
        if ($slug === '') {
            return new LocalizationContext('home');
        }

        try {
            $row = app('cms.slug')->findPublic($slug, $locale);
        } catch (\Throwable) {
            $row = null;
        }

        if ($row !== null && $row->reference_type === 'content') {
            $content = Content::query()->find($row->reference_id);
            if ($content !== null) {
                return new LocalizationContext((string) $content->type, $content);
            }
        }

        if ($row !== null && $row->reference_type === 'term') {
            $term = Term::query()->with('taxonomy')->find($row->reference_id);
            if ($term !== null) {
                return new LocalizationContext((string) ($term->taxonomy->type ?? 'category'), null, $term);
            }
        }

        return new LocalizationContext('home');
    }

    private function bool(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
