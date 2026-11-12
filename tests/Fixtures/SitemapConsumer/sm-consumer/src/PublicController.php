<?php

declare(strict_types=1);

namespace Plugins\SmConsumer;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Serves exactly the items the provider lists: public ones, in their locale. */
final class PublicController
{
    public function show(Request $request): Response
    {
        $type = (string) $request->route()->defaults['smc_type'];
        $locale = (string) $request->route()->defaults['smc_locale'];
        $query = Item::query()->public()->where('family', Families::TYPES[$type]['family'])->whereNotNull('slug_'.$locale);

        if (Families::TYPES[$type]['lottery'] ?? false) {
            $date = \DateTimeImmutable::createFromFormat('!d-m-Y', (string) $request->route('date'));
            $query->where('region', (string) $request->route('region'))
                ->whereDate('draw_date', $date === false ? '1900-01-01' : $date->format('Y-m-d'));
        } else {
            $query->where('slug_'.$locale, (string) $request->route('slug'));
        }

        $item = $query->first();

        abort_if($item === null, 404);

        return response('SMC|'.$type.'|'.$item->getKey().'|'.$locale);
    }
}
