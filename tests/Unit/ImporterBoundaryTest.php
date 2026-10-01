<?php

/**
 * Importer state and malformed-stream boundary contracts.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Database\RowCodec;
use Mudrava\Migration\Migration\Importer;
use Mudrava\Migration\Tests\Support\LocalFileTarget;
use Mudrava\Migration\Tests\Support\RecordingDatabaseTarget;
use PHPUnit\Framework\TestCase;

final class ImporterBoundaryTest extends TestCase
{
    /** @var list<resource> */
    private $streams = [];

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        parent::tearDown();
    }

    /** @param list<array{0:int,1:string}> $frames */
    private function importer(array $frames, bool $finalize = true, array $options = []): Importer
    {
        $stream = fopen('php://temp', 'w+b');
        $header = new Header(1, 0, random_bytes(16), 'test', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        foreach ($frames as [$type, $payload]) {
            $writer->writeFrame($type, $payload);
        }
        if ($finalize) {
            $writer->finalize();
        } else {
            $writer->flush();
            fwrite($stream, Header::MAGIC_TAIL);
        }
        rewind($stream);
        $this->streams[] = $stream;
        return new Importer(
            new FrameReader($stream, new DeflateCodec()),
            new RecordingDatabaseTarget(),
            new LocalFileTarget($GLOBALS['MUDRAVA_TEST_TMP'] . '/import-boundary-' . bin2hex(random_bytes(4))),
            $options
        );
    }

    public function testRejectsInvalidTargetPrefixBeforeReadingArchive(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid target prefix');
        $this->importer([], true, ['target_prefix' => 'wp-unsafe']);
    }

    public function testRejectsInvalidSourcePrefixFromSiteMetadata(): void
    {
        $importer = $this->importer([
            [FrameType::SITE_METADATA, '{"db_prefix":"wp-bad"}'],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid source prefix');
        $importer->step();
    }

    public function testRejectsSchemaForDifferentTable(): void
    {
        $importer = $this->importer([
            [FrameType::SITE_METADATA, '{"db_prefix":"wp_"}'],
            [FrameType::DB_TABLE_BEGIN, '{"table":"wp_one","columns":["id"]}'],
            [FrameType::DB_SCHEMA, 'CREATE TABLE `wp_two` (`id` bigint)'],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('schema table mismatch');
        $importer->step();
    }

    public function testCleanTailWithoutManifestCannotDeclareSuccess(): void
    {
        $importer = $this->importer([
            [FrameType::SITE_METADATA, '{"site_url":"https://source.test"}'],
        ], false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no manifest before EOF');
        $importer->step();
    }

    public function testCompletedImporterExposesStableStateAndMetadata(): void
    {
        $importer = $this->importer([
            [FrameType::SITE_METADATA, '{"site_url":"https://source.test","db_prefix":"wp_"}'],
        ]);

        $this->assertSame(Importer::STATE_DONE, $importer->step());
        $this->assertSame(Importer::STATE_DONE, $importer->step());
        $this->assertSame(Importer::STATE_DONE, $importer->state());
        $this->assertSame(Importer::PHASE_DB, $importer->phase());
        $this->assertSame('https://source.test', $importer->siteMeta()['site_url']);
        $this->assertSame(0, $importer->restoredFiles());
        $this->assertSame(0, $importer->restoredRows());
        $this->assertSame(0, $importer->transformFailures());
        $this->assertNull($importer->resolvedRewrite());
    }

    public function testImporterToleratesVerifierFilteredOrphanDatabaseFrames(): void
    {
        $schemaOnly = $this->importer([
            [FrameType::SITE_METADATA, '{}'],
            [FrameType::DB_SCHEMA, 'CREATE TABLE `wp_demo` (`id` bigint)'],
        ]);
        $this->assertSame(Importer::STATE_DONE, $schemaOnly->step());

        $rowsOnly = $this->importer([
            [FrameType::SITE_METADATA, '{}'],
            [FrameType::DB_ROWS, RowCodec::encode([['1']])],
        ]);
        $this->assertSame(Importer::STATE_DONE, $rowsOnly->step());
    }

    public function testMissingColumnListFallsBackToEmptyList(): void
    {
        $importer = $this->importer([
            [FrameType::SITE_METADATA, '{}'],
            [FrameType::DB_TABLE_BEGIN, '{"table":"wp_demo"}'],
            [FrameType::DB_SCHEMA, 'CREATE TABLE `wp_demo` (`id` bigint)'],
            [FrameType::DB_TABLE_END, '{}'],
        ]);
        $this->assertSame(Importer::STATE_DONE, $importer->step());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testPluginDirectoryOutsideRestoreRootIsTreatedAsOrdinaryPath(): void
    {
        define('MUDRAVA_MB_PLUGIN_DIR', $GLOBALS['MUDRAVA_TEST_TMP'] . '/outside-plugin');
        $importer = $this->importer([
            [FrameType::SITE_METADATA, '{}'],
            [FrameType::FILE_METADATA, '{"path":"ordinary.txt","mode":420,"mtime":0}'],
            [FrameType::FILE_DATA, 'ordinary'],
        ]);
        $this->assertSame(Importer::STATE_DONE, $importer->step());
        $this->assertSame(1, $importer->restoredFiles());
    }
}
