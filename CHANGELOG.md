# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-11

### Added

- From address and name resolved from `wp-config.php` constants, the per-site
  option, the network option on multisite, then a derived default (admin
  email on the site domain, else `no-reply@<site domain>`; site title).
- Header reading that matches core's `wp_mail()`: string and array headers,
  CRLF and LF, any case, last From wins, string keys ignored.
- Explicit-From modes `respect` (default), `align` and `override`; the
  original From moves to Reply-To when replaced and no Reply-To is present.
- Optional Reply-To, added only when a message has none.
- Envelope sender modes `if-empty` (default), `always` and `never`, following
  the final From on the PHPMailer object, with no leak between messages on
  the shared PHPMailer object.
- Address validation (no line breaks, length, dotted domain, no IP literals,
  punycode for Unicode domains) and name cleaning, applied to filter results
  too.
- Detection of WP Mail SMTP, FluentSMTP, Post SMTP and Easy WP SMTP, with
  `defer`, `stand-aside` and `ignore` modes, and reporting of a replaced
  `wp_mail()`.
- Multisite: network defaults, locked fields, per-site derived defaults,
  `switch_to_blog()` support and a rule that stops site admins sending from a
  domain that is not theirs.
- Dry run with one log line per message, and a `wpmf_decision` action.
- Failure logging on `wp_mail_failed` with a fixed prefix and no recipients.
- Two fields on Settings, General; network settings; dismissible admin
  notices; a Site Health test.
- WP-CLI: `wp mail-from show`, `show --message-from`, `set` and `reset`.
- Must-use loader, uninstall routine, filters for every default.

[Unreleased]: https://github.com/IndianOpinion/wp-mail-from/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/wp-mail-from/releases/tag/v0.1.0
