# wp-mail-from

WordPress sends every message as `wordpress@<your domain>` with the name
"WordPress", from a mailbox that almost never exists, so bounces vanish and
receiving servers distrust the mail. This must-use plugin sets a real From
address, From name and envelope sender on every `wp_mail()` message, without
breaking mail that sets its own From on purpose.

Measured result: on the site this came out of, every core notification left
from a `wordpress@` address with no mailbox behind it, so every bounce had
nowhere to go (an observation, not a benchmark).

This project came out of the work on indianopinion.org and is written from
scratch; it is independent of and not affiliated with any third-party plugin
it integrates with.

- WordPress 6.4 or later, PHP 8.1 or later. Single site and multisite.
- No settings page to learn: two fields on Settings, General, or constants in
  `wp-config.php`.
- Never sends mail itself, never opens a connection, never replaces
  `wp_mail()`.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Mail that sets its own From](#mail-that-sets-its-own-from)
- [How it works](#how-it-works)
- [DNS you still need](#dns-you-still-need)
- [Hooks](#hooks)
- [WP-CLI](#wp-cli)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Uninstall](#uninstall)
- [Licence](#licence)

## Install

### As a must-use plugin (recommended)

A mail identity that can be deactivated from wp-admin will be, by accident,
on the day it matters. Must-use plugins cannot be deactivated from wp-admin.

WordPress does not load subdirectories of `mu-plugins`, so the plugin ships
as a directory plus a one-file loader:

```sh
cd wp-content/mu-plugins
# 1. the plugin directory
git clone --depth 1 --branch v0.1.0 https://github.com/IndianOpinion/wp-mail-from.git wp-mail-from
# 2. the loader, one level up, next to the directory
cp wp-mail-from/mu-loader/wp-mail-from-loader.php .
```

The result:

```text
wp-content/mu-plugins/
  wp-mail-from-loader.php      <- WordPress loads this
  wp-mail-from/
    wp-mail-from.php           <- the loader requires this
    src/...
```

The loader is ten lines. If you prefer to write your own:

```php
<?php
require_once __DIR__ . '/wp-mail-from/wp-mail-from.php';
```

### As a normal plugin

Upload the release zip in Plugins, Add New, or unpack it into
`wp-content/plugins/wp-mail-from/`, then activate it. On multisite you can
network-activate it or activate it per site.

### With Composer

```sh
composer require indianopinion/wp-mail-from
```

The package type is `wordpress-muplugin`. With `composer/installers` and an
`installer-paths` entry it lands in `mu-plugins/wp-mail-from/`; copy the
loader next to it as above. No Composer autoloader is needed at runtime: the
plugin carries its own.

### Without Composer

Download the release zip from GitHub, unpack it and follow either of the
first two sections. Nothing else is required.

## Quick start

1. Install it (above). With no configuration at all it already helps: it
   derives an identity from the admin email (when that is on your domain) or
   `no-reply@<your domain>`, and the site title.
2. Put a real mailbox in `wp-config.php`, one that exists and is read:

   ```php
   define( 'WPMF_FROM', 'mail@example.org' );
   define( 'WPMF_FROM_NAME', 'Example News' );
   ```

3. Check what it will do:

   ```sh
   wp mail-from show
   wp mail-from show --message-from="Visitor <visitor@example.net>"
   ```

4. Optional: turn on dry run first on a busy site and read the log lines
   before going live (`define( 'WPMF_DRY_RUN', true );`).

## Configuration

Each value is read from the first source that has a valid value:

1. a constant in `wp-config.php`;
2. the per-site option (Settings, General, or `wp mail-from set`);
3. on multisite, the network option (Network Settings);
4. a derived default (address and name only).

The result then passes through a filter, and the filter's return value is
validated again. An empty constant counts as not set, so you can blank a
value without deleting the line.

| Constant | Option field | Values | Default | Meaning |
|---|---|---|---|---|
| `WPMF_FROM` | `from` | address | derived | From address |
| `WPMF_FROM_NAME` | `from_name` | text | site title | From name |
| `WPMF_REPLY_TO` | `reply_to` | address | none | Reply-To added when a message has none |
| `WPMF_RETURN_PATH` | `return_path` | address | the final From | Envelope sender (becomes Return-Path) |
| `WPMF_EXPLICIT_MODE` | `explicit_mode` | `respect`, `override`, `align` | `respect` | What to do with mail that sets its own From |
| `WPMF_SENDER_MODE` | `sender_mode` | `if-empty`, `always`, `never` | `if-empty` | When to set the envelope sender |
| `WPMF_SMTP_MODE` | `smtp_mode` | `defer`, `stand-aside`, `ignore` | `defer` | What to do when an SMTP plugin is active |
| `WPMF_DRY_RUN` | `dry_run` | true or false | false | Log decisions, change nothing |
| `WPMF_LOG_FAILURES` | `log_failures` | true or false | true | Log every `wp_mail()` failure |
| `WPMF_FROM_PRIORITY` | none | integer | 5 | Priority of the `wp_mail_from` filters |

Derived defaults:

- Address: the site's admin email when it is valid and on the site domain (or
  a subdomain of it); otherwise `no-reply@<site domain>`. A derived address
  is marked "derived" in the CLI, in Site Health and in an admin notice,
  because the plugin cannot know that the mailbox exists. A derived
  `no-reply` shows a warning recommending a real mailbox.
- Name: the site title with HTML entities decoded (`&amp;` becomes `&`), or
  the site domain when the title is empty.

Where the settings are stored:

- Single site and each site of a network: one autoloaded option,
  `wpmf_settings`, an array of the fields above. Settings, General shows two
  of them (From address, From name); set the others with constants or
  `wp mail-from set`.
- Multisite: one network option, `wpmf_network_settings`, with the same
  fields plus `locked` (fields a site cannot override) and `allowed_domains`.
  Edit it on Network Settings.

When a constant is set, its field on Settings, General is read-only with the
note "Set in wp-config.php". The stored option is kept but ignored.

### Multisite

- Constants override everything on every site.
- A per-site value overrides the network value for the fields it sets.
- A super admin can lock a field on Network Settings; it is then read-only on
  every site and site values for it are ignored.
- The derived default is computed per site from that site's home URL, so
  subdomain and domain-mapped sites get their own domain.
- Mail sent while `switch_to_blog()` is active uses the switched-to site's
  identity.
- A site administrator who is not a super admin can only save an address on
  the site's own domain (or a subdomain of it) or on a domain listed in
  "Extra domains" on Network Settings (or added with the
  `wpmf_allowed_domains` filter). This stops one site on a shared network
  sending as another.

## Mail that sets its own From

Contact forms, shops and newsletter plugins often pass their own `From:`
header. The default mode, `respect`, leaves it alone, because replies to
those messages must go where the sender intended, and silently breaking them
is worse than an unaligned From.

The trade-off: when a form puts the visitor's address in From (for example
`visitor@gmail.example`), your server is sending mail "from" a domain it does
not own. Under DMARC that message fails alignment and may be rejected or put
in spam. If that matters more to you than keeping the sender's address in
From, use `align`:

| Mode | Explicit From on your domain | Explicit From on another domain |
|---|---|---|
| `respect` (default) | kept | kept |
| `align` | kept | replaced by your identity; the original moves to Reply-To |
| `override` | replaced; the original moves to Reply-To | replaced; the original moves to Reply-To |

Details that apply in every mode:

- An explicit From equal to the core default (`wordpress@...`, any case) is
  treated as no explicit From and replaced.
- An explicit From that is not a valid address is replaced, and a warning is
  logged. Passed through, it would make PHPMailer throw and the message would
  be lost.
- The original address moves to Reply-To only when the message has no
  Reply-To of its own.
- When an explicit address has no display name (or the core default name
  "WordPress"), it gets your From name only if the address is on your domain.
  A visitor's address never carries your site's name.

The mode can also be chosen per message with the `wpmf_explicit_mode` filter.

## How it works

Inside one `wp_mail()` call:

1. `wp_mail` filter, priority 1000: the plugin reads the headers the way core
   will (string or array, CRLF or LF, any case, last From wins, string array
   keys ignored), resolves the identity and decides. The arguments are
   returned unchanged.
2. `pre_wp_mail`, last priority: if another plugin short-circuits the
   message, the plugin's state is dropped.
3. `wp_mail_from` and `wp_mail_from_name`, priority 5: the decision is
   applied. Running early means later code, including an SMTP plugin's
   "force From" setting, still wins.
4. `phpmailer_init`, priority 999: Reply-To is added if decided and none is
   present, the envelope sender is set (below), the `wpmf_decision` action
   fires and, in dry run, one log line is written.
5. `wp_mail_succeeded` or `wp_mail_failed`: state is dropped; a failure is
   logged.

Envelope sender (PHPMailer's `Sender`, which becomes the SMTP `MAIL FROM` and
the Return-Path the receiver records):

- `if-empty` (default): set only when nothing else set one. The value is the
  return path when configured, else the From address actually on the
  PHPMailer object at that point (so it follows a From another plugin changed
  later).
- `always`: set every time, even over another plugin's value. Only if you
  know your relay.
- `never`: not touched.

WordPress reuses one PHPMailer object for every message in a request and
never resets `Sender` (checked against core, see
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)). The plugin remembers the value
it set and treats it as empty on the next message, so a Sender from message
one never leaks into message two.

The plugin never adds a `Return-Path` header. Receiving servers set
Return-Path from the envelope; a header added by the sender is ignored or
penalised.

Validation. An address is used only if it passes WordPress's `is_email()`,
has no line breaks, is at most 254 characters, has a domain with a dot, and
is not an IP literal. A Unicode domain is converted to punycode when the
`intl` extension is loaded and rejected otherwise. Names lose control
characters and line breaks, whitespace is collapsed and they are capped at
255 characters. Filter results go through the same checks.

Failure handling. Every failure fails open, towards working mail:

| Failure | What happens |
|---|---|
| A configured address is invalid | The next source is used; a warning is logged once; an admin notice shows |
| Every source is invalid (for example a site on `localhost`) | Core values are kept; an error is logged; `wp mail-from show` exits 1 |
| An explicit From is invalid | Your identity is used |
| An unknown mode value | The default is used; logged once |
| An SMTP plugin forces From | It wins; the plugin reports it |
| Logging fails | Ignored; mail is never affected |

Logs. Lines start with `[wp-mail-from]` and go to PHP's error log, which
WordPress points at `wp-content/debug.log` when `WP_DEBUG_LOG` is on. Find
them with:

```sh
grep '\[wp-mail-from\]' wp-content/debug.log
```

Recipients, subjects and bodies are never logged. A visitor's From address
(one not on your domain) is logged with its local part masked
(`j***@example.net`). Failure messages have every address replaced with
`[address]`.

A dry-run line looks like this:

```text
[wp-mail-from] info: dry-run decision=would-replace-default,would-set-sender mode=respect from="wordpress@example.org"->"mail@example.org" name="WordPress"->"Example News" sender=""->"mail@example.org" reason="no explicit From; core default replaced"
```

Decisions: `unchanged`, `replace-default`, `override`, `align`, `set-name`
(the explicit address is kept, only the name changes), `add-reply-to`,
`set-sender`, `skip` (the plugin does not act on this message). In dry run
each change is prefixed `would-`.

### SMTP plugins

The plugin detects WP Mail SMTP, FluentSMTP, Post SMTP and Easy WP SMTP by
public markers (active plugin directory, or a public constant, class or
function name). It never reads their settings. The directory slugs are
checked against the wordpress.org directory; the constant, class and function
names are best effort and were not checked against the plugins' source (see
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)). If detection misses your
plugin, add a marker with `wpmf_smtp_markers`.

| `WPMF_SMTP_MODE` | Behaviour when one is detected |
|---|---|
| `defer` (default) | The plugin's From filters run early, so the SMTP plugin's own From setting wins when it has one. An empty Sender is still filled. CLI, Site Health and an admin notice name the plugin. |
| `stand-aside` | The plugin changes nothing on any message and only reports. |
| `ignore` | Detection is reported in the CLI but changes nothing, and no admin notice is shown. |

Add or remove markers with `wpmf_smtp_markers`, or override the result with
`wpmf_smtp_detection` (return an empty array to act as if none were active).

`wp_mail()` is a pluggable function. Some SMTP plugins replace it. The CLI,
Site Health and an admin notice then say which file defines it. The From
filters are still registered; whether the replacement honours them is
outside this plugin's control.

## DNS you still need

A correct From address is half the job. Receiving servers also check DNS,
which this plugin does not touch:

- SPF: a TXT record on the From domain (and on the envelope sender domain, if
  different) that includes the host or relay that sends the mail, for example
  `v=spf1 include:<your relay> -all`.
- DKIM: signing set up at your relay or local MTA, with its public key
  published in DNS.
- DMARC: a TXT record at `_dmarc.<your domain>`, starting with `p=none` and a
  report address (`rua=mailto:...`). Read the reports for a few weeks, then
  move to `p=quarantine` and `p=reject`.

Also check the transport before blaming the identity. Some cloud hosts block
outbound port 25 on new accounts; a correct From does not fix that.

## Hooks

Filters (all receive a context where useful):

| Filter | Arguments | Purpose |
|---|---|---|
| `wpmf_from_address` | `$address`, `$context` (`site_id`, `source`) | The resolved From address. Validated again. |
| `wpmf_from_name` | `$name`, `$context` | The resolved From name. Cleaned again. |
| `wpmf_reply_to` | `$address`, `$context` | The configured Reply-To ('' for none). |
| `wpmf_return_path` | `$address`, `$context` | The configured envelope sender ('' for the final From). |
| `wpmf_derived_address` | `$address`, `$context` | The derived default address. |
| `wpmf_derived_name` | `$name`, `$context` | The derived default name. |
| `wpmf_explicit_mode` | `$mode`, `$atts` | Explicit-From mode for this message. |
| `wpmf_sender_mode` | `$mode`, `$atts` | Sender mode for this message. |
| `wpmf_smtp_mode` | `$mode`, `$atts` | SMTP mode for this message. |
| `wpmf_should_handle` | `$bool`, `$atts` | Return false to leave this message alone. |
| `wpmf_dry_run` | `$bool`, `$atts` | Dry run for this message. |
| `wpmf_log_failures` | `$bool`, `$error` | Whether to log this failure. |
| `wpmf_smtp_markers` | `$markers` | The SMTP plugin markers. |
| `wpmf_smtp_detection` | `$found` | The detection result. |
| `wpmf_allowed_domains` | `$domains`, `$site_id` | Extra domains a site admin may use on multisite. |
| `wpmf_validate_address` | `$result`, `$input` | The validator result (an `AddressResult`). Line breaks and length are checked again after it. |

`$atts` is the array of `wp_mail()` arguments (`to`, `subject`, `message`,
`headers`, `attachments`).

Actions:

| Action | Arguments | When |
|---|---|---|
| `wpmf_decision` | `$record` | Once per message, in dry run and live alike. Keys: `mode`, `decision`, `actions`, `from_before`, `from_after`, `name_before`, `name_after`, `reply_to_added`, `sender_before`, `sender_after`, `sender_mode`, `smtp`, `reason`, `dry_run`, `site_id`. |
| `wpmf_invalid_address` | `$source`, `$value`, `$reason`, `$field` | A configured address was rejected. |
| `wpmf_smtp_plugin_detected` | `$name`, `$marker` | Once per request per detected SMTP plugin. |

Examples are in [examples/](examples/).

## WP-CLI

```sh
wp mail-from show [--format=table|json|yaml] [--message-from=<header value>] [--url=<site>]
wp mail-from set <field> <value> [--network]
wp mail-from reset [--network] [--yes]
```

`show` prints the effective From address and name, where each value comes
from (`constant`, `site-option`, `network-option`, `derived`, `filter`,
`default`), the three modes, dry run, detected SMTP plugins, whether
`wp_mail()` is replaced and by which file, and every validation warning. It
exits 0 when the identity is valid and 1 when the plugin would leave the core
values in place.

`show --message-from` shows what the plugin would do to a message carrying
that From header. Nothing is sent.

`set` validates with the same rules as the settings screen, except the
multisite domain rule: a shell user is trusted. It warns when a constant or a
network lock means the stored value will be ignored. `reset` deletes the
stored settings; constants still apply.

The envelope sender line always reads "envelope sender requested; the
transport may override it". With PHP's `mail()`, PHPMailer only passes the
envelope sender to sendmail when the address is safe as a shell argument, and
some local MTAs rewrite it for untrusted users. With SMTP, some relays replace
it with their own bounce domain; that is correct for them and the plugin does
not fight it.

## Limitations

- Mail sent outside `wp_mail()` (direct PHPMailer use, PHP `mail()`, external
  APIs) is not touched.
- A replacement `wp_mail()` may ignore the From filters. The plugin reports
  it but cannot change it.
- Alignment is relaxed alignment without a public suffix list. On a site at
  `shop.example.co.uk`, an address at `co.uk` would count as aligned. This
  matters only for `align` mode and the display-name rule; the multisite
  save rule uses strict containment.
- The plugin cannot know that a mailbox exists. A derived address is flagged
  so you can check.
- Settings errors on Settings, General appear in the standard notice at the
  top of the page, not next to the field.
- Must-use plugins have no uninstall hook (see [Uninstall](#uninstall)).

## FAQ

**Do I need this if I use an SMTP plugin?** Often not for From, since most
SMTP plugins can force a From address. It still helps with the envelope
sender, failure logging and the multisite rules. In the default `defer` mode
the two do not fight: the SMTP plugin wins on From.

**Why not just a ten-line `wp_mail_from` filter?** That filter only sees a
parsed address. It cannot tell a From that a contact form set on purpose
from core's default, so it either overrides everything (and breaks replies)
or compares against a host it builds itself (and misses `www` sites and
multisite subsites). It also leaves the envelope sender alone.

**Will it break my contact form?** Not in the default mode: an explicit From
is kept. In `align` or `override` mode the visitor's address moves to
Reply-To, so replies still reach them.

**Where do bounces go?** To the envelope sender: the return path when set,
else the From address. Make sure that mailbox exists and somebody reads it.
Moving bounces from a void to an unread inbox is not a fix.

**Does it work with page caches or a CDN?** Mail is sent server-side, so they
are not involved. Options are read through the normal API, so a persistent
object cache is honoured.

**What does it cost?** On requests that send no mail: hook registration only,
no option reads, no file I/O. Per message: a few string operations and, once
per request, an option read that is already autoloaded.

**Both a must-use copy and a normal plugin copy are installed. Which wins?**
The first one loaded (must-use plugins load first). The second registers
nothing, and an admin notice names both files.

## Uninstall

As a normal plugin: delete it from Plugins. That removes `wpmf_settings` on
every site, `wpmf_network_settings` and the notice dismissals stored in user
meta. If a must-use copy is also installed, the settings are kept because it
still uses them.

As a must-use plugin: delete the loader and the directory, then remove the
stored settings with WP-CLI:

```sh
wp mail-from reset --yes                     # before removing the files, or:
wp option delete wpmf_settings               # per site
wp site option delete wpmf_network_settings  # multisite network
```

## Licence

MIT. See [LICENSE](LICENSE).
