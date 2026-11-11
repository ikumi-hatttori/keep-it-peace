# Keep It Peace

[English](README.md) | 日本語

WordPress サイトのセキュリティ状態を定期的にチェックし、結果をメールで通知するプラグインです。
WordPress 本体や wp.org 配布プラグインではなく、**カスタムテーマ・WP 外のプログラム・外部から見たサイトの状態・PHP 本体**を対象にします。

> 設計は [docs/DESIGN.md](docs/DESIGN.md)、拡張方法は [docs/developer.ja.md](docs/developer.ja.md) を参照してください。wp.org 用の説明は [readme.txt](readme.txt) にあります。

## 動作環境

- WordPress 6.0 以上
- PHP 7.4 以上（シェル実行関数は使いません。`open_basedir` 下でも動作します）

## 実装済みの機能

| チェック | 内容 |
|---|---|
| `php.version` | PHP の EOL・同系列の最新パッチとの差・既知 CVE（NVD） |
| `php.ini` | `display_errors` / `expose_php` / `allow_url_include` / `allow_url_fopen` など |
| `server.database` | MySQL / MariaDB のバージョンと EOL |
| `env.wp_config` | `WP_DEBUG` / `WP_DEBUG_DISPLAY`、`debug.log`、`wp-config.php` の権限、ファイル編集機能 |
| `env.uploads_php` | `wp-content/uploads` 配下の `.php` などの実行ファイル |
| `files.integrity` | テーマの改ざん検知（sha256 ベースライン、追加・変更・削除） |
| `files.code_pattern` | 危険コードの静的スキャン（ルールは `rules/core.json`） |
| `files.suspicious` | 二重拡張子、隠しファイル、画像フォルダ内の PHP、画像内の PHP コード |
| `files.permissions` | 777 / 666 などのファイル・ディレクトリ |
| `deps.osv` | テーマ・WP 外のパスにある `composer.lock` / `package-lock.json` の既知の脆弱性（OSV.dev）。開発用パッケージは実際にインストールされている場合のみ |
| `external.tls` | HTTPS の有無、証明書の残り日数・信頼性・ホスト名の一致、TLS バージョン |
| `external.headers` | HSTS などのセキュリティヘッダー、HTTP→HTTPS のリダイレクト、Server / X-Powered-By / generator によるバージョン露出 |
| `external.exposed_files` | `.env`、`.git`、`wp-config.php` のバックアップ、SQL ダンプ、`debug.log`、phpinfo などの公開漏れ（中身を確認して誤検知を防止） |
| `external.dir_listing` | `uploads` などのディレクトリ一覧表示 |

**WP 外のパス**：設定 → 対象 で、LP やフォームなど WordPress 以外のプログラムのディレクトリを登録できます。存在・読み取り権限・`open_basedir`・WordPress 本体との重複を保存時に検証します。パスごとにチェックの ON/OFF、除外パターン、公開 URL（外部チェックの対象）を設定できます。

**外部チェックの対象 URL**：サイトアドレス・WordPress アドレスと、設定で追加した URL です。追加できるのはサイトと同じドメイン（サブドメインを含む）だけで、それ以外はフィルター `keepitpeace_allowed_hosts` で許可した場合に限ります。レンタルサーバーの共有ドメイン（`xsrv.jp` など）では、他のユーザーのサブドメインは対象になりません。

**JSON エクスポート**：設定 → ツール から最新スキャンの結果を JSON でダウンロードできます。アドオンからは `KeepItPeace\Api::export_scan()` で取得できます。形式は [docs/export-schema.json](docs/export-schema.json) を参照してください。

- 定期実行（WP-Cron、毎日／毎週）。1 回の実行時間に上限を設け、続きは次の Cron で再開
- 管理画面：ダッシュボード、検出結果（絞り込み・一括操作・詳細のコード表示・履歴）、スキャン履歴、タブ式の設定
- メール通知（テキスト／HTML、送信条件、週次ダイジェスト、テスト送信）。ファイルパスやコードはメールに含めません
- 日本語訳を同梱（`languages/`）

## WP-CLI

| コマンド | 内容 |
|---|---|
| `wp keep-it-peace scan [--checks=<ids>] [--no-notify] [--format=table\|json]` | スキャンを完了まで実行（実行中のスキャンがあれば続きを実行） |
| `wp keep-it-peace status [--format=json]` | 重要度別の未解決件数と最終スキャン |
| `wp keep-it-peace findings [--status=open] [--severity=high] [--category=…] [--check=…] [--format=…]` | 検出結果の一覧 |
| `wp keep-it-peace export [--scan=<id>] [--pretty]` | スキャン結果の JSON（[docs/export-schema.json](docs/export-schema.json)） |
| `wp keep-it-peace baseline approve (--scope=<scope> \| --all) [--yes]` | 現在のファイルをベースラインとして承認（デプロイ後に使用） |
| `wp keep-it-peace checks` / `wp keep-it-peace targets` | チェック・スキャン対象の一覧 |

コマンドの出力は、スクリプトで扱いやすいよう英語のままです。

## サーバーの cron から実行する

WP-Cron はサイトにアクセスがあったときにしか動きません。確実に監視するには、サーバーの cron から WP-CLI で実行してください。

```
0 3 * * * cd /path/to/wordpress && wp keep-it-peace scan --quiet
```

共用サーバーでは CLI と Web で PHP のバージョンや php.ini が異なることがあります。本プラグインは管理画面を開いたときなどに Web 側の PHP の情報を記録し、CLI から実行したスキャンでもその情報を評価します。

## 外部サービスへの送信

| サービス | 送信する内容 |
|---|---|
| endoflife.date | 製品名（php / mysql / mariadb） |
| php.net | PHP のリリース系列（例：8.2） |
| NVD | PHP のバージョン（例：8.2.10） |
| OSV.dev | パッケージ名とバージョン（例：guzzlehttp/guzzle 7.4.0） |

サイトの URL・ファイルの内容・個人情報は送信しません。設定画面の「外部サービス」タブで個別に OFF にできます。

外部チェック（TLS・ヘッダー・公開ファイル・ディレクトリ一覧）は、サーバーから自分のサイトにアクセスして確認します（第三者のサービスは使いません）。サーバーが自分自身への接続（ループバック）を禁止している場合は確認できず、前回の結果を保持したまま「確認できなかった」旨の Info を表示します。

## 開発

```
composer install
vendor/bin/phpunit       # ユニットテスト
vendor/bin/phpcs         # コーディング規約（WPCS + PHPCompatibilityWP）
bin/make-pot.sh          # 翻訳テンプレートの更新と .mo のコンパイル（WP-CLI が必要）
bin/build-zip.sh         # 配布用 ZIP を dist/ に作成
```

拡張ポイントの動作確認用に、サンプルアドオンが [dev/sample-addon/](dev/sample-addon/) にあります（配布 ZIP には含めません）。

## コントリビュート・脆弱性の報告

[CONTRIBUTING.md](CONTRIBUTING.md) と [SECURITY.md](SECURITY.md) を参照してください。脆弱性は公開の Issue に書かず、https://keep-it-peace.com/contact/ から報告してください。

ユーザー向けのドキュメント：https://keep-it-peace.com/docs/

## ライセンス

GPLv2 or later
