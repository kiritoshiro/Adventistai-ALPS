<?php
// Just enough of WP-CLI for App\Authors\Cli to run inside Playground's runPHP:
// messages and tables are collected for tests/authors/run.php to check.

namespace {
    if (! class_exists('WP_CLI')) {
        class WP_CLI
        {
            public static $log = [];
            public static $tables = [];
            public static $halted = null;

            public static function reset()
            {
                self::$log = [];
                self::$tables = [];
                self::$halted = null;
            }

            public static function log($message)
            {
                self::$log[] = $message;
            }

            public static function warning($message)
            {
                self::$log[] = 'Warning: ' . $message;
            }

            public static function success($message)
            {
                self::$log[] = 'Success: ' . $message;
            }

            public static function error($message)
            {
                throw new RuntimeException('WP_CLI::error ' . $message);
            }

            public static function halt($code)
            {
                self::$halted = $code;
            }

            public static function add_command()
            {
            }
        }
    }
}

namespace WP_CLI\Utils {
    if (! function_exists('WP_CLI\Utils\format_items')) {
        function format_items($format, $items, $fields)
        {
            \WP_CLI::$tables[] = $items;
        }
    }
}
