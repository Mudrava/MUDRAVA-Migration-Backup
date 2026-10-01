<?php

/**
 * PHPUnit bootstrap. The engine core is WordPress-free by design, so unit
 * tests need no WP. A minimal set of WP function stubs is provided for the
 * few classes that reference them (Logger/ErrorCode use __()).
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var string */
        private $code;

        public function __construct(string $code, string $message = '')
        {
            $this->code = $code;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @var mixed */
        public $data;

        /** @var int */
        private $status;

        /**
         * @param mixed $data
         */
        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        /** @return mixed */
        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string,mixed> */
        private $params;

        /** @var array<string,mixed> */
        private $files;

        /**
         * @param array<string,mixed> $params
         * @param array<string,mixed> $files
         */
        public function __construct(array $params = [], array $files = [])
        {
            $this->params = $params;
            $this->files = $files;
        }

        /** @return mixed */
        public function get_param(string $key)
        {
            return $this->params[$key] ?? null;
        }

        /** @return array<string,mixed> */
        public function get_file_params(): array
        {
            return $this->files;
        }
    }
}

if (!class_exists('wpdb')) {
    class wpdb
    {
        /** @var string */
        public $prefix = 'wp_';

        /** @var string */
        public $last_error = '';

        /** @var list<string> */
        public $queries = [];

        /** @var callable|null */
        public $queryCallback;

        /** @var callable|null */
        public $resultsCallback;

        /** @var callable|null */
        public $rowCallback;

        /** @var callable|null */
        public $colCallback;

        /** @var callable|null */
        public $varCallback;

        /** @return int|false */
        public function query(string $sql)
        {
            $this->queries[] = $sql;
            return is_callable($this->queryCallback) ? ($this->queryCallback)($sql) : 1;
        }

        /**
         * @param mixed ...$args
         */
        public function prepare(string $sql, ...$args): string
        {
            foreach ($args as $arg) {
                $sql = (string) preg_replace_callback('/%[sd]/', static function (array $match) use ($arg): string {
                    return $match[0] === '%d' ? (string) (int) $arg : "'" . addslashes((string) $arg) . "'";
                }, $sql, 1);
            }
            return $sql;
        }

        /** @return mixed */
        public function get_results(string $sql, $format = null)
        {
            return is_callable($this->resultsCallback) ? ($this->resultsCallback)($sql, $format) : [];
        }

        /** @return mixed */
        public function get_row(string $sql, $format = null)
        {
            return is_callable($this->rowCallback) ? ($this->rowCallback)($sql, $format) : null;
        }

        /** @return mixed */
        public function get_col(string $sql)
        {
            return is_callable($this->colCallback) ? ($this->colCallback)($sql) : [];
        }

        /** @return mixed */
        public function get_var(string $sql)
        {
            return is_callable($this->varCallback) ? ($this->varCallback)($sql) : null;
        }
    }
}

if (!function_exists('__')) {
    /**
     * @param string $text
     * @param string|null $domain
     * @return string
     */
    function __($text, $domain = 'default')
    {
        return (string) $text;
    }
}

if (!function_exists('sanitize_text_field')) {
    /**
     * @param string $str
     * @return string
     */
    function sanitize_text_field($str)
    {
        return trim((string) $str);
    }
}

if (!function_exists('sanitize_key')) {
    /** @param string $key */
    function sanitize_key($key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)) ?: '';
    }
}

if (!function_exists('absint')) {
    /** @param mixed $value */
    function absint($value): int
    {
        return abs((int) $value);
    }
}

if (!function_exists('is_wp_error')) {
    /** @param mixed $thing */
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('rest_ensure_response')) {
    /**
     * @param mixed $data
     */
    function rest_ensure_response($data): WP_REST_Response
    {
        return $data instanceof WP_REST_Response ? $data : new WP_REST_Response($data);
    }
}

$GLOBALS['MUDRAVA_STUB_ROUTES'] = [];
if (!function_exists('register_rest_route')) {
    /**
     * @param array<string,mixed> $args
     */
    function register_rest_route(string $namespace, string $route, array $args): bool
    {
        $GLOBALS['MUDRAVA_STUB_ROUTES'][] = compact('namespace', 'route', 'args');
        return true;
    }
}

if (!function_exists('nocache_headers')) {
    function nocache_headers(): void
    {
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return (bool) ($GLOBALS['MUDRAVA_STUB_CURRENT_USER_CAN'] ?? false);
    }
}

$GLOBALS['MUDRAVA_STUB_ACTIONS'] = [];
$GLOBALS['MUDRAVA_STUB_FILTERS'] = [];
if (!function_exists('add_action')) {
    /** @param mixed $callback */
    function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['MUDRAVA_STUB_ACTIONS'][] = compact('hook', 'callback', 'priority', 'acceptedArgs');
        return true;
    }
}
if (!function_exists('add_filter')) {
    /** @param mixed $callback */
    function add_filter(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['MUDRAVA_STUB_FILTERS'][] = compact('hook', 'callback', 'priority', 'acceptedArgs');
        return true;
    }
}

$GLOBALS['MUDRAVA_STUB_SCHEDULED'] = [];
$GLOBALS['MUDRAVA_STUB_CLEARED_HOOKS'] = [];
if (!function_exists('wp_get_scheduled_event')) {
    /** @return object|false */
    function wp_get_scheduled_event(string $hook)
    {
        return $GLOBALS['MUDRAVA_STUB_SCHEDULED'][$hook] ?? false;
    }
}
if (!function_exists('wp_next_scheduled')) {
    /** @return int|false */
    function wp_next_scheduled(string $hook)
    {
        return isset($GLOBALS['MUDRAVA_STUB_SCHEDULED'][$hook]) ? time() + 60 : false;
    }
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook): int
    {
        $GLOBALS['MUDRAVA_STUB_CLEARED_HOOKS'][] = $hook;
        unset($GLOBALS['MUDRAVA_STUB_SCHEDULED'][$hook]);
        return 1;
    }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
    {
        $GLOBALS['MUDRAVA_STUB_SCHEDULED'][$hook] = (object) [
            'timestamp' => $timestamp,
            'schedule'  => $recurrence,
        ];
        return true;
    }
}

$GLOBALS['MUDRAVA_STUB_ADMIN_MENUS'] = [];
if (!function_exists('add_menu_page')) {
    /** @param mixed $callback */
    function add_menu_page($pageTitle, $menuTitle, $capability, $slug, $callback, $icon = '', $position = null)
    {
        $GLOBALS['MUDRAVA_STUB_ADMIN_MENUS'][] = compact(
            'pageTitle',
            'menuTitle',
            'capability',
            'slug',
            'callback',
            'icon',
            'position'
        );
        return 'toplevel_page_' . $slug;
    }
}

$GLOBALS['MUDRAVA_STUB_STYLES'] = [];
$GLOBALS['MUDRAVA_STUB_SCRIPTS'] = [];
$GLOBALS['MUDRAVA_STUB_LOCALIZED'] = [];
if (!function_exists('wp_enqueue_style')) {
    /** @param list<string> $deps */
    function wp_enqueue_style(string $handle, string $src, array $deps = [], $ver = false): void
    {
        $GLOBALS['MUDRAVA_STUB_STYLES'][] = compact('handle', 'src', 'deps', 'ver');
    }
}
if (!function_exists('wp_enqueue_script')) {
    /** @param list<string> $deps */
    function wp_enqueue_script(string $handle, string $src, array $deps = [], $ver = false, bool $footer = false): void
    {
        $GLOBALS['MUDRAVA_STUB_SCRIPTS'][] = compact('handle', 'src', 'deps', 'ver', 'footer');
    }
}
if (!function_exists('wp_localize_script')) {
    /** @param array<string,mixed> $data */
    function wp_localize_script(string $handle, string $objectName, array $data): bool
    {
        $GLOBALS['MUDRAVA_STUB_LOCALIZED'][] = compact('handle', 'objectName', 'data');
        return true;
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return $url;
    }
}
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return 'http://testsite.local/wp-json/' . ltrim($path, '/');
    }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = '-1'): string
    {
        return 'nonce-' . $action;
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['MUDRAVA_STUB_CURRENT_USER_ID'] ?? 1);
    }
}

if (!function_exists('wp_unslash')) {
    /**
     * @param string $value
     * @return string
     */
    function wp_unslash($value)
    {
        return (string) $value;
    }
}

if (!function_exists('trailingslashit')) {
    /**
     * @param string $string
     * @return string
     */
    function trailingslashit($string)
    {
        return rtrim((string) $string, '/\\') . '/';
    }
}

// In-memory options table for JobRunner (job persistence) tests.
$GLOBALS['MUDRAVA_STUB_OPTIONS'] = [];

if (!function_exists('get_option')) {
    /**
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    function get_option($name, $default = false)
    {
        return $GLOBALS['MUDRAVA_STUB_OPTIONS'][$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    /**
     * @param string $name
     * @param mixed $value
     * @param mixed $autoload
     * @return bool
     */
    function update_option($name, $value, $autoload = null)
    {
        if ($name === 'mudrava_job' && !empty($GLOBALS['MUDRAVA_STUB_JOB_OPTION_WRITE_FAIL'])) {
            return false;
        }
        $GLOBALS['MUDRAVA_STUB_OPTIONS'][$name] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    /**
     * @param string $name
     * @return bool
     */
    function delete_option($name)
    {
        unset($GLOBALS['MUDRAVA_STUB_OPTIONS'][$name]);
        return true;
    }
}

if (!function_exists('flush_rewrite_rules')) {
    /** @param bool $hard */
    function flush_rewrite_rules($hard = true): void
    {
        $GLOBALS['MUDRAVA_STUB_REWRITE_FLUSHES'][] = $hard;
    }
}

if (!function_exists('wp_generate_password')) {
    /**
     * @param int $length
     * @param bool $special
     * @return string
     */
    function wp_generate_password($length = 12, $special = true)
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }
}

if (!function_exists('wp_parse_url')) {
    /**
     * @param string $url
     * @param int    $component
     * @return mixed
     */
    function wp_parse_url($url, $component = -1)
    {
        return parse_url((string) $url, $component);
    }
}

if (!function_exists('home_url')) {
    /**
     * @param string $path
     * @return string
     */
    function home_url($path = '')
    {
        return 'http://testsite.local' . $path;
    }
}

if (!function_exists('get_bloginfo')) {
    /**
     * @param string $show
     * @return string
     */
    function get_bloginfo($show = '')
    {
        return $show === 'version' ? '6.8-test' : '';
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return (bool) ($GLOBALS['MUDRAVA_STUB_MULTISITE'] ?? false);
    }
}

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/mudrava-test-wp/');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}
if (!defined('MUDRAVA_MB_VERSION')) {
    define('MUDRAVA_MB_VERSION', '1.0.0-test');
}
if (!is_dir(ABSPATH)) {
    mkdir(ABSPATH, 0777, true);
}

if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new wpdb();
}

// Deterministic-ish but unique temp workspace per run.
$mudravaTmp = sys_get_temp_dir() . '/mudrava-tests-' . getmypid();
if (!is_dir($mudravaTmp)) {
    mkdir($mudravaTmp, 0777, true);
}
$GLOBALS['MUDRAVA_TEST_TMP'] = $mudravaTmp;

// Storage-backed classes (Paths, RestoreToken) resolve their directory from
// WP_CONTENT_DIR; point it inside the per-run temp workspace so tests that
// touch protected storage are isolated and cleaned up automatically.
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', $mudravaTmp . '/wp-content');
}
if (!defined('MUDRAVA_MB_STORAGE_DIR')) {
    define('MUDRAVA_MB_STORAGE_DIR', $mudravaTmp . '/private-storage');
}

register_shutdown_function(static function () use ($mudravaTmp): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($mudravaTmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if ($f->isDir()) {
            @rmdir($f->getPathname());
        } else {
            @unlink($f->getPathname());
        }
    }
    @rmdir($mudravaTmp);
});

// Model the WordPress upload boundary for unit tests. Real HTTP upload provenance
// is checked separately against WordPress in the browser integration test.
if (!function_exists('remove_filter')) {
    function remove_filter(string $hook, $callback, int $priority = 10): bool
    {
        foreach ($GLOBALS['MUDRAVA_STUB_FILTERS'] as $key => $filter) {
            if ($filter['hook'] === $hook && $filter['callback'] === $callback && $filter['priority'] === $priority) {
                unset($GLOBALS['MUDRAVA_STUB_FILTERS'][$key]);
            }
        }
        return true;
    }
}
if (!function_exists('wp_handle_upload')) {
    function wp_handle_upload(array &$file, array $overrides): array
    {
        $uploads = ['path' => '/public/uploads', 'url' => 'https://example.org/uploads'];
        foreach ($GLOBALS['MUDRAVA_STUB_FILTERS'] as $filter) {
            if ($filter['hook'] === 'upload_dir') {
                $uploads = ($filter['callback'])($uploads);
            }
        }
        $GLOBALS['MUDRAVA_STUB_LAST_UPLOAD'] = compact('file', 'overrides', 'uploads');
        if (!empty($GLOBALS['MUDRAVA_STUB_UPLOAD_ERROR'])) {
            return ['error' => 'upload rejected'];
        }
        if ($file['size'] === 0) {
            return ['error' => 'empty file'];
        }
        $destination = $uploads['path'] . '/chunk-' . bin2hex(random_bytes(8)) . '.mudrava';
        if (!rename($file['tmp_name'], $destination)) {
            return ['error' => 'cannot move file'];
        }
        return ['file' => $destination, 'url' => '', 'type' => 'application/octet-stream'];
    }
}
