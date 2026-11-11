<?php

/**
 * Plugin Name: Mail From: decision monitor
 * Description: Counts wp-mail-from decisions per day in an option, for a quick look with WP-CLI.
 *
 *     wp option get wpmf_example_decisions --format=json
 *
 * Example only: an option write per message is fine on a small site. On a
 * busy one, send the record to your metrics system instead.
 */

declare(strict_types=1);

add_action(
    'wpmf_decision',
    static function (array $record): void {
        $day = gmdate('Y-m-d');
        $counts = get_option('wpmf_example_decisions', []);
        $counts = is_array($counts) ? $counts : [];
        foreach ((array) ($record['actions'] ?? []) as $action) {
            $counts[$day][$action] = ($counts[$day][$action] ?? 0) + 1;
        }
        // Keep two weeks.
        $counts = array_slice($counts, -14, null, true);
        update_option('wpmf_example_decisions', $counts, false);
    }
);
