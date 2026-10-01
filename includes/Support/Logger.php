<?php

/**
 * Redacting logger. Secrets must never reach logs, diagnostics or telemetry.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Support;

final class Logger
{
    public const LEVEL_DEBUG = 0;
    public const LEVEL_INFO  = 1;
    public const LEVEL_WARN  = 2;
    public const LEVEL_ERROR = 3;

    /** @var list<string> Context keys whose values must be redacted. */
    private const SECRET_KEYS = [
        'password', 'pass', 'pwd', 'secret', 'token', 'authorization',
        'cookie', 'cookies', 'api_key', 'apikey', 'license', 'access_key',
        'secret_key', 'salt', 'auth', 'private_key', 'db_password',
    ];

    /** @var callable|null */
    private $sink;

    /**
     * @param callable|null $sink fn(string $line): void - injectable for tests.
     */
    public function __construct(?callable $sink = null, int $minLevel = self::LEVEL_INFO)
    {
        $this->sink = $sink;
        $this->minLevel = $minLevel;
    }

    /** @var int */
    private $minLevel;

    /**
     * @param array<string,mixed> $context
     */
    public function debug(string $event, array $context = []): void
    {
        $this->log(self::LEVEL_DEBUG, $event, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $event, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function warn(string $event, array $context = []): void
    {
        $this->log(self::LEVEL_WARN, $event, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function error(string $event, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $event, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function log(int $level, string $event, array $context = []): void
    {
        if ($level < $this->minLevel) {
            return;
        }
        // Events are identifiers, never free-form exception or user text.
        $safeEvent = preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $event) === 1
            ? $event
            : 'event_redacted';
        $line = sprintf(
            '[%s] %s %s %s',
            gmdate('Y-m-d\TH:i:s\Z'),
            $this->levelName($level),
            $safeEvent,
            (string) json_encode($this->redact($context), JSON_UNESCAPED_SLASHES)
        );
        if ($this->sink !== null) {
            ($this->sink)($line);
            return;
        }
        // Fallback when no sink is wired: PHP error log only, never the browser.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('MUDRAVA: ' . $line);
    }

    /**
     * Recursively redact secret-looking values. Public for direct testing.
     *
     * @param array<mixed,mixed> $context
     * @return array<mixed,mixed>
     */
    public function redact(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            $isSecret = false;
            foreach (self::SECRET_KEYS as $needle) {
                if (strpos($lower, $needle) !== false) {
                    $isSecret = true;
                    break;
                }
            }
            if ($isSecret) {
                $out[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                /** @var array<mixed,mixed> $value */
                $out[$key] = $this->redact($value);
            } elseif (is_string($value)) {
                $out[$key] = $this->safeMetadata($lower, $value);
            } elseif (is_object($value)) {
                $out[$key] = get_class($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Only known machine-readable values may reach a log. Arbitrary strings
     * can contain credentials even when their context key looks harmless.
     */
    private function safeMetadata(string $key, string $value): string
    {
        if ($key === 'kind' && in_array($value, ['export', 'import', 'rollback'], true)) {
            return $value;
        }
        if ($key === 'error' && preg_match('/^MUDRAVA_[A-Z0-9_]{1,80}$/D', $value) === 1) {
            return $value;
        }
        if ($key === 'exception_class' && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,127}$/D', $value) === 1) {
            return $value;
        }
        return '[REDACTED]';
    }

    private function levelName(int $level): string
    {
        $names = [self::LEVEL_DEBUG => 'DEBUG', self::LEVEL_INFO => 'INFO', self::LEVEL_WARN => 'WARN', self::LEVEL_ERROR => 'ERROR'];
        return $names[$level] ?? 'INFO';
    }
}
