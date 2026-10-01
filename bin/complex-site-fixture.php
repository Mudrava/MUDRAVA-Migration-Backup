<?php

/**
 * Repeatable Elementor, Polylang and ACF migration fixture for local labs.
 * Run with WP-CLI eval-file and MUDRAVA_FIXTURE_MODE=create or verify.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

if (!defined('ABSPATH') || strpos((string) home_url(), 'http://localhost:') !== 0) {
    throw new RuntimeException('This fixture only runs on a local WordPress lab.');
}

$mode = getenv('MUDRAVA_FIXTURE_MODE');
if ($mode !== 'create' && $mode !== 'verify') {
    throw new RuntimeException('Set MUDRAVA_FIXTURE_MODE=create or verify.');
}

foreach (['elementor/elementor.php', 'polylang/polylang.php', 'advanced-custom-fields/acf.php'] as $plugin) {
    if (!is_plugin_active($plugin)) {
        throw new RuntimeException('Required plugin inactive: ' . $plugin);
    }
}

$option = 'mudrava_complex_fixture_v1';
$oldUrl = 'http://localhost:8083';
$expectedUrl = rtrim((string) home_url(), '/');

if ($mode === 'create') {
    if ($expectedUrl !== $oldUrl) {
        throw new RuntimeException('Create mode requires the isolated source at localhost:8083.');
    }
    if (get_option($option) !== false) {
        throw new RuntimeException('Fixture already exists.');
    }

    wp_set_current_user(1);
    foreach (
        [
        ['locale' => 'en_US', 'name' => 'English', 'slug' => 'en', 'flag_code' => 'us'],
        ['locale' => 'ka_GE', 'name' => 'ქართული', 'slug' => 'ka', 'flag_code' => 'ge'],
        ] as $language
    ) {
        $request = new WP_REST_Request('POST', '/pll/v1/languages');
        $request->set_body_params($language);
        $response = rest_do_request($request);
        if ($response->is_error()) {
            throw new RuntimeException('Polylang language create: ' . wp_json_encode($response->get_data()));
        }
    }

    $english = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_name' => 'mudrava-complex-en',
        'post_title' => 'MUDRAVA complex EN',
        'post_content' => 'Elementor, ACF and Polylang migration fixture.',
    ], true);
    $georgian = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_name' => 'mudrava-complex-ka',
        'post_title' => 'MUDRAVA რთული KA',
        'post_content' => 'ქართული ტექსტი 😀 და სხვა ენები.',
    ], true);
    if (is_wp_error($english) || is_wp_error($georgian)) {
        throw new RuntimeException('Cannot create translated fixture pages.');
    }
    pll_set_post_language($english, 'en');
    pll_set_post_language($georgian, 'ka');
    pll_save_post_translations(['en' => $english, 'ka' => $georgian]);
    flush_rewrite_rules(false);

    $group = acf_update_field_group([
        'key' => 'group_mudrava_complex_v1',
        'title' => 'MUDRAVA complex migration fixture',
        'active' => true,
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
    ]);
    if (empty($group['ID'])) {
        throw new RuntimeException('ACF field group was not saved.');
    }
    acf_update_field([
        'key' => 'field_mudrava_complex_link',
        'label' => 'Destination link',
        'name' => 'mudrava_complex_link',
        'type' => 'url',
        'parent' => $group['ID'],
    ]);
    acf_update_field([
        'key' => 'field_mudrava_complex_unicode',
        'label' => 'Unicode text',
        'name' => 'mudrava_complex_unicode',
        'type' => 'text',
        'parent' => $group['ID'],
    ]);
    update_field('field_mudrava_complex_link', $oldUrl . '/mudrava-complex-ka/', $english);
    update_field('field_mudrava_complex_unicode', 'ქართული 😀 日本語', $english);

    $png = hex2bin(
        '89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c489'
        . '0000000d49444154789c63f8cfc0f01f000580023f49c2ff9f0000000049454e44ae426082'
    );
    if ($png === false) {
        throw new RuntimeException('Invalid fixture image.');
    }
    $upload = wp_upload_bits('mudrava-complex-v1.png', null, $png);
    if (!empty($upload['error'])) {
        throw new RuntimeException('Cannot create fixture media: ' . $upload['error']);
    }
    $attachment = wp_insert_attachment([
        'post_mime_type' => 'image/png',
        'post_title' => 'MUDRAVA complex fixture image',
        'post_status' => 'inherit',
        'guid' => $upload['url'],
    ], $upload['file'], $english, true);
    if (is_wp_error($attachment)) {
        throw new RuntimeException('Cannot create fixture attachment.');
    }
    update_post_meta($attachment, '_wp_attachment_metadata', ['width' => 1, 'height' => 1, 'file' => _wp_relative_upload_path($upload['file'])]);

    $elementor = [[
        'id' => 'a1b2c3d4',
        'elType' => 'container',
        'isInner' => false,
        'settings' => [],
        'elements' => [
            [
                'id' => 'a1b2c3d5', 'elType' => 'widget', 'widgetType' => 'heading',
                'settings' => ['title' => 'Complex migration layout ქართული 😀'], 'elements' => [],
            ],
            [
                'id' => 'a1b2c3d6', 'elType' => 'widget', 'widgetType' => 'button',
                'settings' => ['text' => 'Translated page', 'link' => ['url' => $oldUrl . '/mudrava-complex-ka/']],
                'elements' => [],
            ],
            [
                'id' => 'a1b2c3d7', 'elType' => 'widget', 'widgetType' => 'image',
                'settings' => ['image' => ['id' => $attachment, 'url' => $upload['url']]],
                'elements' => [],
            ],
        ],
    ]];
    update_post_meta($english, '_elementor_edit_mode', 'builder');
    update_post_meta($english, '_elementor_version', '4.3.2');
    update_post_meta($english, '_elementor_data', wp_slash((string) wp_json_encode($elementor)));

    add_option($option, ['en' => $english, 'ka' => $georgian, 'attachment' => $attachment], '', 'no');
}

$ids = get_option($option);
if (!is_array($ids) || !isset($ids['en'], $ids['ka'], $ids['attachment'])) {
    throw new RuntimeException('Fixture IDs missing.');
}
$en = (int) $ids['en'];
$ka = (int) $ids['ka'];
$attachment = (int) $ids['attachment'];
$layout = json_decode((string) get_post_meta($en, '_elementor_data', true), true);
$translations = pll_get_post_translations($en);
$checks = [
    'languages' => array_values(array_intersect(['en', 'ka'], pll_languages_list())),
    'translations' => $translations,
    'acf_link' => get_field('mudrava_complex_link', $en),
    'acf_unicode' => get_field('mudrava_complex_unicode', $en),
    'elementor_json_valid' => is_array($layout),
    'elementor_link' => $layout[0]['elements'][1]['settings']['link']['url'] ?? null,
    'elementor_image_url' => $layout[0]['elements'][2]['settings']['image']['url'] ?? null,
    'media_url' => wp_get_attachment_url($attachment),
    'media_file_exists' => is_file((string) get_attached_file($attachment)),
    'en_permalink' => get_permalink($en),
    'ka_permalink' => get_permalink($ka),
];
if (
    count($checks['languages']) !== 2
    || (int) ($translations['en'] ?? 0) !== $en
    || (int) ($translations['ka'] ?? 0) !== $ka
    || $checks['acf_link'] !== $expectedUrl . '/mudrava-complex-ka/'
    || $checks['acf_unicode'] !== 'ქართული 😀 日本語'
    || !$checks['elementor_json_valid']
    || $checks['elementor_link'] !== $expectedUrl . '/mudrava-complex-ka/'
    || strpos((string) $checks['elementor_image_url'], $expectedUrl . '/') !== 0
    || strpos((string) $checks['media_url'], $expectedUrl . '/') !== 0
    || !$checks['media_file_exists']
) {
    throw new RuntimeException('Complex site fixture mismatch: ' . wp_json_encode($checks));
}

echo wp_json_encode(['home' => $expectedUrl, 'ids' => $ids, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
