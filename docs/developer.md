# Keep It Peace developer documentation

English | [日本語](developer.ja.md)

This document describes the extension points for adding features without modifying the plugin. A working sample is in [`dev/sample-addon/`](../dev/sample-addon/) (one custom check, one notification channel and one settings tab).

- Applies to: plugin 1.0.0 / extension API `1.0.0`
- Requirements: WordPress 6.0+ / PHP 7.4+

---

## 1. Bootstrapping an add-on

`keepitpeace_loaded` fires inside `plugins_loaded`, after the plugin has registered its own hooks. Check the API version there before registering anything.

```php
add_action( 'keepitpeace_loaded', function ( \KeepItPeace\Plugin $plugin ) {
	if ( version_compare( KEEPITPEACE_API_VERSION, '1.0', '<' ) || version_compare( KEEPITPEACE_API_VERSION, '2.0', '>=' ) ) {
		return; // Unsupported version.
	}
	// Register filters and actions.
} );
```

Adding `Requires Plugins: keep-it-peace` to your plugin header is recommended, so that the add-on is not loaded while Keep It Peace is inactive (WordPress 6.5+).

### 1.1 Constants

| Constant | Description |
|---|---|
| `KEEPITPEACE_VERSION` | Plugin version |
| `KEEPITPEACE_API_VERSION` | Extension API version (semver). The major version changes only when interfaces, hook arguments or `Api` methods change incompatibly |
| `KEEPITPEACE_EXPORT_SCHEMA_VERSION` | Schema version of the export JSON |
| `KEEPITPEACE_RULESET_SCHEMA_VERSION` | Schema version of the rule JSON (integer) |
| `KEEPITPEACE_FILE` / `KEEPITPEACE_DIR` / `KEEPITPEACE_URL` | Plugin paths and URL |

### 1.2 Compatibility promise

While the API version is 1.x, the following will not change incompatibly:

- The interfaces in `src/Contracts/`
- The names and arguments of the hooks listed in this document
- The public methods of `KeepItPeace\Api`
- The protected helpers of `AbstractCheck` / `AbstractFileCheck` (`finding()`, `file_finding()`)
- The public properties of `Finding`, `ScanReport`, `ScanTarget` and `CheckResult`

Other classes (repositories, admin screens and so on) are internal. Do not use them directly.

---

## 2. Adding checks

### 2.1 Standalone checks (`CheckInterface`)

Extending `AbstractCheck` gives you the on/off switch on the settings screen (`is_enabled()`) and the `finding()` helper.

```php
use KeepItPeace\Checks\AbstractCheck;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Scan\CheckResult;
use KeepItPeace\Scan\ScanContext;

class MyCheck extends AbstractCheck {
	public function get_id(): string { return 'myaddon.something'; } // Use your own prefix.
	public function get_name(): string { return __( 'Something', 'my-addon' ); }
	public function get_category(): string { return 'environment'; }
	public function get_description(): string { return __( 'Checks something.', 'my-addon' ); }

	public function run( ScanContext $context, array $cursor ): CheckResult {
		$findings = array();
		if ( /* there is a problem */ true ) {
			$findings[] = $this->finding( array(
				'rule_id'        => 'myaddon.something_wrong',
				'severity'       => Severity::MEDIUM,
				'target'         => 'something',
				'title'          => __( 'Something is wrong', 'my-addon' ),
				'message'        => __( 'Why it matters.', 'my-addon' ),
				'recommendation' => __( 'How to fix it.', 'my-addon' ),
			) );
		}
		return CheckResult::complete( $findings, array( 'value' => 42 ) );
	}
}

add_filter( 'keepitpeace_checks', function ( array $checks ) {
	$checks[] = new MyCheck();
	return $checks;
} );
```

- **Checks run in the order they are registered.** Registering the same ID again replaces the earlier check (this also works for the built-in checks).
- The `$data` in `CheckResult::complete( $findings, $data )` is stored in the scan's `summary.data[check ID]` and is available to the dashboard, notifications and the export.
- For **long-running work**, check `$context->has_time()` and, when time is up, return `CheckResult::partial( $findings, $cursor, $progress )`. The next request continues with `run( $context, $cursor )`. `$cursor` is stored as JSON, so use only arrays, strings and numbers.
- When the check cannot decide (for example an external API failed), call `$context->keep( $this->get_id() )` so that existing findings are not marked as resolved.
- If the check throws an exception, only that check stops and an Info finding "A check could not finish" is recorded. The rest of the scan continues.

### 2.2 Findings (`Finding`)

| Key | Description |
|---|---|
| `rule_id` | Rule ID (required) |
| `severity` | `Severity::CRITICAL` to `INFO`, or a string such as `'high'` |
| `category` | `php` / `environment` / `theme` / `external_path` / `external` / `dependency` / `files` / your own value |
| `target_type` | `env` / `file` / `url` |
| `target_scope` | `site` / `uploads` / `content` / `external` / `theme:<slug>` / `plugin:<slug>` / `path:<id>` and so on |
| `target` | What the finding is about (relative file path, URL, setting name and so on) |
| `line` | Line number (optional) |
| `title` / `message` / `recommendation` | Display text (already translated) |
| `meta` | Any array (stored as JSON) |
| `file_hash` | sha256 of the file. An ignored finding becomes active again when this hash changes |
| `fingerprint_basis` | A string that distinguishes several findings of the same rule on the same target |

**Identity (fingerprint)** is computed from `check_id`, `rule_id`, `target_scope`, `target` and `fingerprint_basis`. Line numbers and text are not included, so a finding keeps its identity when lines shift or the wording changes. A finding that is not reported again by a scan is automatically marked as resolved.

### 2.3 File checks (`FileCheckInterface`)

The plugin walks the files of each target once and passes every file to all file checks. Extend `AbstractFileCheck` and implement only the methods you need.

```php
use KeepItPeace\Checks\AbstractFileCheck;
use KeepItPeace\Scan\FileInfo;
use KeepItPeace\Scan\ScanContext;
use KeepItPeace\Scan\ScanTarget;

class TodoCheck extends AbstractFileCheck {
	public function get_id(): string { return 'myaddon.todo'; }
	public function get_name(): string { return 'TODO comments'; }
	public function get_description(): string { return 'Finds TODO comments.'; }

	public function analyze_file( FileInfo $file, ScanTarget $target, ScanContext $context ): array {
		if ( ! $file->is_php() ) {
			return array();
		}
		$code = $file->contents( $context->max_file_bytes() ); // null when the file is over the limit
		if ( null === $code || false === strpos( $code, 'TODO' ) ) {
			return array();
		}
		return array( $this->file_finding( $file, $target, array(
			'rule_id' => 'myaddon.todo', 'severity' => 'info', 'title' => 'TODO found',
		) ) );
	}
}
```

| Method | When it is called |
|---|---|
| `supports_target( ScanTarget $target )` | For each target: whether this check applies |
| `begin_target( $target, $context )` | Before the target is walked (may return Finding[]) |
| `analyze_file( $file, $target, $context )` | For each file |
| `end_target( $target, $context )` | After the target has been walked (for example, to report deleted files) |

A walk can be split across several requests, so `begin_target` and `end_target` may be called in different requests. If you need state across a target, store it in the database or an option, not in object properties.

Main members of `FileInfo`: `path`, `rel_path`, `basename`, `extension`, `size`, `mtime`, `perms`, `is_php()`, `hash()`, `contents( $max_bytes )`, `head( $length )`. File contents and sha256 are shared between checks.

### 2.4 Adding scan targets (`ScanTargetProviderInterface`)

```php
add_filter( 'keepitpeace_scan_target_providers', function ( array $providers ) {
	$providers[] = new class implements \KeepItPeace\Contracts\ScanTargetProviderInterface {
		public function get_targets( \KeepItPeace\Settings\Settings $settings ): array {
			$target = new \KeepItPeace\Scan\ScanTarget( 'myaddon:mu', 'myaddon', 'MU plugins', WPMU_PLUGIN_DIR, 'files' );
			$target->excludes = array( 'cache' );
			return array( $target );
		}
	};
	return $providers;
} );
```

The target ID becomes the `target_scope` of findings and the unit of the baseline. Changing the ID makes it a different target, and the baseline is recorded again on the next scan.

---

## 3. Adding rules

### 3.1 Rule providers (`RuleProviderInterface`)

```php
add_filter( 'keepitpeace_rule_providers', function ( array $providers ) {
	$providers[] = new class implements \KeepItPeace\Contracts\RuleProviderInterface {
		public function get_id(): string { return 'myaddon'; }
		public function get_rule_set(): \KeepItPeace\Rules\RuleSet {
			$data = json_decode( file_get_contents( __DIR__ . '/rules.json' ), true );
			return \KeepItPeace\Rules\RuleSet::from_array( $data, 'myaddon' );
		}
	};
	return $providers;
} );
```

Rules are merged by ID, and providers registered later take precedence (so a built-in rule can be replaced by using the same ID). Finally, `keepitpeace_rules` (`Rule[]`) lets you adjust the whole list.

### 3.2 Rule JSON

```json
{
  "schema_version": 1,
  "ruleset_version": "2026.12.0",
  "rules": [
    {
      "id": "myaddon.debug_function",
      "type": "regex",
      "severity": "low",
      "category": "dangerous_function",
      "pattern": "\\bvar_dump\\s*\\(",
      "flags": "i",
      "target": "code",
      "file_types": ["php"],
      "title": "var_dump() left in code",
      "description": "Debug output can reveal internal data.",
      "recommendation": "Remove the call."
    },
    { "id": "myaddon.custom", "type": "detector", "detector": "my_detector", "severity": "medium" }
  ]
}
```

| Key | Description |
|---|---|
| `type` | `regex` (a regular expression matched per line) or `detector` |
| `target` | `code` (code with comments and strings removed; default) or `raw` (the file contents as they are) |
| `file_types` | File extensions. `php` covers all PHP files such as `.php` and `.phtml` |
| `pattern` / `flags` | A regular expression without delimiters, and flags from `imsxuU` |

The texts of the bundled rules live in PHP (`RuleMessages`) so that they can be translated. For add-on rules, `title` and the other texts in the JSON are displayed as they are.

### 3.3 Detectors (`DetectorInterface`)

Logic that is hard to express as a regular expression is implemented as a detector working on tokens. `get_name()` corresponds to `detector` in the rule JSON.

```php
add_filter( 'keepitpeace_rule_detectors', function ( array $detectors ) {
	$detectors['my_detector'] = new MyDetector(); // implements DetectorInterface
	return $detectors;
} );
```

`detect( SourceFile $source )` returns an array of `array( 'rule_id' => …, 'line' => …, 'severity' => … (optional), 'meta' => … (optional) )`. `SourceFile` holds the result of `token_get_all()`, the code with comments and strings removed, and line information. The helpers in `Rules\Detectors\AbstractDetector` (finding the next token, matching brackets and so on) are also available.

---

## 4. Adding notification channels

```php
add_filter( 'keepitpeace_notification_channels', function ( array $channels ) {
	$channels[] = new SlackChannel(); // implements NotificationChannelInterface
	return $channels;
} );
```

| Method | Description |
|---|---|
| `get_id()` | For example `slack`. Settings are stored in `keepitpeace_settings['channels'][ID]` |
| `is_enabled( Settings $s )` | Whether the channel is configured and enabled |
| `send( ScanReport $report )` | Returns `true` or a `WP_Error` |
| `send_test()` | "Send test" on the settings screen |
| `render_settings( Settings $s )` | Rows (`<tr>`) inside the form of the Notifications tab. Input names are `keepitpeace[channels][ID][...]` |
| `sanitize_settings( array $input, array $current )` | Sanitizing on save |

**Contents of `ScanReport`**: `scan` (`Scan`), `site_name`, `site_url`, `admin_url`, `by_severity` (open issues per severity), `diff` (`new` / `resolved` / `ongoing`), `php`, `database`, `tls`, `is_digest`, `max_severity()`, `has_issues()`, `total_issues()`.

**Do not include file paths, code snippets or URL paths** in messages sent to external services (the plugin's own e-mails only contain counts and status). Details are meant to be checked in the admin screens through the `admin_url` link.

Whether a notification is sent is decided by the plugin's send conditions, and can be overridden per channel with `keepitpeace_should_notify`.

---

## 5. Extending the settings screen

### 5.1 Adding a tab

```php
add_filter( 'keepitpeace_settings_tabs', function ( $tabs ) {
	$tabs['myaddon'] = __( 'My add-on', 'my-addon' );
	return $tabs;
} );

add_action( 'keepitpeace_settings_tab_myaddon', function ( \KeepItPeace\Settings\Settings $settings ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="keepitpeace_save_settings">
		<input type="hidden" name="tab" value="myaddon">
		<?php wp_nonce_field( 'keepitpeace_save_settings' ); ?>
		<input type="text" name="keepitpeace[myaddon][key]" value="<?php echo esc_attr( $settings->get( 'myaddon.key', '' ) ); ?>">
		<?php submit_button(); ?>
	</form>
	<?php
} );

// Defaults and sanitizing (when storing in the plugin's settings option).
add_filter( 'keepitpeace_default_settings', function ( $d ) { $d['myaddon'] = array( 'key' => '' ); return $d; } );
add_filter( 'keepitpeace_sanitize_settings', function ( $values, $input, $tab ) {
	if ( 'myaddon' === $tab ) {
		$values['myaddon'] = array( 'key' => sanitize_text_field( $input['myaddon']['key'] ?? '' ) );
	}
	return $values;
}, 10, 3 );
```

The `keepitpeace_save_settings` handler checks the capability (`keepitpeace_capability`) and the nonce. To store settings in your own option, write your own `admin-post` handler and check the capability and nonce in the same way.

### 5.2 Other screens

| Hook | Description |
|---|---|
| `keepitpeace_settings_section_{$tab}` | Adds rows at the end of the form table of a built-in tab (on the `tools` tab, outside the form) |
| `keepitpeace_dashboard_widgets` | Adds cards to the plugin's dashboard |
| `keepitpeace_finding_detail_panels` | Adds panels to the finding detail screen (diffs, AI explanations and so on) |
| `keepitpeace_show_pro_notice` | Return `false` to hide the Pro notice on the settings screen (when Pro is installed) |

---

## 6. PHP API (`KeepItPeace\Api`)

```php
use KeepItPeace\Api;

Api::get_site_uuid();                    // A UUID that identifies the site without exposing its URL
Api::export_scan( $scan_id = null );     // array|null (null = the latest completed scan)
Api::export_scan_json( $scan_id = null, JSON_PRETTY_PRINT ); // string|null
Api::get_latest_scan();                  // Scan|null
Api::get_findings( array( 'status' => array( 'new' ), 'severity' => 4, 'category' => 'theme', 'per_page' => 50 ) ); // Finding[]
Api::start_scan( 'myaddon' );            // Scan ID. The scan continues on WP-Cron
Api::get_settings();                     // Settings (dot notation, e.g. $settings->get( 'channels.email.recipients' ))
```

### 6.1 Export JSON

The format is defined in [`export-schema.json`](export-schema.json) (JSON Schema 2020-12).

- `schema` is always `keepitpeace.scan-report`. `schema_version` is semver; minor versions only add optional fields
- `findings` contains the findings detected in the scan and those resolved by the scan. `status` is the current status, and `diff` (`new` / `ongoing` / `resolved`) is the difference at the time of the scan
- Add-ons add their data under `extensions.<add-on slug>` with `keepitpeace_export_data`

```php
add_filter( 'keepitpeace_export_data', function ( array $data, int $scan_id ) {
	$data['extensions']['myaddon'] = array( 'foo' => 'bar' );
	return $data;
}, 10, 2 );
```

The export contains file paths and URLs. If your add-on sends it to an external service (such as an aggregation server), obtain the site administrator's consent and state clearly where it is sent and what it contains.

---

## 7. Hook reference

### 7.1 Actions

| Hook | Arguments | Fires when |
|---|---|---|
| `keepitpeace_loaded` | `Plugin $plugin` | The plugin is ready (`plugins_loaded`) |
| `keepitpeace_scan_started` | `Scan $scan` | A scan starts |
| `keepitpeace_scan_completed` | `ScanReport $report` | A scan completes (before notifications) |
| `keepitpeace_scan_failed` | `Scan $scan, WP_Error $error` | A scan fails |
| `keepitpeace_finding_created` | `Finding $finding, Scan $scan` | A finding is detected for the first time |
| `keepitpeace_finding_reopened` | `Finding $finding, Scan $scan, string $outcome` | A resolved or ignored finding becomes active again (`reopened` / `ignore_reset`) |
| `keepitpeace_finding_resolved` | `Finding $finding, Scan $scan` | A finding is no longer detected |
| `keepitpeace_finding_status_changed` | `Finding $finding, string $old, string $new, int $user_id, string $note` | The status is changed in the admin screens |
| `keepitpeace_baseline_approved` | `string $scope, int $file_count, int $user_id` | A baseline is approved |
| `keepitpeace_baseline_file_recorded` | `string $abs_path, string $scope, string $rel_path, string $sha256` | For each file on approval (fires only when hooked; for example, to store file contents for diffs) |
| `keepitpeace_notification_sent` | `string $channel_id, ScanReport $report, true\|WP_Error $result` | After each channel has sent |
| `keepitpeace_daily_maintenance` | — | The daily maintenance cron |
| `keepitpeace_settings_tab_{$tab}` | `Settings $settings` | Rendering an added tab |
| `keepitpeace_settings_section_{$tab}` | `Settings $settings` | End of a built-in tab |
| `keepitpeace_dashboard_widgets` | `Settings $settings` | Dashboard |
| `keepitpeace_finding_detail_panels` | `Finding $finding` | Finding detail screen |

### 7.2 Filters

| Hook | Value (extra arguments) | Purpose |
|---|---|---|
| `keepitpeace_checks` | `CheckInterface[]` | Register, replace or remove checks |
| `keepitpeace_scan_target_providers` | `ScanTargetProviderInterface[]` | Add scan targets |
| `keepitpeace_notification_channels` | `NotificationChannelInterface[]` | Add notification channels |
| `keepitpeace_rule_providers` | `RuleProviderInterface[]` | Add or deliver rules |
| `keepitpeace_rules` | `Rule[]` | Final adjustment of the rules |
| `keepitpeace_rule_detectors` | `array<string, DetectorInterface>` | Add detectors |
| `keepitpeace_schedule_options` | `array<string, array{label, interval}>` | Add scan frequencies (e.g. hourly) |
| `keepitpeace_time_budget` | `float $seconds` (`string $trigger`) | Time limit of one run |
| `keepitpeace_file_limits` | `array{max_size, max_files}` | File size and file count limits |
| `keepitpeace_exclude_patterns` | `string[]` (`ScanTarget $target`) | Exclude patterns |
| `keepitpeace_retention_max_days` | `int` (default 365; `0` = unlimited) | Maximum retention period |
| `keepitpeace_should_notify` | `bool` (`ScanReport $report, string $channel_id`) | Whether to notify |
| `keepitpeace_email_recipients` | `string[]` | E-mail recipients |
| `keepitpeace_email_subject` | `string` (`ScanReport $report`) | Subject |
| `keepitpeace_email_body_text` / `keepitpeace_email_body_html` | `string` (`ScanReport $report`) | Body |
| `keepitpeace_settings_tabs` | `array<string, string>` | Add settings tabs |
| `keepitpeace_default_settings` | `array` | Default settings |
| `keepitpeace_sanitize_settings` | `array $values` (`array $input, string $tab`) | Sanitize settings |
| `keepitpeace_allowed_hosts` | `string[]` | Hosts allowed for external checks (exact match or `*.example.com`) |
| `keepitpeace_public_suffixes` | `string[]` | Multi-label suffixes used to find the registrable domain (add shared hosting domains) |
| `keepitpeace_external_urls` | `array[]` (`Settings $settings`) | URLs checked by the external checks (hosts that are not allowed are removed) |
| `keepitpeace_sensitive_paths` | `array[]` | Path definitions for the exposed files check (same format as `data/sensitive-paths.json`) |
| `keepitpeace_listing_urls` | `string[]` | URLs checked for directory listings |
| `keepitpeace_probe_timeout` | `int` (default 5) | Timeout of the external checks (seconds) |
| `keepitpeace_osv_details_limit` | `int` (default 40) | Maximum number of vulnerability details fetched per run |
| `keepitpeace_export_data` | `array` (`int $scan_id`) | Extend the export |
| `keepitpeace_capability` | `string` (default `manage_options`) | Capability required for the screens and actions |
| `keepitpeace_show_pro_notice` | `bool` | Whether to show the Pro notice |

---

## 8. Developing the plugin

```
composer install
vendor/bin/phpunit          # unit tests (WordPress is not loaded)
vendor/bin/phpcs            # WPCS + PHPCompatibilityWP (testVersion 7.4-)
vendor/bin/phpcs --runtime-set text_domain kip-sample-addon dev   # sample add-on
bin/build-zip.sh            # distribution ZIP (follows .distignore)
bin/make-pot.sh             # translation template and Japanese .mo (requires WP-CLI)
```

To use the sample add-on in a test site, copy (or symlink) `dev/sample-addon` into `wp-content/plugins/` and activate it.
