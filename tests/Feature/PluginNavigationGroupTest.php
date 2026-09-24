<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Providers\Filament\AdminPanelProvider;
use Filament\Pages\Page;
use Tests\TestCase;

/**
 * Plugin pages are filed under one nav group (S-364).
 *
 * Left alone they scatter — one plugin put itself in System beside the screens
 * that administer the machine, another declared nothing and floated ungrouped
 * above the sidebar — and an operator could not tell which screens a plugin
 * had added.
 */
class PluginNavigationGroupTest extends TestCase
{
    /** Calls the provider's private grouping step for one page class. */
    private function group(string $page): void
    {
        $provider = new AdminPanelProvider($this->app);

        $method = new \ReflectionMethod($provider, 'groupPluginPage');
        $method->invoke($provider, $page);
    }

    public function test_a_page_that_named_no_group_is_filed_under_plugins(): void
    {
        $this->group(PluginPageWithoutGroup::class);

        $this->assertSame('Plugins', PluginPageWithoutGroup::getNavigationGroup());
    }

    public function test_a_page_that_named_a_group_keeps_it(): void
    {
        // The audit-log plugin genuinely belongs beside the other System
        // screens, and saying so must not be overridden.
        $this->group(PluginPageWithOwnGroup::class);

        $this->assertSame('System', PluginPageWithOwnGroup::getNavigationGroup());
    }

    public function test_something_that_is_not_a_page_is_ignored(): void
    {
        // A plugin registering a bad class must not take the panel down.
        $this->group(\stdClass::class);

        $this->expectNotToPerformAssertions();
    }

    public function test_the_panel_declares_the_plugins_group(): void
    {
        $groups = \Filament\Facades\Filament::getPanel('admin')->getNavigationGroups();

        $this->assertContains(
            'Plugins',
            array_map(fn ($group) => $group->getLabel(), $groups),
        );
    }
}

class PluginPageWithoutGroup extends Page
{
    protected string $view = 'welcome';
}

class PluginPageWithOwnGroup extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected string $view = 'welcome';
}
