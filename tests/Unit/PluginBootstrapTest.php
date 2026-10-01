<?php

/**
 * WordPress bootstrap contract: hooks, schedules, capability mapping and the
 * complete admin asset payload must stay wired to the protected REST API.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Plugin;
use Mudrava\Migration\Support\Capabilities;
use Mudrava\Migration\Support\Logger;
use Mudrava\Migration\Support\Paths;
use Mudrava\Migration\Support\Preflight;
use PHPUnit\Framework\TestCase;

final class PluginBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['MUDRAVA_STUB_ACTIONS'] = [];
        $GLOBALS['MUDRAVA_STUB_FILTERS'] = [];
        $GLOBALS['MUDRAVA_STUB_SCHEDULED'] = [];
        $GLOBALS['MUDRAVA_STUB_CLEARED_HOOKS'] = [];
        $GLOBALS['MUDRAVA_STUB_ADMIN_MENUS'] = [];
        $GLOBALS['MUDRAVA_STUB_STYLES'] = [];
        $GLOBALS['MUDRAVA_STUB_SCRIPTS'] = [];
        $GLOBALS['MUDRAVA_STUB_LOCALIZED'] = [];
    }

    public function testInaccessibleStorageDoesNotCrashAdminBootstrap(): void
    {
        $path = (new Paths())->storageDir();
        $saved = $path . '.saved-' . uniqid();
        $existing = is_dir($path);
        if ($existing) {
            $this->assertTrue(rename($path, $saved));
        }
        file_put_contents($path, 'blocked directory');
        try {
            Plugin::instance()->boot();
            $adminInit = array_values(array_filter(
                $GLOBALS['MUDRAVA_STUB_ACTIONS'],
                static function (array $action): bool {
                    return $action['hook'] === 'admin_init';
                }
            ));
            $this->assertCount(1, $adminInit);
            $adminInit[0]['callback']();
            $preflight = Preflight::run();
            $this->assertFalse($preflight['can_run']);
            $storage = array_values(array_filter(
                $preflight['checks'],
                static function (array $check): bool {
                    return $check['code'] === 'storage_writable';
                }
            ));
            $this->assertSame('private storage needs repair', $storage[0]['detail']);
        } finally {
            unlink($path);
            if ($existing) {
                rename($saved, $path);
            }
        }
    }

    public function testBootRegistersHooksAndRepairsWrongCleanupSchedule(): void
    {
        $GLOBALS['MUDRAVA_STUB_SCHEDULED']['mudrava_cleanup_uploads'] = (object) ['schedule' => 'hourly'];
        $plugin = Plugin::instance();
        $plugin->boot();

        $actions = array_column($GLOBALS['MUDRAVA_STUB_ACTIONS'], 'hook');
        $filters = array_column($GLOBALS['MUDRAVA_STUB_FILTERS'], 'hook');
        $this->assertSame([
            'rest_api_init',
            'admin_menu',
            'admin_enqueue_scripts',
            'admin_init',
            'mudrava_tick',
            'mudrava_cleanup_uploads',
            'wp_loaded',
        ], $actions);
        $rewriteHook = $GLOBALS['MUDRAVA_STUB_ACTIONS'][6];
        $this->assertSame(999, $rewriteHook['priority']);
        $this->assertContains('cron_schedules', $filters);
        $this->assertContains('map_meta_cap', $filters);
        $this->assertContains('determine_current_user', $filters);
        $this->assertSame(['mudrava_cleanup_uploads'], $GLOBALS['MUDRAVA_STUB_CLEARED_HOOKS']);
        $this->assertSame(
            'mudrava_minute',
            $GLOBALS['MUDRAVA_STUB_SCHEDULED']['mudrava_cleanup_uploads']->schedule
        );

        $cron = array_values(array_filter(
            $GLOBALS['MUDRAVA_STUB_FILTERS'],
            static function (array $filter): bool {
                return $filter['hook'] === 'cron_schedules';
            }
        ));
        $callback = $cron[0]['callback'];
        $schedules = $callback(['hourly' => ['interval' => 3600, 'display' => 'Hourly']]);
        $this->assertSame(60, $schedules['mudrava_minute']['interval']);
        $this->assertSame('Every MUDRAVA minute', $schedules['mudrava_minute']['display']);
    }

    public function testAdminMenuRequiresMigrationCapability(): void
    {
        Plugin::instance()->registerAdminMenu();

        $this->assertCount(1, $GLOBALS['MUDRAVA_STUB_ADMIN_MENUS']);
        $menu = $GLOBALS['MUDRAVA_STUB_ADMIN_MENUS'][0];
        $this->assertSame(Capabilities::CAP, $menu['capability']);
        $this->assertSame('mudrava', $menu['slug']);
        $this->assertSame('dashicons-backup', $menu['icon']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAdminAssetsLoadOnlyOnThePluginScreen(): void
    {
        define('MUDRAVA_MB_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        define(
            'MUDRAVA_MB_PLUGIN_URL',
            'http://testsite.local/wp-content/plugins/mudrava-migration-backup/'
        );
        $plugin = Plugin::instance();
        $plugin->enqueueAdminAssets('dashboard_page_other');
        $this->assertSame([], $GLOBALS['MUDRAVA_STUB_STYLES']);
        $this->assertSame([], $GLOBALS['MUDRAVA_STUB_SCRIPTS']);

        $plugin->enqueueAdminAssets('toplevel_page_mudrava');
        $this->assertCount(1, $GLOBALS['MUDRAVA_STUB_STYLES']);
        $this->assertCount(1, $GLOBALS['MUDRAVA_STUB_SCRIPTS']);
        $this->assertCount(1, $GLOBALS['MUDRAVA_STUB_LOCALIZED']);
        $this->assertSame('mudrava-admin', $GLOBALS['MUDRAVA_STUB_STYLES'][0]['handle']);
        $this->assertSame('mudrava-admin', $GLOBALS['MUDRAVA_STUB_SCRIPTS'][0]['handle']);
        $this->assertTrue($GLOBALS['MUDRAVA_STUB_SCRIPTS'][0]['footer']);

        $payload = $GLOBALS['MUDRAVA_STUB_LOCALIZED'][0];
        $this->assertSame('mudravaAdmin', $payload['objectName']);
        $this->assertSame('http://testsite.local/wp-json/mudrava/v1', $payload['data']['root']);
        $this->assertSame('nonce-wp_rest', $payload['data']['nonce']);
        $this->assertGreaterThanOrEqual(1024, $payload['data']['chunkBytes']);
        $this->assertLessThanOrEqual(16777216, $payload['data']['chunkBytes']);
        foreach (['confirm', 'resumePassword', 'rollbackFailed', 'importHoldMissing', 'pickerFailed'] as $key) {
            $this->assertArrayHasKey($key, $payload['data']['i18n']);
            $this->assertNotSame('', $payload['data']['i18n'][$key]);
        }
    }

    public function testCapabilityMappingAndServiceAccessors(): void
    {
        $this->assertSame(['activate_plugins'], Capabilities::map(['read'], Capabilities::CAP, 7, []));
        $this->assertSame(['read'], Capabilities::map(['read'], 'edit_posts', 7, []));
        $this->assertInstanceOf(Logger::class, Plugin::instance()->logger());
        $this->assertSame(Plugin::instance()->logger(), Plugin::instance()->logger());
        $this->assertInstanceOf(Paths::class, Plugin::instance()->paths());
    }
}
