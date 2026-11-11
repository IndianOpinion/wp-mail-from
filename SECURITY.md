# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Reporting a vulnerability

Email hello@indianopinion.org with:

- what the issue is and where (file, function, hook);
- how to reproduce it, ideally with a minimal `wp-config.php` and the mail
  call that triggers it;
- the impact you see (for example header injection, spoofing on multisite,
  information in logs).

Please do not open a public issue or pull request for a vulnerability.

We acknowledge reports within 3 working days and aim to ship a fix or a
mitigation within 30 days, sooner for anything that allows header injection
or sending as another site's domain. We credit reporters in the release notes
unless you ask us not to.

## Scope

In scope: this plugin's code, its handling of addresses and names, its admin
screens, its WP-CLI command and what it writes to logs.

Out of scope: WordPress core, PHPMailer, SMTP plugins, your mail server and
DNS records.
