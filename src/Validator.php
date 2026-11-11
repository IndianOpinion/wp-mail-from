<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * Address and display-name validation (cases C39 and C42).
 *
 * Every value that can reach PHPMailer passes through here, including the
 * return values of this plugin's own filters.
 */
final class Validator
{
    public const MAX_ADDRESS_LENGTH = 254;
    public const MAX_NAME_LENGTH = 255;

    /** @var (callable(string): (string|false))|null */
    private $idnToAscii;

    /**
     * @param (callable(string): (string|false))|null|false $idnToAscii Converter for Unicode domains.
     *        Null means "use ext-intl when it is loaded"; false means "no converter".
     */
    public function __construct(callable|null|false $idnToAscii = null)
    {
        if ($idnToAscii === null) {
            $idnToAscii = function_exists('idn_to_ascii')
                ? static fn (string $domain): string|false => idn_to_ascii(
                    $domain,
                    IDNA_NONTRANSITIONAL_TO_ASCII,
                    INTL_IDNA_VARIANT_UTS46
                )
                : false;
        }
        $this->idnToAscii = $idnToAscii === false ? null : $idnToAscii;
    }

    /**
     * Validate one address. The result passes through the
     * `wpmf_validate_address` filter, after which the hard rules (no line
     * breaks, length limit) are applied again so a filter can never open a
     * header-injection hole.
     */
    public function validate(string $input): AddressResult
    {
        $result = $this->check($input);

        /**
         * Filters the validator result for one address.
         *
         * @param AddressResult $result The result.
         * @param string        $input  The raw input.
         */
        $filtered = apply_filters('wpmf_validate_address', $result, $input);
        if ($filtered instanceof AddressResult && $filtered !== $result) {
            $result = $filtered;
            if ($result->valid) {
                $hard = $this->hardRules($result->address);
                if ($hard !== '') {
                    $result = AddressResult::fail($result->address, $hard);
                }
            }
        }

        return $result;
    }

    /**
     * The rules without the filter.
     */
    public function check(string $input): AddressResult
    {
        $address = trim($input);
        if ($address === '') {
            return AddressResult::fail($input, 'empty');
        }
        $hard = $this->hardRules($address);
        if ($hard !== '') {
            return AddressResult::fail($input, $hard);
        }

        $at = strrpos($address, '@');
        if ($at === false || $at === 0 || $at === strlen($address) - 1) {
            return AddressResult::fail($input, 'not-an-address');
        }
        $local = substr($address, 0, $at);
        $domain = substr($address, $at + 1);

        if (str_starts_with($domain, '[') || filter_var(trim($domain, '[]'), FILTER_VALIDATE_IP) !== false) {
            return AddressResult::fail($input, 'ip-literal-domain');
        }

        if (preg_match('/[^\x20-\x7e]/', $domain) === 1) {
            if ($this->idnToAscii === null) {
                return AddressResult::fail($input, 'unicode-domain-needs-intl');
            }
            $ascii = ($this->idnToAscii)($domain);
            if (!is_string($ascii) || $ascii === '') {
                return AddressResult::fail($input, 'unicode-domain-not-convertible');
            }
            $domain = $ascii;
        }
        $domain = strtolower($domain);

        if (!str_contains($domain, '.')) {
            return AddressResult::fail($input, 'domain-without-dot');
        }

        $normalised = $local . '@' . $domain;
        if (strlen($normalised) > self::MAX_ADDRESS_LENGTH) {
            return AddressResult::fail($input, 'too-long');
        }
        if (!is_email($normalised)) {
            return AddressResult::fail($input, 'rejected-by-is-email');
        }

        return AddressResult::ok($normalised);
    }

    /**
     * Clean a display name: control characters removed, whitespace trimmed
     * and collapsed, length capped. Returns an empty string when nothing is
     * left, which callers treat as "not set".
     */
    public function cleanName(string $name): string
    {
        $clean = preg_replace('/[\p{Cc}\x{2028}\x{2029}]+/u', ' ', $name);
        if ($clean === null) {
            // Not valid UTF-8: fall back to a byte-level clean.
            $clean = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name);
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        if (function_exists('mb_substr') && preg_match('//u', $clean) === 1) {
            return trim(mb_substr($clean, 0, self::MAX_NAME_LENGTH, 'UTF-8'));
        }

        return trim(substr($clean, 0, self::MAX_NAME_LENGTH));
    }

    private function hardRules(string $address): string
    {
        if (preg_match('/[\r\n\0]/', $address) === 1) {
            return 'contains-line-break';
        }
        if (strlen($address) > self::MAX_ADDRESS_LENGTH) {
            return 'too-long';
        }

        return '';
    }
}
