<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * The outcome of validating one address.
 */
final class AddressResult
{
    /**
     * @param bool   $valid   Whether the address may be handed to PHPMailer.
     * @param string $address The normalised address (domain lower-cased and in ASCII form), or the input when invalid.
     * @param string $reason  A short machine-readable reason when invalid, empty when valid.
     */
    public function __construct(
        public readonly bool $valid,
        public readonly string $address,
        public readonly string $reason = ''
    ) {
    }

    public static function ok(string $address): self
    {
        return new self(true, $address);
    }

    public static function fail(string $address, string $reason): self
    {
        return new self(false, $address, $reason);
    }
}
