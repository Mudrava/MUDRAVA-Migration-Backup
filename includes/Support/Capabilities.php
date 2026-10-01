<?php

/**
 * Capability plumbing. Migration is the most destructive action a site can
 * take, so it maps to activate_plugins - never subscriber/editor.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Support;

defined('ABSPATH') || exit;

final class Capabilities
{
    public const CAP = 'mudrava_migrate';

    /**
     * @param list<string> $caps
     * @param array<int,mixed> $args
     * @return list<string>
     */
    public static function map(array $caps, string $cap, int $user_id, array $args): array
    {
        if ($cap === self::CAP) {
            return ['activate_plugins'];
        }
        return $caps;
    }

    public static function currentCan(): bool
    {
        return current_user_can(self::CAP);
    }
}
