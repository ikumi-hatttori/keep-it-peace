# WordPress.org 公開手順（Keep It Peace）

最終更新: 2026-11-09

## 0. 現状（2026-11-09 時点）

| 項目 | 状態 |
|---|---|
| Plugin Check 2.2.0（WP 7.1.4 / PHP 8.3、experimental 含む） | エラー・警告 0 |
| 有効化 → スキャン → アンインストール | PHP notice なし、テーブル・オプションは全削除 |
| スラッグ `keep-it-peace` | 未使用（plugins API で "Plugin not found"） |
| Tested up to: 7.1 | 最新 7.1.4 と整合 |
| 配布 ZIP | `bin/build-zip.sh`。tests / dev / docs / vendor は除外済み |
| 外部サービスの開示 | readme.txt の `== External services ==` に記載済み |

レビュー対策として行った修正:
- 管理画面のバッジ出力を `wp_kses_post()` で出力時エスケープに変更（`phpcs:ignore` での回避をやめた）。

## 1. 申請前にやること（服部さん）

1. **WordPress.org アカウント**
   - WordPress.org ユーザー名は `ikumackarel`（readme.txt の Contributors に設定済み）。
   - 登録メールは会社の常時確認するアドレス。`plugins@wordpress.org` を迷惑メール除外に追加。
   - プロフィールで **2段階認証を有効化**（コミット権限に必須）。
2. **Plugin URI（https://keep-it-peace.com）**
   - 設計書では「仮」。レビューでリンク切れは指摘されるため、公開ページを用意するか、用意できなければヘッダの Plugin URI を削除／mackarel.jp の紹介ページに変更。
   - `Admin::PRO_URL`（/pro）も同様。Pro が未提供の間は設定画面のリンクを外すのが無難。
3. **バージョン**: 1.0.0 で申請（ヘッダ・定数・Stable tag・Changelog 更新済み）。
4. **変更をコミット**し、`bin/build-zip.sh` で ZIP を作り直す。

## 2. 申請

1. https://wordpress.org/plugins/developers/add/ にログインし ZIP をアップロード。
2. 表示される**スラッグ（keep-it-peace）を確認**。承認後は変更不可。変えたい場合は承認前にレビューメールへ返信して依頼。
3. 自動チェック → 人手レビュー（目安 数日〜数週間）。指摘はメールで来るので、修正版 ZIP を同じ申請画面から再アップロードし、メールに返信。

### レビューで指摘されやすい点（本プラグイン固有）
- **ファイル走査・外部通信**: 「読み取り専用」「自サイト以外へ送らない」を readme に明記済み。問われたら同じ説明で回答。
- **`phpcs:ignore` の多用**: レビューは注釈を無視して見る。残っているのはカスタムテーブルの直接クエリ、JSON ダウンロード、テキストメール等の正当なもの。理由コメントは付いている。
- **フリーミアム**: 無料版に機能ロック・試用期限・ライセンスチェックを入れるのはガイドライン違反（Guideline 5）。Pro は別プラグイン（アドオン）として配布し、無料版側はフックで拡張される形を維持。Pro の案内は設定画面内の控えめなリンク程度に留め、ダッシュボード全体に出る通知は出さない。
- **同梱翻訳（ja）**: 問題ないが、公開後は translate.wordpress.org の言語パックが優先される。

## 3. 承認後のリリース（SVN）

前提: `brew install subversion`。SVN パスワードは WordPress.org のプロフィール → Account & Security で発行（ログインパスワードとは別）。

```bash
bin/deploy-wporg.sh            # ドライラン：trunk / tags/<ver> / assets を準備し svn status を表示
bin/deploy-wporg.sh --commit   # コミット（ユーザー名と SVN パスワードを聞かれる）
```

- trunk は `.distignore` と同じ除外ルールで同期。
- `tags/<version>` を自動作成。既存タグがあれば中止（バージョンを上げる）。
- 反映まで数分〜15分程度。

### ディレクトリ用アセット（`.wordpress-org/` に置く → SVN の assets/ へ）

| ファイル | サイズ |
|---|---|
| banner-772x250.png / banner-1544x500.png | ヘッダー画像 |
| icon-128x128.png / icon-256x256.png（または icon.svg） | アイコン |
| screenshot-1.png 〜 screenshot-4.png | readme.txt の `== Screenshots ==` の順番どおり |

## 4. 以後のアップデート手順

1. 変更 → テスト（`composer test` / `composer lint`）
2. `keep-it-peace.php`（Version と `KEEPITPEACE_VERSION`）と readme.txt（Stable tag、Changelog、Upgrade Notice）を更新
3. WP の新バージョンが出たら動作確認して `Tested up to` を更新（readme だけの変更なら trunk の readme.txt 更新のみで可）
4. コミット → `bin/deploy-wporg.sh` → `--commit`

## 5. 公開後

- 日本語翻訳: translate.wordpress.org で ja のプロジェクトを作成し、PTE（翻訳編集者）権限を Polyglots チームに申請すると自分の訳を承認できる。
- サポートフォーラム（wordpress.org/support/plugin/keep-it-peace/）の通知を購読。
