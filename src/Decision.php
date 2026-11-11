<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * What the plugin decided for one message's From address and name.
 *
 * `action` is one of:
 * - `unchanged`: nothing about From is changed;
 * - `replace-default`: no explicit From, the core default is replaced;
 * - `override`: an explicit From is replaced (mode override);
 * - `align`: an unaligned explicit From is replaced (mode align);
 * - `set-name`: the explicit address is kept, only the name is changed (C23, C24);
 * - `skip`: the plugin does not act on this message.
 */
final class Decision
{
    /**
     * @param array{address: string, name: string}|null $moveToReplyTo The original From to move to Reply-To.
     */
    public function __construct(
        public readonly string $action,
        public readonly string $mode,
        public readonly string $fromBefore,
        public readonly string $fromAfter,
        public readonly string $nameBefore,
        public readonly string $nameAfter,
        public readonly string $reason,
        public readonly bool $explicit = false,
        public readonly bool $explicitAligned = false,
        public readonly ?array $moveToReplyTo = null
    ) {
    }

    /**
     * True when the decision changes the From address or name.
     */
    public function changesFrom(): bool
    {
        return $this->fromAfter !== $this->fromBefore || $this->nameAfter !== $this->nameBefore;
    }
}
