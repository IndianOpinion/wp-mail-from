<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * A Site Health test that reports the effective mail identity.
 */
final class SiteHealth
{
    public const TEST = 'wpmf_identity';

    public function __construct(private readonly Report $report)
    {
    }

    /**
     * `site_status_tests` filter.
     *
     * @param mixed $tests
     * @return mixed
     */
    public function register(mixed $tests): mixed
    {
        if (is_array($tests)) {
            $tests['direct'][self::TEST] = [
                'label' => __('Mail From identity', 'wp-mail-from'),
                'test' => [$this, 'run'],
            ];
        }

        return $tests;
    }

    /**
     * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string}
     */
    public function run(): array
    {
        $data = $this->report->collect();
        $id = $data['identity'];
        $status = 'good';
        $label = sprintf(
            /* translators: 1: address, 2: name. */
            __('Mail is sent from %1$s as "%2$s"', 'wp-mail-from'),
            (string) $id->from,
            $id->fromName
        );
        $lines = [
            sprintf(
                /* translators: 1: source of the address, 2: explicit-From mode, 3: sender mode. */
                __('Address source: %1$s. Mail that sets its own From: %2$s. Envelope sender: %3$s.', 'wp-mail-from'),
                $id->fromSource,
                $id->explicitMode,
                $id->senderMode
            ),
        ];

        if (!$id->isValid()) {
            $status = 'critical';
            $label = __('No valid From address: WordPress defaults are being used', 'wp-mail-from');
            $lines[] = __('Set WPMF_FROM in wp-config.php to a mailbox on your domain that is read.', 'wp-mail-from');
        } elseif ($id->isDerived() || $id->warnings !== []) {
            $status = 'recommended';
            if ($id->isNoReply()) {
                $lines[] = __('The From address is a derived no-reply address. Bounces to it are probably lost. Configure a real mailbox.', 'wp-mail-from');
            } elseif ($id->isDerived()) {
                $lines[] = __('The From address is derived from the admin email. Set it explicitly.', 'wp-mail-from');
            }
            foreach ($id->warnings as $w) {
                $lines[] = sprintf('Invalid %s from %s skipped (%s).', $w['field'], $w['source'], $w['reason']);
            }
        }
        foreach ($data['smtp'] as $smtp) {
            /* translators: %s: plugin name. */
            $lines[] = sprintf(__('%s is active and may override the From address.', 'wp-mail-from'), $smtp['name']);
        }
        if ($data['wp_mail_replaced_by'] !== null) {
            /* translators: %s: file. */
            $lines[] = sprintf(__('wp_mail() is replaced by %s.', 'wp-mail-from'), $data['wp_mail_replaced_by']);
        }

        $description = '';
        foreach ($lines as $line) {
            $description .= '<p>' . esc_html($line) . '</p>';
        }

        return [
            'label' => $label,
            'status' => $status,
            'badge' => ['label' => __('Email', 'wp-mail-from'), 'color' => $status === 'good' ? 'blue' : 'orange'],
            'description' => $description,
            'actions' => '',
            'test' => self::TEST,
        ];
    }
}
