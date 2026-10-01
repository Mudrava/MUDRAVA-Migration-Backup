<?php
/**
 * POT generator. Scans tracked PHP files for WordPress gettext calls and
 * writes languages/mudrava-migration-backup.pot. Token-based (no regex
 * over raw source), so strings inside comments or lookalikes are ignored.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

$root = getenv('MUDRAVA_POT_SOURCE') ?: dirname(__DIR__);
$domain = 'mudrava-migration-backup';
$functions = [
    '__' => 'single',
    '_e' => 'single',
    '_x' => 'context',
    'esc_html__' => 'single',
    'esc_html_e' => 'single',
    'esc_attr__' => 'single',
    'esc_attr_e' => 'single',
    'esc_html_x' => 'context',
    'esc_attr_x' => 'context',
];

if (getenv('MUDRAVA_POT_SOURCE')) {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
} else {
    $files = explode("\n", trim((string) shell_exec('git ls-files --cached --others --exclude-standard "*.php"')));
}
sort($files);
$entries = []; // key => ['msgid'=>, 'context'=>, 'refs'=>[]]

foreach ($files as $file) {
    if ($file === '' || !is_file($root . '/' . $file)) {
        continue;
    }
    if (strpos($file, 'tests/') === 0 || strpos($file, 'vendor/') === 0) {
        continue;
    }
    $src = (string) file_get_contents($root . '/' . $file);
    $tokens = token_get_all($src);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING) {
            continue;
        }
        $name = $t[1];
        if (!isset($functions[$name])) {
            continue;
        }
        // Must be a call: next non-whitespace token is '('.
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $count || $tokens[$j] !== '(') {
            continue;
        }
        // Guard against $obj->__ or Class::__ (method calls).
        $k = $i - 1;
        while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
            $k--;
        }
        if ($k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            continue;
        }
        // Collect literal string args until the closing paren.
        $args = [];
        $depth = 0;
        for ($m = $j; $m < $count; $m++) {
            $tok = $tokens[$m];
            if ($tok === '(') {
                $depth++;
                continue;
            }
            if ($tok === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                continue;
            }
            if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                $args[] = [stripslashes(substr($tok[1], 1, -1)), $tok[2]];
            }
        }
        if ($args === []) {
            continue; // dynamic first arg: not extractable
        }
        $msgid = $args[0][0];
        $line = $args[0][1];
        $ctx = null;
        if ($functions[$name] === 'context' && isset($args[1])) {
            $ctx = $args[1][0];
        }
        // Domain must be ours when explicitly given.
        $hasDomain = false;
        foreach ($args as $a) {
            if ($a[0] === $domain) {
                $hasDomain = true;
            }
        }
        if (!$hasDomain && count($args) >= ($functions[$name] === 'context' ? 3 : 2)) {
            continue; // explicit foreign domain
        }
        $key = ($ctx !== null ? $ctx . "\x04" : '') . $msgid;
        if (!isset($entries[$key])) {
            $entries[$key] = ['msgid' => $msgid, 'ctx' => $ctx, 'refs' => []];
        }
        $entries[$key]['refs'][] = $file . ':' . $line;
    }
}

ksort($entries);
$epoch = getenv('SOURCE_DATE_EPOCH');
if (!is_string($epoch) || preg_match('/^[0-9]+$/', $epoch) !== 1) {
    $gitEpoch = trim((string) shell_exec(
        'git log -1 --format=%ct -- mudrava-migration-backup.php uninstall.php readme.txt LICENSE '
        . 'includes assets languages/index.php bin/make-pot.php bin/make-pot.sh bin/normalize-mtime.php bin/dist.sh'
    ));
    $epoch = preg_match('/^[0-9]+$/', $gitEpoch) === 1 ? $gitEpoch : '1767225600';
}
$now = gmdate('Y-m-d H:iO', (int) $epoch);
$out = [];
$out[] = '# Copyright (C) 2026 MUDRAVA';
$out[] = '# This file is distributed under the GPL-2.0-or-later license.';
$out[] = 'msgid ""';
$out[] = 'msgstr ""';
$out[] = '"Project-Id-Version: MUDRAVA Migration & Backup 1.0.0\\n"';
$out[] = '"Report-Msgid-Bugs-To: https://mudrava.com/en/\\n"';
$out[] = '"POT-Creation-Date: ' . $now . '\\n"';
$out[] = '"MIME-Version: 1.0\\n"';
$out[] = '"Content-Type: text/plain; charset=UTF-8\\n"';
$out[] = '"Content-Transfer-Encoding: 8bit\\n"';
$out[] = '"Plural-Forms: nplurals=INTEGER; plural=EXPRESSION;\\n"';
$out[] = '"X-Domain: ' . $domain . '\\n"';

$esc = static function (string $s): string {
    return '"' . str_replace(["\\", '"', "\n"], ['\\\\', '\\"', "\\n\"\n\""], $s) . '"';
};

foreach ($entries as $entry) {
    $out[] = '';
    foreach ($entry['refs'] as $ref) {
        $out[] = '#: ' . $ref;
    }
    if ($entry['ctx'] !== null) {
        $out[] = 'msgctxt ' . $esc($entry['ctx']);
    }
    $out[] = 'msgid ' . $esc($entry['msgid']);
    $out[] = 'msgstr ""';
}

$configuredPath = getenv('MUDRAVA_POT_OUTPUT');
$path = is_string($configuredPath) && $configuredPath !== ''
    ? $configuredPath
    : $root . '/languages/' . $domain . '.pot';
if (file_put_contents($path, implode("\n", $out) . "\n") === false) {
    fwrite(STDERR, 'cannot write POT file: ' . $path . "\n");
    exit(1);
}
fwrite(STDERR, 'pot entries: ' . count($entries) . "\n");
