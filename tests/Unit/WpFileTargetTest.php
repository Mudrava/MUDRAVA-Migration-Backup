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

final class WpFileTargetTest extends TestCase
{
    public function testPreflightRejectsUnsafeTemporaryPathWithoutChangingIt(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/preflight-target-' . uniqid();
        mkdir($root, 0777, true);
        $outside = $GLOBALS['MUDRAVA_TEST_TMP'] . '/preflight-outside-' . uniqid();
        file_put_contents($outside, 'untouched');
        symlink($outside, $root . '/victim.mudrava-tmp');

        try {
            (new WpFileTarget($root))->assertPathWritable('victim');
            $this->fail('unsafe temporary path must fail before import');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_PATH_UNSAFE', $e->getMessage());
            $this->assertSame('untouched', file_get_contents($outside));
            $this->assertTrue(is_link($root . '/victim.mudrava-tmp'));
        } finally {
            unlink($root . '/victim.mudrava-tmp');
            unlink($outside);
            rmdir($root);
        }
    }

    public function testPreflightAcceptsWritableNewAndExistingFiles(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/preflight-target-' . uniqid();
        mkdir($root . '/nested', 0777, true);
        file_put_contents($root . '/existing.txt', 'old');
        try {
            $target = new WpFileTarget($root);
            $target->assertPathWritable('new/deep/file.txt');
            $target->assertPathWritable('existing.txt');
            $target->assertPathWritable('nested/link.txt', true);
            $this->assertFileDoesNotExist($root . '/new');
            $this->assertSame('old', file_get_contents($root . '/existing.txt'));
        } finally {
            unlink($root . '/existing.txt');
            rmdir($root . '/nested');
            rmdir($root);
        }
    }

    public function testPreflightRejectsAFileUsedAsParentBeforeImportChangesAnything(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/preflight-parent-' . uniqid();
        mkdir($root, 0777, true);
        file_put_contents($root . '/blocked', 'original');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('destination directory is not writable');
            (new WpFileTarget($root))->assertPathWritable('blocked/child.txt');
        } finally {
            $this->assertSame('original', file_get_contents($root . '/blocked'));
            $this->assertFileDoesNotExist($root . '/blocked/child.txt');
            unlink($root . '/blocked');
            rmdir($root);
        }
    }

    public function testExistingTempSymlinkCannotOverwriteOutsideFile(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        $outside = $GLOBALS['MUDRAVA_TEST_TMP'] . '/outside-' . uniqid();
        file_put_contents($outside, 'unchanged');
        symlink($outside, $root . '/victim.mudrava-tmp');

        try {
            (new WpFileTarget($root))->beginFile('victim', 0644);
            $this->fail('a temporary symlink must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_PATH_UNSAFE', $e->getMessage());
            $this->assertSame('unchanged', file_get_contents($outside));
        } finally {
            unlink($root . '/victim.mudrava-tmp');
            unlink($outside);
            rmdir($root);
        }
    }

    public function testRegularFileCanBeResumedAndRenamed(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        try {
            $first = new WpFileTarget($root);
            $first->beginFile('ok.txt', 0644);
            $first->appendChunk('first');
            // A new process resumes the same temp file after a crash.
            $next = new WpFileTarget($root);
            $next->beginFile('ok.txt', 0644, true);
            $next->appendChunk(' second');
            $next->endFile('ok.txt', 0);
            $this->assertSame('first second', file_get_contents($root . '/ok.txt'));
        } finally {
            @unlink($root . '/ok.txt');
            @unlink($root . '/ok.txt.mudrava-tmp');
            rmdir($root);
        }
    }

    /** @dataProvider hardlinkModes */
    public function testExistingTempHardlinkCannotOverwriteOutsideFile(bool $append): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        $outside = $GLOBALS['MUDRAVA_TEST_TMP'] . '/outside-' . uniqid();
        file_put_contents($outside, 'unchanged');
        link($outside, $root . '/victim.mudrava-tmp');

        try {
            (new WpFileTarget($root))->beginFile('victim', 0644, $append);
            $this->fail('a temporary hardlink must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_PATH_UNSAFE', $e->getMessage());
            $this->assertSame('unchanged', file_get_contents($outside));
        } finally {
            unlink($root . '/victim.mudrava-tmp');
            unlink($outside);
            rmdir($root);
        }
    }

    public function testSymlinkRestoreRejectsHardlinkedTemporaryFileBeforeMutation(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        $outside = $GLOBALS['MUDRAVA_TEST_TMP'] . '/outside-' . uniqid();
        file_put_contents($outside, 'untouched');
        link($outside, $root . '/link.mudrava-tmp');

        try {
            $target = new WpFileTarget($root);
            foreach (['preflight', 'publish'] as $phase) {
                try {
                    if ($phase === 'preflight') {
                        $target->assertPathWritable('link', true);
                    } else {
                        $target->makeSymlink('link', 'relative-target');
                    }
                    $this->fail('symlink ' . $phase . ' accepted a hardlinked temporary file');
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('MUDRAVA_PATH_UNSAFE', $e->getMessage());
                }
            }
            $this->assertSame('untouched', file_get_contents($outside));
            $this->assertSame(2, (int) lstat($outside)['nlink']);
            $this->assertFileDoesNotExist($root . '/link');
        } finally {
            unlink($root . '/link.mudrava-tmp');
            unlink($outside);
            rmdir($root);
        }
    }

    /** @return array<string, array{bool}> */
    public static function hardlinkModes(): array
    {
        return ['fresh' => [false], 'resume' => [true]];
    }

    public function testHardlinkAddedAfterOpenBlocksFurtherWrites(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        $outside = $GLOBALS['MUDRAVA_TEST_TMP'] . '/outside-' . uniqid();

        try {
            $target = new WpFileTarget($root);
            $target->beginFile('victim', 0644);
            $target->appendChunk('before');
            link($root . '/victim.mudrava-tmp', $outside);

            try {
                $target->appendChunk(' after');
                $this->fail('a newly added hardlink must block writing');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('MUDRAVA_PATH_UNSAFE', $e->getMessage());
                $this->assertSame('before', file_get_contents($outside));
            }
        } finally {
            @unlink($root . '/victim.mudrava-tmp');
            @unlink($outside);
            rmdir($root);
        }
    }

    public function testFreshImportReplacesUnlinkedStaleTemp(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        file_put_contents($root . '/file.txt.mudrava-tmp', 'stale');

        try {
            $target = new WpFileTarget($root);
            $target->beginFile('file.txt', 0644);
            $target->appendChunk('new');
            $target->endFile('file.txt', 0);
            $this->assertSame('new', file_get_contents($root . '/file.txt'));
        } finally {
            @unlink($root . '/file.txt');
            @unlink($root . '/file.txt.mudrava-tmp');
            rmdir($root);
        }
    }

    public function testNewDestinationAppearingBeforePublicationIsPreserved(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        try {
            $target = new WpFileTarget($root);
            $target->beginFile('new.txt', 0644);
            $target->appendChunk('imported');
            file_put_contents($root . '/new.txt', 'concurrent');

            try {
                $target->endFile('new.txt', 0);
                $this->fail('a concurrent destination must not be overwritten');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('MUDRAVA_PATH_UNSAFE', $e->getMessage());
            }
            $this->assertSame('concurrent', file_get_contents($root . '/new.txt'));
        } finally {
            @unlink($root . '/new.txt');
            @unlink($root . '/new.txt.mudrava-tmp');
            rmdir($root);
        }
    }

    public function testExistingDestinationReplacementBeforePublicationIsPreserved(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        file_put_contents($root . '/existing.txt', 'original');
        try {
            $target = new WpFileTarget($root);
            $target->beginFile('existing.txt', 0644);
            $target->appendChunk('imported');
            unlink($root . '/existing.txt');
            file_put_contents($root . '/existing.txt', 'concurrent');

            try {
                $target->endFile('existing.txt', 0);
                $this->fail('a replacement destination must not be overwritten');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('MUDRAVA_PATH_UNSAFE', $e->getMessage());
            }
            $this->assertSame('concurrent', file_get_contents($root . '/existing.txt'));
        } finally {
            @unlink($root . '/existing.txt');
            @unlink($root . '/existing.txt.mudrava-tmp');
            rmdir($root);
        }
    }

    public function testParentSymlinkRetargetedBeforePublicationIsRejected(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root . '/first', 0777, true);
        mkdir($root . '/second', 0777, true);
        symlink('first', $root . '/current');
        try {
            $target = new WpFileTarget($root);
            $target->beginFile('current/file.txt', 0644);
            $target->appendChunk('imported');
            unlink($root . '/current');
            symlink('second', $root . '/current');

            try {
                $target->endFile('current/file.txt', 0);
                $this->fail('a changed parent path must not redirect publication');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('MUDRAVA_PATH_UNSAFE', $e->getMessage());
            }
            $this->assertFileDoesNotExist($root . '/second/file.txt');
        } finally {
            @unlink($root . '/first/file.txt');
            @unlink($root . '/first/file.txt.mudrava-tmp');
            @unlink($root . '/second/file.txt');
            @unlink($root . '/current');
            rmdir($root . '/first');
            rmdir($root . '/second');
            rmdir($root);
        }
    }

    public function testSuspendedHandleCannotPublishWithoutResume(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        try {
            $target = new WpFileTarget($root);
            $target->beginFile('new.txt', 0644);
            $target->appendChunk('partial');
            $target->suspendFile();

            try {
                $target->endFile('new.txt', 0);
                $this->fail('a suspended file must be reopened and checked before publication');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('MUDRAVA_PATH_UNSAFE', $e->getMessage());
            }
            $this->assertFileDoesNotExist($root . '/new.txt');
        } finally {
            @unlink($root . '/new.txt');
            @unlink($root . '/new.txt.mudrava-tmp');
            rmdir($root);
        }
    }

    public function testChunkLifecycleSupportsTruncateResumeOverwriteAndMtime(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        file_put_contents($root . '/existing.txt', 'old');
        $mtime = time() - 3600;
        try {
            $target = new WpFileTarget($root);
            $this->assertSame($root, $target->root());
            $target->beginFile('existing.txt', 0644);
            $target->appendChunk('abcdef');
            $this->assertSame(6, $target->bytesWritten());
            $target->truncateTo(3);
            $target->appendChunk('XY');
            $target->endFile('existing.txt', $mtime);

            $this->assertSame('abcXY', file_get_contents($root . '/existing.txt'));
            $this->assertSame(5, $target->bytesWritten());
            $this->assertSame($mtime, filemtime($root . '/existing.txt'));
        } finally {
            @unlink($root . '/existing.txt');
            @unlink($root . '/existing.txt.mudrava-tmp');
            rmdir($root);
        }
    }

    /** @dataProvider operationsWithoutOpenFile */
    public function testOperationsWithoutAnOpenFileFail(string $operation): void
    {
        $target = new WpFileTarget($GLOBALS['MUDRAVA_TEST_TMP']);
        $this->expectException(\Throwable::class);
        if ($operation === 'append') {
            $target->appendChunk('x');
        } elseif ($operation === 'suspend') {
            $target->suspendFile();
        } else {
            $target->truncateTo(0);
        }
    }

    /** @return array<string,array{string}> */
    public static function operationsWithoutOpenFile(): array
    {
        return ['append' => ['append'], 'suspend' => ['suspend'], 'truncate' => ['truncate']];
    }

    public function testInvalidTruncateLengthIsRejectedWithoutChangingTemp(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        try {
            $target = new WpFileTarget($root);
            $target->beginFile('partial.txt', 0644);
            $target->appendChunk('abc');
            foreach ([-1, 4] as $length) {
                try {
                    $target->truncateTo($length);
                    $this->fail('invalid partial length must fail');
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString('invalid partial file length', $error->getMessage());
                }
            }
            $this->assertSame('abc', file_get_contents($root . '/partial.txt.mudrava-tmp'));
        } finally {
            @unlink($root . '/partial.txt.mudrava-tmp');
            rmdir($root);
        }
    }

    public function testMissingResumeTempAndDirectoryDestinationAreRejected(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root . '/directory', 0777, true);
        $target = new WpFileTarget($root);
        try {
            try {
                $target->beginFile('missing.txt', 0644, true);
                $this->fail('resume without a partial file must fail');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('partial file missing', $error->getMessage());
            }
            try {
                $target->beginFile('directory', 0644);
                $this->fail('directory destination must fail');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('destination is not a regular file', $error->getMessage());
            }
        } finally {
            @unlink($root . '/directory.mudrava-tmp');
            rmdir($root . '/directory');
            rmdir($root);
        }
    }

    public function testReplacingOpenTemporaryNameBlocksFurtherWrites(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        try {
            $target = new WpFileTarget($root);
            $target->beginFile('victim.txt', 0644);
            $target->appendChunk('original');
            unlink($root . '/victim.txt.mudrava-tmp');
            file_put_contents($root . '/victim.txt.mudrava-tmp', 'replacement');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('temporary path changed');
            $target->appendChunk('x');
        } finally {
            @unlink($root . '/victim.txt.mudrava-tmp');
            rmdir($root);
        }
    }

    public function testSafeSymlinksCanBeCreatedAndReplaced(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root . '/links', 0777, true);
        try {
            $target = new WpFileTarget($root);
            $target->makeSymlink('links/current', 'first.txt');
            $this->assertTrue(is_link($root . '/links/current'));
            $this->assertSame('first.txt', readlink($root . '/links/current'));
            $target->makeSymlink('links/current', 'second.txt');
            $this->assertSame('second.txt', readlink($root . '/links/current'));
        } finally {
            @unlink($root . '/links/current');
            @unlink($root . '/links/current.mudrava-tmp');
            rmdir($root . '/links');
            rmdir($root);
        }
    }

    public function testUnsafeSymlinkAndDirectoryPreflightAreRejected(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root . '/directory', 0777, true);
        $target = new WpFileTarget($root);
        try {
            try {
                $target->makeSymlink('bad', '../../etc/passwd');
                $this->fail('escaping symlink must fail');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('unsafe symlink target', $error->getMessage());
            }
            try {
                $target->assertPathWritable('directory');
                $this->fail('directory cannot be replaced as a file');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('destination type', $error->getMessage());
            }
        } finally {
            rmdir($root . '/directory');
            rmdir($root);
        }
    }

    public function testEndingBeforeBeginningIsANoop(): void
    {
        $target = new WpFileTarget($GLOBALS['MUDRAVA_TEST_TMP']);
        $target->endFile('unused.txt', 0);
        $this->assertSame(0, $target->bytesWritten());
    }

    public function testNewFileAndSymlinkAreJournaledForGuardedRollback(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/journal-target-' . uniqid();
        mkdir($root, 0777, true);
        $journal = new ImportJournal('file-target-' . uniqid());
        $journal->create([]);
        try {
            $target = new WpFileTarget($root, $journal);
            $target->beginFile('nested/new.txt', 0644);
            $target->appendChunk('journalled');
            $target->endFile('nested/new.txt', 0);
            $target->makeSymlink('nested/current', 'new.txt');

            $this->assertSame('journalled', file_get_contents($root . '/nested/new.txt'));
            $this->assertSame('new.txt', readlink($root . '/nested/current'));
        } finally {
            $journal->remove();
            @unlink($root . '/nested/current');
            @unlink($root . '/nested/current.mudrava-tmp');
            @unlink($root . '/nested/new.txt');
            @unlink($root . '/nested/new.txt.mudrava-tmp');
            @rmdir($root . '/nested');
            @rmdir($root);
        }
    }

    public function testExistingSymlinkIsReplacedWithoutFollowingItsTarget(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        file_put_contents($root . '/old.txt', 'safe');
        symlink('old.txt', $root . '/current');
        try {
            (new WpFileTarget($root))->makeSymlink('current', 'replacement.txt');
            $this->assertSame('replacement.txt', readlink($root . '/current'));
            $this->assertSame('safe', file_get_contents($root . '/old.txt'));
        } finally {
            @unlink($root . '/current');
            @unlink($root . '/current.mudrava-tmp');
            @unlink($root . '/old.txt');
            @rmdir($root);
        }
    }

    public function testFileRestoreRejectsExistingSymlinkWithoutChangingTarget(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        file_put_contents($root . '/real.txt', 'safe');
        symlink('real.txt', $root . '/alias.txt');
        try {
            (new WpFileTarget($root))->beginFile('alias.txt', 0644);
            $this->fail('file restore followed an existing destination symlink');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('destination is not a regular file', $e->getMessage());
            $this->assertSame('safe', file_get_contents($root . '/real.txt'));
            $this->assertSame('real.txt', readlink($root . '/alias.txt'));
        } finally {
            @unlink($root . '/alias.txt.mudrava-tmp');
            @unlink($root . '/alias.txt');
            @unlink($root . '/real.txt');
            @rmdir($root);
        }
    }

    public function testRegularFileRestoreReportsParentThatCannotBecomeDirectory(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        file_put_contents($root . '/blocked', 'file');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('cannot create');
            (new WpFileTarget($root))->beginFile('blocked/child.txt', 0644);
        } finally {
            @unlink($root . '/blocked');
            @rmdir($root);
        }
    }

    public function testSymlinkRestoreReportsParentThatCannotBecomeDirectory(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        file_put_contents($root . '/blocked', 'file');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('cannot create');
            (new WpFileTarget($root))->makeSymlink('blocked/child', 'target.txt');
        } finally {
            @unlink($root . '/blocked');
            @rmdir($root);
        }
    }

    public function testJournalFailureClosesAndRemovesFreshTemporaryFile(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        $missingJournal = new ImportJournal('never-created-' . uniqid());
        try {
            (new WpFileTarget($root, $missingJournal))->beginFile('new.txt', 0644);
            $this->fail('missing journal must fail before restore writes');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('unsafe journal', $error->getMessage());
            $this->assertFileDoesNotExist($root . '/new.txt.mudrava-tmp');
        } finally {
            @unlink($root . '/new.txt.mudrava-tmp');
            @rmdir($root);
        }
    }

    public function testJournalFailureRemovesPreparedTemporarySymlink(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/target-' . uniqid();
        mkdir($root, 0777, true);
        $missingJournal = new ImportJournal('never-created-' . uniqid());
        try {
            (new WpFileTarget($root, $missingJournal))->makeSymlink('new-link', 'target.txt');
            $this->fail('missing journal must fail before symlink publication');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('unsafe journal', $error->getMessage());
            $this->assertFalse(is_link($root . '/new-link.mudrava-tmp'));
        } finally {
            @unlink($root . '/new-link.mudrava-tmp');
            @rmdir($root);
        }
    }

    public function testInternalOpenFileGuardRejectsEmptyState(): void
    {
        $method = new \ReflectionMethod(WpFileTarget::class, 'assertOpenTempSafe');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('temporary file is not open');
        $method->invoke(new WpFileTarget($GLOBALS['MUDRAVA_TEST_TMP']));
    }
}
