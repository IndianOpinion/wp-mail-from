=== Mail From (wp-mail-from) ===
Contributors: indianopinion
Tags: email, wp_mail, from address, dmarc, mu-plugin
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Sets a real From address, From name and envelope sender on every wp_mail() message, without breaking mail that sets its own From.

== Description ==

WordPress sends every message as wordpress@ your domain with the name "WordPress", from a mailbox that almost never exists. Bounces vanish and receiving servers distrust the mail.

This plugin replaces that default with a real identity, and does it carefully:

* Reads constants in wp-config.php first, then the settings, then a sensible derived default.
* Leaves a From set on purpose by a contact form or shop alone by default, with "align" and "override" modes for DMARC alignment. A replaced From moves to Reply-To so replies still arrive.
* Fills the envelope sender (Return-Path) only when nothing else set it, and never leaks it from one message to the next.
* Validates every address before PHPMailer sees it, so a bad setting never loses mail.
* Detects WP Mail SMTP, FluentSMTP, Post SMTP and Easy WP SMTP and lets them win.
* Multisite: network defaults, locked fields, per-site identities, and no sending as another site's domain.
* Logs every wp_mail() failure with the prefix [wp-mail-from], without recipients.
* Dry run, a Site Health test and the WP-CLI command "wp mail-from show".

It never sends mail itself, never configures SMTP and never touches DNS.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

As a must-use plugin (recommended, so it cannot be deactivated by accident):

1. Put the plugin directory at wp-content/mu-plugins/wp-mail-from/.
2. Copy wp-content/mu-plugins/wp-mail-from/mu-loader/wp-mail-from-loader.php to wp-content/mu-plugins/.

As a normal plugin: upload the zip in Plugins, Add New, and activate it.

Then add to wp-config.php:

`define( 'WPMF_FROM', 'mail@example.org' );`
`define( 'WPMF_FROM_NAME', 'Example News' );`

Or set the two fields on Settings, General.

== Frequently Asked Questions ==

= Will it break my contact form? =

Not in the default mode: a From the form sets is kept. In align or override mode the visitor's address moves to Reply-To.

= I use an SMTP plugin. Do I need this? =

Often not for From. It still helps with the envelope sender, failure logging and multisite rules. By default the SMTP plugin's From wins.

= Where are the logs? =

In PHP's error log, or wp-content/debug.log when WP_DEBUG_LOG is on. Search for [wp-mail-from].

= What DNS do I still need? =

SPF, DKIM and DMARC records for your domain. The README on GitHub lists what each needs.

== Changelog ==

= 0.1.0 =
* First release.
