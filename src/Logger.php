<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * Writes one-line log entries with a fixed prefix so one grep finds them.
 *
 * Lines go to PHP's error log, which WordPress points at
 * wp-content/debug.log when WP_DEBUG_LOG is on. A failing logger never
 * affects mail (C61).
 */
final class Logger
{
    public const PREFIX = '[wp-mail-from]';

    /** @var callable(string): mixed */
    private $sink;

    /** @var array<string, true> */
    private array $once = [];

    /**
     * @param (callable(string): mixed)|null $sink Receives each complete line. Defaults to error_log().
     */
    public function __construct(?callable $sink = null)
    {
        $this->sink = $sink ?? static fn (string $line): bool => error_log($line);
    }

    public function info(string $message): void
    {
        $this->write('info', $message);
    }

    public function warning(string $message): void
    {
        $this->write('warning', $message);
    }

    public function error(string $message): void
    {
        $this->write('error', $message);
    }

    /**
     * Log a line only the first time its key is seen in this request.
     */
    public function once(string $key, string $level, string $message): void
    {
        if (isset($this->once[$key])) {
            return;
        }
        $this->once[$key] = true;
        $this->write($level, $message);
    }

    public function write(string $level, string $message): void
    {
        $line = self::PREFIX . ' ' . $level . ': ' . str_replace(["\r", "\n"], ' ', $message);
        try {
            ($this->sink)($line);
        } catch (\Throwable $e) {
            // C61: logging is never allowed to affect delivery. This is the
            // only place a failure is swallowed, and it is deliberate.
            unset($e);
        }
    }

    /**
     * Replace every address in a free-text message so recipients are never
     * logged (C60).
     */
    public static function redactAddresses(string $text): string
    {
        return (string) preg_replace('/[^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+/u', '[address]', $text);
    }
}
