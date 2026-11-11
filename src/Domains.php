<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * Domain helpers: the site domain, the core default address and alignment.
 */
final class Domains
{
    /** The local part WordPress core uses for its default sender. */
    public const CORE_LOCAL_PART = 'wordpress';

    /** The display name WordPress core uses when no name is given. */
    public const CORE_DEFAULT_NAME = 'WordPress';

    /**
     * The address core builds for the default From: `wordpress@` plus the
     * network home host with a leading `www.` removed. Built the same way
     * core does it, case included.
     */
    public static function coreDefaultAddress(): string
    {
        $host = wp_parse_url(network_home_url(), PHP_URL_HOST);
        $address = self::CORE_LOCAL_PART . '@';
        if (is_string($host)) {
            if (str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
            }
            $address .= $host;
        }

        return $address;
    }

    /**
     * The current site's domain: home URL host, leading `www.` removed,
     * lower-cased, ASCII form. Follows switch_to_blog() and domain mapping
     * because it reads the home URL of the site being served.
     */
    public static function siteDomain(?Validator $validator = null): string
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $validator ??= new Validator();
            $result = $validator->check('x@' . $host);
            $host = $result->valid ? substr($result->address, 2) : $host;
        }

        return $host;
    }

    /**
     * The domain part of an address, lower-cased. Empty when there is none.
     */
    public static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : strtolower(trim(substr($address, $at + 1), " \t<>[]"));
    }

    /**
     * Relaxed alignment as DMARC uses it: equal, or one is a subdomain of
     * the other. No public suffix list is used (see README, Limitations).
     */
    public static function aligned(string $domain, string $siteDomain): bool
    {
        $domain = strtolower(trim($domain));
        $siteDomain = strtolower(trim($siteDomain));
        if ($domain === '' || $siteDomain === '') {
            return false;
        }

        return $domain === $siteDomain
            || str_ends_with($domain, '.' . $siteDomain)
            || str_ends_with($siteDomain, '.' . $domain);
    }

    /**
     * Strict containment: the domain is the site domain or a subdomain of it.
     * Used for the multisite save rule, where the reverse direction would let
     * a subsite send as the network's parent domain.
     */
    public static function within(string $domain, string $siteDomain): bool
    {
        $domain = strtolower(trim($domain));
        $siteDomain = strtolower(trim($siteDomain));
        if ($domain === '' || $siteDomain === '') {
            return false;
        }

        return $domain === $siteDomain || str_ends_with($domain, '.' . $siteDomain);
    }

    /**
     * Mask the local part of an address for logs: `j***@example.org`.
     */
    public static function mask(string $address): string
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return '***';
        }
        $local = substr($address, 0, $at);
        $first = $local === '' ? '' : substr($local, 0, 1);

        return $first . '***' . substr($address, $at);
    }
}
