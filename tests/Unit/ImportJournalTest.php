<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */


declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Filesystem\WpFileTarget;
use Mudrava\Migration\Rollback\ImportJournal;
use PHPUnit\Framework\TestCase;

final class ImportJournalTest extends TestCase
{
    /** @var string */
    private $root;

    /** @var ImportJournal */
    private $journal;

    protected function setUp(): void
    {
        $this->root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/journal-root-' . uniqid();
        mkdir($this->root, 0777, true);
        $this->journal = new ImportJournal('journal-test-' . uniqid());
        $this->journal->create();
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root . '/*') as $path) {
            @unlink($path);
        }
        $this->journal->remove();
        rmdir($this->root);
    }

    private function journalPath(): string
    {
        $property = new \ReflectionProperty(ImportJournal::class, 'path');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        return (string) $property->getValue($this->journal);
    }

    private function database()
    {
        return new class {
            /** @var list<string> */
            public $queries = [];

            public function query(string $sql)
            {
                $this->queries[] = $sql;
                return 1;
            }
        };
    }

    public function testCreatedFileIsRemovedButPreexistingFileSurvives(): void
    {
        file_put_contents($this->root . '/old.txt', 'before');
        $target = new WpFileTarget($this->root, $this->journal);
        $target->beginFile('new.txt', 0644);
        $target->appendChunk('created');
        $target->endFile('new.txt', 0);
        $target->beginFile('old.txt', 0644);
        $target->appendChunk('replaced');
        $target->endFile('old.txt', 0);

        $this->journal->cleanup($this->root, $this->database());
        $this->assertFileDoesNotExist($this->root . '/new.txt');
        $this->assertSame('replaced', file_get_contents($this->root . '/old.txt'));
    }

    public function testReplacementInodeIsNotDeleted(): void
    {
        $target = new WpFileTarget($this->root, $this->journal);
        $target->beginFile('new.txt', 0644);
        $target->appendChunk('created');
        $target->endFile('new.txt', 0);
        // Keep the original inode allocated. Filesystems may immediately
        // reuse an unlinked inode, making a new file look like the import's
        // file to an inode-only cleanup guard.
        $this->assertTrue(link($this->root . '/new.txt', $this->root . '/held.txt'));
        unlink($this->root . '/new.txt');
        file_put_contents($this->root . '/new.txt', 'later');

        $this->journal->cleanup($this->root, $this->database());
        $this->assertSame('later', file_get_contents($this->root . '/new.txt'));
    }

    public function testReusedInodeWithDifferentContentIsNotDeleted(): void
    {
        $target = new WpFileTarget($this->root, $this->journal);
        $target->beginFile('new.txt', 0644);
        $target->appendChunk('created');
        $target->endFile('new.txt', 0);
        unlink($this->root . '/new.txt');
        file_put_contents($this->root . '/new.txt', 'replace');

        // Force the same device/inode identity in the journal. Filesystems
        // can reuse the original inode after unlink, but need not do so on
        // every run of this test.
        $property = new \ReflectionProperty(ImportJournal::class, 'path');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $journalPath = (string) $property->getValue($this->journal);
        $lines = file($journalPath);
        $this->assertIsArray($lines);
        $current = lstat($this->root . '/new.txt');
        $this->assertIsArray($current);
        foreach ($lines as &$line) {
            $entry = json_decode($line, true);
            if (($entry['type'] ?? '') === 'file' && ($entry['path'] ?? '') === 'new.txt') {
                $entry['dev'] = (int) $current['dev'];
                $entry['ino'] = (int) $current['ino'];
                $line = json_encode($entry) . "\n";
            }
        }
        unset($line);
        file_put_contents($journalPath, implode('', $lines));

        $this->journal->cleanup($this->root, $this->database());
        $this->assertSame('replace', file_get_contents($this->root . '/new.txt'));
    }

    public function testCreatedSymlinkIsRemoved(): void
    {
        file_put_contents($this->root . '/target.txt', 'target');
        $target = new WpFileTarget($this->root, $this->journal);
        $target->makeSymlink('link.txt', 'target.txt');

        $this->journal->cleanup($this->root, $this->database());
        $this->assertFalse(is_link($this->root . '/link.txt'));
        $this->assertSame('target', file_get_contents($this->root . '/target.txt'));
    }

    public function testInvalidCompleteEntryStopsBeforeAnyDeletion(): void
    {
        $target = new WpFileTarget($this->root, $this->journal);
        $target->beginFile('new.txt', 0644);
        $target->appendChunk('created');
        $target->endFile('new.txt', 0);
        $property = new \ReflectionProperty(ImportJournal::class, 'path');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $path = (string) $property->getValue($this->journal);
        file_put_contents($path, "{invalid}\n", FILE_APPEND);

        try {
            $this->journal->cleanup($this->root, $this->database());
            $this->fail('invalid journal must stop cleanup');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_RECOVERY_JOURNAL', $e->getMessage());
            $this->assertSame('created', file_get_contents($this->root . '/new.txt'));
        }
    }

    public function testBaselineTableIsNeverDroppedAfterAnInterruptedCreate(): void
    {
        $this->journal->remove();
        $this->journal->create(['wp_original']);
        // A later request sees the same private journal after the table was
        // dropped but before its original schema could be recreated.
        $property = new \ReflectionProperty(ImportJournal::class, 'path');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $path = (string) $property->getValue($this->journal);
        $next = new ImportJournal('unused');
        $property->setValue($next, $path);
        $next->recordTable('wp_original');
        $next->recordTable('wp_new');

        $db = $this->database();
        $next->cleanup($this->root, $db);
        $this->assertContains('DROP TABLE IF EXISTS `wp_new`', $db->queries);
        $this->assertNotContains('DROP TABLE IF EXISTS `wp_original`', $db->queries);
    }

    public function testCleanupResumesAcrossBoundedSteps(): void
    {
        for ($i = 0; $i < 600; ++$i) {
            $this->journal->recordTable('wp_new_' . $i);
        }
        $db = $this->database();
        $phase = 'validate';
        $offset = 0;
        $steps = 0;
        do {
            $result = $this->journal->cleanupStep($this->root, $db, $phase, $offset);
            $phase = $result['phase'];
            $offset = $result['offset'];
            ++$steps;
            if ($phase === 'validate') {
                $this->assertSame([], $db->queries);
            }
        } while (!$result['done']);
        $this->assertGreaterThan(4, $steps);
        $this->assertContains('DROP TABLE IF EXISTS `wp_new_599`', $db->queries);
    }

    public function testTornLastLineIsDiscardedBeforeNextAppend(): void
    {
        $property = new \ReflectionProperty(ImportJournal::class, 'path');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $path = (string) $property->getValue($this->journal);
        file_put_contents($path, '{"type":"file",', FILE_APPEND);
        $this->journal->recordTable('wp_new');
        $db = $this->database();
        $this->journal->cleanup($this->root, $db);
        $this->assertContains('DROP TABLE IF EXISTS `wp_new`', $db->queries);
    }

    public function testInterruptedNewFileTempIsRemoved(): void
    {
        $target = new WpFileTarget($this->root, $this->journal);
        $target->beginFile('new.txt', 0644);
        $target->appendChunk('partial');
        $target->suspendFile();

        $this->journal->cleanup($this->root, $this->database());
        $this->assertFileDoesNotExist($this->root . '/new.txt.mudrava-tmp');
        $this->assertFileDoesNotExist($this->root . '/new.txt');
    }

    public function testInterruptedOverwriteTempIsRemovedWithoutChangingOriginal(): void
    {
        file_put_contents($this->root . '/old.txt', 'original');
        $target = new WpFileTarget($this->root, $this->journal);
        $target->beginFile('old.txt', 0644);
        $target->appendChunk('partial');
        $target->suspendFile();

        $this->journal->cleanup($this->root, $this->database());
        $this->assertFileDoesNotExist($this->root . '/old.txt.mudrava-tmp');
        $this->assertSame('original', file_get_contents($this->root . '/old.txt'));
    }

    public function testRemoveIfPristineDeletesBaselineOnlyJournal(): void
    {
        $this->journal->remove();
        $this->journal->create(['wp_a', 'wp_b']);
        $this->assertTrue($this->journal->exists());
        $this->assertTrue($this->journal->removeIfPristine());
        $this->assertFalse($this->journal->exists());
    }

    public function testRemoveIfPristineToleratesTornFinalAppend(): void
    {
        $property = new \ReflectionProperty(ImportJournal::class, 'path');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $path = (string) $property->getValue($this->journal);
        // A torn append preceded any publication, so the journal is still
        // provably pristine and may be reclaimed.
        file_put_contents($path, '{"type":"file","path":"a.txt"', FILE_APPEND);
        $this->assertTrue($this->journal->removeIfPristine());
        $this->assertFalse($this->journal->exists());
    }

    public function testRemoveIfPristineRefusesJournalWithMutationRecord(): void
    {
        $this->journal->recordTable('wp_created');
        $property = new \ReflectionProperty(ImportJournal::class, 'path');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $path = (string) $property->getValue($this->journal);
        $this->assertFalse($this->journal->removeIfPristine());
        // Nothing was deleted: the journal still guards real work.
        $this->assertFileExists($path);
        $this->assertCount(2, file($path));
    }

    public function testRemoveIfPristineRefusesPublishedFileRecord(): void
    {
        $target = new WpFileTarget($this->root, $this->journal);
        $target->beginFile('new.txt', 0644);
        $target->appendChunk('created');
        $target->endFile('new.txt', 0);
        $this->assertFalse($this->journal->removeIfPristine());
        $this->assertTrue($this->journal->exists());
    }

    public function testRemoveIfPristineRejectsDamagedBaseline(): void
    {
        $property = new \ReflectionProperty(ImportJournal::class, 'path');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $path = (string) $property->getValue($this->journal);
        file_put_contents($path, '{"type":"table","table":"wp_x"}' . "\n");
        try {
            $this->journal->removeIfPristine();
            $this->fail('damaged baseline must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('invalid baseline', $e->getMessage());
        }
        $this->assertFileExists($path);
    }

    public function testRemoveIfPristineRejectsNonRegularJournal(): void
    {
        $property = new \ReflectionProperty(ImportJournal::class, 'path');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $path = (string) $property->getValue($this->journal);
        $this->journal->remove();
        $this->assertFalse($this->journal->exists());
        symlink($this->root . '/nowhere', $path);
        try {
            $this->journal->removeIfPristine();
            $this->fail('symlinked journal must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unsafe journal', $e->getMessage());
        }
        $this->assertTrue(is_link($path));
        unlink($path);
    }

    public function testExistsSeesAnyInodeIncludingLinks(): void
    {
        $property = new \ReflectionProperty(ImportJournal::class, 'path');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $path = (string) $property->getValue($this->journal);
        $this->journal->remove();
        $this->assertFalse($this->journal->exists());
        symlink($this->root . '/nowhere', $path);
        $this->assertTrue($this->journal->exists());
        unlink($path);
    }

    public function testCreateAndRecordRejectInvalidOrDuplicateState(): void    {
        $this->journal->remove();
        try {
            $this->journal->create([123]);
            $this->fail('non-string baseline must fail');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('invalid baseline', $error->getMessage());
        }
        $this->journal->create();

        try {
            $this->journal->create();
            $this->fail('journal must be created exclusively');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('cannot create journal', $error->getMessage());
        }
        try {
            $this->journal->recordTable('wp_bad;drop');
            $this->fail('unsafe table must fail');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('invalid table', $error->getMessage());
        }
    }

    public function testMissingBaselineAndPublishedFileReplacementAreRejected(): void
    {
        $missing = new ImportJournal('missing-' . uniqid());
        try {
            $missing->recordTable('wp_new');
            $this->fail('missing baseline must fail');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('baseline missing', $error->getMessage());
        }

        $path = $this->root . '/published.txt';
        file_put_contents($path, 'first');
        $stat = lstat($path);
        $this->assertIsArray($stat);
        unlink($path);
        file_put_contents($path, 'replacement');
        try {
            // Some filesystems immediately reuse the unlinked inode. Pass a
            // deliberately stale identity so this boundary remains deterministic.
            $this->journal->recordPublishedFile('published.txt', $path, PHP_INT_MAX, (int) $stat['ino']);
            $this->fail('replaced published file must fail');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('published file changed', $error->getMessage());
        }
    }

    /** @dataProvider invalidJournalLines */
    public function testValidationRejectsEveryMalformedEntry(string $line, string $message): void
    {
        file_put_contents($this->journalPath(), '{"type":"baseline","tables":[]}' . "\n" . $line . "\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        $this->journal->cleanupStep($this->root, $this->database(), 'validate', 0);
    }

    /** @return array<string,array{string,string}> */
    public static function invalidJournalLines(): array
    {
        return [
            'invalid json' => ['{broken}', 'invalid entry'],
            'baseline not array' => ['{"type":"baseline","tables":"bad"}', 'invalid baseline'],
            'baseline member' => ['{"type":"baseline","tables":[1]}', 'invalid baseline'],
            'file missing identity' => ['{"type":"file","path":"a"}', 'invalid file entry'],
            'file unsafe path' => ['{"type":"file","path":"../a","dev":1,"ino":2}', 'MUDRAVA_PATH_UNSAFE'],
            'file bad sample' => ['{"type":"file","path":"a","dev":1,"ino":2,"sample":"bad","size":1,"mtime":1,"ctime":1}', 'invalid file sample'],
            'table bad name' => ['{"type":"table","table":"wp;bad"}', 'invalid entry'],
            'unknown type' => ['{"type":"other"}', 'invalid entry'],
        ];
    }

    public function testCleanupRejectsInvalidCursorAndMissingJournal(): void
    {
        foreach ([['unknown', 0], ['validate', -1]] as [$phase, $offset]) {
            try {
                $this->journal->cleanupStep($this->root, $this->database(), $phase, $offset);
                $this->fail('invalid cursor must fail');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('invalid cursor', $error->getMessage());
            }
        }
        $this->journal->remove();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('journal missing');
        $this->journal->cleanupStep($this->root, $this->database(), 'validate', 0);
    }

    public function testCleanupReportsDatabaseDropFailureAndRestoresForeignKeys(): void
    {
        $this->journal->recordTable('wp_new');
        $db = new class {
            /** @var list<string> */
            public $queries = [];

            public function query(string $sql)
            {
                $this->queries[] = $sql;
                return strpos($sql, 'DROP TABLE') === 0 ? false : 1;
            }
        };
        try {
            $this->journal->cleanup($this->root, $db);
            $this->fail('failed table cleanup must be reported');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('cannot remove created table', $error->getMessage());
            $this->assertSame('SET FOREIGN_KEY_CHECKS = 1', end($db->queries));
        }
    }

    /** @dataProvider invalidBaselines */
    public function testRecordTableRejectsMalformedStoredBaseline(string $baseline): void
    {
        file_put_contents($this->journalPath(), $baseline . "\n");
        $next = new ImportJournal('detached-' . uniqid());
        $property = new \ReflectionProperty(ImportJournal::class, 'path');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue($next, $this->journalPath());

        $this->expectExceptionMessage('invalid baseline');
        $next->recordTable('wp_new');
    }

    /** @return array<string,array{string}> */
    public static function invalidBaselines(): array
    {
        return [
            'invalid json' => ['{broken'],
            'wrong type' => ['{"type":"file","tables":[]}'],
            'tables scalar' => ['{"type":"baseline","tables":"bad"}'],
            'non-string member' => ['{"type":"baseline","tables":[1]}'],
        ];
    }

    public function testValidationIgnoresTornTailAndRejectsOffsetPastEnd(): void
    {
        file_put_contents($this->journalPath(), '{"type":"file",', FILE_APPEND);
        $first = $this->journal->cleanupStep($this->root, $this->database(), 'validate', 0);
        $this->assertSame('remove', $first['phase']);

        $this->expectExceptionMessage('read failed');
        $this->journal->cleanupStep(
            $this->root,
            $this->database(),
            'validate',
            filesize($this->journalPath()) + 1
        );
    }

    public function testMissingAndLegacyFinalFilesAreLeftSafely(): void
    {
        $this->journal->recordFile('missing.txt', 1, 2);
        $legacy = $this->root . '/legacy.txt';
        file_put_contents($legacy, 'keep');
        $stat = lstat($legacy);
        $this->assertIsArray($stat);
        $this->journal->recordFile('legacy.txt', (int) $stat['dev'], (int) $stat['ino']);

        $this->journal->cleanup($this->root, $this->database());
        $this->assertSame('keep', file_get_contents($legacy));
    }

    public function testLegacyJournalEntryCannotRemoveDirectoryAsAFile(): void
    {
        $dir = $this->root . '/created-dir';
        mkdir($dir);
        $stat = lstat($dir);
        $this->assertIsArray($stat);
        $this->journal->recordFile('created-dir', (int) $stat['dev'], (int) $stat['ino']);

        $this->journal->cleanup($this->root, $this->database());
        $this->assertDirectoryExists($dir);
        rmdir($dir);
    }

    public function testPublishedHardlinkIsRejectedAndLargeFileSamplingIsStable(): void
    {
        $original = $this->root . '/original.txt';
        $linked = $this->root . '/linked.txt';
        file_put_contents($original, 'content');
        link($original, $linked);
        $stat = lstat($linked);
        $this->assertIsArray($stat);
        try {
            $this->journal->recordPublishedFile('linked.txt', $linked, (int) $stat['dev'], (int) $stat['ino']);
            $this->fail('multi-link destination was journaled as uniquely owned');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('unsafe published file', $error->getMessage());
        }

        unlink($linked);
        unlink($original);
        $large = $this->root . '/large.bin';
        file_put_contents($large, str_repeat('A', 5000) . str_repeat('B', 5000));
        $largeStat = lstat($large);
        $this->assertIsArray($largeStat);
        $this->journal->recordPublishedFile(
            'large.bin',
            $large,
            (int) $largeStat['dev'],
            (int) $largeStat['ino']
        );
        $this->journal->cleanup($this->root, $this->database());
        $this->assertFileDoesNotExist($large);
    }

    public function testHardlinkedJournalIsRejectedForAppendAndCleanup(): void
    {
        $path = $this->journalPath();
        $alias = $path . '.alias';
        $this->assertTrue(link($path, $alias));
        try {
            try {
                $this->journal->recordTable('wp_new');
                $this->fail('append must reject a hardlinked journal');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('unsafe journal', $error->getMessage());
            }
            try {
                $this->journal->cleanupStep($this->root, $this->database(), 'validate', 0);
                $this->fail('cleanup must reject a hardlinked journal');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('unsafe journal', $error->getMessage());
            }
        } finally {
            unlink($alias);
        }
    }

    public function testUnencodableJournalPathFailsBeforePublishingEntry(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot encode entry');
        $this->journal->recordFile("invalid-\xb1", 1, 2);
    }

    public function testCleanupRefusesJournalWhenDestinationRootDisappeared(): void
    {
        $this->journal->recordFile('new.mudrava-tmp', 1, 2);
        $missingRoot = $this->root . '/gone';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('parent missing');
        $this->journal->cleanup($missingRoot, $this->database());
    }

    public function testModifiedPublishedFileIsPreservedByContentSample(): void
    {
        $target = new WpFileTarget($this->root, $this->journal);
        $target->beginFile('sampled.txt', 0644);
        $target->appendChunk('original');
        $target->endFile('sampled.txt', 0);
        file_put_contents($this->root . '/sampled.txt', 'modified-longer');

        $this->journal->cleanup($this->root, $this->database());

        $this->assertSame('modified-longer', file_get_contents($this->root . '/sampled.txt'));
    }
}
