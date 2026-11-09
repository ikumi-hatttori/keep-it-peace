# Keep It Peace — 設計案

WordPress サイトのセキュリティ状態（カスタムテーマ / WP外プログラム / 外部から見た状態 / PHP・DB）を定期チェックし、サマリーをメール通知するプラグイン。本体は wp.org で無料配布し、Pro アドオンが本体を改修せずに機能を追加できる構造にする。

---

## 0. 命名・基本方針

| 項目 | 値 |
|---|---|
| プラグイン名 | Keep It Peace |
| スラッグ / テキストドメイン | `keep-it-peace` |
| メインファイル | `keep-it-peace.php` |
| PHP 名前空間 | `KeepItPeace\` |
| フック・オプション・テーブル接頭辞 | `keepitpeace_`（wp.org レビューで短い接頭辞は指摘されるため 3 文字の `kip_` は使わない） |
| 定数接頭辞 | `KEEPITPEACE_` |
| REST 名前空間 | `keepitpeace/v1` |
| WP-CLI | `wp keep-it-peace scan` ほか |
| 動作要件 | WordPress 6.0+ / PHP 7.4+（union 型・`match`・名前付き引数・`str_contains` 等は使わない） |
| 依存ライブラリ | 実行時ゼロ。Composer は開発時のみ（PHPUnit, PHPCS/WPCS, PHPCompatibilityWP）。オートローダーは自前の PSR-4 |
| シェル実行 | 一切使わない（`exec` / `shell_exec` / `proc_open` / `popen` / バッククォート） |
| ファイル操作 | 読み取りのみ。書き込み・削除はしない（DB とオプションのみ書き込む） |

### open_basedir 対策
ファイルに触る前に `PathGuard::is_accessible( $path )` で `ini_get( 'open_basedir' )` の許可リストを自前で判定し、範囲外なら PHP の警告を出さずに「読めない」という Info の検出結果を出す。`is_readable` の失敗、`scandir` の `false` も同様に扱う（`@` 演算子は使わない）。

---

## 1. ディレクトリ構成

リポジトリのルートをそのままプラグインのルートにする。配布 ZIP は `.distignore` に従って `bin/build-zip.sh` で作る（`tests/`、`dev/`、`docs/`、`composer.*`、`phpcs.xml.dist` などを除外）。

```
keep-it-peace/
├── keep-it-peace.php            # ヘッダー、定数、オートローダー、Plugin::boot()
├── uninstall.php                # テーブル・オプション・Cron・transient を削除
├── readme.txt                   # wp.org 形式（外部サービスの送信データを明記）
├── README.md                    # 日本語 README（サーバー cron の設定方法を含む）
├── composer.json                # dev 依存のみ
├── phpcs.xml.dist / phpunit.xml.dist / .distignore
├── src/
│   ├── Plugin.php               # 起動、サービスの組み立て、keepitpeace_loaded の発火
│   ├── Api.php                  # Pro 向けの公開 PHP API（ファサード）
│   ├── Autoloader.php
│   ├── Install/                 # Installer（dbDelta）, Migrator（DB バージョン管理）, Uninstaller
│   ├── Settings/                # Settings（取得・保存・既定値）, SettingsSanitizer
│   ├── Contracts/               # 拡張用インターフェース（後述）
│   ├── Scan/                    # Scanner, ScanContext, ScanState, CheckResult, ScanLock,
│   │                            # Scheduler, FileWalker, ScanTarget, TargetRegistry, PathGuard
│   ├── Checks/
│   │   ├── Php/                 # PhpVersionCheck, PhpIniCheck, DatabaseVersionCheck
│   │   ├── Files/               # IntegrityCheck, CodePatternCheck, SuspiciousFileCheck, PermissionCheck
│   │   ├── Environment/         # WpConfigCheck, UploadsPhpCheck
│   │   ├── Dependencies/        # DependencyCheck（composer.lock / package-lock.json → OSV）
│   │   └── External/            # TlsCheck, SecurityHeadersCheck, ExposedFilesCheck, DirectoryListingCheck
│   ├── Rules/                   # Rule, RuleSet, RuleRepository, BundledRuleProvider, RuleEngine,
│   │                            # Detectors/（トークン解析の検出器）
│   ├── Findings/                # Finding, Severity, FindingStatus, FindingRepository,
│   │                            # FindingEventRepository, ScanDiff
│   ├── Baseline/                # FileIndexRepository（ベースラインと現在値）
│   ├── Remote/                  # HttpClient（ON/OFF・キャッシュ・タイムアウト）, EndOfLifeClient,
│   │                            # PhpReleasesClient, NvdClient, OsvClient, VersionComparator
│   ├── Notifications/           # ChannelRegistry, EmailChannel, NotificationPolicy, Report, DigestSender
│   ├── Export/                  # ReportExporter（バージョン付き JSON）
│   ├── Admin/                   # Menu, DashboardPage, FindingsPage, FindingsListTable, FindingDetailPage,
│   │                            # ScansPage, SettingsPage（タブ）, DashboardWidget, Notices, Assets
│   ├── Rest/                    # ScanController, FindingsController
│   └── Cli/                     # Command（WP-CLI）
├── rules/core.json              # 同梱ルールセット（バージョン付き）
├── data/
│   ├── php-versions.json        # 外部 API が OFF のときに使う EOL 情報のスナップショット
│   └── sensitive-paths.json     # 公開漏れを調べるパスの既定リスト
├── templates/                   # メール（text / html）、管理画面のビュー
├── assets/                      # admin.js（手動スキャンの進捗）, admin.css
├── languages/                   # keep-it-peace.pot, -ja.po/.mo, JS 用 JSON
├── tests/
│   ├── bootstrap.php            # WP 関数の最小スタブ
│   ├── Unit/                    # ルール, バージョン判定, 差分, エクスポート
│   └── fixtures/                # 脆弱なコードのサンプル（vulnerable-theme/ など）
├── dev/sample-addon/            # 拡張ポイントの動作確認用アドオン（配布物に含めない）
├── docs/
│   ├── DESIGN.md                # 本書
│   ├── developer.md             # 拡張ポイントのドキュメント
│   └── export-schema.json       # エクスポートの JSON Schema
└── bin/build-zip.sh
```

テストは WordPress を起動しない純粋なユニットテストを中心にする。ルール・バージョン判定・差分・エクスポートのロジックは WP 関数に依存しないクラスに分け、DB アクセスはリポジトリクラスに閉じ込める。

---

## 2. DB スキーマ

`dbDelta` で作成。DB スキーマのバージョンは `keepitpeace_db_version` で管理し、プラグイン更新時にマイグレーションする。

### 2.1 `{prefix}keepitpeace_scans` — スキャン履歴

| カラム | 型 | 説明 |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| status | VARCHAR(20) | `running` / `completed` / `failed` / `aborted` |
| trigger_type | VARCHAR(20) | `cron` / `manual` / `cli` |
| started_at / finished_at | DATETIME (UTC) | |
| state | LONGTEXT | 再開用カーソル（JSON：現在のチェック、ディレクトリのスタック等） |
| progress | TINYINT | 0〜100 |
| summary | LONGTEXT | 重要度別件数・新規/解決/継続件数・PHP/SSL の状態（JSON） |
| ruleset_version | VARCHAR(32) | |
| plugin_version | VARCHAR(20) | |
| user_id | BIGINT | 手動実行した人 |

INDEX: `status`, `started_at`

### 2.2 `{prefix}keepitpeace_findings` — 検出結果

同じ問題は 1 行にまとめ、スキャンのたびに `last_seen_at` を更新する。

| カラム | 型 | 説明 |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| fingerprint | CHAR(64) UNIQUE | sha256(check_id + rule_id + 対象 + 正規化した該当行)。行番号のずれで別物扱いにならないよう、行番号は含めない |
| check_id | VARCHAR(64) | 例 `files.code_pattern` |
| rule_id | VARCHAR(64) | 例 `php.eval` |
| category | VARCHAR(32) | `php` / `theme` / `external_path` / `external` / `dependency` / `environment` |
| severity | TINYINT | 5=Critical 4=High 3=Medium 2=Low 1=Info |
| target_type | VARCHAR(10) | `file` / `url` / `env` / `package` |
| target_scope | VARCHAR(191) | `theme:twentytwentyfour` / `path:<id>` / `site` |
| target | TEXT | 相対パス、URL、設定名など |
| line | INT NULL | |
| title | VARCHAR(255) | |
| message | TEXT | |
| recommendation | TEXT | |
| meta | LONGTEXT | 追加情報（JSON：CVE ID、パッケージ名、ヘッダー値など） |
| file_hash | CHAR(64) NULL | 検出時のファイルのハッシュ（無視の自動解除に使う） |
| status | VARCHAR(20) | `new` / `acknowledged` / `ignored` / `resolved` |
| status_note | TEXT | 無視の理由など |
| first_seen_at / last_seen_at | DATETIME | |
| first_scan_id / last_scan_id | BIGINT | |
| resolved_at | DATETIME NULL | |

INDEX: `(status, severity)`, `category`, `last_scan_id`

### 2.3 `{prefix}keepitpeace_finding_events` — 検出の履歴

詳細画面の「検出の履歴」と、スキャンごとの差分（新規・解決）の算出に使う。

| カラム | 型 | 説明 |
|---|---|---|
| id | BIGINT PK AI | |
| finding_id | BIGINT | |
| scan_id | BIGINT NULL | |
| event | VARCHAR(20) | `detected` / `resolved` / `reopened` / `status_changed` / `ignore_reset` |
| from_status / to_status | VARCHAR(20) | |
| user_id | BIGINT NULL | |
| note | TEXT | |
| created_at | DATETIME | |

INDEX: `finding_id`, `(scan_id, event)`

**差分の定義**（スキャン S について）
- 新規：S で `detected` イベントがある
- 再発：S で `reopened` イベントがある（新規に含めて数える）
- 解決：S で `resolved` イベントがある
- 継続：`last_scan_id = S` かつ `first_scan_id < S`

### 2.4 `{prefix}keepitpeace_files` — ファイルのベースラインと現在値

1 ファイル 1 行で「ベースライン」と「最新スキャンの値」を並べて持つ。承認は `baseline_* = current_*` の UPDATE 1 回で済む。

| カラム | 型 | 説明 |
|---|---|---|
| id | BIGINT PK AI | |
| scope | VARCHAR(191) | `theme:<slug>` / `path:<id>` |
| rel_path | TEXT | スコープのルートからの相対パス |
| path_hash | CHAR(64) | sha256(scope + rel_path)。scope と合わせて UNIQUE（長いパスでもインデックスに収まるように） |
| baseline_hash / baseline_size / baseline_mtime / baseline_perms | | NULL = ベースライン未登録 |
| current_hash / current_size / current_mtime / current_perms | | |
| last_seen_scan_id | BIGINT | 今回のスキャンで見つからなければ「削除」 |
| approved_at / approved_by | | |

検出ロジック：ベースラインなし＆初回 → ベースラインとして自動登録（初回スキャンだけ）。以降は、ベースラインなし＝追加、ハッシュ差異＝変更、`last_seen_scan_id` が古い＝削除。

### 2.5 オプション（`wp_options`）

| キー | 内容 |
|---|---|
| `keepitpeace_settings` | 設定（1 つの配列。autoload=no） |
| `keepitpeace_site_uuid` | サイト固有の UUID（`wp_generate_uuid4()`、有効化時に生成） |
| `keepitpeace_db_version` | |
| `keepitpeace_last_completed_scan` | 最終完了スキャンの ID と日時（Cron の健全性チェック用） |
| transient `keepitpeace_cache_*` | 外部 API の結果キャッシュ（NVD/OSV は 24 時間、EOL は 7 日） |
| transient `keepitpeace_scan_lock` | 同時実行防止ロック |
| user meta `keepitpeace_hide_pro_notice` | Pro 案内の非表示 |

---

## 3. クラス設計

### 3.1 スキャンの流れ

```
Scheduler (WP-Cron) ─┐
REST /scan/step ─────┼─→ Scanner::run_slice( $time_budget )
WP-CLI ──────────────┘        │
                              ├─ ScanLock を取得（取れなければ終了）
                              ├─ ScanState（scans.state）を読み込む
                              ├─ CheckRegistry から有効なチェックを順に実行
                              │    ├─ CheckInterface      : run( $context, $cursor ) → CheckResult
                              │    └─ FileCheckInterface  : FileWalker が対象ごとに 1 回だけ走査し、
                              │                             各ファイルを全ファイルチェックに渡す
                              ├─ 予算時間を超えたらカーソルを保存して終了
                              │    └─ Cron の場合は 60 秒後に続きの単発イベントを登録
                              └─ 全チェック完了 → FindingRepository::finalize( $scan )
                                   ├─ 今回見つからなかった検出 → resolved
                                   ├─ ScanDiff を算出 → scans.summary
                                   ├─ do_action( 'keepitpeace_scan_completed', $report )
                                   └─ NotificationPolicy → ChannelRegistry の各チャネルに送信
```

- **時間の予算**：Cron/CLI は既定 20 秒、手動（AJAX）は 1 リクエスト 8 秒。`max_execution_time` が短い場合はその 60% を上限にする。フィルター `keepitpeace_time_budget` で変更可能
- **ファイル走査の再開**：`FileWalker` は再帰イテレーターではなく「未処理ディレクトリのスタック＋現在ディレクトリ内の位置」をカーソルとして持つ反復処理。`scandir` の結果はソートするため、途中から再開しても順序が変わらない
- **上限**：ファイルサイズの上限（既定 2MB、超えたらハッシュのみ計算しコードスキャンはスキップ）、対象ごとのファイル数の上限（既定 20,000。超えたら Info を出して打ち切る）、除外パターン（glob 形式。既定 `node_modules`, `vendor`, `.git`, `cache`）
- **ロック**：transient に有効期限付きで保存。期限切れのロックは乗っ取り可能（プロセスが落ちても詰まらない）
- **スキャンの放置**：`running` のまま 24 時間更新がなければ `aborted` にする

### 3.2 主要インターフェース（`src/Contracts/`）

```php
interface CheckInterface {
    public function get_id(): string;              // 'php.version'
    public function get_name(): string;            // 翻訳済みの表示名
    public function get_category(): string;
    public function get_description(): string;
    public function is_enabled( Settings $settings ): bool;
    /**
     * @param array $cursor 前回の続き（初回は空配列）
     */
    public function run( ScanContext $context, array $cursor ): CheckResult;
}

// CheckResult: findings[], is_complete, cursor（次回の続き）, progress(0-1), data（ダッシュボード用の値。例：PHP の状態）

interface FileCheckInterface extends CheckInterface {
    public function supports_target( ScanTarget $target ): bool;    // 対象ごとの ON/OFF
    public function begin_target( ScanTarget $target, ScanContext $context ): void;
    /** @return Finding[] */
    public function analyze_file( FileInfo $file, ScanTarget $target, ScanContext $context ): array;
    /** @return Finding[] 例：削除されたファイルの検出 */
    public function end_target( ScanTarget $target, ScanContext $context ): array;
}

interface NotificationChannelInterface {
    public function get_id(): string;
    public function get_name(): string;
    public function is_enabled( Settings $settings ): bool;
    /** @return true|\WP_Error */
    public function send( ScanReport $report );
    /** @return true|\WP_Error */
    public function send_test();
    public function render_settings( Settings $settings ): void;   // 通知タブの中のセクション
}

interface RuleProviderInterface {
    public function get_id(): string;            // 'bundled'
    /** @return RuleSet  version, schema_version, rules[] */
    public function get_rule_set(): RuleSet;
}

interface ScanTargetProviderInterface {
    /** @return ScanTarget[] 例：選択されたテーマ、登録された WP 外のパス */
    public function get_targets( Settings $settings ): array;
}
```

`AbstractCheck` / `AbstractFileCheck` に共通処理（Finding の生成ヘルパーなど）を置き、本体のチェックはすべてこれを継承する。

### 3.3 本体のチェック一覧

| ID | 種類 | 内容 | フェーズ |
|---|---|---|---|
| `php.version` | Check | EOL・セキュリティサポート終了日（endoflife.date）、同系列の最新パッチとの差（php.net）、既知 CVE（NVD 2.0、`cpe:2.3:a:php:php:<ver>`）。外部 API が OFF なら同梱スナップショットで EOL のみ判定 | 1 |
| `php.ini` | Check | `display_errors`, `expose_php`, `allow_url_include`, `allow_url_fopen`, `log_errors`, `session.cookie_httponly` など | 1 |
| `server.database` | Check | MySQL/MariaDB の判別とバージョン、EOL（endoflife.date） | 1 |
| `env.wp_config` | Check | `WP_DEBUG` / `WP_DEBUG_DISPLAY`、`debug.log` の有無、`wp-config.php` のパーミッション | 1 |
| `files.integrity` | FileCheck | sha256・サイズ・更新日時でベースラインと比較（追加・変更・削除） | 1 |
| `files.code_pattern` | FileCheck | ルールエンジンによる静的スキャン | 1 |
| `files.suspicious` | FileCheck | 二重拡張子、隠しファイル、画像ディレクトリ内の `.php`、テーマ内の想定外の PHP | 1 |
| `files.permissions` | FileCheck | 777/666 のファイル・ディレクトリ | 1 |
| `env.uploads_php` | Check（走査） | `wp-content/uploads` 配下の `.php` / `.phtml` / `.phar` 等 | 1 |
| `deps.osv` | FileCheck | `composer.lock` / `package-lock.json` を集め、`end_target` で OSV `/v1/querybatch`（Packagist / npm）に照会 | 2 |
| `external.tls` | Check | 証明書の残り日数、ネゴシエートされた TLS バージョン（`stream_socket_client` + `capture_peer_cert`。関数が無効な環境では Info を出してスキップ） | 2 |
| `external.headers` | Check | HSTS、X-Content-Type-Options、X-Frame-Options / CSP frame-ancestors、Referrer-Policy、CSP、`Server` / `X-Powered-By` の露出 | 2 |
| `external.exposed_files` | Check | `.env`、`.git/HEAD`、`wp-config.php.bak` 等の公開漏れ。応答の中身も確認して誤検知を減らす（例：`.git/HEAD` は `ref:` で始まるか） | 2 |
| `external.dir_listing` | Check | `/wp-content/uploads/` 等で `Index of /` が返るか | 2 |

**対象（ScanTarget）**
- テーマ：既定は有効なテーマ＋親テーマ。設定で任意のインストール済みテーマを選べる
- WP 外のパス（フェーズ 2）：絶対パス、ラベル、対応 URL（任意）、チェックごとの ON/OFF、追加の除外パターン。登録時に「存在するか・読めるか・open_basedir の範囲内か・WP 本体のディレクトリと重複しないか」を検証する

### 3.4 ルールエンジン

`rules/core.json`:

```json
{
  "schema_version": 1,
  "ruleset_version": "2026.11.0",
  "rules": [
    {
      "id": "php.eval",
      "type": "regex",
      "severity": "high",
      "category": "dangerous_function",
      "pattern": "\\beval\\s*\\(",
      "file_types": ["php"],
      "title": "eval() の使用",
      "recommendation": "..."
    },
    {
      "id": "php.superglobal_unsanitized",
      "type": "detector",
      "detector": "superglobal_unsanitized",
      "severity": "medium"
    }
  ]
}
```

- `type: regex` — 行単位（必要に応じて複数行）の正規表現。コメント・文字列の除外はトークン化した結果に対して行う
- `type: detector` — トークン解析（`token_get_all`）による検出器。正規表現では難しいものを扱う
  - `variable_function`：`$func(...)` の呼び出し
  - `superglobal_unsanitized`：`$_GET` 等が `sanitize_*` / `esc_*` / `absint` / `intval` / `(int)` / `wp_verify_nonce` などを通らずに `echo` / `print` / SQL / `include` / ファイル関数で使われている箇所
  - `wpdb_unprepared`：`$wpdb->query|get_results|get_row|get_var|get_col(` の引数に変数・連結があり `prepare` を通っていない
  - `ajax_missing_guard`：`wp_ajax_*` / `admin_post_*` / `register_rest_route` のコールバック本体を追い、nonce（`check_ajax_referer` / `wp_verify_nonce` / `check_admin_referer`）と `current_user_can` の有無を判定。REST は `permission_callback` 欠如・`__return_true` を指摘
  - `decode_chain`：`base64_decode` / `gzinflate` / `str_rot13` / `gzuncompress` の入れ子
  - `obfuscated_string`：長い base64/hex 文字列、高エントロピーの文字列
  - `remote_include`：`include` / `file_get_contents` 等に `http(s)://` の URL
- ルールのメッセージ（title/recommendation）は翻訳対象にするため、同梱ルールは JSON にキーだけ置き、文言は PHP 側の `RuleMessages` で `__()` を通す。リモート配信ルール（Pro）は JSON 内の文言をそのまま使う
- `RuleRepository` は `keepitpeace_rule_providers` で集めたプロバイダーのルールを ID で統合する（後から登録された方が優先 → Pro が差し替え可能）。`keepitpeace_rules` で最終調整も可能
- 検出器は `keepitpeace_rule_detectors` フィルターで追加可能

### 3.5 検出結果の管理

- `FindingRepository::upsert( Finding $f, Scan $scan )` — fingerprint で検索し、新規なら INSERT＋`detected` イベント＋`keepitpeace_finding_created`。既存なら `last_seen_*` を更新。`resolved` だったら `reopened`
- **無視の自動解除**：無視にしたときの `file_hash` と現在のハッシュが違えば `new` に戻して `ignore_reset` イベントを記録
- **状態の変更**：一覧の一括操作・詳細画面から。`keepitpeace_finding_status_changed` を発火
- **保持期間**：既定 30 日。日次の Cron で、期間を過ぎたスキャン・イベント・解決済みの検出を削除。設定できる上限は `keepitpeace_retention_max_days`（既定 365）

### 3.6 外部通信

- すべて `Remote\HttpClient` を通す。外部 API の利用は設定で全体 OFF ／ API ごとの ON/OFF が可能
- 送信するのは PHP のバージョン、DB のバージョン、パッケージ名とバージョンのみ。サイトの URL や個人情報は送らない（User-Agent も `KeepItPeace/<ver>` のみで、WP 既定のサイト URL 入り UA を上書きする）
- NVD：キーなしの上限（30 秒に 5 リクエスト）を守るため、1 スキャンで最大 1〜2 リクエスト、結果は 24 時間キャッシュ。失敗時は前回のキャッシュを使い、Info を出す
- 自サイトへのチェック（外部チェック）は `wp_safe_remote_get` / `wp_safe_remote_head`、タイムアウト 5 秒、リダイレクトは追わない

**ドメイン制限（外部チェックの対象 URL）**
- 自動で対象になるのは `home_url()` と `site_url()`
- 管理画面で登録できる URL は「サイトと同じホスト、またはそのサブドメイン／親ドメイン（登録可能ドメインが同じもの）」に限る。それ以外はフィルター `keepitpeace_allowed_hosts` で明示的に許可した場合のみ
- WP 外のパスに対応する URL も同じ制限を受ける

### 3.7 通知

- `NotificationPolicy::should_send( ScanReport $report, Settings $s ): bool` — 条件：`always` / `issues_only` / `new_only` / `min_severity`（選択した重要度以上がある場合）。最後に `keepitpeace_should_notify` フィルター
- `DigestSender`：週次ダイジェスト（ON/OFF）。未解決の検出の件数サマリーを週 1 回送る
- `EmailChannel`：宛先は複数（既定は管理者メール）。テキスト＋HTML（`multipart/alternative`）。件名に状態を入れる：
  - `[要対応] サイト名 セキュリティチェック結果`（Critical/High あり）
  - `[注意] …`（Medium/Low あり）
  - `[問題なし] …`
- 本文はサマリーのみ：サイト名、実行日時、重要度別件数、新規・解決の件数、PHP の状態（バージョン／EOL）、SSL の残り日数、管理画面へのリンク。**ファイルパス・コード片・URL のパスは含めない**
- テスト送信ボタン（各チャネルの `send_test()`）

### 3.8 管理画面

トップレベルメニュー「Keep It Peace」:

| サブメニュー | 内容 |
|---|---|
| ダッシュボード | 最終実行日時、重要度別件数、PHP の状態、SSL の残り日数、次回実行予定、Cron の健全性、「今すぐスキャン」（REST＋進捗バー） |
| 検出結果 | `WP_List_Table`。重要度・カテゴリ・状態で絞り込み、ソート、一括で状態変更。詳細画面：該当行の前後 5 行（エスケープ表示）、推奨対処、履歴、無視（理由のメモ）、追加パネル用フック |
| スキャン履歴 | 一覧（新規・解決・継続の件数、所要時間、トリガー） |
| 設定（タブ） | 一般（頻度、時間の上限、保持期間）／対象（テーマ、WP 外のパス、URL）／チェック（各チェックの ON/OFF、除外パターン、上限値）／通知／外部 API／ツール（ベースラインの承認、WP-CLI・サーバー cron の案内） |

- WP ダッシュボードウィジェット：重要度別件数と最終実行日時
- **Cron の警告**：最後の完了スキャンから「頻度 × 2 ＋ 1 日」以上経ったら、本プラグインの画面とダッシュボードウィジェットに「Cron が動いていない可能性」を表示（`manage_options` を持つユーザーのみ）
- **Pro の案内**：設定画面のサイドバーに 1 か所だけ控えめな枠を置き、「非表示にする」でユーザーごとに消せる。ほかの画面には出さない

### 3.9 プラグイン自体のセキュリティ

- すべての画面・操作で `current_user_can( 'manage_options' )`（フィルター `keepitpeace_capability` で変更可能）＋ nonce
- REST はすべて `permission_callback` 付き。`X-WP-Nonce` を使用
- 出力はすべて `esc_html` / `esc_attr` / `esc_url` / `wp_kses`。コード片は `esc_html` してから `<pre>` で表示
- DB は `$wpdb->prepare` のみ使用
- 設定の保存時、WP 外のパスは `realpath` で正規化し、ディレクトリのみ許可

### 3.10 WP-CLI

```
wp keep-it-peace scan [--checks=<ids>] [--no-notify] [--format=table|json]   # 完了まで繰り返し実行
wp keep-it-peace status
wp keep-it-peace findings [--severity=<s>] [--status=<s>] [--format=...]
wp keep-it-peace baseline approve [--scope=<scope>]
wp keep-it-peace export [--scan=<id>] > report.json
```

README と設定画面の「ツール」タブで、サーバーの cron から `wp keep-it-peace scan` を実行する方法（Xserver の例を含む）と、`DISABLE_WP_CRON` の併用を案内する。

---

## 4. 拡張ポイントの一覧（Pro アドオン向け）

### 4.1 定数

| 定数 | 用途 |
|---|---|
| `KEEPITPEACE_VERSION` | 本体のバージョン |
| `KEEPITPEACE_API_VERSION` | 拡張 API のバージョン（semver。インターフェースを壊す変更でメジャーを上げる） |
| `KEEPITPEACE_EXPORT_SCHEMA_VERSION` | エクスポート JSON のスキーマバージョン |
| `KEEPITPEACE_RULESET_SCHEMA_VERSION` | ルール JSON のスキーマバージョン |
| `KEEPITPEACE_FILE` / `KEEPITPEACE_DIR` / `KEEPITPEACE_URL` | パス |

Pro は `keepitpeace_loaded` の中で `version_compare( KEEPITPEACE_API_VERSION, '1.0', '>=' )` を確認して起動する。

### 4.2 アクション

| フック | 引数 | 用途（Pro の想定） |
|---|---|---|
| `keepitpeace_loaded` | `Plugin $plugin` | Pro の起動ポイント |
| `keepitpeace_scan_started` | `Scan $scan` | 集約サーバーへのハートビート |
| `keepitpeace_scan_completed` | `ScanScanReport $report` | 集約サーバーへの送信、ファイル大量変更の検知 |
| `keepitpeace_scan_failed` | `Scan $scan, \WP_Error $error` | |
| `keepitpeace_finding_created` | `Finding $finding, Scan $scan` | |
| `keepitpeace_finding_resolved` | `Finding $finding, Scan $scan` | |
| `keepitpeace_finding_reopened` | `Finding $finding, Scan $scan` | |
| `keepitpeace_finding_status_changed` | `Finding $finding, string $old, string $new, int $user_id, string $note` | |
| `keepitpeace_baseline_approved` | `string $scope, int $file_count, int $user_id` | |
| `keepitpeace_baseline_file_recorded` | `string $abs_path, string $scope, string $rel_path, string $sha256` | **ファイル内容の保存**（差分表示用）。本体はハッシュのみ保存 |
| `keepitpeace_notification_sent` | `string $channel_id, ScanReport $report, true\|\WP_Error $result` | |
| `keepitpeace_settings_tab_{$tab}` | `Settings $settings` | 追加タブの描画 |
| `keepitpeace_settings_section_{$tab}` | `Settings $settings` | 既存タブの末尾にセクションを追加 |
| `keepitpeace_finding_detail_panels` | `Finding $finding` | 詳細画面に追加パネル（差分表示、AI 解説） |
| `keepitpeace_dashboard_widgets` | `Settings $settings` | 本プラグインのダッシュボードにカードを追加 |
| `keepitpeace_daily_maintenance` | — | 日次メンテナンス Cron に処理を追加 |

### 4.3 フィルター

| フック | 値 | 用途 |
|---|---|---|
| `keepitpeace_checks` | `CheckInterface[]` | チェックの登録・差し替え・削除 |
| `keepitpeace_scan_target_providers` | `ScanTargetProviderInterface[]` | スキャン対象の追加 |
| `keepitpeace_notification_channels` | `NotificationChannelInterface[]` | Slack/Chatwork/Teams/LINE WORKS |
| `keepitpeace_rule_providers` | `RuleProviderInterface[]` | リモート配信ルール |
| `keepitpeace_rules` | `Rule[]` | ルールの最終調整 |
| `keepitpeace_rule_detectors` | `array<string, DetectorInterface>` | 検出器の追加 |
| `keepitpeace_schedule_options` | `array<string, array{label, interval}>` | 毎時などの頻度を追加 |
| `keepitpeace_retention_max_days` | `int`（既定 365） | 無期限保存（`0` = 無期限） |
| `keepitpeace_exclude_patterns` | `string[]`, `ScanTarget` | 除外パターン |
| `keepitpeace_should_notify` | `bool`, `ScanReport`, `string $channel_id` | 通知の条件 |
| `keepitpeace_email_recipients` | `string[]` | |
| `keepitpeace_email_subject` | `string`, `ScanReport` | |
| `keepitpeace_email_body_text` / `keepitpeace_email_body_html` | `string`, `ScanReport` | |
| `keepitpeace_settings_tabs` | `array<string, string>` | タブの追加 |
| `keepitpeace_default_settings` / `keepitpeace_sanitize_settings` | `array` | Pro の設定項目を本体の設定に同居させる場合 |
| `keepitpeace_sensitive_paths` | `string[]` | 公開漏れチェックのパス |
| `keepitpeace_allowed_hosts` | `string[]` | 外部チェックで許可するホスト |
| `keepitpeace_time_budget` | `int`, `string $trigger` | 1 回の実行時間の上限 |
| `keepitpeace_file_limits` | `array{max_size, max_files}` | |
| `keepitpeace_export_data` | `array`, `int $scan_id` | エクスポート内容の拡張（`extensions` キー配下） |
| `keepitpeace_capability` | `string` | 必要な権限 |
| `keepitpeace_show_pro_notice` | `bool` | Pro 導入済みなら案内を消す |

### 4.4 PHP API（`KeepItPeace\Api`）

```php
Api::get_site_uuid(): string
Api::export_scan( ?int $scan_id = null ): array     // null = 最新の完了スキャン
Api::export_scan_json( ?int $scan_id = null ): string
Api::get_latest_scan(): ?Scan
Api::get_findings( array $args ): Finding[]
Api::start_scan( string $trigger = 'api' ): int
Api::get_settings(): Settings
```

**エクスポートの JSON（`docs/export-schema.json` で定義）**

```json
{
  "schema": "keepitpeace.scan-report",
  "schema_version": "1.0.0",
  "generated_at": "2026-11-06T09:00:00Z",
  "site": { "uuid": "…", "name": "…", "url": "…", "wp_version": "…", "php_version": "…", "plugin_version": "…" },
  "scan": { "id": 12, "status": "completed", "trigger": "cron", "started_at": "…", "finished_at": "…", "ruleset_version": "…" },
  "summary": { "by_severity": {"critical":0,"high":1,"medium":3,"low":2,"info":5}, "new": 1, "resolved": 2, "ongoing": 8,
               "php": {"version":"8.1.29","eol":false,"security_support_until":"2025-12-31","latest_patch":"8.1.31","cve_count":3},
               "tls": {"days_remaining": 54, "protocol": "TLSv1.3"},
               "files": {"added":0,"modified":2,"deleted":0} },
  "findings": [ { "id": 1, "fingerprint": "…", "check_id": "…", "rule_id": "…", "category": "…", "severity": "high",
                  "status": "new", "target_type": "file", "target": "…", "line": 10, "title": "…", "message": "…",
                  "recommendation": "…", "first_seen_at": "…", "last_seen_at": "…", "diff": "new" } ],
  "extensions": {}
}
```

エクスポートはサイト内の PHP API なので URL やパスも含む（外部へ送るかどうかは Pro 側の責任・同意で行う）。

---

## 5. フェーズ分け

### フェーズ 1（MVP）
- 骨組み：メインファイル、オートローダー、Installer（テーブル 4 つ）、Settings、uninstall.php
- Contracts（Check / FileCheck / NotificationChannel / RuleProvider / ScanTargetProvider）と各 Registry
- Scanner：バッチ実行、ロック、カーソル、Cron（毎日／毎週＋続きの単発イベント）、Cron の健全性
- チェック：`php.version`（EOL・最新パッチ・NVD）、`php.ini`、`server.database`、`env.wp_config`、`env.uploads_php`、テーマに対する `files.integrity` / `files.code_pattern` / `files.suspicious` / `files.permissions`
- ルールエンジン＋`rules/core.json`＋トークン検出器
- 検出結果：upsert、差分、無視（自動解除）、保持期間
- 通知：EmailChannel（text/HTML）、NotificationPolicy、週次ダイジェスト、テスト送信
- 管理画面：ダッシュボード、検出結果（一覧・詳細・一括操作）、スキャン履歴、設定（タブ）、ダッシュボードウィジェット、手動スキャン（REST＋進捗）
- PHPUnit：ルール、バージョン判定、差分。フィクスチャ

### フェーズ 2
- WP 外のパス（登録・検証・パスごとの ON/OFF）を ScanTargetProvider として追加
- 外部チェック 4 種（ドメイン制限つき）、WP 外のパスに対応する URL
- `deps.osv`（テーマ内・WP 外のパスの lock ファイル）
- `Api` と JSON エクスポート、`docs/export-schema.json`、エクスポートのテスト

**フェーズ 2 の実装メモ（設計からの変更点）**
- `deps.osv` は `end_target` ではなく `analyze_file` で lock ファイルごとに照会する（スキャンが複数回のリクエストに分かれても、収集した内容を持ち越す必要がないため）。開発用パッケージは `vendor/` / `node_modules/` に実際に存在する場合のみ対象にする
- 外部 API や自サイトへの接続に失敗したとき、既存の検出を解決済みにしないよう `ScanContext::keep( $check_id, $rule_id )` を追加した
- TLS の確認は 2 段階：証明書の読み取り（検証なし・`security_level` 0）と、WP 同梱の CA バンドルでの検証
- 登録パスの ID はパスのハッシュ。パスを変えると別の対象として扱い、初回スキャンでベースラインを取り直す

### フェーズ 3
- WP-CLI
- i18n：`.pot` 生成、日本語訳（`.po`/`.mo`、JS 用 JSON）
- サンプルアドオン（独自チェック 1 つ＋ダミー通知チャネル 1 つ＋設定タブ 1 つ）
- `docs/developer.md`、`readme.txt`、日本語 `README.md`
- PHPCS（WPCS＋PHPCompatibilityWP、`testVersion 7.4-`）、Plugin Check の対応、`bin/build-zip.sh`

---

## 6. 決定事項

1. 接頭辞は `keepitpeace_` / `KeepItPeace\`、CLI は `wp keep-it-peace`
2. 保持期間：既定 30 日、設定の上限 365 日、`keepitpeace_retention_max_days` で変更可（`0` = 無期限）
3. 登録 URL：サイトと同じ登録可能ドメイン（サブドメイン含む）＋`keepitpeace_allowed_hosts` で許可したホスト
4. Cron の警告：本プラグインの画面と WP ダッシュボードのウィジェットのみ
5. ヘッダー：Author `mackarel Inc.`（https://mackarel.jp）、Plugin URI `https://keep-it-peace.com`（仮）、Pro の案内 `https://keep-it-peace.com/pro`（仮）
6. `main` に設計書を初回コミットし、フェーズごとにブランチを切る
