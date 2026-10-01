<?php
/**
 * Regenerate the golden archive fixture. Run MANUALLY only when the format
 * is intentionally changed (with an ADR + container_format bump):
 *
 *   php tests/GoldenArchives/regenerate.php
 *
 * The fixture is deterministic: fixed UUID/salt/nonce, no compression, no
 * encryption, fixed timestamps. Any byte diff = format change.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Database\RowCodec;

$uuid = hex2bin('00112233445566778899aabbccddeeff');
$salt = hex2bin('000102030405060708090a0b0c0d0e0f');
$nonce = hex2bin('f0f1f2f3f4f5f6f7');

$header = new Header(
    Header::CONTAINER_FORMAT,
    0,
    $uuid,
    '1.0.0',
    0,
    0,
    0,
    $salt,
    $nonce
);

$path = __DIR__ . '/golden_v1.golden';
$stream = fopen($path, 'wb');
$writer = new FrameWriter($stream, $header, new DeflateCodec(), null);

$writer->writeJson(FrameType::SITE_METADATA, [
    'site_url'   => 'https://golden.example',
    'home_url'   => 'https://golden.example',
    'wp_version' => '6.5',
    'charset'    => 'UTF-8',
    'generated'  => 'golden-fixture',
]);
$writer->writeJson(FrameType::DB_TABLE_BEGIN, [
    'table'   => 'wp_options',
    'columns' => ['option_id', 'option_name', 'option_value'],
    'pk'      => 'option_id',
    'engine'  => 'InnoDB',
    'charset' => 'utf8mb4',
]);
$writer->writeFrame(FrameType::DB_SCHEMA, 'CREATE TABLE `wp_options` (`option_id` TEXT, `option_name` TEXT, `option_value` TEXT)');
$writer->writeFrame(FrameType::DB_ROWS, RowCodec::encode([
    ['1', 'home', 'https://golden.example'],
    ['2', 'blob', "\x00\x01\xff binary"],
]));
$writer->writeJson(FrameType::DB_TABLE_END, ['table' => 'wp_options', 'row_count' => 2]);
$writer->writeJson(FrameType::FILE_METADATA, [
    'path'  => 'index.php',
    'size'  => 16,
    'mtime' => 1700000000,
    'mode'  => 420,
    'type'  => 'file',
    'target' => null,
]);
$writer->writeFrame(FrameType::FILE_DATA, "<?php // golden\n");
$writer->finalize([
    'file_count'  => 1,
    'row_count'   => 2,
    'table_count' => 1,
    'created_at'  => 1700000000, // frozen for determinism
]);

fclose($stream);
clearstatcache();
echo 'wrote ', $path, ' (', filesize($path), " bytes)\n";
echo 'sha256: ', hash_file('sha256', $path), "\n";
