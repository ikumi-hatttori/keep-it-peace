=== Keep It Peace ===
Contributors: ikumackarel
Tags: security, scanner, malware, monitoring, vulnerability
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scheduled security checks for custom themes and plugins, programs outside WordPress, your site's public surface and PHP, with e-mail summaries.

== Description ==

Most security plugins focus on WordPress core and plugins from WordPress.org. Keep It Peace checks the parts they usually miss:

* **Custom themes and plugins** – choose your own themes and the plugins you developed (plugins from WordPress.org are excluded). File integrity against a trusted baseline (added, modified and deleted files) and a static scan for dangerous code: eval(), shell execution, obfuscated payloads, unescaped request data, SQL without prepare(), AJAX and REST handlers without nonce or capability checks, and more.
* **Programs outside WordPress** – landing pages, form mailers or legacy applications on the same server. Register their directories and they get the same file checks.
* **The public surface** – SSL certificate expiry and trust, TLS version, security headers, version disclosure, publicly downloadable files such as .env, .git, wp-config.php backups, SQL dumps and debug.log, and directory listings.
* **PHP and the server** – end of life and security support of your PHP version, missing patch releases, known CVEs, risky php.ini settings, MySQL/MariaDB support status, WP_DEBUG and wp-config.php permissions, PHP files in the uploads folder.
* **Dependencies** – known vulnerabilities in composer.lock and package-lock.json found in themes, selected plugins and registered directories.

Results are stored as findings with a history. New, resolved and ongoing issues are tracked between scans; you can acknowledge or ignore findings (ignored file findings become active again when the file changes).

= Notifications =

After each scan you get an e-mail summary (HTML and plain text) with the number of issues by severity, new and resolved issues, the PHP status and the days left on your SSL certificate. File paths and code are never included in e-mails – details stay in your dashboard. Choose when to send: always, only when there are issues, only for new issues, or above a minimum severity. A weekly digest is optional.

= Built for shared hosting =

* No shell commands (exec, shell_exec, proc_open and backticks are not used).
* Works under open_basedir: directories PHP cannot read are reported instead of causing warnings.
* Scans run in short slices and resume where they stopped, so they never hit max_execution_time.
* The plugin only reads files. It never changes or deletes them.
* Can be run from the server cron with WP-CLI (`wp keep-it-peace scan`). The PHP version used by the web server is recorded and evaluated even when the scan runs on the CLI.

= For developers =

Checks, scan targets, notification channels, rules, settings tabs and the JSON export can be extended through documented interfaces, actions and filters. See the developer documentation in the plugin repository.

== External services ==

Keep It Peace contacts the services below to evaluate your PHP and database versions and your dependencies. Each service can be switched off under Settings → External services, and all of them can be disabled at once. Your site URL, file contents and personal data are never sent; requests use the User-Agent "KeepItPeace/<version>" instead of the WordPress default, which contains the site URL. Responses are cached (1 to 7 days).

* **endoflife.date** – support periods of PHP, MySQL and MariaDB. Sent: the product name (php, mysql or mariadb), during scans. https://endoflife.date/ – data and terms: https://github.com/endoflife-date/endoflife.date
* **php.net** – the latest patch release of your PHP series. Sent: the release series (for example 8.2), during scans. https://www.php.net/ – privacy policy: https://www.php.net/privacy.php
* **NVD (National Vulnerability Database, NIST)** – known vulnerabilities of your PHP version. Sent: the PHP version (for example 8.2.10), during scans. https://nvd.nist.gov/ – privacy policy: https://www.nist.gov/privacy-policy
* **OSV.dev (Google)** – known vulnerabilities of Composer and npm packages. Sent: package names and versions read from composer.lock and package-lock.json, during scans. https://osv.dev/ – terms: https://policies.google.com/terms – privacy policy: https://policies.google.com/privacy

When the external services are disabled, a bundled snapshot of PHP, MySQL and MariaDB support periods is used.

The external checks (SSL certificate, headers, exposed files, directory listings) send requests from your server to your own site only. They are limited to the domain of your site; other hosts must be explicitly allowed with the `keepitpeace_allowed_hosts` filter.

== Installation ==

1. Install and activate the plugin.
2. Open Keep It Peace → Settings. Choose the themes to scan (by default the active theme and its parent) and your custom plugins, add directories outside WordPress if you have any, and set the notification recipients.
3. Click "Scan now" on the Keep It Peace dashboard. The first scan records the current files as the trusted baseline.
4. For reliable scheduling, run the scan from the server cron: `wp keep-it-peace scan --quiet`. Settings → Tools shows the exact command for your site.

== Frequently Asked Questions ==

= Does it scan WordPress core and plugins from WordPress.org? =

No. Those are better covered by update checks and existing tools. Keep It Peace focuses on code that only you have: custom themes, custom plugins and other programs on your server, plus your PHP version and the public surface of the site.

= I deployed new theme or plugin files. How do I stop the "modified file" findings? =

Run a scan after the deployment, then approve the new state from the notice shown on Keep It Peace screens or in Settings → General (or with `wp keep-it-peace baseline approve --all`). The files become the new trusted baseline.

= Scheduled scans do not run. =

WP-Cron only runs when the site has visitors. Keep It Peace warns you on its screens when no scan has completed for too long. Set up a server cron job as described in Settings → Tools.

= The external checks say the site could not be reached. =

Some hosts block requests from the server to itself (loopback). Previous results are kept and an informational finding is shown. Ask your host to allow loopback requests, or disable the external checks.

= Is there a Pro version? =

Keep It Peace is complete on its own. A separate Pro add-on (multi-site dashboard, chat notifications, client reports) is planned; it extends the free plugin through the same public hooks.

== Screenshots ==

1. Dashboard with open issues by severity, PHP and SSL status and the most severe findings.
2. Findings list with filters and bulk actions.
3. Finding details with the affected code, recommendation and history.
4. Settings: scan targets including directories outside WordPress.

== Changelog ==

= 1.0.0 =
* First public release.
* Checks: PHP and database support status, php.ini, wp-config.php, PHP files in uploads.
* Custom themes, custom plugins and directories outside WordPress: file integrity against a baseline, dangerous code patterns, suspicious files, permissions, vulnerable Composer/npm dependencies (OSV.dev).
* External checks: SSL/TLS, security headers, exposed files, directory listings.
* E-mail summaries, findings history, JSON export, WP-CLI commands and Japanese translation.

== Upgrade Notice ==

= 1.0.0 =
First public release.
