<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * The configured identity for one site, with the source of every value.
 */
final class Identity
{
    /**
     * @param list<array{field: string, source: string, value: string, reason: string}> $warnings
     */
    public function __construct(
        public readonly int $siteId,
        public readonly string $siteDomain,
        public readonly string $coreDefault,
        public readonly ?string $from,
        public readonly string $fromSource,
        public readonly string $fromName,
        public readonly string $fromNameSource,
        public readonly ?string $replyTo,
        public readonly string $replyToSource,
        public readonly ?string $returnPath,
        public readonly string $returnPathSource,
        public readonly string $explicitMode,
        public readonly string $explicitModeSource,
        public readonly string $senderMode,
        public readonly string $senderModeSource,
        public readonly string $smtpMode,
        public readonly string $smtpModeSource,
        public readonly bool $dryRun,
        public readonly string $dryRunSource,
        public readonly bool $logFailures,
        public readonly string $logFailuresSource,
        public readonly array $warnings = []
    ) {
    }

    /**
     * False when every source, including the derived default, is invalid
     * (C41). The plugin then leaves core values in place.
     */
    public function isValid(): bool
    {
        return $this->from !== null;
    }

    public function isDerived(): bool
    {
        return $this->fromSource === 'derived';
    }

    /**
     * True when the derived address had to fall back to no-reply because the
     * admin email is not on the site domain.
     */
    public function isNoReply(): bool
    {
        return $this->isDerived() && $this->from !== null && str_starts_with($this->from, 'no-reply@');
    }
}
