<?php

declare(strict_types=1);

namespace Tests\Unit\ThemeOptions;

use PHPUnit\Framework\TestCase;
use TheNguyen\CMS\Support\ThemeOptionGroup;

/**
 * CORE-THEME-OPTIONS-UX-1 — the canonical group registry that owns the admin
 * tab identity (label + icon + order). Core, not the theme, decides these.
 */
final class ThemeOptionGroupTest extends TestCase
{
    public function test_missing_or_unknown_group_falls_back_to_general(): void
    {
        $this->assertSame('general', ThemeOptionGroup::normalize(null));
        $this->assertSame('general', ThemeOptionGroup::normalize(''));
        $this->assertSame('general', ThemeOptionGroup::normalize('   '));
        $this->assertSame('general', ThemeOptionGroup::normalize('frobnicate'));
        $this->assertSame('general', ThemeOptionGroup::normalize(42));
        $this->assertSame('general', ThemeOptionGroup::normalize(['colors']));
    }

    public function test_known_groups_normalize_case_insensitively(): void
    {
        $this->assertSame('colors', ThemeOptionGroup::normalize('colors'));
        $this->assertSame('colors', ThemeOptionGroup::normalize('Colors'));
        $this->assertSame('colors', ThemeOptionGroup::normalize('  COLORS '));
        $this->assertSame('seo', ThemeOptionGroup::normalize('seo'));
        $this->assertSame('code', ThemeOptionGroup::normalize('code'));
    }

    public function test_all_standard_groups_are_registered(): void
    {
        foreach (['general', 'brand', 'colors', 'layout', 'media', 'seo', 'code', 'advanced'] as $group) {
            $this->assertTrue(ThemeOptionGroup::isKnown($group), "{$group} must be a known group");
            $this->assertNotSame('', ThemeOptionGroup::label($group));
            $this->assertStringStartsWith('heroicon-', ThemeOptionGroup::icon($group));
        }
    }

    public function test_tab_order_is_canonical_general_first_code_before_advanced(): void
    {
        $keys = ThemeOptionGroup::keys();

        $this->assertSame('general', $keys[0], 'General is the fallback tab and comes first.');
        $this->assertLessThan(
            array_search('advanced', $keys, true),
            array_search('code', $keys, true),
            'Custom CSS & Code precedes Advanced.',
        );
        $this->assertSame(
            ['general', 'brand', 'colors', 'layout', 'media', 'seo', 'code', 'advanced'],
            $keys,
        );
    }

    public function test_custom_css_lives_in_the_code_tab(): void
    {
        $this->assertSame('code', ThemeOptionGroup::CUSTOM_CSS);
        $this->assertSame('general', ThemeOptionGroup::FALLBACK);
    }

    public function test_unknown_label_and_icon_degrade_to_general(): void
    {
        $this->assertSame(ThemeOptionGroup::label('general'), ThemeOptionGroup::label('nope'));
        $this->assertSame(ThemeOptionGroup::icon('general'), ThemeOptionGroup::icon('nope'));
    }
}
