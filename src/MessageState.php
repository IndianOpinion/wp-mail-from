<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * Everything decided for the message in flight. Created in the `wp_mail`
 * filter and dropped when the message ends.
 */
final class MessageState
{
    /**
     * @param list<array{name: string, marker: string}> $detected
     */
    public function __construct(
        public readonly Identity $identity,
        public readonly ParsedHeaders $headers,
        public readonly Decision $decision,
        public readonly string $mode,
        public readonly string $senderMode,
        public readonly string $smtpMode,
        public readonly bool $dryRun,
        public readonly array $detected
    ) {
    }

    public function withDecision(Decision $decision): self
    {
        return new self(
            $this->identity,
            $this->headers,
            $decision,
            $this->mode,
            $this->senderMode,
            $this->smtpMode,
            $this->dryRun,
            $this->detected
        );
    }
}
