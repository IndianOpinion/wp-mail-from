<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * The per-message hooks around wp_mail() (C17 to C38, C46 to C50, C57 to
 * C65).
 *
 * Order inside one wp_mail() call:
 *
 * 1. `wp_mail` (late): reset state, read headers, decide.
 * 2. `pre_wp_mail` (last): a short-circuit clears state.
 * 3. `wp_mail_from`, `wp_mail_from_name` (early): apply the From decision.
 * 4. `phpmailer_init` (late): Reply-To, Sender, decision action, dry-run log.
 * 5. `wp_mail_succeeded` / `wp_mail_failed`: clear state; log failures.
 */
final class MailHandler
{
    public const PRIORITY_WP_MAIL = 1000;
    public const PRIORITY_FROM = 5;
    public const PRIORITY_PHPMAILER = 999;

    private ?MessageState $state = null;

    /** The Sender this plugin set on the shared PHPMailer object last time (C34). */
    private ?string $lastSenderSet = null;

    public function __construct(
        private readonly Resolver $resolver,
        private readonly HeaderParser $parser,
        private readonly DecisionEngine $engine,
        private readonly SmtpDetector $detector,
        private readonly Validator $validator,
        private readonly Logger $logger
    ) {
    }

    public function register(int $fromPriority): void
    {
        add_filter('wp_mail', [$this, 'onWpMail'], self::PRIORITY_WP_MAIL);
        add_filter('pre_wp_mail', [$this, 'onPreWpMail'], PHP_INT_MAX, 2);
        add_filter('wp_mail_from', [$this, 'filterFrom'], $fromPriority);
        add_filter('wp_mail_from_name', [$this, 'filterFromName'], $fromPriority);
        add_action('phpmailer_init', [$this, 'onPhpMailerInit'], self::PRIORITY_PHPMAILER);
        add_action('wp_mail_succeeded', [$this, 'onSucceeded'], 10, 1);
        add_action('wp_mail_failed', [$this, 'onFailed'], 10, 1);
    }

    /**
     * The state of the message in flight, for tests and diagnostics.
     */
    public function state(): ?MessageState
    {
        return $this->state;
    }

    /**
     * `wp_mail` filter: start a fresh message (C50, C62). Returns the
     * arguments unchanged.
     *
     * @param mixed $atts
     * @return mixed
     */
    public function onWpMail(mixed $atts): mixed
    {
        $this->state = null;
        $this->state = $this->evaluate(is_array($atts) ? $atts : []);

        return $atts;
    }

    /**
     * Compute the full state for a message without applying anything. Used
     * by the live path and by `wp mail-from show --message-from`.
     *
     * @param array<string, mixed> $atts The wp_mail() arguments.
     */
    public function evaluate(array $atts): MessageState
    {
        $identity = $this->resolver->resolve();
        $headers = $this->parser->parse($atts['headers'] ?? '');

        /**
         * Filters whether the plugin acts on this message at all.
         *
         * @param bool                 $handle
         * @param array<string, mixed> $atts The wp_mail() arguments.
         */
        $handle = (bool) apply_filters('wpmf_should_handle', true, $atts);

        $mode = $this->filteredChoice('wpmf_explicit_mode', $identity->explicitMode, Options::EXPLICIT_MODES, $atts);
        $senderMode = $this->filteredChoice('wpmf_sender_mode', $identity->senderMode, Options::SENDER_MODES, $atts);
        $smtpMode = $this->filteredChoice('wpmf_smtp_mode', $identity->smtpMode, Options::SMTP_MODES, $atts);

        /**
         * Filters dry run for this message.
         *
         * @param bool                 $dryRun
         * @param array<string, mixed> $atts
         */
        $dryRun = (bool) apply_filters('wpmf_dry_run', $identity->dryRun, $atts);

        $detected = $smtpMode === 'ignore' ? [] : $this->detector->detect();
        $skipReason = '';
        if (!$handle) {
            $skipReason = 'wpmf_should_handle returned false';
        } elseif ($smtpMode === 'stand-aside' && $detected !== []) {
            $skipReason = 'smtp mode stand-aside: ' . $detected[0]['name'] . ' detected';
        }

        if ($skipReason !== '') {
            $before = $headers->fromAddress ?? $identity->coreDefault;
            $name = $headers->fromName ?? Domains::CORE_DEFAULT_NAME;
            $decision = new Decision('skip', $mode, $before, $before, $name, $name, $skipReason);
        } else {
            $decision = $this->engine->decide($identity, $headers, $mode);
            if (
                $headers->hasFrom() && $decision->action === 'replace-default'
                && strcasecmp((string) $headers->fromAddress, $identity->coreDefault) !== 0
            ) {
                $this->logger->warning(
                    'invalid explicit From "' . Domains::mask((string) $headers->fromAddress)
                    . '"; using the configured identity'
                );
            }
        }

        return new MessageState($identity, $headers, $decision, $mode, $senderMode, $smtpMode, $dryRun, $detected);
    }

    /**
     * `pre_wp_mail` filter, last priority: when another plugin short-circuits
     * the message, nothing of it may carry over (C50).
     */
    public function onPreWpMail(mixed $return, mixed $atts = null): mixed
    {
        if ($return !== null) {
            $this->state = null;
        }

        return $return;
    }

    /**
     * `wp_mail_from` filter (C63).
     */
    public function filterFrom(mixed $from): mixed
    {
        $state = $this->stateFor(is_string($from) ? $from : '');
        if ($state === null || $state->dryRun || $state->decision->action === 'skip') {
            return $from;
        }

        return $state->decision->fromAfter;
    }

    /**
     * `wp_mail_from_name` filter (C63).
     */
    public function filterFromName(mixed $name): mixed
    {
        $state = $this->state;
        if ($state === null || $state->dryRun || $state->decision->action === 'skip') {
            return $name;
        }

        return $state->decision->nameAfter;
    }

    /**
     * `phpmailer_init` action (C29 to C38, C57 to C59).
     *
     * Anything that is not a PHPMailer instance is left alone.
     */
    public function onPhpMailerInit(mixed $mailer): void
    {
        if (!$mailer instanceof PHPMailer) {
            return;
        }

        // C34: a Sender this plugin set on the previous message is not
        // another plugin's choice. Treat it as empty.
        $leaked = $this->lastSenderSet !== null && $this->readString($mailer, 'Sender') === $this->lastSenderSet;
        $this->lastSenderSet = null;

        $state = $this->state;
        if ($state === null) {
            if ($leaked) {
                $mailer->Sender = '';
            }

            return;
        }

        $actions = in_array($state->decision->action, ['unchanged', 'skip'], true)
            ? []
            : [$state->decision->action];
        $replyToAdded = null;
        $senderBefore = $leaked ? '' : $this->readString($mailer, 'Sender');
        $senderAfter = $senderBefore;
        if ($leaked && !$state->dryRun) {
            $mailer->Sender = '';
        }

        if ($state->decision->action !== 'skip' && $state->identity->isValid()) {
            $replyToAdded = $this->applyReplyTo($mailer, $state);
            if ($replyToAdded !== null) {
                $actions[] = 'add-reply-to';
            }
            $senderAfter = $this->applySender($mailer, $state, $senderBefore);
            if ($senderAfter !== $senderBefore) {
                $actions[] = 'set-sender';
            }
        }

        if ($state->dryRun) {
            $actions = array_map(static fn (string $a): string => 'would-' . $a, $actions);
        }
        if ($actions === []) {
            $actions = [$state->decision->action === 'skip' ? 'skip' : 'unchanged'];
        }

        $record = [
            'mode' => $state->mode,
            'decision' => $actions[0],
            'actions' => $actions,
            'from_before' => $state->decision->fromBefore,
            'from_after' => $state->decision->fromAfter,
            'name_before' => $state->decision->nameBefore,
            'name_after' => $state->decision->nameAfter,
            'reply_to_added' => $replyToAdded,
            'sender_before' => $senderBefore,
            'sender_after' => $senderAfter,
            'sender_mode' => $state->senderMode,
            'smtp' => $state->detected,
            'reason' => $state->decision->reason,
            'dry_run' => $state->dryRun,
            'site_id' => $state->identity->siteId,
        ];

        /**
         * Fires once per message, in dry run and live mode alike (C59).
         *
         * @param array<string, mixed> $record
         */
        do_action('wpmf_decision', $record);

        if ($state->dryRun) {
            $this->logger->info($this->dryRunLine($record, $state));
        }
    }

    /**
     * `wp_mail_succeeded` action (C65).
     */
    public function onSucceeded(mixed $data = null): void
    {
        $this->state = null;
    }

    /**
     * `wp_mail_failed` action (C60, C65).
     */
    public function onFailed(mixed $error = null): void
    {
        $state = $this->state;
        $this->state = null;

        $enabled = $state !== null ? $state->identity->logFailures : $this->resolver->resolve()->logFailures;

        /**
         * Filters whether wp_mail() failures are logged.
         *
         * @param bool  $enabled
         * @param mixed $error The WP_Error passed to wp_mail_failed.
         */
        if (!(bool) apply_filters('wpmf_log_failures', $enabled, $error)) {
            return;
        }

        $message = 'unknown error';
        if (is_object($error) && method_exists($error, 'get_error_message')) {
            $text = $error->get_error_message();
            $message = is_string($text) && $text !== '' ? $text : $message;
        }
        $this->logger->error('wp_mail failed: ' . Logger::redactAddresses($message));
    }

    /**
     * The state matching the From value core is about to use. When a later
     * `wp_mail` filter changed the headers after this plugin read them, the
     * decision is made again from what core actually parsed.
     */
    private function stateFor(string $from): ?MessageState
    {
        $state = $this->state;
        if ($state === null || $state->decision->action === 'skip') {
            return $state;
        }
        if (strcasecmp($from, $state->decision->fromBefore) === 0) {
            return $state;
        }

        $address = strcasecmp($from, $state->identity->coreDefault) === 0 || $from === '' ? null : $from;
        $headers = $state->headers->withFromAddress($address);
        $this->state = $state->withDecision($this->engine->decide($state->identity, $headers, $state->mode));

        return $this->state;
    }

    /**
     * @return array{address: string, name: string}|null What was added (or would be, in dry run).
     */
    private function applyReplyTo(PHPMailer $mailer, MessageState $state): ?array
    {
        $candidate = $state->decision->moveToReplyTo;
        if ($candidate === null && $state->identity->replyTo !== null && !$state->headers->hasReplyTo()) {
            $candidate = ['address' => $state->identity->replyTo, 'name' => ''];
        }
        if ($candidate === null) {
            return null;
        }
        if ($mailer->getReplyToAddresses() !== []) {
            return null; // C29: another hook already set one.
        }
        if ($state->dryRun) {
            return $candidate;
        }
        try {
            if ($mailer->addReplyTo($candidate['address'], $candidate['name'])) {
                return $candidate;
            }
        } catch (\Exception $e) {
            $this->logger->warning('could not add Reply-To: ' . Logger::redactAddresses($e->getMessage()));
        }

        return null;
    }

    private function applySender(PHPMailer $mailer, MessageState $state, string $current): string
    {
        if ($state->senderMode === 'never') {
            return $current;
        }
        if ($state->senderMode === 'if-empty' && $current !== '') {
            return $current;
        }

        // C33: follow the final From on the mailer, not this plugin's own choice.
        $value = $state->identity->returnPath;
        if ($value === null) {
            $final = $this->readString($mailer, 'From');
            if ($state->dryRun && strcasecmp($final, $state->decision->fromBefore) === 0) {
                // Dry run: the From change was not applied; use what it would be.
                $final = $state->decision->fromAfter;
            }
            $result = $this->validator->check($final);
            if (!$result->valid) {
                return $current;
            }
            $value = $result->address;
        }
        if ($value === $current) {
            return $current;
        }
        if (!$state->dryRun) {
            $mailer->Sender = $value;
            $this->lastSenderSet = $value;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function dryRunLine(array $record, MessageState $state): string
    {
        $show = function (string $address) use ($state): string {
            if ($address === '') {
                return '';
            }
            $domain = Domains::domainOf($address);

            return Domains::aligned($domain, $state->identity->siteDomain) ? $address : Domains::mask($address);
        };

        return sprintf(
            'dry-run decision=%s mode=%s from="%s"->"%s" name="%s"->"%s" sender="%s"->"%s" reason="%s"',
            implode(',', (array) $record['actions']),
            $state->mode,
            $show($state->decision->fromBefore),
            $show($state->decision->fromAfter),
            $state->decision->nameBefore,
            $state->decision->nameAfter,
            $show((string) $record['sender_before']),
            $show((string) $record['sender_after']),
            $state->decision->reason
        );
    }

    /**
     * @param non-empty-string     $filter
     * @param list<string>         $allowed
     * @param array<string, mixed> $atts
     */
    private function filteredChoice(string $filter, string $value, array $allowed, array $atts): string
    {
        /**
         * Filters a mode for one message: wpmf_explicit_mode, wpmf_sender_mode or wpmf_smtp_mode.
         *
         * @param string               $value
         * @param array<string, mixed> $atts
         */
        $filtered = apply_filters($filter, $value, $atts);
        if (is_string($filtered) && in_array($filtered, $allowed, true)) {
            return $filtered;
        }
        $this->logger->once(
            $filter . '-unknown',
            'warning',
            sprintf('%s returned an unknown value; using "%s"', $filter, $allowed[0])
        );

        return $allowed[0];
    }

    private function readString(PHPMailer $mailer, string $property): string
    {
        $value = $mailer->{$property} ?? '';

        return is_string($value) ? $value : '';
    }
}
