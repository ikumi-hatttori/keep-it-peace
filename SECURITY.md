# Security policy

## Reporting a vulnerability

**Please do not report security vulnerabilities in public GitHub issues, pull requests or discussions, or in the WordPress.org support forum.**

Report them privately through the contact form:

https://keep-it-peace.com/contact/

Please include:

- The affected version of Keep It Peace
- A description of the vulnerability and its impact
- Steps to reproduce, or a proof of concept
- Your name or handle if you would like to be credited

We will acknowledge your report, investigate it and keep you informed of the progress. Please give us reasonable time to release a fix before disclosing the issue publicly. Once a fix is released, we will credit you in the changelog unless you prefer to stay anonymous.

## Supported versions

Security fixes are released for the latest version only. Please make sure you can reproduce the issue on the latest release.

## Scope

In scope: the code in this repository (the Keep It Peace plugin and its bundled rules and data).

Out of scope:

- Vulnerabilities in WordPress core, other plugins or themes. Report them to their authors, or to the [WordPress.org plugin team](https://developer.wordpress.org/plugins/wordpress-org/plugin-security/reporting-plugin-security-issues/) for plugins hosted on WordPress.org.
- The code in `tests/fixtures/`, which is intentionally vulnerable test data and is not included in the plugin distribution.
- Missed detections and false positives of the scanner. These are not vulnerabilities in the plugin; please open a regular issue (see [CONTRIBUTING.md](CONTRIBUTING.md)).
