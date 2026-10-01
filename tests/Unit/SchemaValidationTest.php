<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */


declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Database\WpDatabaseTarget;
use PHPUnit\Framework\TestCase;

final class SchemaValidationTest extends TestCase
{
    public function testAcceptsSingleCreateTableStatement(): void
    {
        self::assertSame('wp_demo', WpDatabaseTarget::validateSchema('CREATE TABLE `wp_demo` (`id` bigint NOT NULL) ENGINE=InnoDB'));
        self::assertSame('wp_demo', WpDatabaseTarget::validateSchema("CREATE TABLE `wp_demo` (`note` text COMMENT 'a; b') ENGINE=InnoDB"));
        self::assertSame('wp_demo', WpDatabaseTarget::validateSchema("CREATE TABLE `wp_demo` (`note` text DEFAULT 'it\\'s; fine') ENGINE=InnoDB"));
    }

    /** @dataProvider unsafeSchemas */
    public function testRejectsUnsafeSchema(string $schema): void
    {
        $this->expectException(\RuntimeException::class);
        WpDatabaseTarget::validateSchema($schema);
    }

    /** @return array<string,array{string}> */
    public function unsafeSchemas(): array
    {
        return [
            'multiple statements' => ['CREATE TABLE `wp_demo` (`id` int); DROP TABLE `wp_users`'],
            'prefixed statement' => ['DROP TABLE `wp_users` CREATE TABLE `wp_demo` (`id` int)'],
            'invalid identifier' => ['CREATE TABLE `wp-demo` (`id` int)'],
            'null byte' => ["CREATE TABLE `wp_demo` (`id` int)\0"],
            'statement after quoted semicolon' => ["CREATE TABLE `wp_demo` (`note` text COMMENT 'a; b'); DROP TABLE `wp_users`"],
            'unclosed quote' => ["CREATE TABLE `wp_demo` (`note` text COMMENT 'a; b)"],
        ];
    }
}
