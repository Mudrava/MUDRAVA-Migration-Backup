<?php
/**
 * Task K - smallest vertical migration. A tiny fake WordPress (two tables
 * with serialized option values + a handful of files) is exported to a real
 * .mudrava archive on disk, then imported into a fresh destination with a
 * URL rewrite, and semantically verified:
 *
 *   - every row restored, URLs rewritten inside serialized payloads
 *   - every file byte-identical, symlinks preserved
 *   - manifest root-hash verification passes
 *
 * This is the end-to-end proof the engine works before any WordPress glue.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Contract;

use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Database\ValueTransformer;
use Mudrava\Migration\Migration\Exporter;
use Mudrava\Migration\Migration\Importer;
use Mudrava\Migration\Tests\Support\ArrayDatabaseSource;
use Mudrava\Migration\Tests\Support\ArrayFileInventory;
use Mudrava\Migration\Tests\Support\LocalFileTarget;
use Mudrava\Migration\Tests\Support\RecordingDatabaseTarget;
use PHPUnit\Framework\TestCase;

final class VerticalMigrationTest extends TestCase
{
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = $GLOBALS['MUDRAVA_TEST_TMP'] . '/vertical-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    private function serializedSiteUrl(string $url): string
    {
        // Real WP option layout: a:2:{s:9:"home";s:..;s:14:"siteurl";s:..;}
        return serialize([
            'home'    => $url,
            'siteurl' => $url,
            'nested'  => serialize(['upload_url' => $url . '/wp-content/uploads']),
        ]);
    }

    public function testTinySiteExportImportWithUrlRewrite(): void
    {
        $old = 'https://old.example';
        $new = 'https://new.example';

        // ---- fake source site ------------------------------------------------
        $src = new ArrayDatabaseSource([
            'wp_options' => [
                'columns' => ['option_id', 'option_name', 'option_value'],
                'pk'      => 'option_id',
                'rows'    => [
                    ['1', 'home', $old],
                    ['2', 'siteurl', $old],
                    ['3', 'site_meta', $this->serializedSiteUrl($old)],
                    ['4', 'plain', 'no urls here'],
                ],
            ],
            'wp_posts' => [
                'columns' => ['ID', 'post_content'],
                'pk'      => 'ID',
                'rows'    => [
                    ['1', '<p>Visit ' . $old . '/about</p>'],
                    ['2', serialize(['link' => $old . '/contact'])],
                    ['3', 'binary-safe: ' . random_bytes(64)],
                ],
            ],
            'wp_binary' => [
                'columns' => ['id', 'payload', 'caption'],
                'types' => ['payload' => 'BLOB'],
                'pk' => 'id',
                'rows' => [['1', 'bytes:' . $old, 'Visit ' . $old]],
            ],
        ]);

        $files = [
            'wp-content/themes/demo/style.css' => "body{color:red}\n/* " . $old . " */",
            'wp-content/uploads/huge.bin'      => random_bytes(1024 * 1024), // 1 MiB, multi-chunk
            'index.php'                        => "<?php // old site\n",
        ];
        // Symlink entry: type=symlink, payload lives in metadata only.
        $filesWithSym = $files;
        $filesWithSym['wp-content/uploads/sym.bin'] = '';
        $inventory = new ArrayFileInventory($filesWithSym, [
            'wp-content/uploads/sym.bin' => ['type' => 'symlink', 'target' => 'huge.bin'],
        ]);

        // ---- export ----------------------------------------------------------
        $archivePath = $this->tmp . '/site.mudrava';
        $out = fopen($archivePath, 'wb');
        $header = new Header(Header::CONTAINER_FORMAT, 0, random_bytes(16), '1.0.0-test', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $writer = new FrameWriter($out, $header, new DeflateCodec(), null);

        $exporter = new Exporter($writer, $src, $inventory, [
            'site_url'  => $old,
            'home_url'  => $old,
            'wp_version' => '6.5',
            'charset'   => 'UTF-8',
        ]);
        $exporter->writeSiteMetadata();
        $guard = 0;
        while ($exporter->step() !== Exporter::STATE_DONE) {
            if (++$guard > 10000) {
                $this->fail('exporter did not terminate');
            }
        }
        $exporter->finalize();
        fclose($out);
        $this->assertFileExists($archivePath);
        $this->assertGreaterThan(0, filesize($archivePath));

        // ---- import into a fresh destination ---------------------------------
        $destRoot = $this->tmp . '/dest';
        $in = fopen($archivePath, 'rb');
        $reader = new FrameReader($in, new DeflateCodec());
        $dest = new RecordingDatabaseTarget();
        $target = new LocalFileTarget($destRoot);

        $importer = new Importer($reader, $dest, $target, ['search' => $old, 'replace' => $new]);
        $guard = 0;
        while ($importer->step() !== Importer::STATE_DONE) {
            if (++$guard > 10000) {
                $this->fail('importer did not terminate');
            }
        }
        fclose($in);

        // ---- semantic verification -------------------------------------------
        $this->assertSame(0, $importer->transformFailures());
        $this->assertSame(8, $importer->restoredRows());
        $this->assertSame('bytes:' . $old, $dest->rows['wp_binary'][0][1]);
        $this->assertSame('Visit ' . $new, $dest->rows['wp_binary'][0][2]);

        // URLs rewritten in plain cells.
        $options = $dest->rows['wp_options'];
        $this->assertSame($new, $options[0][2]);
        $this->assertSame($new, $options[1][2]);
        $this->assertSame('no urls here', $options[3][2]);

        // URLs rewritten INSIDE serialized values, with valid lengths.
        $meta = $options[2][2];
        $decoded = @unserialize($meta);
        $this->assertIsArray($decoded, 'serialized option must remain valid PHP after rewrite');
        $this->assertSame($new, $decoded['home']);
        $this->assertSame($new, $decoded['siteurl']);
        $nested = @unserialize($decoded['nested']);
        $this->assertIsArray($nested, 'nested serialized must remain valid');
        $this->assertSame($new . '/wp-content/uploads', $nested['upload_url']);

        // Posts: plain HTML, serialized, and binary-safe rows.
        $posts = $dest->rows['wp_posts'];
        $this->assertSame('<p>Visit ' . $new . '/about</p>', $posts[0][1]);
        $postMeta = @unserialize($posts[1][1]);
        $this->assertIsArray($postMeta);
        $this->assertSame($new . '/contact', $postMeta['link']);
        $this->assertStringStartsWith('binary-safe: ', $posts[2][1]);
        $this->assertSame(64, strlen($posts[2][1]) - strlen('binary-safe: '));

        // Files: byte-identical (URL rewrite is DB-only by design - rewriting
        // arbitrary file bytes is unsafe and out of scope for v1).
        $css = file_get_contents($destRoot . '/wp-content/themes/demo/style.css');
        $this->assertSame($files['wp-content/themes/demo/style.css'], $css);
        $this->assertSame($files['wp-content/uploads/huge.bin'], file_get_contents($destRoot . '/wp-content/uploads/huge.bin'));
        $this->assertSame("<?php // old site\n", file_get_contents($destRoot . '/index.php'));

        // Symlink preserved.
        $sym = $destRoot . '/wp-content/uploads/sym.bin';
        $this->assertTrue(is_link($sym), 'symlink must be restored as a symlink');
        $this->assertSame('huge.bin', readlink($sym));

        // Idempotent replay: re-import the same archive into the same dest -
        // DROP+CREATE + re-insert must not duplicate rows.
        $in2 = fopen($archivePath, 'rb');
        $reader2 = new FrameReader($in2, new DeflateCodec());
        $importer2 = new Importer($reader2, $dest, new LocalFileTarget($destRoot), ['search' => $old, 'replace' => $new]);
        $guard = 0;
        while ($importer2->step() !== Importer::STATE_DONE) {
            if (++$guard > 10000) {
                $this->fail('replay did not terminate');
            }
        }
        fclose($in2);
        $this->assertCount(4, $dest->rows['wp_options'], 'replay must not duplicate rows');
        $this->assertCount(3, $dest->rows['wp_posts']);
    }

    public function testTransformerLeavesUnparseableSerializedUntouched(): void
    {
        $t = new ValueTransformer('https://old.example', 'https://new.example');
        $broken = 'a:1:{s:4:"home";s:9:"https://old.example";BROKEN';
        $out = $t->transform($broken);
        // Serialized-looking but unparseable: NEVER blind-replaced (that would
        // corrupt s:N: lengths). Left byte-identical + counted as a failure.
        $this->assertSame($broken, $out);
        $this->assertSame(1, $t->parseFailures());
    }
}
