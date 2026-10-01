<?php
/**
 * PK-less offset paging regression (validation BUG-04).
 *
 * A table without a single primary key is paged with LIMIT/OFFSET, which
 * has no stable row identity. On a live site a DELETE before the window
 * slides the window and the export silently skips rows that exist both
 * before and after the export - a "verified" archive with a gap the
 * manifest count cannot see. The engine must instead re-read the last
 * archived row before every offset batch and fail loudly when the anchor
 * moved.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Contract;

use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Database\RowCodec;
use Mudrava\Migration\Migration\Exporter;
use Mudrava\Migration\Migration\ExportTick;
use Mudrava\Migration\Tests\Support\ArrayFileInventory;
use Mudrava\Migration\Tests\Support\MutableDatabaseSource;
use PHPUnit\Framework\TestCase;

final class NoPrimaryKeyShiftTest extends TestCase
{
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = $GLOBALS['MUDRAVA_TEST_TMP'] . '/nopk-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    /** @return list<list<?string>> */
    private function makeRows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [(string) $i, 'value ' . $i];
        }
        return $rows;
    }

    /**
     * @param list<list<?string>> $rows
     * @param callable|null       $onBatch
     */
    private function runExport(array &$rows, ?callable $onBatch): string
    {
        $archive = $this->tmp . '/site.mudrava';
        $out = fopen($archive, 'wb');
        $this->assertNotFalse($out);
        $source = new MutableDatabaseSource(['id', 'value'], $rows, $onBatch);
        $writer = new FrameWriter(
            $out,
            new Header(1, 0, random_bytes(16), '1.0.0-test', 0, 0, 0, random_bytes(16), random_bytes(8)),
            new DeflateCodec(),
            null
        );
        $exporter = new Exporter($writer, $source, new ArrayFileInventory([]), []);
        $exporter->writeSiteMetadata();
        $guard = 0;
        while ($exporter->step() !== Exporter::STATE_DONE) {
            if (++$guard > 100) {
                $this->fail('export did not terminate');
            }
        }
        $exporter->finalize();
        fclose($out);
        return $archive;
    }

    /** @return list<string> ids archived, in archive order */
    private function archivedIds(string $archive): array
    {
        $in = fopen($archive, 'rb');
        $this->assertNotFalse($in);
        $reader = new FrameReader($in, new DeflateCodec());
        $ids = [];
        while (($frame = $reader->next()) !== null) {
            if ($frame->type !== FrameType::DB_ROWS) {
                continue;
            }
            foreach (RowCodec::decode($frame->payload) as $row) {
                $ids[] = (string) $row[0];
            }
        }
        fclose($in);
        return $ids;
    }

    /** Run the plugin's own full verification over the finished archive. */
    private function verifies(string $archive): bool
    {
        $in = fopen($archive, 'rb');
        $this->assertNotFalse($in);
        $reader = new FrameReader($in, new DeflateCodec());
        $reader->readHeader();
        $verifier = new \Mudrava\Migration\Migration\ArchiveVerifier($reader);
        $done = $verifier->step(microtime(true) + 30.0);
        fclose($in);
        return $done;
    }

    public function testDeleteBeforeOffsetWindowFailsLoudlyInsteadOfSkipping(): void
    {
        $rows = $this->makeRows(1200);
        // After the first 500-row batch, delete 60 rows BEFORE the window
        // (ids 1-60). The window would slide by 60 and skip ids 501-560,
        // which exist both before and after the export.
        $onBatch = static function () use (&$rows): void {
            $rows = array_values(array_filter(
                $rows,
                static function (array $row): bool {
                    return (int) $row[0] > 60;
                }
            ));
        };

        try {
            $this->runExport($rows, $onBatch);
            $this->fail('a sliding PK-less window must abort the export');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('MUDRAVA_DB_TABLE_CHANGED', $error->getMessage());
        }
    }

    public function testStablePkLessTableExportsEveryRow(): void
    {
        $rows = $this->makeRows(1200);
        $archive = $this->runExport($rows, null);
        $ids = $this->archivedIds($archive);
        $this->assertCount(1200, $ids);
        $this->assertSame('1', $ids[0]);
        $this->assertSame('1200', $ids[1199]);
        // No gap anywhere: the archived set equals the live set exactly.
        $this->assertSame(range(1, 1200), array_map('intval', $ids));
    }

    /**
     * KNOWN LIMITATION (pinned, not fixed): a reorder across the read
     * window leaves the anchor row untouched, so the single-row anchor
     * check cannot see it. Swapping an already-read row with the first
     * unread row makes the window re-read the archived row (duplicate in
     * the archive) and skip the swapped-out row (missing although the
     * live table still has it). The manifest count still matches what
     * was read, so the archive verifies. Reproduced live 2026-09-30
     * (a4-nopk-reorder evidence: row 101 twice, row 501 absent,
     * verified:true). Only a primary key makes the table immune;
     * docs/large-sites.md states this exact guarantee.
     */
    public function testReorderAcrossWindowSkipsAndDuplicatesKnownLimitation(): void
    {
        $rows = $this->makeRows(1200);
        // After the first 500-row batch, swap the row at offset 100
        // (already archived) with the row at offset 500 (first unread).
        // The anchor (offset 499) is untouched, so the guard passes.
        $onBatch = static function () use (&$rows): void {
            $tmp = $rows[100];
            $rows[100] = $rows[500];
            $rows[500] = $tmp;
        };

        $archive = $this->runExport($rows, $onBatch);
        $ids = array_map('intval', $this->archivedIds($archive));

        // The archived multiset: id 101 twice (re-read through the window)
        // and id 501 absent (swapped into already-read territory).
        $counts = array_count_values($ids);
        $this->assertSame(
            2,
            (int) ($counts[101] ?? 0),
            'known limitation: the swapped-in archived row is emitted twice'
        );
        $this->assertArrayNotHasKey(
            501,
            $counts,
            'known limitation: the swapped-out unread row is silently skipped'
        );
        // The live table still contains id 501 exactly once - the archive
        // disagrees with reality and still verifies (see below).
        $live = array_values(array_filter(
            $rows,
            static function (array $row): bool {
                return $row[0] === '501';
            }
        ));
        $this->assertCount(1, $live, 'the skipped row still exists on the live table');
        $this->assertTrue(
            $this->verifies($archive),
            'the manifest count matches what was read, so the archive verifies anyway'
        );
    }

    /**
     * KNOWN LIMITATION (pinned): a shift-style INSERT of an exact copy of
     * the anchor row before the window slides the identical duplicate into
     * the anchor slot. The fingerprint matches, the guard passes, and the
     * window re-reads the original anchor row - emitted three times while
     * the live table holds it twice. This models engines that place a new
     * row before the window (free-slot reuse, page split); a live InnoDB
     * PK-less append of the same duplicate stays honest because the row
     * lands after the window (verified live 2026-09-30, a4-nopk-dup-anchor
     * evidence: archive multiset equals the live multiset).
     */
    public function testDuplicateAnchorWithInsertBeforeWindowDuplicatesKnownLimitation(): void
    {
        // Rows 1..498, then TWO identical rows (id 500) at offsets 498
        // and 499, then 501..1200. Batch 1 archives both copies; the
        // anchor is the second copy.
        $rows = [];
        for ($i = 1; $i <= 498; $i++) {
            $rows[] = [(string) $i, 'value ' . $i];
        }
        $rows[] = ['500', 'value 500'];
        $rows[] = ['500', 'value 500'];
        for ($i = 501; $i <= 1200; $i++) {
            $rows[] = [(string) $i, 'value ' . $i];
        }
        $this->assertCount(1200, $rows);

        // Insert a new row before the window (offset 0). Everything
        // shifts right; the duplicate lands in the anchor slot and the
        // fingerprint still matches.
        $onBatch = static function () use (&$rows): void {
            array_unshift($rows, ['9998', 'late-insert']);
        };

        $archive = $this->runExport($rows, $onBatch);
        $ids = array_map('intval', $this->archivedIds($archive));
        $counts = array_count_values($ids);

        // Live table: id 500 twice. Archive: three times - the window
        // re-read the copy that slid out of the anchor position.
        $this->assertSame(
            3,
            (int) ($counts[500] ?? 0),
            'known limitation: duplicate anchor + pre-window insert re-emits the anchor row'
        );
        // 1200 live rows + the one re-read copy; the manifest count
        // matches what was read, so the archive verifies anyway.
        $this->assertSame(1201, count($ids));
        $this->assertTrue($this->verifies($archive));
    }

    public function testInsertAfterWindowIsHonestMixedTimeNotAnAnchorFailure(): void
    {
        $rows = $this->makeRows(1200);
        // Inserting AFTER the window does not move the anchor row, so the
        // export stays honest (mixed-time) rather than failing.
        $onBatch = static function () use (&$rows): void {
            $rows[] = ['9999', 'late insert'];
        };
        $archive = $this->runExport($rows, $onBatch);
        $ids = array_map('intval', $this->archivedIds($archive));
        $this->assertContains(9999, $ids, 'rows after the window may appear (mixed time)');
        $this->assertSame(range(1, 1200), array_values(array_filter(
            $ids,
            static function (int $id): bool {
                return $id <= 1200;
            }
        )), 'no original row may be skipped');
    }

    /**
     * The anchor must survive the checkpoint round-trip, or a resumed
     * process would either restart the table forever or resume into a gap.
     */
    public function testOffsetAnchorSurvivesSimulatedProcessRestart(): void
    {
        $rows = $this->makeRows(1200);
        $archive = $this->tmp . '/tick.mudrava';
        $header = static function (): Header {
            return new Header(1, 0, random_bytes(16), '1.0.0-test', 0, 0, 0, random_bytes(16), random_bytes(8));
        };
        $noCipher = static function (): ?\Mudrava\Migration\Crypto\FrameCipher {
            return null;
        };

        $checkpoint = null;
        $ticks = 0;
        $guard = 0;
        while (true) {
            // Fresh process per tick: the anchor can only come from the
            // persisted checkpoint cursor.
            $result = \Mudrava\Migration\Migration\ExportTick::run(
                $archive,
                $checkpoint,
                static fn (array $exclude = []): MutableDatabaseSource => new MutableDatabaseSource(
                    ['id', 'value'],
                    $rows
                ),
                static fn (array $dirs = []): ArrayFileInventory => new ArrayFileInventory([]),
                [],
                $checkpoint === null ? $header() : null,
                $noCipher,
                null,
                microtime(true) - 10.0
            );
            $ticks++;
            $this->assertNotNull($result['checkpoint'], 'every tick must persist a checkpoint');
            $checkpoint = $result['checkpoint'];
            if ($result['state'] === Exporter::STATE_DONE) {
                break;
            }
            if (++$guard > 200) {
                $this->fail('export did not converge across ticks');
            }
        }

        $this->assertGreaterThan(1, $ticks, 'test must span simulated restarts');
        $ids = array_map('intval', $this->archivedIds($archive));
        $this->assertSame(range(1, 1200), $ids, 'resumed export lost or duplicated no row');
    }
}
