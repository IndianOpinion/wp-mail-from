<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom\Cli;

use IndianOpinion\MailFrom\MailHandler;
use IndianOpinion\MailFrom\Options;
use IndianOpinion\MailFrom\Report;
use IndianOpinion\MailFrom\Resolver;
use IndianOpinion\MailFrom\Settings;

/**
 * Shows and manages the mail From identity.
 */
final class Command
{
    public function __construct(
        private readonly Report $report,
        private readonly Resolver $resolver,
        private readonly MailHandler $handler,
        private readonly Settings $settings
    ) {
    }

    /**
     * Shows the effective From identity for the current site.
     *
     * Exit status is 0 when the identity is valid, 1 when the plugin would
     * leave the WordPress defaults in place.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * [--message-from=<value>]
     * : Show what the plugin would do to a message with this From header value. Nothing is sent.
     *
     * ## EXAMPLES
     *
     *     wp mail-from show
     *     wp mail-from show --format=json
     *     wp mail-from show --message-from="Visitor <visitor@example.net>"
     *     wp mail-from show --url=shop.example.org
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function show(array $args, array $assoc): void
    {
        $format = $assoc['format'] ?? 'table';
        if (!in_array($format, ['table', 'json', 'yaml'], true)) {
            \WP_CLI::error('Unknown format: ' . $format);
        }
        $this->resolver->flush();

        if (isset($assoc['message-from'])) {
            $this->preview((string) $assoc['message-from'], $format);
        } elseif ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode($this->report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            \WP_CLI\Utils\format_items($format, $this->report->rows(), ['field', 'value', 'source']);
        }

        if (!$this->resolver->resolve()->isValid()) {
            \WP_CLI::halt(1);
        }
    }

    /**
     * Stores one setting for the current site, or for the network.
     *
     * ## OPTIONS
     *
     * <field>
     * : One of from, from_name, reply_to, return_path, explicit_mode, sender_mode, smtp_mode, dry_run, log_failures.
     *
     * <value>
     * : The value. An empty string clears the field.
     *
     * [--network]
     * : Store as a network default (multisite).
     *
     * ## EXAMPLES
     *
     *     wp mail-from set from mail@example.org
     *     wp mail-from set explicit_mode align
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function set(array $args, array $assoc): void
    {
        [$field, $value] = $args + ['', ''];
        if (!in_array($field, Options::LOCKABLE_FIELDS, true)) {
            \WP_CLI::error('Unknown field: ' . $field);
        }
        $network = isset($assoc['network']);
        if ($network && !is_multisite()) {
            \WP_CLI::error('--network needs a multisite install.');
        }
        $result = $this->settings->sanitizeField($field, $value);
        if (!$result['ok']) {
            \WP_CLI::error($result['error']);
        }

        if ($network) {
            $stored = get_site_option(Options::NETWORK_OPTION, []);
            $stored = is_array($stored) ? $stored : [];
            $stored[$field] = $result['value'];
            update_site_option(Options::NETWORK_OPTION, $stored);
        } else {
            $stored = get_option(Options::SITE_OPTION, []);
            $stored = is_array($stored) ? $stored : [];
            $stored[$field] = $result['value'];
            update_option(Options::SITE_OPTION, $stored);
        }
        $this->resolver->flush();
        if (!$network && $this->settings->lockedOnNetwork($field)) {
            \WP_CLI::warning($field . ' is locked at network level; this site value is ignored.');
        }
        if (defined(Options::CONSTANTS[$field])) {
            \WP_CLI::warning(Options::CONSTANTS[$field] . ' is defined in wp-config.php and takes precedence.');
        }
        \WP_CLI::success(sprintf('%s saved.', $field));
    }

    /**
     * Deletes the stored settings for the current site, or the network.
     *
     * ## OPTIONS
     *
     * [--network]
     * : Delete the network settings instead (multisite).
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function reset(array $args, array $assoc): void
    {
        $network = isset($assoc['network']);
        \WP_CLI::confirm($network ? 'Delete the network Mail From settings?' : 'Delete this site\'s Mail From settings?', $assoc);
        if ($network) {
            delete_site_option(Options::NETWORK_OPTION);
        } else {
            delete_option(Options::SITE_OPTION);
        }
        $this->resolver->flush();
        \WP_CLI::success('Settings deleted. Constants in wp-config.php, if any, still apply.');
    }

    private function preview(string $from, string $format): void
    {
        $state = $this->handler->evaluate([
            'to' => '',
            'subject' => '',
            'message' => '',
            'headers' => ['From: ' . str_replace(["\r", "\n"], ' ', $from)],
            'attachments' => [],
        ]);
        $d = $state->decision;
        $move = $d->moveToReplyTo;
        $data = [
            'decision' => $d->action,
            'mode' => $state->mode,
            'from_before' => $d->fromBefore,
            'from_after' => $d->fromAfter,
            'name_before' => $d->nameBefore,
            'name_after' => $d->nameAfter,
            'reply_to_moved' => $move === null ? '' : $move['address'],
            'reason' => $d->reason,
            'dry_run' => $state->dryRun ? 'on' : 'off',
            'envelope_sender' => $state->senderMode === 'never' ? 'not touched' : Report::TRANSPORT_CAVEAT,
        ];
        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }
        $rows = [];
        foreach ($data as $field => $value) {
            $rows[] = ['field' => $field, 'value' => (string) $value];
        }
        \WP_CLI\Utils\format_items($format, $rows, ['field', 'value']);
    }
}
