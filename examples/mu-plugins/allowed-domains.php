<?php

/**
 * Plugin Name: Mail From: shared mail domain
 * Description: Lets site administrators on a network use a shared mail domain.
 *
 * Without this, a site administrator who is not a super admin may only save
 * an address on the site's own domain (or a domain listed on Network
 * Settings).
 */

declare(strict_types=1);

add_filter(
    'wpmf_allowed_domains',
    static function (array $domains, int $siteId): array {
        $domains[] = 'mail.example.org';

        return $domains;
    },
    10,
    2
);
