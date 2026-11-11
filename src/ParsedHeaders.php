<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * What the plugin needs from the headers of one message, read the way
 * WordPress core reads them.
 */
final class ParsedHeaders
{
    /**
     * @param string|null  $fromAddress The From address core will use, or null when the headers set none.
     * @param string|null  $fromName    The From name core will use, or null when the headers set none.
     * @param list<string> $replyTo     Every Reply-To entry, one per comma-separated address.
     */
    public function __construct(
        public readonly ?string $fromAddress = null,
        public readonly ?string $fromName = null,
        public readonly array $replyTo = []
    ) {
    }

    public function hasFrom(): bool
    {
        return $this->fromAddress !== null;
    }

    public function hasReplyTo(): bool
    {
        return $this->replyTo !== [];
    }

    /**
     * Copy with a different From address, used when a later filter changed
     * what core parsed.
     */
    public function withFromAddress(?string $address): self
    {
        return new self($address, $this->fromName, $this->replyTo);
    }
}
