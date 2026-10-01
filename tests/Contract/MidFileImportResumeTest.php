<?php

/**
 * Importing one file must respect the tick budget across PHP requests.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Contract;

use Mudrava\Migration\Archive\Checkpoint;
use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Filesystem\WpFileTarget;
use Mudrava\Migration\Migration\Importer;
use Mudrava\Migration\Tests\Support\RecordingDatabaseTarget;
use PHPUnit\Framework\TestCase;

final class MidFileImportResumeTest extends TestCase
{
    /** @return array{string,string,string} */
    private function archive(): array
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/mid-file-' . uniqid();
        mkdir($root, 0777, true);
        mkdir($root . '/destination', 0777, true);
        $archive = $root . '/source.mudrava';
        $data = random_bytes(1024 * 1024);
        $out = fopen($archive, 'wb');
        $header = new Header(Header::CONTAINER_FORMAT, 0, random_bytes(16), '1.0.0-test', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $writer = new FrameWriter($out, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, ['site_url' => 'https://old.example']);
        $writer->writeJson(FrameType::FILE_METADATA, [
            'path' => 'uploads/large.bin', 'type' => 'file', 'mode' => 0644, 'mtime' => 0,
        ]);
        $writer->writeFrame(FrameType::FILE_DATA, $data);
        $writer->writeFrame(FrameType::FILE_DATA, $data);
        $writer->writeFrame(FrameType::FILE_DATA, $data);
        $writer->finalize();
        fclose($out);
        return [$root, $archive, $data];
    }

    public function testEachDataFrameCanBeCheckpointedAndResumed(): void
    {
        [$root, $archive, $data] = $this->archive();
        $checkpoint = null;
        $ticks = 0;
        do {
            $in = fopen($archive, 'rb');
            $importer = new Importer(
                new FrameReader($in, new DeflateCodec()),
                new RecordingDatabaseTarget(),
                new WpFileTarget($root . '/destination'),
                [],
                $checkpoint
            );
            $state = $importer->step(microtime(true) - 1);
            $ticks++;
            if ($state !== Importer::STATE_DONE) {
                $saved = $importer->checkpointData();
                $this->assertTrue($saved['cursor']->writing);
                $this->assertSame($ticks * strlen($data), $saved['cursor']->bytes_written);
                $this->assertFileDoesNotExist($root . '/destination/uploads/large.bin');
                $checkpoint = Checkpoint::fromArray($saved);
            }
            fclose($in);
        } while ($state !== Importer::STATE_DONE && $ticks < 6);

        $this->assertSame(4, $ticks);
        $this->assertSame(1, $importer->restoredFiles());
        $this->assertSame(str_repeat($data, 3), file_get_contents($root . '/destination/uploads/large.bin'));
    }

    public function testChangedPartialFileIsRejectedBeforeAppending(): void
    {
        [$root, $archive] = $this->archive();
        $in = fopen($archive, 'rb');
        $importer = new Importer(
            new FrameReader($in, new DeflateCodec()),
            new RecordingDatabaseTarget(),
            new WpFileTarget($root . '/destination')
        );
        $this->assertSame(Importer::STATE_RESTORE, $importer->step(microtime(true) - 1));
        $checkpoint = Checkpoint::fromArray($importer->checkpointData());
        fclose($in);

        $partial = $root . '/destination/uploads/large.bin.mudrava-tmp';
        $handle = fopen($partial, 'c+b');
        ftruncate($handle, 64);
        fclose($handle);
        $in = fopen($archive, 'rb');
        try {
            new Importer(
                new FrameReader($in, new DeflateCodec()),
                new RecordingDatabaseTarget(),
                new WpFileTarget($root . '/destination'),
                [],
                $checkpoint
            );
            $this->fail('changed partial file must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_PATH_UNSAFE', $e->getMessage());
            $this->assertFileDoesNotExist($root . '/destination/uploads/large.bin');
        } finally {
            fclose($in);
        }
    }

    public function testUncommittedSuffixIsDiscardedBeforeResume(): void
    {
        [$root, $archive, $data] = $this->archive();
        $in = fopen($archive, 'rb');
        $importer = new Importer(
            new FrameReader($in, new DeflateCodec()),
            new RecordingDatabaseTarget(),
            new WpFileTarget($root . '/destination')
        );
        $this->assertSame(Importer::STATE_RESTORE, $importer->step(microtime(true) - 1));
        $checkpoint = Checkpoint::fromArray($importer->checkpointData());
        fclose($in);

        file_put_contents($root . '/destination/uploads/large.bin.mudrava-tmp', 'uncommitted', FILE_APPEND);
        $in = fopen($archive, 'rb');
        $resumed = new Importer(
            new FrameReader($in, new DeflateCodec()),
            new RecordingDatabaseTarget(),
            new WpFileTarget($root . '/destination'),
            [],
            $checkpoint
        );
        $this->assertSame(Importer::STATE_DONE, $resumed->step());
        fclose($in);
        $this->assertSame(str_repeat($data, 3), file_get_contents($root . '/destination/uploads/large.bin'));
    }
}
