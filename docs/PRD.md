# wp-mail-from: product requirements

Status: draft for review. Target release: 0.1.0.

A small WordPress must-use plugin (also installable as a normal plugin) that
sets a real From address and From name on every message sent through
`wp_mail()`.

## 1. Problem

Out of the box, WordPress sends every message as `wordpress@<your domain>`
with the display name "WordPress". On almost every site that mailbox does
not exist. Two things follow:

- Bounces vanish. Delivery failures, out-of-office replies and complaints go
  back to an address nobody reads, or that rejects them. The operator never
  learns that password resets or order mails are not arriving.
- Receiving servers distrust the domain. A sender that cannot receive mail,
  an envelope sender that does not match the From header, and a generic
  "WordPress" name all count against the message in spam scoring. When the
  site later adds SPF and DMARC, an unaligned envelope sender can turn "spam
  folder" into "rejected".

Observed result: on indianopinion.org, before a fix of this kind, every core
notification left from a `wordpress@` address with no mailbox behind it, so
every bounce had nowhere to go. No delivery figures were recorded; this is an
observation, not a benchmark.

Existing answers are either a ten-line snippet that gets one of the cases in
section 4 wrong, or a full SMTP plugin that does far more than this job and
fights other mail plugins. This project does one job and does it correctly.

## 2. Goals and non-goals

Goals

1. Replace the core default From address and name on every outgoing mail with
   a real, configured identity.
2. Read configuration from, in order: constants in `wp-config.php`, then
   stored options, then a default derived from the site domain.
3. Handle mail that already sets its own From header in a predictable,
   configurable way, with a documented default.
4. Optionally set Reply-To, and align the envelope sender (Sender, which
   becomes Return-Path) with the From address for SPF and DMARC where the
   transport allows it.
5. Coexist with SMTP plugins: detect common ones, never fight them, and
   document the precedence.
6. Work on multisite, with network defaults and per-site overrides.
7. Validate every address before it reaches PHPMailer, and never turn a bad
   configuration into lost mail.
8. Expose a filter or action for every decision.
9. Ship a WP-CLI command that shows the effective From for the current site,
   and a dry-run mode that logs what the plugin would change.
10. Log `wp_mail()` failures so they can be found with grep.

Non-goals

- Sending mail. The plugin never replaces `wp_mail()` and never opens a
  connection.
- SMTP configuration: hosts, ports, credentials, API mailers, queues.
- Templates, HTML wrapping, content type, tracking.
- DNS. SPF, DKIM and DMARC records are the operator's job; the README says so
  and lists what is needed.
- Bounce processing. The plugin makes bounces land somewhere real; reading
  them is out of scope.
- Rewriting mail sent outside `wp_mail()` (direct PHPMailer use, `mail()`
  calls, external services).

## 3. Users and setting

- Site operators and agencies who run WordPress on a VPS, managed host or
  container and want password resets, notifications and form mail to arrive
  and to bounce somewhere real.
- Network operators running multisite who need one identity per site, or a
  network default with exceptions.
- Installed as a must-use plugin (single file, or a loader file plus a
  directory) so it cannot be deactivated by accident from wp-admin. Also
  installable as a normal plugin from a zip or Composer.
- Configured mostly in `wp-config.php` by someone with shell access;
  optionally on Settings, General by a site administrator.
- Runs inside every PHP request that calls `wp_mail()`: web requests, cron,
  WP-CLI, REST and AJAX.

## 4. Behaviour

Terms used below:

- Core default address: the address WordPress itself builds, `wordpress@`
  followed by the host of the network home URL with a leading `www.`
  removed. The plugin must compute it the same way core does, for the site
  being served.
- Core default name: the literal string `WordPress`.
- Configured identity: the address, name, Reply-To and return-path address
  resolved from the sources in 4.1.
- Explicit From: a From header present in the headers passed to `wp_mail()`
  (after all `wp_mail` filters have run), whose address is not the core
  default address.
- Site domain: the host of the current site's home URL with a leading
  `www.` removed, lower-cased, in its ASCII (punycode) form.
- Aligned: an address whose domain equals the site domain or is a subdomain
  of it, or the reverse (relaxed alignment, as DMARC uses it).

### 4.1 Source precedence

1. C1. The From address is resolved from the first source that yields a
   valid address: (a) a constant defined in `wp-config.php`; (b) on
   multisite, the per-site option; (c) on multisite, the network option; on
   single site, the site option; (d) the derived default (C4). The result is
   then passed through the address filter (section 5), whose return value is
   validated again.
2. C2. The From name follows the same order: constant, per-site option,
   network option, then the derived name (C5), then the name filter.
3. C3. Address and name resolve independently. A constant address with an
   option name is a valid combination.
4. C4. Derived default address: the site administrator email if it is valid
   and aligned with the site domain; otherwise `no-reply@<site domain>`. A
   derived address is marked "derived" in the CLI output and the admin
   notice, because the plugin cannot know that the mailbox exists.
5. C5. Derived default name: the site title with HTML entities decoded (the
   stored title holds `&amp;` and similar); if that is empty, the site
   domain.
6. C6. A constant that is defined but empty is treated as not set, and the
   next source is used. This lets an operator blank a value without
   deleting the line.
7. C7. When a constant is set, the matching settings field is shown read-only
   with the note "set in wp-config.php". A stored option is kept but ignored.
8. C8. Resolution runs at most once per site per request and is cached in
   memory. A change to an option during the request (for example from the
   settings screen or the CLI) clears that cache.

### 4.2 The header shapes `wp_mail()` accepts

9. C9. Headers as one string, lines separated by CRLF or by LF alone.
10. C10. Headers as an array of strings, one header per element.
11. C11. Header names are matched case-insensitively (`From:`, `from:`,
    `FROM:`), with optional whitespace after the colon.
12. C12. From value shapes: `Name <addr>`, `"Quoted, Name" <addr>`, bare
    `addr`, `<addr>`. Surrounding whitespace is trimmed, quotes around the
    name are removed.
13. C13. More than one From header: core keeps the last one, so the plugin
    treats the last one as the explicit From.
14. C14. An array element with a string key (for example `'From' => 'a@b'`):
    core uses the value only, and a value with no colon is not a header. The
    plugin follows core exactly: such an element is not an explicit From.
15. C15. Lines without a colon (folded continuations, MIME boundary lines)
    are ignored for the purpose of finding From and Reply-To, as core does.
16. C16. An empty headers argument, an empty string, or an empty array means
    no explicit From.
17. C17. A From header whose address is the core default address (any case)
    is treated as no explicit From, so it is replaced.
18. C18. A From header whose address is invalid is treated as no explicit
    From: the configured identity is used and a warning is logged. Passing
    it through would make PHPMailer throw and the whole message would fail.
19. C19. Reply-To headers are read in the same shapes, including several
    comma-separated addresses in one header and several Reply-To headers.

### 4.3 Explicit From handling

20. C20. Mode `respect` (default): an explicit From address and its name are
    left unchanged. Reason: contact forms, shops and newsletter plugins set
    From on purpose, and replies to those messages must go where the sender
    intended. Breaking them silently is worse than an unaligned From. The
    trade-off (DMARC failure when a form puts a visitor's address in From) is
    stated in the README with a pointer to mode `align`.
21. C21. Mode `override`: the From address and name are always the configured
    identity. The original explicit From address is copied to Reply-To when
    the message has no Reply-To, so replies still reach the intended person.
22. C22. Mode `align`: an explicit From that is aligned with the site domain
    is respected; one that is not aligned is replaced with the configured
    identity, and the original address moves to Reply-To when the message has
    no Reply-To. This is the DMARC-safe mode.
23. C23. Explicit From address with no display name: the address follows the
    mode. The name is the configured name only when the address is aligned
    with the site domain; otherwise the message has no display name. A
    visitor's address must never carry the site's name.
24. C24. Explicit From address with the core default name `WordPress`: the
    name is replaced by the configured name under the rule in C23.
25. C25. The mode can be set by constant, option, or per message by filter
    (the filter receives the mail arguments). An unknown mode value is
    treated as `respect` and logged once.

### 4.4 Reply-To

26. C26. Default: Reply-To is not touched.
27. C27. When a Reply-To address is configured and the message has no
    Reply-To, the configured Reply-To is added. An existing Reply-To is never
    replaced.
28. C28. A Reply-To moved from an explicit From (C21, C22) takes precedence
    over the configured Reply-To.
29. C29. Reply-To is set on the PHPMailer object in `phpmailer_init`, after
    checking that it has no reply-to addresses, so a Reply-To added by
    another plugin in the same hook is not duplicated.

### 4.5 Sender and Return-Path

30. C30. Sender mode `if-empty` (default): in `phpmailer_init`, when the
    PHPMailer Sender is empty, it is set to the return-path address if one is
    configured, else to the final From address on the PHPMailer object. A
    Sender already set by an SMTP plugin or other code is left alone.
31. C31. Sender mode `always`: the Sender is set every time, even when
    another plugin set one. Documented as "only if you know your relay".
32. C32. Sender mode `never`: the plugin does not touch Sender.
33. C33. The Sender is read from the PHPMailer object's final From, not from
    the plugin's own resolution, so it stays aligned when another plugin
    changed From later in the chain.
34. C34. The global PHPMailer object is reused between `wp_mail()` calls in
    one request. The plugin records the Sender value it set on the previous
    call and treats that value as empty on the next call, so a Sender from
    message one never leaks into message two with a different From.
35. C35. The plugin never adds a `Return-Path` header. Receiving servers set
    Return-Path from the envelope; a header added by the sender is ignored or
    penalised.
36. C36. With the `mail()` transport, PHPMailer only passes the envelope
    sender to sendmail when the address is safe for a shell argument. Some
    local MTAs also rewrite or ignore it for untrusted users. The plugin
    cannot detect this; the README and the CLI output say "envelope sender
    requested; the transport may override it".
37. C37. With an SMTP transport, the Sender becomes the SMTP MAIL FROM. Some
    relays replace it with their own bounce domain; that is correct for
    them and the plugin does not fight it.
38. C38. An invalid configured return-path address is ignored (falls back to
    From) and logged, as in C40.

### 4.6 Validation and invalid configuration

39. C39. An address is valid only if it passes the WordPress email check, has
    no CR or LF, is at most 254 characters, and its domain has at least one
    dot (so `root@localhost` and bare hostnames are rejected). An IP-literal
    domain is rejected. A Unicode domain is converted to punycode before the
    check when the intl extension is available; without it, a Unicode
    domain is rejected and the reason logged.
40. C40. Invalid configured address (constant, option or filter result):
    the plugin falls through to the next source in C1 (fail open to a working
    identity, never to no mail). It logs one warning per source per request,
    fires the invalid-address action, and shows a dismissible admin notice to
    users who can manage options.
41. C41. If every source is invalid, including the derived default (for
    example a site on `localhost` or an IP address), the plugin leaves the
    core values untouched for that message and logs an error. Mail keeps
    working as it would without the plugin.
42. C42. Names: CR, LF and other control characters are removed, whitespace
    trimmed and collapsed, length capped at 255 characters. A name that is
    empty after cleaning is treated as not set.
43. C43. Settings form input is validated on save with the same rules; an
    invalid value is not stored and the field shows the reason.
44. C44. On multisite, a site administrator who is not a super admin may only
    save a From, Reply-To or return-path address on the site domain or on a
    domain the network allows by option or filter. This stops one site on a
    shared network from sending as another domain.

### 4.7 SMTP plugins and replaced `wp_mail()`

45. C45. Detection: the plugin checks for the common SMTP plugins (WP Mail
    SMTP, FluentSMTP, Post SMTP, Easy WP SMTP, and a filterable list of
    others) by their documented public markers (constants, classes or active
    plugin slugs). Detection never reads their settings.
46. C46. Precedence when one is detected (`smtp` mode `defer`, default): the
    plugin's From filters run at an early priority, so an SMTP plugin's
    "force From" setting, which runs later, wins. The plugin still fills an
    empty Sender (C30) unless Sender mode is `never`. The CLI and the admin
    notice name the detected plugin and say it may override From.
47. C47. `smtp` mode `stand-aside`: when an SMTP plugin is detected, this
    plugin changes nothing on any message and only reports.
48. C48. `smtp` mode `ignore`: detection results are reported but do not
    change behaviour.
49. C49. Replaced `wp_mail()`: `wp_mail()` is pluggable. When the function is
    not defined by core's pluggable file, the plugin reports "wp_mail() is
    replaced by <file>" in the CLI and admin notice. The From filters are
    still registered; whether the replacement honours them is outside the
    plugin's control and is said so.
50. C50. A short-circuit through `pre_wp_mail` means no From filters fire.
    The plugin's per-message state is reset at the start of every message so
    nothing carries over to the next one.
51. C51. Two copies loaded (must-use and normal plugin at once): the second
    copy registers nothing, and an admin notice says which copy is active.

### 4.8 Multisite

52. C52. Network option holds the network defaults (address, name, Reply-To,
    return path, modes). A per-site option overrides any field it sets.
    Constants override both on every site.
53. C53. The derived default is computed per site from that site's domain,
    not the network's main domain. On a subdirectory network every site
    shares the same domain.
54. C54. A super admin can lock fields at network level; locked fields are
    read-only on site settings screens.
55. C55. Mail sent while `switch_to_blog()` is active uses the switched-to
    site's identity. The in-memory cache (C8) is keyed by site.
56. C56. Domain-mapped sites use the mapped home URL host as the site domain.

### 4.9 Dry run

57. C57. When dry run is on (constant, option, or filter), the plugin
    computes every decision for every message but changes nothing: no From,
    name, Reply-To or Sender change is applied.
58. C58. For each message it writes one log line: decision (`unchanged`,
    `would-replace-default`, `would-override`, `would-align`,
    `would-add-reply-to`, `would-set-sender`), From before and after, name
    before and after, and the reason. Recipients, subject and body are never
    logged.
59. C59. The decision action fires in both dry run and live mode with the
    same decision record, so a test or a monitor can observe it.

### 4.10 Failure logging

60. C60. On `wp_mail_failed`, one line is logged with a fixed prefix and the
    error message. It is on by default and can be switched off by constant or
    filter. Recipients are not logged.
61. C61. If the logger itself fails (unwritable log), the failure is
    swallowed only at that point; mail sending is never affected by logging.

### 4.11 Hook registration and priorities

62. C62. `wp_mail` filter at a late priority (documented, default near the
    end of the chain) to read the headers exactly as core will parse them,
    and to start fresh per-message state.
63. C63. `wp_mail_from` and `wp_mail_from_name` at an early priority (default
    5) so that later code, including SMTP plugins, can still decide. The
    priority can be changed by constant before the plugin loads.
64. C64. `phpmailer_init` at a late priority (default 999) so it sees the
    final From and any Sender or Reply-To other plugins set.
65. C65. `wp_mail_succeeded` and `wp_mail_failed` clear per-message state.

### 4.12 Failure modes summary

| Failure | Behaviour | Open or closed | Why |
|---|---|---|---|
| Configured address invalid | Next source used, warning logged | Open | Lost password resets are worse than a derived sender |
| All sources invalid | Core values kept, error logged | Open | Same; the plugin must never be the reason mail stops |
| Explicit From invalid | Configured identity used | Open | Core would throw and drop the message |
| Unknown mode value | Treated as default, logged | Open | Typos in wp-config.php must not break mail |
| SMTP plugin overrides From | Plugin yields, reports it | Open | Two plugins fighting over From gives random results |
| Multisite admin sets foreign domain | Save refused | Closed | Prevents spoofing another site's domain |
| Logging fails | Ignored | Open | Logging is never allowed to affect delivery |

## 5. Public interface

All names below are the public interface of this project and use the prefix
`wpmf` (constants upper case). The builder may adjust names in review but
must keep one prefix.

Constants (in `wp-config.php`)

| Constant | Meaning | Default |
|---|---|---|
| `WPMF_FROM` | From address | unset |
| `WPMF_FROM_NAME` | From name | unset |
| `WPMF_REPLY_TO` | Reply-To added when absent | unset |
| `WPMF_RETURN_PATH` | Envelope sender | unset (From is used) |
| `WPMF_EXPLICIT_MODE` | `respect`, `override`, `align` | `respect` |
| `WPMF_SENDER_MODE` | `if-empty`, `always`, `never` | `if-empty` |
| `WPMF_SMTP_MODE` | `defer`, `stand-aside`, `ignore` | `defer` |
| `WPMF_DRY_RUN` | true or false | false |
| `WPMF_LOG_FAILURES` | true or false | true |
| `WPMF_FROM_PRIORITY` | priority for the From filters | 5 |

Options

- One option per site holding an array of the same fields (except priority).
- On multisite, one network option with the same fields plus the list of
  locked fields and allowed extra domains.
- Settings fields on Settings, General (single site and per site) and on
  Network Settings. Capability `manage_options` per site and
  `manage_network_options` for the network. Nonces on every save.

Filters (each receives the value plus a context array: site id, source, mail
arguments where relevant)

- address, name, Reply-To and return-path value after resolution;
- derived default address and derived name;
- explicit-From mode per message;
- sender mode per message;
- whether to act on this message at all (return false to leave it alone);
- the list of SMTP plugin markers and the detection result;
- allowed domains on multisite;
- dry run on or off;
- failure logging on or off;
- the validator result for an address.

Actions

- decision made for a message (record: mode, decision, before, after,
  reason, dry run flag);
- configured address rejected (source, value, reason);
- SMTP plugin detected (name, marker).

WP-CLI

- `wp mail-from show [--format=table|json|yaml] [--url=<site>]`: prints the
  effective From address and name, each value's source (constant, site
  option, network option, derived, filter), the three modes, dry run state,
  detected SMTP plugins, whether `wp_mail()` is replaced and by which file,
  and every validation warning. Exit status 0 when the effective identity is
  valid and configured, 1 when the plugin would leave core values in place
  (C41).
- `wp mail-from show --message-from="<header value>"`: shows what the plugin
  would do to a message carrying that explicit From, without sending.

## 6. Data

- Stored: one option per site, one network option on multisite. Both small
  arrays, autoloaded (they are read on every mail and are tiny).
- Not stored: no tables, no transients, no post meta, no logs in the
  database, no record of any message.
- Logs: lines go to the PHP error log through the WordPress debug log path
  when enabled, else PHP's configured error log.
- Dismissed admin notices: one user meta flag per user per notice key.
- Uninstall (normal plugin): removes the site options on every site, the
  network option, and the notice user meta. Must-use plugins have no
  uninstall hook; the README gives the one WP-CLI line that removes the
  option, and the CLI offers `wp mail-from reset` to delete stored settings
  for the current site.

## 7. Configuration and sensible defaults

- With no configuration at all the plugin already helps: the derived
  identity (C4, C5) replaces `wordpress@` and "WordPress", explicit From is
  respected, Sender is filled when empty, failures are logged.
- Recommended minimum in `wp-config.php`: the From address (a mailbox that
  is read) and the From name.
- README sections the builder must write: why the default sender is a
  problem; what DNS the operator still needs (an SPF record that includes
  the sending host or relay, DKIM signing set up at the relay or MTA with
  the public key in DNS, a DMARC record starting at `p=none` with a report
  address, then tightened); what this plugin cannot do.

## 8. Security and privacy

- Header injection: every value that reaches PHPMailer is free of CR and LF
  (C39, C42). Filter results are validated again, never trusted.
- Multisite spoofing: C44.
- Admin screens: capability checks and nonces on save; every output escaped.
- No personal data in logs: no recipients, subjects or bodies. From
  addresses are logged because they are the site's own configuration, except
  in respect mode where an explicit From that is a visitor's address is
  logged with its local part masked.
- No network calls, no shell, no `eval`.

## 9. Performance budget

- Load cost on requests that send no mail: hook registration only, no
  option reads beyond autoloaded data, no file I/O.
- Per message: under 1 ms added on PHP 8.1 for a message with 20 header
  lines. No database query after the first resolution in a request.
- SMTP detection runs once per request, lazily, on the first message or CLI
  call.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 and later (PHPMailer 6 as bundled).
- Single site and multisite (subdomain, subdirectory, domain-mapped).
- Must-use: the main file works when dropped straight into `mu-plugins`
  (WordPress does not load subdirectories there), or via a one-line loader
  documented in the README.
- Transports: core `mail()` with sendmail or a local MTA, and any SMTP
  plugin that uses core's PHPMailer.
- Page caches and CDNs are not involved: mail is sent server-side.
- Object caches: options are read through the normal API, so a persistent
  object cache is honoured.

## 11. Observability

- `wp mail-from show` is the first tool: effective identity, sources,
  warnings, SMTP plugin, replaced `wp_mail()`.
- Dry-run log lines (C58) let an operator see exactly what would change on
  real traffic before switching it on.
- Failure log lines (C60) with a fixed prefix make silent mail failures
  findable with one grep.
- Admin notice on invalid configuration or a derived identity, dismissible,
  only for users who can manage options.
- Decision action (C59) for custom monitoring.

## 12. Acceptance tests

Tests run under PHPUnit 10 against a stub layer or Brain\Monkey. Each test
names the cases it covers.

| Test | What it proves | Cases |
|---|---|---|
| T1 | Constant beats site option beats derived, for address | C1 |
| T2 | Same order for name | C2 |
| T3 | Constant address plus option name resolves both | C3 |
| T4 | Derived address uses aligned admin email, else no-reply on site domain | C4 |
| T5 | Derived name decodes entities; empty title gives domain | C5 |
| T6 | Empty constant falls through to option | C6 |
| T7 | Settings field read-only when constant set | C7 |
| T8 | Resolution cached; option update clears cache | C8 |
| T9 | CRLF string headers parsed | C9 |
| T10 | LF-only string headers parsed | C9 |
| T11 | Array headers parsed | C10 |
| T12 | Header name case variants | C11 |
| T13 | Each From value shape parsed to address and name | C12 |
| T14 | Last of several From headers wins | C13 |
| T15 | String-keyed array element not treated as From | C14 |
| T16 | Lines without colon ignored | C15 |
| T17 | Empty string, empty array, null headers | C16 |
| T18 | Explicit core default address (mixed case) is replaced | C17 |
| T19 | Invalid explicit From replaced and warned | C18 |
| T20 | Reply-To with several addresses and several headers | C19 |
| T21 | Respect mode keeps explicit From and name | C20 |
| T22 | Override mode replaces From, moves original to Reply-To | C21 |
| T23 | Override mode keeps existing Reply-To | C21, C28 |
| T24 | Align mode keeps aligned From, replaces unaligned | C22 |
| T25 | No display name: configured name only when aligned | C23 |
| T26 | Default name `WordPress` replaced under C23 | C24 |
| T27 | Mode by filter per message; unknown mode treated as respect | C25 |
| T28 | Reply-To untouched by default | C26 |
| T29 | Configured Reply-To added when absent, not replaced | C27 |
| T30 | Moved Reply-To beats configured Reply-To | C28 |
| T31 | Reply-To not duplicated when another hook added one | C29 |
| T32 | Sender filled when empty, from return path then From | C30 |
| T33 | Sender always mode overwrites | C31 |
| T34 | Sender never mode leaves it | C32 |
| T35 | Sender follows From changed by a later plugin | C33 |
| T36 | Two messages in one request: Sender does not leak | C34 |
| T37 | No Return-Path header ever added | C35 |
| T38 | CLI output carries the transport caveat | C36, C37 |
| T39 | Invalid return path falls back to From | C38 |
| T40 | Validator table: valid, CR/LF, too long, no dot, IP literal, Unicode with and without intl | C39 |
| T41 | Invalid constant falls through, warns once, fires action | C40 |
| T42 | All sources invalid leaves core values, logs error | C41 |
| T43 | Name cleaning: controls, whitespace, length, empty | C42 |
| T44 | Invalid settings input not saved | C43 |
| T45 | Multisite site admin cannot save foreign domain; super admin can | C44 |
| T46 | Each listed SMTP plugin marker detected; filter extends list | C45 |
| T47 | Defer mode: later force-From filter wins | C46, C63 |
| T48 | Stand-aside mode changes nothing when detected | C47 |
| T49 | Ignore mode behaves as no detection | C48 |
| T50 | Replaced `wp_mail()` reported with file | C49 |
| T51 | `pre_wp_mail` short-circuit leaves no state for next message | C50, C65 |
| T52 | Second copy registers no hooks | C51 |
| T53 | Network option, site option, constant precedence on multisite | C52 |
| T54 | Derived default per site on subdomain and subdirectory networks | C53 |
| T55 | Locked network fields read-only on site screen | C54 |
| T56 | Mail under `switch_to_blog()` uses that site | C55 |
| T57 | Domain-mapped site uses mapped host | C56 |
| T58 | Dry run changes nothing | C57 |
| T59 | Dry-run log line format, no recipients or subject | C58 |
| T60 | Decision action fires in dry run and live | C59 |
| T61 | Failure logged with prefix, switchable, no recipients | C60 |
| T62 | Logger failure does not affect send result | C61 |
| T63 | Hooks registered at documented priorities; priority constant honoured | C62, C63, C64 |
| T64 | Per-message state cleared on success and failure | C65 |
| T65 | `wp mail-from show` table and JSON output, exit codes | C40, C41, C49 |
| T66 | `--message-from` preview matches live decision | C20, C21, C22 |
| T67 | Uninstall removes options and notice meta on every site | section 6 |
| T68 | Visitor From masked in logs in respect mode | section 8 |

Every case C1 to C65 appears in at least one row.

## 13. Open questions

Answered by the builder in `docs/ARCHITECTURE.md`.

1. Should the default explicit-From mode be `align` instead of `respect`?
   `align` is safer for DMARC; `respect` is less surprising and matches
   production experience. Owner decision; the PRD assumes `respect`.
2. Does core reset the PHPMailer Sender between `wp_mail()` calls in
   WordPress 6.4 and later? C34 is written for the case where it does not;
   if it does, the tracking is still harmless. Verify against core.
3. The exact public markers for each SMTP plugin in C45, taken from their
   public documentation, and whether each one replaces `wp_mail()`.
4. The derived local part when the admin email is not aligned: `no-reply`,
   or nothing derived at all (leave core values and only warn)?
5. Single-file must-use layout versus loader plus directory: which one the
   release zip ships as the default.
6. Whether settings live on Settings, General or on a small page of their
   own under Settings.

## 14. Lessons from production

1. Compare against the default address the way core builds it (network home
   host, `www.` stripped), not against a host you build yourself. A check
   that builds it differently silently misses every message on `www` sites
   and on multisite subsites.
2. The `wp_mail_from` filter only sees a parsed value. It cannot tell a From
   header set on purpose from core's default. Read the headers yourself.
3. Never set the envelope sender unconditionally. Fill it only when empty,
   or an SMTP relay's own bounce handling breaks.
4. An invalid From does not just look bad: PHPMailer throws and the message
   is dropped. Validate before you hand anything over, and fall back to a
   working identity.
5. Silent mail failures are the most confusing class of WordPress bug. Log
   every `wp_mail_failed` with a fixed prefix so one grep finds them.
6. Put this in `mu-plugins`. A mail identity that can be deactivated from
   wp-admin will be, by accident, on the day it matters.
7. Keep the address out of the plugin file. Hard-coded constants in plugin
   code get overwritten on update and copied to the wrong site.
8. Some cloud hosts block outbound SMTP on port 25 for new accounts. A
   correct From does not fix that; check the transport before blaming the
   identity.
9. A local MTA may rewrite the envelope sender anyway. Aligning it in
   WordPress still matters the day a relay is put in front.
10. Do not replace a sender a contact form or shop set deliberately unless
    you also keep the reply path; replies that go nowhere are a support
    problem nobody notices for weeks.
11. The configured mailbox must exist and be read. Moving bounces from a
    void to an unread inbox is not a fix.
