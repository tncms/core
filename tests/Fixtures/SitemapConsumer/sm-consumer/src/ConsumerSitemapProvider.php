<?php

declare(strict_types=1);

namespace Plugins\SmConsumer;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use TheNguyen\CMS\Sitemap\KeysetSitemapProvider;
use TheNguyen\CMS\Sitemap\SitemapEntry;

/**
 * The fixture's sitemap provider: one keyset set per (type, locale) over the
 * plugin's own table. Cache flags (`smc.fail`, `smc.hostile`) exist only to
 * prove Core's failure isolation and URL validation.
 */
final class ConsumerSitemapProvider extends KeysetSitemapProvider
{
    public function types(): array
    {
        $types = array_keys(Families::TYPES);

        if (Cache::get('smc.hostile') === true) {
            $types[] = 'hostile';
        }

        return $types;
    }

    public function entries(string $type, string $locale, ?int $after, ?int $until, int $limit): iterable
    {
        if (Cache::get('smc.fail') === $type) {
            throw new RuntimeException('fixture failure with a secret-looking detail /var/www/.env');
        }

        return parent::entries($type, $locale, $after, $until, $limit);
    }

    protected function query(string $type, string $locale): ?Builder
    {
        if ($type === 'hostile') {
            return Item::query()->public()->where('family', 'kb_article')->whereNotNull('slug_'.$locale);
        }

        if (Families::segment($type, $locale) === null) {
            return null;
        }

        return Item::query()
            ->public()
            ->where('family', Families::TYPES[$type]['family'])
            ->whereNotNull('slug_'.$locale)
            ->where('slug_'.$locale, '!=', '');
    }

    protected function entriesFor(Collection $rows, string $type, string $locale): iterable
    {
        foreach ($rows as $item) {
            if ($type === 'hostile') {
                yield from $this->hostile($item, $locale);

                continue;
            }

            $loc = Families::url($type, $item, $locale);

            if ($loc === null) {
                continue;
            }

            $alternates = [];
            foreach (array_keys(Families::TYPES[$type]['segments']) as $code) {
                if (in_array($code, app('cms.language')->getPublicLocales(), true) && ($url = Families::url($type, $item, $code)) !== null) {
                    $alternates[$code] = $url;
                }
            }

            $default = app('cms.language')->defaultCode();
            if (count($alternates) > 1 && isset($alternates[$default])) {
                $alternates['x-default'] = $alternates[$default];
            }

            yield new SitemapEntry((int) $item->getKey(), $loc, $item->updated_at, $alternates);
        }
    }

    /**
     * URLs Core must refuse: foreign host, Core-owned, admin, signed/token,
     * wrong locale, traversal. Only the last one (a valid own URL) may survive.
     *
     * @return iterable<SitemapEntry>
     */
    private function hostile(Item $item, string $locale): iterable
    {
        $key = (int) $item->getKey();
        $base = rtrim(url('/'), '/');

        foreach ([
            'https://evil.example/kien-thuc/bai-viet/'.$item->slug_vi,
            $base.'/blog/stolen-core-post',
            $base.'/admin/login',
            $base.'/account/profile',
            $base.'/kien-thuc/bai-viet/'.$item->slug_vi.'?signature=abc&token=secret',
            $base.'/kien-thuc/bai-viet/..%2f..%2fadmin',
            $base.'/en/kien-thuc/bai-viet/'.$item->slug_vi,
        ] as $bad) {
            yield new SitemapEntry($key, $bad, null, []);
        }
    }
}
