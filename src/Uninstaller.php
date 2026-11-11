<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

use IndianOpinion\MailFrom\Admin\NetworkSettings;
use IndianOpinion\MailFrom\Admin\Notices;

/**
 * Removes everything the plugin stored: the per-site option on every site,
 * the network option and the notice user meta (PRD section 6).
 */
final class Uninstaller
{
    public static function run(): void
    {
        if (is_multisite()) {
            $sites = get_sites(['fields' => 'ids', 'number' => 0]);
            foreach (is_array($sites) ? $sites : [] as $siteId) {
                switch_to_blog((int) $siteId);
                delete_option(Options::SITE_OPTION);
                restore_current_blog();
            }
            delete_site_option(Options::NETWORK_OPTION);
        } else {
            delete_option(Options::SITE_OPTION);
        }

        foreach ([...Notices::metaKeys(), NetworkSettings::ERRORS_META] as $key) {
            delete_metadata('user', 0, $key, '', true);
        }
    }
}
