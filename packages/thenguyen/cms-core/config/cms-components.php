<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Component Registry (theme-architecture 13)
|--------------------------------------------------------------------------
|
| A lightweight catalog of the reusable components the Company sections compose.
| v1 is catalog-only (name, altitude, title, view, since): rendering does not
| depend on it — section views render components directly. Full prop schemas
| arrive with the Page Builder (a non-goal for this phase). Declaring the new
| `feature-item` and `accordion` molecules now keeps section `renders` lists
| honest and lets the builder/AI validate them later.
*/

$component = fn (string $name, string $altitude, string $title) => [
    'name' => $name,
    'altitude' => $altitude,
    'title' => $title,
    'view' => 'theme::components.'.str_replace('.', '.', $name),
    'since' => '1.0.0',
];

return [
    // Primitives
    'button' => $component('button', 'primitive', 'Button'),
    'media' => $component('media', 'primitive', 'Media'),
    'icon' => $component('icon', 'primitive', 'Icon'),
    'heading' => $component('heading', 'primitive', 'Heading'),

    // Molecules
    'section-header' => $component('section-header', 'molecule', 'Section Header'),
    'feature-item' => $component('feature-item', 'molecule', 'Feature Item'),
    'accordion' => $component('accordion', 'molecule', 'Accordion'),
    'card.pricing' => $component('card.pricing', 'molecule', 'Pricing Card'),
    'card.testimonial' => $component('card.testimonial', 'molecule', 'Testimonial Card'),
    'card.stat' => $component('card.stat', 'molecule', 'Stat Card'),
];
