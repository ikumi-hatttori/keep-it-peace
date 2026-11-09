# Keep It Peace 開発者向けドキュメント

本体を改修せずに機能を追加するための拡張ポイントをまとめます。動作するサンプルは [`dev/sample-addon/`](../dev/sample-addon/) にあります（独自チェック 1 つ、通知チャネル 1 つ、設定タブ 1 つ）。

- 対象バージョン：本体 0.3.0 / 拡張 API `1.0.0`
- 要件：WordPress 6.0+ / PHP 7.4+

---

## 1. アドオンの起動

`keepitpeace_loaded` は `plugins_loaded` の中で、本体がフックを登録し終えたあとに発火します。ここで API バージョンを確認してから処理を登録してください。

```php
add_action( 'keepitpeace_loaded', function ( \KeepItPeace\Plugin $plugin ) {
	if ( version_compare( KEEPITPEACE_API_VERSION, '1.0', '<' ) || version_compare( KEEPITPEACE_API_VERSION, '2.0', '>=' ) ) {
		return; // 対応していないバージョン
	}
	// フィルター・アクションを登録する
} );
```

本体が無効なときに読み込まれないよう、プラグインヘッダーに `Requires Plugins: keep-it-peace` を書くことを推奨します（WordPress 6.5+）。

### 1.1 定数

| 定数 | 内容 |
|---|---|
| `KEEPITPEACE_VERSION` | 本体のバージョン |
| `KEEPITPEACE_API_VERSION` | 拡張 API のバージョン（semver）。インターフェース・フック引数・`Api` のメソッドを互換性なく変える場合だけメジャーを上げます |
| `KEEPITPEACE_EXPORT_SCHEMA_VERSION` | エクスポート JSON のスキーマバージョン |
| `KEEPITPEACE_RULESET_SCHEMA_VERSION` | ルール JSON のスキーマバージョン（整数） |
| `KEEPITPEACE_FILE` / `KEEPITPEACE_DIR` / `KEEPITPEACE_URL` | 本体のパス・URL |

### 1.2 互換性の約束

API バージョン 1.x の間は、次のものを互換性なく変更しません。

- `src/Contracts/` のインターフェース
- このドキュメントに載っているフックの名前と引数
- `KeepItPeace\Api` の公開メソッド
- `AbstractCheck` / `AbstractFileCheck` の protected ヘルパー（`finding()`、`file_finding()`）
- `Finding`・`ScanReport`・`ScanTarget`・`CheckResult` の公開プロパティ

それ以外のクラス（リポジトリ、管理画面など）は内部実装なので、直接使わないでください。

---

## 2. チェックを追加する

### 2.1 単独のチェック（`CheckInterface`）

`AbstractCheck` を継承すると、設定画面の ON/OFF（`is_enabled()`）と `finding()` ヘルパーが使えます。

```php
use KeepItPeace\Checks\AbstractCheck;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Scan\CheckResult;
use KeepItPeace\Scan\ScanContext;

class MyCheck extends AbstractCheck {
	public function get_id(): string { return 'myaddon.something'; } // 自分の接頭辞を付ける
	public function get_name(): string { return __( 'Something', 'my-addon' ); }
	public function get_category(): string { return 'environment'; }
	public function get_description(): string { return __( 'Checks something.', 'my-addon' ); }

	public function run( ScanContext $context, array $cursor ): CheckResult {
		$findings = array();
		if ( /* 問題がある */ true ) {
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

- **登録順＝実行順**です。同じ ID を登録すると後から登録した方で置き換わります（本体のチェックの差し替えも可能）。
- `CheckResult::complete( $findings, $data )` の `$data` は、スキャンの `summary.data[チェックID]` に保存されます（ダッシュボード・通知・エクスポートで利用可能）。
- **時間のかかる処理**は `$context->has_time()` を確認し、時間切れなら `CheckResult::partial( $findings, $cursor, $progress )` を返してください。次のリクエストで `run( $context, $cursor )` として続きから呼ばれます。`$cursor` は JSON にして保存するので、配列・文字列・数値だけにしてください。
- 外部 API の失敗などで判定できなかったときは `$context->keep( $this->get_id() )` を呼ぶと、既存の検出結果が「解決済み」になりません。
- 例外を投げると、そのチェックだけが中断され、Info の検出結果「チェックを完了できませんでした」が記録されます（スキャン全体は続きます）。

### 2.2 検出結果（`Finding`）

| キー | 内容 |
|---|---|
| `rule_id` | ルール ID（必須） |
| `severity` | `Severity::CRITICAL`〜`INFO`、または `'high'` などの文字列 |
| `category` | `php` / `environment` / `theme` / `external_path` / `external` / `dependency` / `files` / 独自の値 |
| `target_type` | `env` / `file` / `url` |
| `target_scope` | `site` / `uploads` / `content` / `external` / `theme:<slug>` / `path:<id>` など |
| `target` | 対象（ファイルの相対パス、URL、設定名など） |
| `line` | 行番号（任意） |
| `title` / `message` / `recommendation` | 表示用の文言（翻訳済みの文字列） |
| `meta` | 任意の配列（JSON で保存） |
| `file_hash` | ファイルの sha256。無視にした検出は、このハッシュが変わると自動で再開されます |
| `fingerprint_basis` | 同じ対象に同じルールの検出が複数あるときに区別する文字列 |

**同一性（fingerprint）** は `check_id`・`rule_id`・`target_scope`・`target`・`fingerprint_basis` から計算します。行番号や文言は含まないので、行がずれたり文言を変えたりしても同じ検出として扱われます。スキャンで再び出なかった検出は自動で「解決済み」になります。

### 2.3 ファイルを走査するチェック（`FileCheckInterface`）

ファイルの走査は本体が対象ごとに 1 回だけ行い、各ファイルをすべてのファイルチェックに渡します。`AbstractFileCheck` を継承すると、必要なメソッドだけ実装すれば済みます。

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
		$code = $file->contents( $context->max_file_bytes() ); // 上限を超えると null
		if ( null === $code || false === strpos( $code, 'TODO' ) ) {
			return array();
		}
		return array( $this->file_finding( $file, $target, array(
			'rule_id' => 'myaddon.todo', 'severity' => 'info', 'title' => 'TODO found',
		) ) );
	}
}
```

| メソッド | 呼ばれるタイミング |
|---|---|
| `supports_target( ScanTarget $target )` | 対象ごとに、このチェックを使うかどうか |
| `begin_target( $target, $context )` | 対象の走査を始める前（Finding[] を返せる） |
| `analyze_file( $file, $target, $context )` | ファイルごと |
| `end_target( $target, $context )` | 対象の走査が終わったあと（例：削除されたファイルの検出） |

走査は複数のリクエストに分かれることがあるため、`begin_target` と `end_target` が別のリクエストで呼ばれる場合があります。対象をまたいで状態を持つ場合は、オブジェクトのプロパティではなく DB やオプションに保存してください。

`FileInfo` の主なメンバー：`path`、`rel_path`、`basename`、`extension`、`size`、`mtime`、`perms`、`is_php()`、`hash()`、`contents( $max_bytes )`、`head( $length )`。ファイルの内容と sha256 はチェック間で共有されます。

### 2.4 スキャン対象を追加する（`ScanTargetProviderInterface`）

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

対象 ID は検出結果の `target_scope` とベースラインの単位になります。ID を変えると別の対象として扱われ、初回スキャンでベースラインを取り直します。

---

## 3. ルールを追加する

### 3.1 ルールプロバイダー（`RuleProviderInterface`）

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

ルールは ID で統合され、後から登録したプロバイダーが優先されます（同じ ID で本体のルールを差し替え可能）。最後に `keepitpeace_rules`（`Rule[]`）で一覧全体を調整できます。

### 3.2 ルール JSON

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

| キー | 内容 |
|---|---|
| `type` | `regex`（行単位の正規表現）または `detector` |
| `target` | `code`（コメントと文字列を除いたコード。既定）または `raw`（ファイルの内容そのまま） |
| `file_types` | 対象の拡張子。`php` は `.php` / `.phtml` などの PHP ファイル全般 |
| `pattern` / `flags` | 区切り文字なしの正規表現と、`imsxuU` のフラグ |

本体同梱ルールの文言は翻訳のため PHP 側（`RuleMessages`）にありますが、アドオンのルールは JSON の `title` などがそのまま表示されます。

### 3.3 検出器（`DetectorInterface`）

正規表現では難しい判定は、トークン解析の検出器で行います。`get_name()` がルール JSON の `detector` と対応します。

```php
add_filter( 'keepitpeace_rule_detectors', function ( array $detectors ) {
	$detectors['my_detector'] = new MyDetector(); // implements DetectorInterface
	return $detectors;
} );
```

`detect( SourceFile $source )` は `array( 'rule_id' => …, 'line' => …, 'severity' => …（任意）, 'meta' => …（任意）)` の配列を返します。`SourceFile` は `token_get_all()` の結果、コメント・文字列を除いたコード、行の情報を持ちます。`Rules\Detectors\AbstractDetector` のヘルパー（次のトークンの検索、括弧の対応など）も利用できます。

---

## 4. 通知チャネルを追加する

```php
add_filter( 'keepitpeace_notification_channels', function ( array $channels ) {
	$channels[] = new SlackChannel(); // implements NotificationChannelInterface
	return $channels;
} );
```

| メソッド | 内容 |
|---|---|
| `get_id()` | `slack` など。設定は `keepitpeace_settings['channels'][ID]` に保存されます |
| `is_enabled( Settings $s )` | 設定済みで有効か |
| `send( ScanReport $report )` | `true` または `WP_Error` を返す |
| `send_test()` | 設定画面の「テスト送信」 |
| `render_settings( Settings $s )` | 通知タブのフォーム内（`<tr>` 単位）。入力名は `keepitpeace[channels][ID][...]` |
| `sanitize_settings( array $input, array $current )` | 保存時のサニタイズ |

**`ScanReport` の内容**：`scan`（`Scan`）、`site_name`、`site_url`、`admin_url`、`by_severity`（重要度ごとの未解決件数）、`diff`（`new` / `resolved` / `ongoing`）、`php`、`database`、`tls`、`is_digest`、`max_severity()`、`has_issues()`、`total_issues()`。

外部サービスに送るメッセージには、**ファイルパス・コード片・URL のパスを含めないでください**（本体のメールも件数と状態だけを送ります）。詳細は管理画面へのリンク（`admin_url`）で確認してもらう設計です。

送信するかどうかは本体の通知条件で決まり、チャネルごとに `keepitpeace_should_notify` で上書きできます。

---

## 5. 設定画面を拡張する

### 5.1 タブを追加する

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

// 既定値とサニタイズ（本体の設定オプションに同居させる場合）
add_filter( 'keepitpeace_default_settings', function ( $d ) { $d['myaddon'] = array( 'key' => '' ); return $d; } );
add_filter( 'keepitpeace_sanitize_settings', function ( $values, $input, $tab ) {
	if ( 'myaddon' === $tab ) {
		$values['myaddon'] = array( 'key' => sanitize_text_field( $input['myaddon']['key'] ?? '' ) );
	}
	return $values;
}, 10, 3 );
```

`keepitpeace_save_settings` のハンドラーが権限（`keepitpeace_capability`）と nonce を確認します。独自のオプションに保存したい場合は、自分で `admin-post` ハンドラーを用意し、同じように権限と nonce を確認してください。

### 5.2 その他の画面

| フック | 内容 |
|---|---|
| `keepitpeace_settings_section_{$tab}` | 本体のタブのフォーム表の末尾に行を追加（`tools` タブはフォームの外） |
| `keepitpeace_dashboard_widgets` | 本プラグインのダッシュボードにカードを追加 |
| `keepitpeace_finding_detail_panels` | 検出結果の詳細画面にパネルを追加（差分表示、AI による解説など） |
| `keepitpeace_show_pro_notice` | `false` を返すと設定画面の Pro の案内を消す（Pro を導入済みのとき） |

---

## 6. PHP API（`KeepItPeace\Api`）

```php
use KeepItPeace\Api;

Api::get_site_uuid();                    // サイト固有の UUID（URL を出さずにサイトを識別）
Api::export_scan( $scan_id = null );     // array|null（null = 最新の完了スキャン）
Api::export_scan_json( $scan_id = null, JSON_PRETTY_PRINT ); // string|null
Api::get_latest_scan();                  // Scan|null
Api::get_findings( array( 'status' => array( 'new' ), 'severity' => 4, 'category' => 'theme', 'per_page' => 50 ) ); // Finding[]
Api::start_scan( 'myaddon' );            // スキャン ID。続きは WP-Cron で実行
Api::get_settings();                     // Settings（$settings->get( 'channels.email.recipients' ) のようにドット記法で取得）
```

### 6.1 エクスポートの JSON

形式は [`export-schema.json`](export-schema.json)（JSON Schema 2020-12）で定義しています。

- `schema` は常に `keepitpeace.scan-report`。`schema_version` は semver で、マイナーバージョンでは任意項目の追加だけを行います
- `findings` は、そのスキャンで検出されたものと、そのスキャンで解決されたもの。`status` は現在の状態、`diff`（`new` / `ongoing` / `resolved`）はそのスキャン時点の差分
- アドオンのデータは `keepitpeace_export_data` で `extensions.<アドオンのスラッグ>` の下に追加します

```php
add_filter( 'keepitpeace_export_data', function ( array $data, int $scan_id ) {
	$data['extensions']['myaddon'] = array( 'foo' => 'bar' );
	return $data;
}, 10, 2 );
```

エクスポートにはファイルパスや URL が含まれます。外部（集約サーバーなど）に送る場合は、アドオン側でサイト管理者の同意を得て、送信先と内容を明示してください。

---

## 7. フック一覧

### 7.1 アクション

| フック | 引数 | 発火のタイミング |
|---|---|---|
| `keepitpeace_loaded` | `Plugin $plugin` | 本体の準備完了（`plugins_loaded`） |
| `keepitpeace_scan_started` | `Scan $scan` | スキャン開始 |
| `keepitpeace_scan_completed` | `ScanReport $report` | スキャン完了（通知の前） |
| `keepitpeace_scan_failed` | `Scan $scan, WP_Error $error` | スキャンの失敗 |
| `keepitpeace_finding_created` | `Finding $finding, Scan $scan` | 初めて検出された |
| `keepitpeace_finding_reopened` | `Finding $finding, Scan $scan, string $outcome` | 解決済み・無視が再開された（`reopened` / `ignore_reset`） |
| `keepitpeace_finding_resolved` | `Finding $finding, Scan $scan` | 検出されなくなった |
| `keepitpeace_finding_status_changed` | `Finding $finding, string $old, string $new, int $user_id, string $note` | 管理画面で状態を変更 |
| `keepitpeace_baseline_approved` | `string $scope, int $file_count, int $user_id` | ベースラインの承認 |
| `keepitpeace_baseline_file_recorded` | `string $abs_path, string $scope, string $rel_path, string $sha256` | 承認時にファイルごと（フックがある場合のみ発火。差分表示用にファイルの内容を保存する用途） |
| `keepitpeace_notification_sent` | `string $channel_id, ScanReport $report, true\|WP_Error $result` | 各チャネルの送信後 |
| `keepitpeace_daily_maintenance` | — | 日次メンテナンスの Cron |
| `keepitpeace_settings_tab_{$tab}` | `Settings $settings` | 追加タブの描画 |
| `keepitpeace_settings_section_{$tab}` | `Settings $settings` | 既存タブの末尾 |
| `keepitpeace_dashboard_widgets` | `Settings $settings` | ダッシュボード |
| `keepitpeace_finding_detail_panels` | `Finding $finding` | 検出結果の詳細画面 |

### 7.2 フィルター

| フック | 値（追加の引数） | 用途 |
|---|---|---|
| `keepitpeace_checks` | `CheckInterface[]` | チェックの登録・差し替え・削除 |
| `keepitpeace_scan_target_providers` | `ScanTargetProviderInterface[]` | スキャン対象の追加 |
| `keepitpeace_notification_channels` | `NotificationChannelInterface[]` | 通知チャネルの追加 |
| `keepitpeace_rule_providers` | `RuleProviderInterface[]` | ルールの追加・配信 |
| `keepitpeace_rules` | `Rule[]` | ルールの最終調整 |
| `keepitpeace_rule_detectors` | `array<string, DetectorInterface>` | 検出器の追加 |
| `keepitpeace_schedule_options` | `array<string, array{label, interval}>` | スキャン頻度の追加（例：毎時） |
| `keepitpeace_time_budget` | `float $seconds`（`string $trigger`） | 1 回の実行時間の上限 |
| `keepitpeace_file_limits` | `array{max_size, max_files}` | ファイルサイズ・ファイル数の上限 |
| `keepitpeace_exclude_patterns` | `string[]`（`ScanTarget $target`） | 除外パターン |
| `keepitpeace_retention_max_days` | `int`（既定 365。`0` = 無期限） | 保持期間の上限 |
| `keepitpeace_should_notify` | `bool`（`ScanReport $report, string $channel_id`） | 通知するかどうか |
| `keepitpeace_email_recipients` | `string[]` | メールの宛先 |
| `keepitpeace_email_subject` | `string`（`ScanReport $report`） | 件名 |
| `keepitpeace_email_body_text` / `keepitpeace_email_body_html` | `string`（`ScanReport $report`） | 本文 |
| `keepitpeace_settings_tabs` | `array<string, string>` | 設定タブの追加 |
| `keepitpeace_default_settings` | `array` | 設定の既定値 |
| `keepitpeace_sanitize_settings` | `array $values`（`array $input, string $tab`） | 設定のサニタイズ |
| `keepitpeace_allowed_hosts` | `string[]` | 外部チェックで許可するホスト（完全一致または `*.example.com`） |
| `keepitpeace_public_suffixes` | `string[]` | 登録可能ドメインの判定に使う複数ラベルのサフィックス（共用ドメインの追加） |
| `keepitpeace_external_urls` | `array[]`（`Settings $settings`） | 外部チェックで確認する URL（許可されていないホストは除外されます） |
| `keepitpeace_sensitive_paths` | `array[]` | 公開漏れチェックのパス定義（`data/sensitive-paths.json` と同じ形式） |
| `keepitpeace_listing_urls` | `string[]` | ディレクトリ一覧表示を確認する URL |
| `keepitpeace_probe_timeout` | `int`（既定 5） | 外部チェックのタイムアウト（秒） |
| `keepitpeace_osv_details_limit` | `int`（既定 40） | 1 回の実行で取得する脆弱性の詳細の上限 |
| `keepitpeace_export_data` | `array`（`int $scan_id`） | エクスポートの拡張 |
| `keepitpeace_capability` | `string`（既定 `manage_options`） | 画面・操作に必要な権限 |
| `keepitpeace_show_pro_notice` | `bool` | Pro の案内を表示するか |

---

## 8. 本体の開発

```
composer install
vendor/bin/phpunit          # ユニットテスト（WordPress を起動しない）
vendor/bin/phpcs            # WPCS + PHPCompatibilityWP（testVersion 7.4-）
vendor/bin/phpcs --runtime-set text_domain kip-sample-addon dev   # サンプルアドオン
bin/build-zip.sh            # 配布用 ZIP（.distignore に従う）
bin/make-pot.sh             # 翻訳テンプレートと日本語訳のコンパイル（WP-CLI が必要）
```

サンプルアドオンをテスト環境で使うには、`dev/sample-addon` を `wp-content/plugins/` にコピー（またはシンボリックリンク）して有効化します。
