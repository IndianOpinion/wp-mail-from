<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * What an operator needs to see: the effective identity, sources, modes,
 * SMTP plugins, a replaced wp_mail() and every warning. Shared by the CLI,
 * the admin notices and Site Health.
 */
final class Report
{
    public const TRANSPORT_CAVEAT = 'envelope sender requested; the transport may override it';

    public function __construct(
        private readonly Resolver $resolver,
        private readonly SmtpDetector $detector,
        private readonly ?string $corePluggable = null
    ) {
    }

    /**
     * @return array{
     *     identity: Identity,
     *     smtp: list<array{name: string, marker: string}>,
     *     wp_mail_replaced_by: ?string,
     *     duplicates: list<string>,
     *     active_file: string
     * }
     */
    public function collect(): array
    {
        $identity = $this->resolver->resolve();

        return [
            'identity' => $identity,
            'smtp' => $this->detector->detect(),
            'wp_mail_replaced_by' => $this->detector->wpMailReplacement($this->corePluggable),
            'duplicates' => Plugin::duplicates(),
            'active_file' => Plugin::activeFile(),
        ];
    }

    /**
     * Rows for `wp mail-from show` in table or YAML form.
     *
     * @return list<array{field: string, value: string, source: string}>
     */
    public function rows(): array
    {
        $data = $this->collect();
        $id = $data['identity'];
        $rows = [
            $this->row('from', $id->from ?? '(none: core default kept)', $id->fromSource),
            $this->row('from_name', $id->fromName, $id->fromNameSource),
            $this->row('reply_to', $id->replyTo ?? '', $id->replyToSource),
            $this->row('return_path', $id->returnPath ?? '(final From)', $id->returnPathSource),
            $this->row('explicit_mode', $id->explicitMode, $id->explicitModeSource),
            $this->row('sender_mode', $id->senderMode, $id->senderModeSource),
            $this->row('smtp_mode', $id->smtpMode, $id->smtpModeSource),
            $this->row('dry_run', $id->dryRun ? 'on' : 'off', $id->dryRunSource),
            $this->row('log_failures', $id->logFailures ? 'on' : 'off', $id->logFailuresSource),
            $this->row('site_domain', $id->siteDomain, 'home URL'),
            $this->row('core_default', $id->coreDefault, 'network home URL'),
            $this->row('envelope_sender', $id->senderMode === 'never' ? 'not touched' : self::TRANSPORT_CAVEAT, 'note'),
        ];
        if ($id->isDerived()) {
            $rows[] = $this->row(
                'note',
                'From is derived; the plugin cannot know that this mailbox exists',
                'derived'
            );
        }
        foreach ($data['smtp'] as $smtp) {
            $rows[] = $this->row('smtp_plugin', $smtp['name'] . ' (may override From)', $smtp['marker']);
        }
        $rows[] = $this->row(
            'wp_mail',
            $data['wp_mail_replaced_by'] === null
                ? 'core'
                : 'wp_mail() is replaced by ' . $data['wp_mail_replaced_by'] . '; it may ignore the From filters',
            'detected'
        );
        foreach ($data['duplicates'] as $file) {
            $rows[] = $this->row('duplicate_copy', $file . ' (ignored; active: ' . $data['active_file'] . ')', 'detected');
        }
        foreach ($id->warnings as $warning) {
            $rows[] = $this->row(
                'warning',
                sprintf('invalid %s "%s" (%s)', $warning['field'], $warning['value'], $warning['reason']),
                $warning['source']
            );
        }
        if (!$id->isValid()) {
            $rows[] = $this->row('error', 'no valid From address; the plugin leaves core values in place', 'resolution');
        }

        return $rows;
    }

    /**
     * Structured form for `--format=json`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->collect();
        $id = $data['identity'];

        return [
            'valid' => $id->isValid(),
            'site_id' => $id->siteId,
            'site_domain' => $id->siteDomain,
            'core_default' => $id->coreDefault,
            'from' => ['value' => $id->from, 'source' => $id->fromSource, 'derived' => $id->isDerived()],
            'from_name' => ['value' => $id->fromName, 'source' => $id->fromNameSource],
            'reply_to' => ['value' => $id->replyTo, 'source' => $id->replyToSource],
            'return_path' => ['value' => $id->returnPath, 'source' => $id->returnPathSource],
            'explicit_mode' => ['value' => $id->explicitMode, 'source' => $id->explicitModeSource],
            'sender_mode' => ['value' => $id->senderMode, 'source' => $id->senderModeSource],
            'smtp_mode' => ['value' => $id->smtpMode, 'source' => $id->smtpModeSource],
            'dry_run' => ['value' => $id->dryRun, 'source' => $id->dryRunSource],
            'log_failures' => ['value' => $id->logFailures, 'source' => $id->logFailuresSource],
            'envelope_sender' => $id->senderMode === 'never' ? 'not touched' : self::TRANSPORT_CAVEAT,
            'smtp_plugins' => $data['smtp'],
            'wp_mail_replaced_by' => $data['wp_mail_replaced_by'],
            'duplicates' => $data['duplicates'],
            'warnings' => $id->warnings,
        ];
    }

    /**
     * @return array{field: string, value: string, source: string}
     */
    private function row(string $field, string $value, string $source): array
    {
        return ['field' => $field, 'value' => $value, 'source' => $source];
    }
}
