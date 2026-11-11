# Architecture

This document describes how wp-mail-from is built and records every decision
taken while building it from [PRD.md](PRD.md), including the answers to the
PRD's open questions (section 13).

## Layout

```text
wp-mail-from.php            Main plugin file: header, duplicate guard, boot.
uninstall.php               Normal-plugin uninstall; skips when a must-use copy is active.
mu-loader/
  wp-mail-from-loader.php   The file that goes into mu-plugins/.
src/
  autoload.php              PSR-4 autoloader for runs without Composer.
  Plugin.php                Wires services, registers hooks, duplicate detection.
  Options.php               Field list, constants map, raw reads of constants and options.
  Validator.php             Address and name rules (C39, C42).
  AddressResult.php         Value object returned by the validator.
  Domains.php               Site domain, core default address, alignment, masking.
  Resolver.php              Source precedence, derived defaults, per-site cache (C1 to C8, C40, C41).
  Identity.php              The resolved identity with the source of every value.
  HeaderParser.php          Reads From and Reply-To exactly as core does (C9 to C19).
  ParsedHeaders.php         What the parser found.
  DecisionEngine.php        Pure From decision for one message (C17 to C25).
  Decision.php              The decision value object.
  MessageState.php          Everything decided for the message in flight.
  MailHandler.php           The wp_mail hooks: apply, Reply-To, Sender, logging (C26 to C38, C46 to C65).
  SmtpDetector.php          SMTP plugin markers and replaced wp_mail() (C45, C49).
  Settings.php              Validation of stored settings (C43, C44).
  Report.php                Diagnostics shared by CLI, notices and Site Health.
  SiteHealth.php            Site Health direct test.
  Logger.php                Prefixed one-line logging that never throws (C60, C61).
  Uninstaller.php           Removes options and user meta.
  Admin/GeneralSettings.php Two fields on Settings, General (C7, C54).
  Admin/NetworkSettings.php Network defaults, locks, allowed domains.
  Admin/Notices.php         Dismissible notices.
  Cli/Command.php           wp mail-from show | set | reset.
tests/
  stubs/                    A small model of WordPress, PHPMailer and WP-CLI.
  Unit/                     PHPUnit tests, named after the PRD's T1 to T68.
examples/                   Runnable samples.
```

## Data flow

```text
wp_mail($to, $subject, $message, $headers)
  |
  |-- filter wp_mail (priority 1000)
  |     MailHandler::onWpMail
  |       Resolver::resolve()       constants > site option > network option > derived, then filters
  |       HeaderParser::parse()     From, name, Reply-To, as core reads them
  |       per-message filters       should_handle, explicit/sender/smtp mode, dry run
  |       SmtpDetector::detect()    once per request
  |       DecisionEngine::decide()  -> MessageState
  |
  |-- filter pre_wp_mail (PHP_INT_MAX)   short-circuit: drop state
  |
  |-- core parses headers, builds wordpress@<network host> if no From
  |-- filter wp_mail_from (5)        -> decision->fromAfter (re-decides if a later filter changed headers)
  |-- filter wp_mail_from_name (5)   -> decision->nameAfter
  |-- PHPMailer::setFrom($from, $name, false)
  |
  |-- action phpmailer_init (999)
  |     MailHandler::onPhpMailerInit
  |       clear a Sender this plugin left on the shared object last time
  |       add Reply-To (moved From, else configured) when the mailer has none
  |       set Sender per mode, from return path or the mailer's final From
  |       action wpmf_decision; dry-run log line
  |
  |-- send
  |-- action wp_mail_succeeded | wp_mail_failed   drop state; log failure
```

The decision is computed once, in the `wp_mail` filter, and applied in the
From filters and `phpmailer_init`. The same `MailHandler::evaluate()` runs for
`wp mail-from show --message-from`, so the preview and the live path cannot
disagree.

## Decisions on the PRD's open questions

### 1. Default explicit-From mode: `respect`

The default is `respect`. Contact forms, shops and newsletter plugins set
From on purpose, and silently redirecting replies is a worse failure than an
unaligned From, which is visible in DMARC reports. `align` exists for
operators who want DMARC alignment; the README states the trade-off in its
own section ("Mail that sets its own From") with a table of the three modes.

### 2. Does core reset PHPMailer's Sender between wp_mail() calls?

No. Checked against WordPress core's `wp-includes/pluggable.php` on GitHub,
on the `6.4-branch` and `6.8-branch` of WordPress/WordPress and on `trunk` of
WordPress/wordpress-develop (11 November 2026):

- `wp_mail()` keeps one global PHPMailer object and only creates it when it
  is missing.
- Per call it runs `clearAllRecipients()`, `clearAttachments()`,
  `clearCustomHeaders()`, `clearReplyTos()` and empties `Body` and `AltBody`.
  It never assigns `Sender`.
- It calls `setFrom( $from_email, $from_name, false )`. The third argument,
  `$auto = false`, stops PHPMailer copying From into Sender.
- PHPMailer's `clearAllRecipients()` does not touch `Sender`. PHPMailer itself
  sets `Sender` from the `sendmail_from` ini value when it is empty during a
  `mail()` send, and that value then also persists.

So a Sender set during one message stays on the object for the next. The
plugin records the value it set and, on the next message, treats a Sender
equal to that value as empty (and clears it when it will not set one). This
is implemented regardless of what core does, so a future core change that
resets Sender leaves the tracking harmless. Tested in `MailFlowTest::
testSenderDoesNotLeak` against a stub that reproduces the reuse.

Related finding: trunk adds a sixth `$embeds` argument to `wp_mail()`; it
does not affect From, Reply-To or Sender.

### 3. SMTP plugin markers

Detection uses public markers only and never reads another plugin's settings
or source. A plugin counts as detected when any one marker matches.

| Plugin | Directory slug (primary) | Other public markers |
|---|---|---|
| WP Mail SMTP | `wp-mail-smtp` | constant `WPMS_PLUGIN_VER`, class `WPMailSMTP\Core`, function `wp_mail_smtp` |
| FluentSMTP | `fluent-smtp` | constants `FLUENTMAIL`, `FLUENTMAIL_PLUGIN_VERSION` |
| Post SMTP | `post-smtp` | constant `POST_SMTP_VER`, class `PostmanOptions` |
| Easy WP SMTP | `easy-wp-smtp` | constant `EasyWPSMTP_PLUGIN_VERSION`, classes `EasyWPSMTP\Core`, `EasyWPSMTP` |

Sources and confidence:

- Slugs: the wordpress.org plugin directory. `wp-mail-smtp`, `fluent-smtp`,
  `post-smtp` and `easy-wp-smtp` were each confirmed through the public
  plugins API (`api.wordpress.org/plugins/info/1.2/`) on 11 November 2026.
  The plugin's folder is its slug, so `active_plugins` (and
  `active_sitewide_plugins` on multisite) entries starting with `<slug>/`
  match. This is the reliable marker.
- Constants, classes and functions: well-known public names of each plugin
  as they appear in public integration code and support answers. Project
  rules forbid reading third-party plugin source, and they were not checked
  against it or against vendor documentation in this build: treat them as
  best effort. They are secondary: they catch a plugin that was loaded from
  a renamed folder or as a must-use plugin. A wrong name can only fail to
  match, never cause a false positive, because each is specific to its
  plugin. Corrections are welcome as issues; operators can fix them at once
  with `wpmf_smtp_markers`.
- Whether each replaces `wp_mail()` is not hard-coded. It is detected at run
  time for whatever plugin defines it: `ReflectionFunction('wp_mail')` gives
  the defining file, which is compared with core's `wp-includes/pluggable.php`.
  This covers any replacement, listed or not.

Override: `wpmf_smtp_markers` changes the list, `wpmf_smtp_detection`
changes the result (return `[]` to act as if none were present), and
`wpmf_smtp_mode` chooses defer, stand-aside or ignore per message. In the
default `defer` mode the plugin's From filters run at priority 5, so an SMTP
plugin's own From (normally at the default priority 10 or later) wins.

### 4. Derived address when the admin email is not on the site domain

`no-reply@<site domain>`, with an admin notice (warning) and a Site Health
"recommended" result asking for a real, read mailbox. Leaving the core
values in place would keep `wordpress@`, which is no better a mailbox and is
also wrong when the site domain differs from the network host. The address
is always marked "derived" in the CLI.

### 5. Layout: loader plus directory

The plugin is a directory with `wp-mail-from.php` as its main file. As a
normal plugin that directory goes in `plugins/`. As a must-use plugin the
directory goes in `mu-plugins/wp-mail-from/` and
`mu-loader/wp-mail-from-loader.php` is copied to `mu-plugins/`. The release
zip ships the directory, with the loader inside it under `mu-loader/`.

A single-file build was rejected: it would duplicate code, and the plugin
has several classes worth keeping separate and testable.

### 6. Settings location: Settings, General

Two fields, "Mail From address" and "Mail From name", on Settings, General,
saved through core's `options.php` (nonce `general-options`, capability
`manage_options`). The other fields are set by constant or
`wp mail-from set`. On multisite, network defaults, locks and allowed domains
are on Network Settings (nonce `siteoptions`, capability
`manage_network_options`, both checked again by the plugin).

## Other decisions

- Prefix. Constants `WPMF_*`, hooks `wpmf_*`, options `wpmf_settings` and
  `wpmf_network_settings`, user meta `wpmf_dismissed_<key>` and
  `wpmf_network_errors`. PHP namespace `IndianOpinion\MailFrom`.
- Hook priorities. `wp_mail` at 1000 (late, to read what core will parse;
  other `wp_mail` filters usually run at 10). `wp_mail_from` and
  `wp_mail_from_name` at 5, changeable with `WPMF_FROM_PRIORITY` (an integer;
  anything else is ignored). `phpmailer_init` at 999. `pre_wp_mail` at
  `PHP_INT_MAX`.
- Late header rewrites. If a `wp_mail` filter after priority 1000 changes the
  From header, the address `wp_mail_from` receives will not match the
  prediction. The plugin then decides again from that address.
- Explicit From equal to the configured address. In `override` and `align`
  it is treated as kept, so the plugin does not move the site's own address
  to Reply-To.
- An extra decision label, `set-name`. The PRD lists `unchanged` and the
  replacement decisions. When an explicit address is kept but its name
  changes (C23, C24), calling it `unchanged` would be wrong, so it is
  `set-name` (`would-set-name` in dry run).
- Masking. The PRD asks to mask a visitor's From in respect mode. The plugin
  masks any From or Sender that is not aligned with the site domain in every
  log line, in every mode, which is a superset.
- Failure log. PHPMailer error messages can contain recipient addresses
  ("The following recipients failed: ..."). Every address in the message is
  replaced with `[address]` before logging.
- Multisite save rule (C44) uses strict containment: the address domain must
  equal the site domain or be a subdomain of it, or of an allowed domain.
  Relaxed alignment would let `shop.example.org` send as `example.org`.
  Super admins and WP-CLI runs are not restricted.
- Locked fields are ignored in resolution as well as read-only on screen, so
  a stored site value cannot leak through after a lock.
- `smtp_mode` `ignore` versus `defer`. Both leave behaviour unchanged; the
  difference is reporting. `ignore` skips detection for messages and shows no
  admin notice. The CLI still lists detected plugins.
- Notice dismissal stores a hash of the notice text per user and key, so a
  dismissed notice comes back when the situation changes. Network save errors
  are kept in user meta for one page load because Network Settings redirects
  after saving and `add_settings_error()` would be lost.
- Uninstall of a normal copy is skipped when a must-use copy is active,
  because that copy still uses the settings.
- PHP version guard. Must-use plugins skip the "Requires PHP" header check,
  so the main file returns early on PHP older than 8.1 instead of failing
  with a parse error in a class file.
- No Composer at runtime. `src/autoload.php` is a 20-line PSR-4 autoloader.
- Validator injection. `Validator` takes the IDN converter as a constructor
  argument so the "no intl" path is tested on a machine that has intl.

## Testing approach

Tests run under PHPUnit 10 against `tests/stubs/`, a small model of the
WordPress functions the plugin calls, PHPMailer 6's relevant behaviour
(including `setFrom($auto)` and object reuse) and WP-CLI. The stub
`wp_mail()` follows core's order of filters and actions and its header
parsing, so the end-to-end tests exercise the same hook sequence as
production. Each test's docblock names the PRD test (T1 to T68) and cases it
covers.

Static analysis runs PHPStan at level 8 with the WordPress and WP-CLI stubs
(`szepeviktor/phpstan-wordpress`, `php-stubs/wp-cli-stubs`) against `src/`
and the entry files. Style is PSR-12 with a few security sniffs (no `eval`,
no shell functions, no `@`).
