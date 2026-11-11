# Examples

| File | How to run | What it shows |
|---|---|---|
| `wp-config-snippet.php` | Copy the lines into `wp-config.php` | Every constant with a sensible value |
| `parse-headers.php` | `php examples/parse-headers.php` | How the plugin reads From and Reply-To, exactly as core does; no WordPress needed |
| `preview.php` | `wp eval-file examples/preview.php` | What the plugin would do to a set of From headers on your site, without sending |
| `mu-plugins/align-form-mail.php` | Copy into `wp-content/mu-plugins/` | Align mode for one kind of message only, using `wpmf_explicit_mode` |
| `mu-plugins/decision-monitor.php` | Copy into `wp-content/mu-plugins/` | Counting decisions per day with the `wpmf_decision` action |
| `mu-plugins/allowed-domains.php` | Copy into `wp-content/mu-plugins/` | Letting every site on a network use a shared mail domain |
