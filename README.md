# Keep It Peace

WordPress サイトのセキュリティ状態を定期的にチェックし、結果をメールで通知するプラグインです。
WordPress 本体や wp.org 配布プラグインではなく、**カスタムテーマ・WP 外のプログラム・外部から見たサイトの状態・PHP 本体**を対象にします。

> 開発中（フェーズ 1 / MVP）。設計は [docs/DESIGN.md](docs/DESIGN.md) を参照してください。
> 配布用の `readme.txt`、日本語訳、開発者向けドキュメントはフェーズ 3 で整備します。

## 動作環境

- WordPress 6.0 以上
- PHP 7.4 以上（シェル実行関数は使いません。`open_basedir` 下でも動作します）

## フェーズ 1 で実装済みの機能

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

- 定期実行（WP-Cron、毎日／毎週）。1 回の実行時間に上限を設け、続きは次の Cron で再開
- 管理画面：ダッシュボード、検出結果（絞り込み・一括操作・詳細のコード表示・履歴）、スキャン履歴、タブ式の設定
- メール通知（テキスト／HTML、送信条件、週次ダイジェスト、テスト送信）。ファイルパスやコードはメールに含めません
- WP-CLI：`wp keep-it-peace scan` / `wp keep-it-peace status`

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

サイトの URL・ファイルの内容・個人情報は送信しません。設定画面の「外部サービス」タブで個別に OFF にできます。

## 開発

```
composer install
vendor/bin/phpunit
vendor/bin/phpcs
```

## ライセンス

GPLv2 or later
