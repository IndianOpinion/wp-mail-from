<?php

declare(strict_types=1);

namespace {
    final class WP_CLI_Halt extends \RuntimeException
    {
    }

    /**
     * Records WP-CLI output instead of printing it.
     */
    final class WP_CLI
    {
        /** @var list<string> */
        public static array $lines = [];
        /** @var array<string, object> */
        public static array $commands = [];
        public static ?int $exit = null;

        public static function reset(): void
        {
            self::$lines = [];
            self::$commands = [];
            self::$exit = null;
        }

        public static function add_command(string $name, object $command): void
        {
            self::$commands[$name] = $command;
        }

        public static function line(string $text = ''): void
        {
            self::$lines[] = $text;
        }

        public static function success(string $text): void
        {
            self::$lines[] = 'Success: ' . $text;
        }

        public static function warning(string $text): void
        {
            self::$lines[] = 'Warning: ' . $text;
        }

        public static function error(string $text): void
        {
            self::$lines[] = 'Error: ' . $text;
            self::$exit = 1;
            throw new WP_CLI_Halt($text);
        }

        public static function halt(int $code): void
        {
            self::$exit = $code;
        }

        /**
         * @param array<string, mixed> $assoc
         */
        public static function confirm(string $question, array $assoc = []): void
        {
            if (!isset($assoc['yes'])) {
                throw new WP_CLI_Halt('confirmation needed');
            }
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param list<array<string, string>> $items
     * @param list<string>                $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
        foreach ($items as $item) {
            $values = [];
            foreach ($fields as $field) {
                $values[] = $item[$field] ?? '';
            }
            \WP_CLI::$lines[] = $format . '|' . implode('|', $values);
        }
    }
}
