# Contributing to Keep It Peace

Thank you for your interest in improving Keep It Peace. Bug reports, false positive reports, translations and pull requests are welcome.

**Security vulnerabilities must not be reported in public issues or pull requests.** See [SECURITY.md](SECURITY.md).

## Reporting bugs and false positives

Open an issue with:

- WordPress, PHP and Keep It Peace versions
- What you did, what you expected and what happened
- For a false positive or a missed detection: the rule ID (shown on the finding detail screen) and a minimal code sample that reproduces it

Do not paste real site URLs, server paths, credentials or the full JSON export into an issue. Reduce the example to the smallest code that reproduces the problem.

Support questions about using the plugin are best asked in the [WordPress.org support forum](https://wordpress.org/support/plugin/keep-it-peace/). User documentation is at https://keep-it-peace.com/docs/.

## Development setup

Requirements: PHP 7.4 or later and Composer. WP-CLI is needed only for `bin/make-pot.sh`.

```
composer install
composer test    # PHPUnit (WordPress is not loaded)
composer lint    # PHP_CodeSniffer: WordPress Coding Standards + PHPCompatibilityWP (PHP 7.4+)
```

Both commands must pass before a pull request can be merged.

To try the plugin in a real site, copy or symlink the repository into `wp-content/plugins/keep-it-peace`. The sample add-on in [dev/sample-addon/](dev/sample-addon/) exercises the extension points described in [docs/developer.md](docs/developer.md).

## Pull requests

- Keep each pull request focused on one change, and describe why it is needed.
- Add or update unit tests in `tests/Unit/`. For new detection rules, add a positive case to `tests/fixtures/vulnerable-theme/` and make sure `tests/fixtures/safe-theme/` still produces no findings.
- Fixtures must contain only the smallest pattern that triggers the rule. Do not add working exploits, web shells or real malware samples.
- Keep the plugin's principles:
  - Read-only: the plugin never changes or deletes files on the site.
  - No shell execution (`exec`, `shell_exec`, `proc_open`, backticks and so on).
  - Works on shared hosting: respect `open_basedir` and the time budget of each run.
  - Nothing about the site (URL, file paths, file contents, personal data) is sent to third parties. New external services must be documented in `readme.txt` under `== External services ==` and must be possible to switch off.
- User-facing strings must be translatable with the `keep-it-peace` text domain. Run `bin/make-pot.sh` if you change them.
- Extension APIs listed in [docs/developer.md](docs/developer.md) are covered by a compatibility promise. Do not change them incompatibly.
- Update both the English and Japanese documents (`README.md` / `README.ja.md`, `docs/developer.md` / `docs/developer.ja.md`) when behavior or APIs change. If you cannot write one of the languages, say so in the pull request.

## License

By contributing, you agree that your contributions are licensed under the GNU General Public License v2 or later, the same license as the plugin.
