<?php

/**
 * Informational panels; all paid execution remains in a separate add-on.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Admin;

defined('ABSPATH') || exit;

final class ProPreview
{
    /** @return array<string,string> */
    public static function tabs(): array
    {
        return [
            'schedule' => __('Schedule', 'mudrava-migration-backup'),
            'storage' => __('Storage', 'mudrava-migration-backup'),
            'notifications' => __('Notifications', 'mudrava-migration-backup'),
            'pro' => __('Pro', 'mudrava-migration-backup'),
        ];
    }

    public static function render(string $slug): void
    {
        $titles = [
            'schedule' => __('Scheduled backups', 'mudrava-migration-backup'),
            'storage' => __('Off-site storage', 'mudrava-migration-backup'),
            'notifications' => __('Backup notifications', 'mudrava-migration-backup'),
            'pro' => __('MUDRAVA Pro', 'mudrava-migration-backup'),
        ];
        $descriptions = [
            'schedule' => __('Set a backup routine and reduce repetitive work across your sites.', 'mudrava-migration-backup'),
            'storage' => __('Keep backup copies away from the WordPress server you are protecting.', 'mudrava-migration-backup'),
            'notifications' => __('Know when a backup needs attention without checking every site manually.', 'mudrava-migration-backup'),
            'pro' => __('Free provides manual migration and restore on unlimited sites. Pro adds automation.', 'mudrava-migration-backup'),
        ];
        $features = [
            'schedule' => [
                __('Daily or weekly backups in your site timezone', 'mudrava-migration-backup'),
                __('Missed runs handled without creating a backlog', 'mudrava-migration-backup'),
                __('Overlapping jobs prevented; safe review after imports', 'mudrava-migration-backup'),
            ],
            'storage' => [
                __('S3 and S3-compatible storage', 'mudrava-migration-backup'),
                __('Verified delivery with retries', 'mudrava-migration-backup'),
                __('Retention rules for older backup copies', 'mudrava-migration-backup'),
            ],
            'notifications' => [
                __('Email alerts for failed backups or delivery', 'mudrava-migration-backup'),
                __('Backup and delivery reports', 'mudrava-migration-backup'),
                __('Choose where reports are sent', 'mudrava-migration-backup'),
            ],
            'pro' => [
                __('Scheduled backups, off-site storage, retention and notifications', 'mudrava-migration-backup'),
                __('Planned annual plans: $59 / 3 sites, $129 / 15 sites, $249 / 50 sites.', 'mudrava-migration-backup'),
                __('Same Pro features in every plan. Manual Free restore remains available after expiry.', 'mudrava-migration-backup'),
            ],
        ];
        ?>
        <h2><?php echo esc_html($titles[$slug]); ?> <span class="mudrava-badge-pro">PRO</span></h2>
        <p class="mudrava-sub"><?php echo esc_html($descriptions[$slug]); ?></p>
        <ul class="mudrava-feature-list">
            <?php foreach ($features[$slug] as $feature) : ?>
                <li><?php echo esc_html($feature); ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="mudrava-notice">
            <?php echo esc_html__(
                'These capabilities belong to the separate Pro add-on, currently in development. Purchasing is not open yet.',
                'mudrava-migration-backup'
            ); ?>
        </p>
        <?php if ($slug !== 'pro') : ?>
            <button type="button" class="mudrava-btn mudrava-btn-primary" data-goto-tab="pro">
                <?php echo esc_html__('Explore Pro', 'mudrava-migration-backup'); ?>
            </button>
        <?php else : ?>
            <a class="mudrava-btn mudrava-btn-primary" href="https://mudrava.com/en/" target="_blank" rel="noopener noreferrer">
                <?php echo esc_html__('Visit MUDRAVA', 'mudrava-migration-backup'); ?>
            </a>
        <?php endif; ?>
        <?php
    }
}
