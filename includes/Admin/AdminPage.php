<?php

/**
 * Shared administration screen. The markup supports status viewing without JS;
 * the JS layer drives job ticks, uploads and the live progress rail.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Admin;

use Mudrava\Migration\Support\Capabilities;
use Mudrava\Migration\Support\Preflight;

defined('ABSPATH') || exit;

final class AdminPage
{
    public static function render(): void
    {
        if (!Capabilities::currentCan()) {
            wp_die(esc_html__('You do not have permission to run migrations.', 'mudrava-migration-backup'));
        }
        $preflight = Preflight::run();
        $legacy_archives = (new \Mudrava\Migration\Support\Paths())->legacyPublicArchiveCount();
        $multisite = is_multisite();
        // Deep-linkable tab: ?tab=… survives reloads and is shareable.
        // Rendered server-side so the right panel shows even without JS.
        // Read-only selector: sanitized, whitelisted below, mutates nothing,
        // so no nonce applies (phpcs cannot know that).
        $tab_slug = sanitize_key((string) (isset($_GET['tab']) ? $_GET['tab'] : 'backup')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $addon_tabs = \Mudrava\Migration\Integration\AdminPanels::tabs();
        if (class_exists(ProPreview::class)) {
            $addon_tabs = array_merge(ProPreview::tabs(), $addon_tabs);
        }
        $valid_tabs = array_merge(['backup', 'restore', 'archives', 'environment'], array_keys($addon_tabs));
        if (!in_array($tab_slug, $valid_tabs, true)) {
            $tab_slug = 'backup';
        }
        $env_failed = false;
        foreach ($preflight['checks'] as $check) {
            if ($check['ok'] === false) {
                $env_failed = true;
                break;
            }
        }

        // Long UI strings decoded once so the markup below stays readable.
        $l = [
            'tagline'      => __('Portable backups. Resumable exports. Optional encryption.', 'mudrava-migration-backup'),
            'free'         => __('Free. Host limits apply', 'mudrava-migration-backup'),
            'ms_title'     => __('Multisite detected.', 'mudrava-migration-backup'),
            'ms_body'      => __(
                'Network-wide migration is not supported in this release. MUDRAVA currently migrates single sites.',
                'mudrava-migration-backup'
            ),
            'env'          => __('Environment', 'mudrava-migration-backup'),
            'env_fail'     => __(
                'Some environment checks failed. Open the Environment tab for details.',
                'mudrava-migration-backup'
            ),
            'review'       => __('Review', 'mudrava-migration-backup'),
            /* translators: %d: number of archive files in the old public storage directory. */
            'legacy_public' => __(
                'Security action required: %d old backup files remain in public wp-content. Move them to private storage.',
                'mudrava-migration-backup'
            ),
            'ops'          => __('Migration operations', 'mudrava-migration-backup'),
            'export'       => __('Export', 'mudrava-migration-backup'),
            'import'       => __('Import', 'mudrava-migration-backup'),
            'export_h'     => __('Export this site', 'mudrava-migration-backup'),
            'export_sub'   => __(
                'Export creates a .mudrava archive. If site cron is inactive, keep this tab open; the progress dialog shows whether it is safe to close.',
                'mudrava-migration-backup'
            ),
            'export_stability' => __(
                'Pause writes during final export. Changes during the run can stop the export or leave unvisited files out of the backup.',
                'mudrava-migration-backup'
            ),
            'encrypt'      => __('Encrypt backup with password', 'mudrava-migration-backup'),
            'pw'           => __('Password', 'mudrava-migration-backup'),
            'pw_ph'        => __('Choose a strong password', 'mudrava-migration-backup'),
            'pw2'          => __('Repeat password', 'mudrava-migration-backup'),
            'pw2_ph'       => __('Repeat the password', 'mudrava-migration-backup'),
            'pw_mismatch'  => __('Passwords do not match.', 'mudrava-migration-backup'),
            'show_pw'      => __('Show password', 'mudrava-migration-backup'),
            'hide_pw'      => __('Hide password', 'mudrava-migration-backup'),
            'pw_hint'      => __(
                'Encrypted with XChaCha20-Poly1305 + Argon2id. Lost passwords cannot be recovered.',
                'mudrava-migration-backup'
            ),
            'hint'         => __('Password hint (optional)', 'mudrava-migration-backup'),
            'hint_ph'      => __('e.g. company vault / migration', 'mudrava-migration-backup'),
            'hint_warn'    => __(
                'Anyone who has this archive can read the password hint. Never put the password or part of it here.',
                'mudrava-migration-backup'
            ),
            'split'        => __('Archive splitting', 'mudrava-migration-backup'),
            'split_presets' => __('Split presets', 'mudrava-migration-backup'),
            'auto'         => __('Auto', 'mudrava-migration-backup'),
            'off'          => __('Off', 'mudrava-migration-backup'),
            'custom'       => __('Custom', 'mudrava-migration-backup'),
            'split_hint'   => __(
                'Split archives to meet host file limits. Auto uses 7 GB parts. Off makes one file. Custom accepts a size in MB or GB.',
                'mudrava-migration-backup'
            ),
            'unit_label'   => __('Part size unit', 'mudrava-migration-backup'),
            // Content pickers: real tables and real wp-content folders,
            // measured live. Everything is on by default; the operator
            // only unchecks what they are sure this site does not need.
            'content_h'    => __('What goes into the archive', 'mudrava-migration-backup'),
            'content_all'  => __('Everything included', 'mudrava-migration-backup'),
            'content_edit' => __('Change what is included', 'mudrava-migration-backup'),
            'content_edit_close' => __('Done editing', 'mudrava-migration-backup'),
            'content_sub'  => __(
                'Everything is included by default. Uncheck only what you are sure this site does not need.',
                'mudrava-migration-backup'
            ),
            'db_h'         => __('Database', 'mudrava-migration-backup'),
            'files_h'      => __('Files', 'mudrava-migration-backup'),
            'filter_db_ph' => __('Filter tables…', 'mudrava-migration-backup'),
            'filter_fs_ph' => __('Filter folders…', 'mudrava-migration-backup'),
            'select_all'   => __('Select all', 'mudrava-migration-backup'),
            'refresh'      => __('Reload the live inventory', 'mudrava-migration-backup'),
            'core_note'    => __(
                'wp-admin, wp-includes and root files are always included. A restored site needs them to run.',
                'mudrava-migration-backup'
            ),
            'extra_dirs'   => __('Extra paths to skip (optional)', 'mudrava-migration-backup'),
            'extra_dirs_ph' => __('uploads/2023, cache', 'mudrava-migration-backup'),
            'extra_dirs_tip' => __(
                'Skip folders inside wp-content, separated by commas. Example: uploads/2023 skips media from 2023. Use the checkboxes above for whole groups.',
                'mudrava-migration-backup'
            ),
            'cloud'        => __('Cloud storage', 'mudrava-migration-backup'),
            'cloud_local'  => __('This site (Free)', 'mudrava-migration-backup'),
            'cloud_remote' => __('S3 / S3-compatible storage', 'mudrava-migration-backup'),
            'cloud_sched'  => __('Scheduled exports', 'mudrava-migration-backup'),
            'create'       => __('Create backup', 'mudrava-migration-backup'),
            'import_h'     => __('Import an archive', 'mudrava-migration-backup'),
            'import_sub'   => __(
                'Upload a .mudrava archive (or every part of a split set) and restore it onto this site.',
                'mudrava-migration-backup'
            ),
            'drop_aria'    => __('Choose archive files to upload', 'mudrava-migration-backup'),
            'drop'         => __('Drop .mudrava files here', 'mudrava-migration-backup'),
            'drop_sub'     => __('or click to browse. For split sets, select every part', 'mudrava-migration-backup'),
            'arc_pw'       => __('Archive password', 'mudrava-migration-backup'),
            'unsafe'       => __(
                'I understand: restore overwrites this site database and files, and I accept proceeding without a restore point.',
                'mudrava-migration-backup'
            ),
            'restore'      => __('Restore site', 'mudrava-migration-backup'),
            // The rewrite is auto-derived from the archive (source site ->
            // this site), so the operator never types URLs - they only see
            // the detected pair after the upload.
            'rewriting'    => __('Rewriting URLs', 'mudrava-migration-backup'),
            'cancel'       => __('Cancel', 'mudrava-migration-backup'),
            'close'        => __('Close', 'mudrava-migration-backup'),
            'pwNeeded'     => __(
                'This archive is encrypted. Enter its password to restore.',
                'mudrava-migration-backup'
            ),
            'close_note'   => __(
                'Safe to close this tab. The job continues on the server and resumes when you return.',
                'mudrava-migration-backup'
            ),
            'archives'     => __('Archives on this site', 'mudrava-migration-backup'),
            'archives_tab' => __('Archives', 'mudrava-migration-backup'),
            // Per-check explanation: what the value is, what it affects, why
            // it matters. Rendered as a second line under each check row and
            // mirrored into a title attribute for a hover tooltip.
            'check_help'   => [
                'php_version' => __(
                    'The PHP version of this site. PHP 7.4 or newer is required for streaming and crypto functions.',
                    'mudrava-migration-backup'
                ),
                'int64' => __(
                    'Whether PHP uses 64-bit integers. Without them, archives over 2 GB cannot be read or written safely.',
                    'mudrava-migration-backup'
                ),
                'zlib' => __(
                    'The zlib extension compresses archive frames while streaming. Export requires it.',
                    'mudrava-migration-backup'
                ),
                'crypto' => __(
                    'The encryption backend protects password-encrypted archives. Without it, unencrypted export still works.',
                    'mudrava-migration-backup'
                ),
                'json' => __(
                    'The JSON extension. It encodes archive metadata and job status. Without it, the engine cannot describe or resume an archive.',
                    'mudrava-migration-backup'
                ),
                'storage_writable' => __(
                    'Whether the plugin can write to private storage. Archives and restore points need this directory.',
                    'mudrava-migration-backup'
                ),
                'disk_free' => __(
                    'Free space in private storage. A safe restore needs room for the archive and a restore point.',
                    'mudrava-migration-backup'
                ),
                'site_size' => __(
                    'Approximate size of site files. The database is measured when export starts. This helps estimate time and space.',
                    'mudrava-migration-backup'
                ),
            ],
        ];
        ?>
        <div class="wrap mudrava-wrap">
            <header class="mudrava-hero">
                <div class="mudrava-hero-brand">
                    <img class="mudrava-logo" src="<?php echo esc_url(MUDRAVA_MB_PLUGIN_URL . 'assets/brand-icon.svg'); ?>"
                         width="64" height="64" alt="" aria-hidden="true">
                    <div>
                        <h1>MUDRAVA <span>Migration &amp; Backup</span></h1>
                        <p class="mudrava-tagline"><?php echo esc_html($l['tagline']); ?></p>
                    </div>
                </div>
                <div class="mudrava-hero-meta">
                    <span class="mudrava-pill mudrava-pill-free"><?php echo esc_html($l['free']); ?></span>
                    <span class="mudrava-pill">v<?php echo esc_html(MUDRAVA_MB_VERSION); ?></span>
                </div>
            </header>
            <?php if ($multisite) : ?>
                <div class="mudrava-notice mudrava-notice-warn" role="alert">
                    <strong><?php echo esc_html($l['ms_title']); ?></strong>
                    <?php echo esc_html($l['ms_body']); ?>
                </div>
            <?php endif; ?>
            <?php if ($legacy_archives > 0) : ?>
                <div class="mudrava-notice mudrava-notice-danger" role="alert">
                    <?php echo esc_html(sprintf($l['legacy_public'], $legacy_archives)); ?>
                </div>
            <?php endif; ?>
            <?php if ($env_failed) : ?>
                <div class="mudrava-notice mudrava-notice-danger" role="alert">
                    <strong><?php echo esc_html($l['env_fail']); ?></strong>
                    <button type="button" class="mudrava-linkbtn" data-goto-tab="environment">
                        <?php echo esc_html($l['review']); ?>
                    </button>
                </div>
            <?php endif; ?>
            <?php
            // Action tabs first (export, import, archives), the diagnostic
            // Environment tab sits last: operators live in the first three,
            // Environment is where they go when something is wrong.
            $tabs = [
                'backup'      => $l['export'],
                'restore'     => $l['import'],
                'archives'    => $l['archives_tab'],
                'environment' => $l['env'],
            ];
            $environment_tab = ['environment' => $tabs['environment']];
            unset($tabs['environment']);
            $tabs = array_merge($tabs, $addon_tabs, $environment_tab);
            ?>
            <div class="mudrava-tabs" role="tablist" aria-label="<?php echo esc_attr($l['ops']); ?>">
                <?php foreach ($tabs as $slug => $label) : ?>
                    <?php $is_active = $slug === $tab_slug; ?>
                    <button class="mudrava-tab<?php echo $is_active ? ' is-active' : ''; ?>"
                            data-tab="<?php echo esc_attr($slug); ?>" role="tab"
                            id="mudrava-tab-<?php echo esc_attr($slug); ?>"
                            aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
                            aria-controls="mudrava-panel-<?php echo esc_attr($slug); ?>"
                            <?php echo $is_active ? '' : 'tabindex="-1"'; ?>>
                        <?php echo esc_html($label); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <div id="mudrava-panel-backup" class="mudrava-card mudrava-panel"
                 role="tabpanel" aria-labelledby="mudrava-tab-backup"
                 <?php echo $tab_slug === 'backup' ? '' : 'hidden'; ?>>
                <h2><?php echo esc_html($l['export_h']); ?></h2>
                <p class="mudrava-sub"><?php echo esc_html($l['export_sub']); ?></p>
                <p class="mudrava-notice mudrava-notice-warn"><?php echo esc_html($l['export_stability']); ?></p>
                <label class="mudrava-checkline" for="mudrava-encrypt-on">
                    <input type="checkbox" id="mudrava-encrypt-on">
                    <?php echo esc_html($l['encrypt']); ?>
                </label>
                <div id="mudrava-encrypt-fields" hidden>
                    <div class="mudrava-field">
                        <label for="mudrava-export-password" class="mudrava-lab">
                            <?php echo esc_html($l['pw']); ?>
                            <span class="mudrava-more" tabindex="0">i</span>
                            <span id="mudrava-pw-hint" class="mudrava-tip" role="tooltip"><?php echo esc_html($l['pw_hint']); ?></span>
                        </label>
                        <div class="mudrava-pwwrap">
                            <input type="password" id="mudrava-export-password" autocomplete="new-password"
                                   aria-describedby="mudrava-pw-hint" placeholder="<?php echo esc_attr($l['pw_ph']); ?>">
                            <button type="button" class="mudrava-eye" data-eye="mudrava-export-password"
                                    aria-pressed="false" aria-label="<?php echo esc_attr($l['show_pw']); ?>">
                                <svg class="mv-eye-open" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg class="mv-eye-off" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                    <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                                    <line x1="1" y1="1" x2="23" y2="23"/>
                                </svg>
                            </button>
                        </div>
                        <p id="mudrava-pw-error" class="mudrava-field-error" role="alert" hidden></p>
                    </div>
                    <div class="mudrava-field">
                        <label for="mudrava-export-password2"><?php echo esc_html($l['pw2']); ?></label>
                        <div class="mudrava-pwwrap">
                            <input type="password" id="mudrava-export-password2" autocomplete="new-password"
                                   placeholder="<?php echo esc_attr($l['pw2_ph']); ?>">
                            <button type="button" class="mudrava-eye" data-eye="mudrava-export-password2"
                                    aria-pressed="false" aria-label="<?php echo esc_attr($l['show_pw']); ?>">
                                <svg class="mv-eye-open" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg class="mv-eye-off" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                    <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                                    <line x1="1" y1="1" x2="23" y2="23"/>
                                </svg>
                            </button>
                        </div>
                        <p id="mudrava-pw2-error" class="mudrava-field-error" role="alert" hidden></p>
                    </div>
                    <div class="mudrava-field">
                        <label for="mudrava-hint" class="mudrava-lab">
                            <?php echo esc_html($l['hint']); ?>
                            <span class="mudrava-more" tabindex="0">i</span>
                            <span id="mudrava-hint-warn" class="mudrava-tip" role="tooltip"><?php echo esc_html($l['hint_warn']); ?></span>
                        </label>
                        <input type="text" id="mudrava-hint" maxlength="200"
                               aria-describedby="mudrava-hint-warn" placeholder="<?php echo esc_attr($l['hint_ph']); ?>">
                        <p id="mudrava-hint-error" class="mudrava-field-error" role="alert" hidden></p>
                    </div>
                </div>
                <div class="mudrava-section">
                    <div class="mudrava-section-head">
                        <div class="mudrava-section-titles">
                            <h3 class="mudrava-section-h"><?php echo esc_html($l['content_h']); ?></h3>
                            <p class="mudrava-section-sub" id="mudrava-content-summary">
                                <?php echo esc_html($l['content_all']); ?>
                            </p>
                        </div>
                        <button type="button" class="mudrava-btn-ghost" id="mudrava-content-toggle"
                                aria-expanded="false" aria-controls="mudrava-content-body">
                            <?php echo esc_html($l['content_edit']); ?>
                        </button>
                    </div>
                    <div id="mudrava-content-body" hidden>
                        <p class="mudrava-section-sub"><?php echo esc_html($l['content_sub']); ?></p>
                        <div class="mudrava-pickers">
                            <section class="mudrava-picker" aria-labelledby="mudrava-db-h">
                                <header class="mudrava-picker-head">
                                    <h4 id="mudrava-db-h"><?php echo esc_html($l['db_h']); ?></h4>
                                    <span class="mudrava-picker-count" id="mudrava-db-count"></span>
                                    <button type="button" class="mudrava-picker-refresh" data-refresh="db"
                                            aria-label="<?php echo esc_attr($l['refresh']); ?>">
                                        &#8635;
                                    </button>
                                </header>
                                <label class="mudrava-checkline mudrava-picker-allrow">
                                    <input type="checkbox" id="mudrava-db-all" checked>
                                    <?php echo esc_html($l['select_all']); ?>
                                </label>
                                <input type="search" class="mudrava-filter" id="mudrava-db-filter"
                                       placeholder="<?php echo esc_attr($l['filter_db_ph']); ?>"
                                       aria-label="<?php echo esc_attr($l['filter_db_ph']); ?>">
                                <div class="mudrava-picker-list" id="mudrava-db-list"
                                     role="group" aria-label="<?php echo esc_attr($l['db_h']); ?>"></div>
                            </section>
                            <section class="mudrava-picker" aria-labelledby="mudrava-files-h">
                                <header class="mudrava-picker-head">
                                    <h4 id="mudrava-files-h"><?php echo esc_html($l['files_h']); ?></h4>
                                    <span class="mudrava-picker-count" id="mudrava-files-count"></span>
                                    <button type="button" class="mudrava-picker-refresh" data-refresh="files"
                                            aria-label="<?php echo esc_attr($l['refresh']); ?>">
                                        &#8635;
                                    </button>
                                </header>
                                <label class="mudrava-checkline mudrava-picker-allrow">
                                    <input type="checkbox" id="mudrava-files-all" checked>
                                    <?php echo esc_html($l['select_all']); ?>
                                </label>
                                <input type="search" class="mudrava-filter" id="mudrava-files-filter"
                                       placeholder="<?php echo esc_attr($l['filter_fs_ph']); ?>"
                                       aria-label="<?php echo esc_attr($l['filter_fs_ph']); ?>">
                                <div class="mudrava-picker-list" id="mudrava-files-list"
                                     role="group" aria-label="<?php echo esc_attr($l['files_h']); ?>"></div>
                                <p class="mudrava-picker-foot"><?php echo esc_html($l['core_note']); ?></p>
                            </section>
                        </div>
                        <div class="mudrava-field">
                            <label for="mudrava-exclude-dirs" class="mudrava-lab">
                                <?php echo esc_html($l['extra_dirs']); ?>
                                <span class="mudrava-more" tabindex="0">i</span>
                                <span id="mudrava-extra-dirs-tip" class="mudrava-tip" role="tooltip">
                                    <?php echo esc_html($l['extra_dirs_tip']); ?>
                                </span>
                            </label>
                            <input type="text" id="mudrava-exclude-dirs"
                                   aria-describedby="mudrava-extra-dirs-tip"
                                   placeholder="<?php echo esc_attr($l['extra_dirs_ph']); ?>">
                        </div>
                    </div>
                </div>
                <div class="mudrava-field">
                    <label for="mudrava-split-size" class="mudrava-lab">
                        <?php echo esc_html($l['split']); ?>
                        <span class="mudrava-more" tabindex="0">i</span>
                        <span id="mudrava-split-hint" class="mudrava-tip" role="tooltip"><?php echo esc_html($l['split_hint']); ?></span>
                    </label>
                    <div class="mudrava-split-row">
                        <div class="mudrava-chips" role="group" aria-label="<?php echo esc_attr($l['split_presets']); ?>">
                            <button type="button" class="mudrava-chip is-active" data-split="auto">
                                <?php echo esc_html($l['auto']); ?>
                            </button>
                            <button type="button" class="mudrava-chip" data-split="0">
                                <?php echo esc_html($l['off']); ?>
                            </button>
                            <button type="button" class="mudrava-chip" data-split="2048">2 GB</button>
                            <button type="button" class="mudrava-chip" data-split="4096">4 GB</button>
                            <button type="button" class="mudrava-chip" data-split="custom">
                                <?php echo esc_html($l['custom']); ?>
                            </button>
                        </div>
                        <span class="mudrava-customwrap" hidden id="mudrava-split-custom-wrap">
                            <input type="number" id="mudrava-split-size" min="1" step="1" value="2"
                                   aria-describedby="mudrava-split-hint">
                            <select id="mudrava-split-unit"
                                    aria-label="<?php echo esc_attr($l['unit_label']); ?>">
                                <option value="1048576" selected>MB</option>
                                <option value="1073741824">GB</option>
                            </select>
                        </span>
                    </div>
                </div>
                <button class="mudrava-btn mudrava-btn-primary" id="mudrava-start-export">
                    <?php echo esc_html($l['create']); ?>
                </button>
            </div>
            <div id="mudrava-panel-restore" class="mudrava-panel mudrava-card"
                 role="tabpanel" aria-labelledby="mudrava-tab-restore"
                 <?php echo $tab_slug === 'restore' ? '' : 'hidden'; ?>>
                <h2><?php echo esc_html($l['import_h']); ?></h2>
                <p class="mudrava-sub"><?php echo esc_html($l['import_sub']); ?></p>
                <div id="mudrava-dropzone" class="mudrava-dropzone" role="button" tabindex="0"
                     aria-label="<?php echo esc_attr($l['drop_aria']); ?>">
                    <span class="mudrava-drop-icon">&#8682;</span>
                    <strong><?php echo esc_html($l['drop']); ?></strong>
                    <span class="mudrava-drop-sub"><?php echo esc_html($l['drop_sub']); ?></span>
                </div>
                <input type="file" id="mudrava-file" multiple accept=".mudrava,.mudrava.part*">
                <p id="mudrava-file-names" class="description" aria-live="polite"></p>
                <?php // Honest refusal: why the picked files are not a restorable set (missing parts etc.). ?>
                <div id="mudrava-import-hold" class="mudrava-notice mudrava-notice-danger" hidden aria-live="assertive"></div>
                <div id="mudrava-restore-point" class="mudrava-notice" hidden></div>
                <?php
                // Nothing to type here: the source URL is read from the
                // archive and the destination is always this site, so the
                // operator never retypes URLs. The detected pair is shown
                // inside the progress dialog at restore time, and the
                // password field appears only when the uploaded archive
                // is actually encrypted (read from its header locally).
                ?>
                <div id="mudrava-import-pw" class="mudrava-field" hidden>
                    <label for="mudrava-import-password"><?php echo esc_html($l['arc_pw']); ?></label>
                    <p class="description"><?php echo esc_html($l['pwNeeded']); ?></p>
                    <div class="mudrava-pwwrap">
                        <input type="password" id="mudrava-import-password" autocomplete="off">
                        <button type="button" class="mudrava-eye" data-eye="mudrava-import-password"
                                aria-pressed="false" aria-label="<?php echo esc_attr($l['show_pw']); ?>">
                            <svg class="mv-eye-open" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="mv-eye-off" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <?php
                // The destructive controls stay invisible until there is
                // actually something to restore: the JS gate reveals this
                // block only once archive files are picked, and shows the
                // acknowledgement row only when the disk state is unsafe.
                ?>
                <div id="mudrava-restore-gate" hidden>
                    <label class="mudrava-check" id="mudrava-unsafe-row" hidden>
                        <input type="checkbox" id="mudrava-proceed-unsafe">
                        <?php echo esc_html($l['unsafe']); ?>
                    </label>
                    <button class="mudrava-btn mudrava-btn-danger" id="mudrava-start-restore" disabled>
                        <?php echo esc_html($l['restore']); ?>
                    </button>
                </div>
            </div>
            <div id="mudrava-panel-archives" class="mudrava-panel mudrava-card"
                 role="tabpanel" aria-labelledby="mudrava-tab-archives"
                 <?php echo $tab_slug === 'archives' ? '' : 'hidden'; ?>>
                <h2><?php echo esc_html($l['archives']); ?></h2>
                <div id="mudrava-archive-list" class="mudrava-archives"></div>
                <?php // Filled by admin.js: prev / page numbers / next. ?>
                <nav id="mudrava-archives-pager" class="mudrava-pager"
                     aria-label="<?php echo esc_attr($l['archives_tab']); ?>" hidden></nav>
            </div>
            <?php foreach (array_keys($addon_tabs) as $addon_slug) : ?>
                <div id="mudrava-panel-<?php echo esc_attr($addon_slug); ?>" class="mudrava-panel mudrava-card"
                     role="tabpanel" aria-labelledby="mudrava-tab-<?php echo esc_attr($addon_slug); ?>"
                     <?php echo $tab_slug === $addon_slug ? '' : 'hidden'; ?>>
                    <?php
                    if (!\Mudrava\Migration\Integration\AdminPanels::render($addon_slug) && class_exists(ProPreview::class)) {
                        ProPreview::render($addon_slug);
                    }
                    ?>
                </div>
            <?php endforeach; ?>
            <div id="mudrava-panel-environment" class="mudrava-panel mudrava-card"
                 role="tabpanel" aria-labelledby="mudrava-tab-environment"
                 <?php echo $tab_slug === 'environment' ? '' : 'hidden'; ?>>
                <div id="mudrava-preflight" class="mudrava-env">
                    <h2><?php echo esc_html($l['env']); ?></h2>
                    <ul class="mudrava-checks">
                        <?php foreach ($preflight['checks'] as $check) : ?>
                            <?php
                            $help = $l['check_help'][$check['code']] ?? '';
                            ?>
                            <li class="<?php echo $check['ok'] ? 'ok' : 'fail'; ?>"
                                <?php if ($help !== '') : ?>
                                    tabindex="0"
                                    aria-describedby="mudrava-tip-<?php echo esc_attr($check['code']); ?>"
                                <?php endif; ?>
                            >
                                <span class="mudrava-check-icon" aria-hidden="true"></span>
                                <strong><?php echo esc_html($check['code']); ?></strong>
                                <span class="mudrava-check-detail"><?php echo esc_html($check['detail']); ?></span>
                                <?php if ($help !== '') : ?>
                                    <span class="mudrava-more" aria-hidden="true">i</span>
                                    <span id="mudrava-tip-<?php echo esc_attr($check['code']); ?>"
                                          class="mudrava-tip" role="tooltip"><?php echo esc_html($help); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php foreach ($preflight['warnings'] as $warning) : ?>
                        <p class="mudrava-warn"><?php echo esc_html($warning); ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
            // The progress dialog is a blocking modal: while a migration
            // runs it covers the page, tab switching is disabled in JS,
            // and beforeunload guards accidental reloads. The Cancel
            // button is wired in admin.js (it only appears for exports,
            // where a cooperative cancel exists).
            ?>
            <footer class="mudrava-footer">
                <a href="https://mudrava.com/en/" target="_blank" rel="noopener noreferrer"
                   aria-label="<?php echo esc_attr__('Visit MUDRAVA', 'mudrava-migration-backup'); ?>">
                    <img src="<?php echo esc_url(MUDRAVA_MB_PLUGIN_URL . 'assets/studio-wordmark.svg'); ?>"
                         width="120" height="24" alt="MUDRAVA">
                </a>
            </footer>
            <div id="mudrava-progress" class="mudrava-modal" hidden>
                <div class="mudrava-modal-backdrop"></div>
                <div class="mudrava-modal-card mudrava-progress" role="dialog"
                     aria-modal="true" aria-labelledby="mudrava-phase" aria-live="polite"
                     tabindex="-1">
                    <div class="mudrava-prog-head">
                        <strong id="mudrava-phase"></strong>
                        <span id="mudrava-rate"></span>
                    </div>
                    <div class="mudrava-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <div id="mudrava-bar-fill"></div>
                    </div>
                    <p id="mudrava-status" role="status"></p>
                    <div id="mudrava-resume-controls" class="mudrava-resume-controls" hidden>
                        <label for="mudrava-resume-password">
                            <?php echo esc_html__('Enter the archive password to continue', 'mudrava-migration-backup'); ?>
                        </label>
                        <div class="mudrava-resume-row">
                            <input id="mudrava-resume-password" type="password" autocomplete="off">
                            <button type="button" id="mudrava-resume-job" class="mudrava-btn mudrava-btn-primary">
                                <?php echo esc_html__('Continue', 'mudrava-migration-backup'); ?>
                            </button>
                        </div>
                    </div>
                    <p id="mudrava-rewrite-line" class="mudrava-rewrite-line" hidden>
                        <span class="mudrava-rewrite-label"><?php echo esc_html($l['rewriting']); ?></span>
                        <code id="mudrava-rewrite-from-label"></code>
                        <span aria-hidden="true">&rarr;</span>
                        <code id="mudrava-rewrite-to-label"></code>
                    </p>
                    <p id="mudrava-close-note" class="mudrava-close-note" hidden>
                        <?php echo esc_html($l['close_note']); ?>
                    </p>
                    <div class="mudrava-modal-foot">
                        <button type="button" id="mudrava-cancel-job" class="mudrava-btn mudrava-btn-ghost" hidden>
                            <?php echo esc_html($l['cancel']); ?>
                        </button>
                        <button type="button" id="mudrava-close-modal" class="mudrava-btn" hidden>
                            <?php echo esc_html($l['close']); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
