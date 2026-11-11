# Keep It Peace

English | [日本語](README.ja.md)

A WordPress plugin that checks the security of your site on a schedule and e-mails you a summary.
Instead of WordPress core and plugins from WordPress.org, it focuses on the parts other security plugins usually miss: **custom themes and plugins, programs outside WordPress, the public surface of the site, and the PHP runtime**.

- User documentation: https://keep-it-peace.com/docs/
- Extending the plugin: [docs/developer.md](docs/developer.md)
- Design notes (Japanese): [docs/DESIGN.md](docs/DESIGN.md)
- WordPress.org description: [readme.txt](readme.txt)

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later (no shell execution functions are used; works under `open_basedir`)

## Checks

| Check | What it looks at |
|---|---|
| `php.version` | PHP end of life, missing patch releases in the same series, known CVEs (NVD) |
| `php.ini` | `display_errors`, `expose_php`, `allow_url_include`, `allow_url_fopen` and more |
| `server.database` | MySQL / MariaDB version and end of life |
| `env.wp_config` | `WP_DEBUG` / `WP_DEBUG_DISPLAY`, `debug.log`, `wp-config.php` permissions, the file editor |
| `env.uploads_php` | `.php` and other executable files under `wp-content/uploads` |
| `files.integrity` | Changes to themes and selected plugins against a sha256 baseline (added, modified, deleted) |
| `files.code_pattern` | Static scan for dangerous code (rules in `rules/core.json`) |
| `files.suspicious` | Double extensions, hidden files, PHP in asset folders, PHP code inside images |
| `files.permissions` | Files and directories with modes such as 777 / 666 |
| `deps.osv` | Known vulnerabilities in `composer.lock` / `package-lock.json` (OSV.dev). Dev packages only when they are actually installed |
| `external.tls` | HTTPS, certificate expiry, trust and host name, TLS version |
| `external.headers` | HSTS and other security headers, HTTP→HTTPS redirect, version disclosure via Server / X-Powered-By / generator |
| `external.exposed_files` | Publicly downloadable `.env`, `.git`, `wp-config.php` backups, SQL dumps, `debug.log`, phpinfo and more (the response body is checked to avoid false positives) |
| `external.dir_listing` | Directory listings such as `uploads` |

**Scan targets**: Settings → Targets. Choose the themes to scan (by default the active theme and its parent) and the plugins you developed yourself (plugins distributed on WordPress.org are not scanned). You can also register directories outside WordPress, such as landing pages or form mailers. Existence, read permission, `open_basedir` and overlap with WordPress are validated on save. Each directory has its own check switches, exclude patterns and an optional public URL for the external checks.

**URLs for external checks**: the site address, the WordPress address and the URLs added in the settings. Only the site's own domain (including subdomains) is allowed; other hosts must be allowed with the `keepitpeace_allowed_hosts` filter. On shared hosting domains (such as `xsrv.jp`), other users' subdomains are never targeted.

**JSON export**: Settings → Tools downloads the latest scan as JSON. Add-ons can use `KeepItPeace\Api::export_scan()`. The format is defined in [docs/export-schema.json](docs/export-schema.json).

Also:

- Scheduled scans (WP-Cron, daily or weekly). Each run has a time limit and the scan resumes on the next cron run
- Admin screens: dashboard, findings (filters, bulk actions, code view, history), scan history, tabbed settings
- E-mail notifications (plain text / HTML, send conditions, weekly digest, test e-mail). File paths and code are never included in e-mails
- Japanese translation bundled (`languages/`)

## WP-CLI

| Command | Description |
|---|---|
| `wp keep-it-peace scan [--checks=<ids>] [--no-notify] [--format=table\|json]` | Run a scan to completion (continues a running scan if there is one) |
| `wp keep-it-peace status [--format=json]` | Open issues by severity and the last scan |
| `wp keep-it-peace findings [--status=open] [--severity=high] [--category=…] [--check=…] [--format=…]` | List findings |
| `wp keep-it-peace export [--scan=<id>] [--pretty]` | Scan result as JSON ([docs/export-schema.json](docs/export-schema.json)) |
| `wp keep-it-peace baseline approve (--scope=<scope> \| --all) [--yes]` | Approve the current files as the baseline (use after a deployment) |
| `wp keep-it-peace checks` / `wp keep-it-peace targets` | List checks and scan targets |

Command output is always in English so that scripts can parse it.

### Running from the server cron

WP-Cron only runs when the site receives a request. For reliable monitoring, run the scan from the server cron:

```
0 3 * * * cd /path/to/wordpress && wp keep-it-peace scan --quiet
```

On shared hosting, the CLI and the web server may use different PHP versions or php.ini files. The plugin records the web server's PHP information (for example when you open the admin screens) and evaluates it even when the scan runs on the CLI.

## External services

| Service | What is sent |
|---|---|
| endoflife.date | Product name (php / mysql / mariadb) |
| php.net | PHP release series (e.g. 8.2) |
| NVD | PHP version (e.g. 8.2.10) |
| OSV.dev | Package names and versions (e.g. guzzlehttp/guzzle 7.4.0) |

The site URL, file contents and personal data are never sent. Each service can be switched off under Settings → External services. See `== External services ==` in [readme.txt](readme.txt) for details and the services' terms.

The external checks (TLS, headers, exposed files, directory listings) send requests from your server to your own site; no third-party service is used. If the server blocks loopback requests, the previous results are kept and an Info finding says the check could not be completed.

## Development

```
composer install
composer test            # unit tests (PHPUnit, WordPress is not loaded)
composer lint            # coding standards (WPCS + PHPCompatibilityWP)
bin/make-pot.sh          # update the translation template and compile .mo files (requires WP-CLI)
bin/build-zip.sh         # build the distribution ZIP in dist/
```

A sample add-on that exercises the extension points is in [dev/sample-addon/](dev/sample-addon/) (not included in the distribution ZIP).

See [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## Security

Please do not report vulnerabilities in public issues. See [SECURITY.md](SECURITY.md).

## License

GPLv2 or later
