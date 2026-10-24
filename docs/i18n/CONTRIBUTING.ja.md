# Kintaiへのコントリビュート

🌐 [English](../../CONTRIBUTING.md) · [Français](CONTRIBUTING.fr.md) · **日本語**

ご興味を持っていただきありがとうございます！

## はじめる前に

Kintaiは **GNU Affero General Public License v3.0**（AGPL-3.0）の下で公開されています。
コントリビュートすることで、あなたの貢献も同じライセンス条件で公開されることに同意したものとします。

## 貢献の方法

### バグ報告

**Bug Report** テンプレートを使ってissueを作成してください。以下を含めてください：
- PHPのバージョンとOS
- 再現手順
- 期待される動作と実際の動作
- `storage/logs/` の関連ログ

### 機能提案

**Feature Request** テンプレートを使ってissueを作成してください。ユースケースと、それがプロジェクトの方向性に合致する理由を説明してください — プロダクトの方向性については [プロダクトビジョン (wiki)](https://github.com/AudricSan/Kintai/wiki/ja/%E3%83%97%E3%83%AD%E3%83%80%E3%82%AF%E3%83%88%E3%83%93%E3%82%B8%E3%83%A7%E3%83%B3) を参照してください。

### プルリクエストの提出

1. リポジトリをフォークする
2. ブランチを作成する：`git checkout -b feat/my-feature` または `fix/my-bug`
3. 下記の規約に従って変更を行う
4. テストを実行する：`./vendor/bin/phpunit`
5. `develop` ブランチ（日常作業のデフォルトの統合ブランチ — ここへのマージではリリースは公開**されません**）に対してプルリクエストを開く。`main`・`alpha`・`beta` は保護されたリリースチャンネル用ブランチで、いずれかへのマージはGitHub Releaseの自動タグ付け・公開をトリガーするため、`develop` を順に昇格させるときにのみ意図的に更新されます（[releasing.ja.md](releasing.ja.md) を参照）

## コーディング規約

- PHP 8.3以上、すべてのファイルでstrict types（`declare(strict_types=1)`）を使用
- ネームスペースのルート：`kintai\` → `src/`
- コントローラー：`final class`、コンストラクタインジェクション、シグネチャは `method(Request $request): Response` — ルートパラメータはメソッド引数ではなく `$request->param('name')` で取得する
- 永続化は `src/Core/Repositories/*Interface.php` のリポジトリインターフェース経由で行い、`RepositoryServiceProvider` にバインドする — コントローラーやサービスがEloquentモデルを直接扱うことはない
- HTTPレベルのエラーは `src/Core/Exceptions/` の例外階層を使用する
- 新しいテーブル：`database/migrations/php/` に単一のEloquentマイグレーションを追加する — SQLiteとMySQLの両方で動作すること（ドライバー別ファイルは作らない）
- SQLiteでは `ON DELETE CASCADE` が適用されないため、依存する行の削除はリポジトリのコードで明示的に行う
- コード内のコメントはフランス語で記述する。それ以外（コミットメッセージ、PRの説明、ドキュメント）はすべて英語
- `illuminate/database`（ORMとしてのみ使用するEloquent）以外の外部フレームワーク依存を追加しない
- ビュー内でインラインの `style="..."` は使用禁止 — `public/assets/css/src/` 配下のCSSモジュールを拡張すること
- ビューやコンポーネントにインラインのイベントハンドラー（`onclick=`、`onchange=`、`onsubmit=`、`oninput=` など）、`javascript:` リンク、nonce のない `<script>` を置かないこと — Content-Security-Policy（`script-src 'self' 'nonce-…'`）が黙ってブロックします。`data-on-click`/`data-on-change`/`data-args`、`data-submit-on-change`、`data-confirm`、`data-stop-propagation`（`public/assets/js/modules/csp-actions.js`）と `<script nonce="<?= csp_nonce() ?>">` を使ってください。違反すると `NoInlineScriptGuardTest` が失敗します。バンドル作者は the wiki page [Creating a Bundle](https://github.com/AudricSan/Kintai/wiki/Creating-a-Bundle)（「Content Security Policy」）を参照
- フィルターバーは即座に適用される仕組みにすること — 「フィルター」ボタンは不要。テキスト入力は `input` イベントでデバウンスしてフォームを送信し（`public/assets/js/app.js` の `form.filter-bar`/`form.shifts-filters` を対象とした汎用処理を参照）、`select`/日付フィールドは `onchange` で送信する。これは名前・テキスト検索フィールドを含むすべてのフィルターに適用される

## テストの実行

```bash
composer install
./vendor/bin/phpunit
```

CI では新規インストールのスモークテスト（`install-smoke` ジョブ、`scripts/ci/install-smoke-test.sh`）も実行します。空の SQLite データベースで実際の Web インストーラーを動かし、作成したアカウントでログインします。チェックアウト先に書き込むため、CI 外（`CI=true` なし）や既にインストール済みの場所では実行を拒否します。ローカルでは使い捨てのクローンでのみ実行してください。

2 つ目のジョブ `provision-smoke`（`scripts/ci/provision-smoke-test.sh`）は、コマンドラインのインストーラー `scripts/provision.php` について同じことを行い、拒否すべきケース（不正な入力、インストール済み、既存アカウント）も確認します。安全策は同じです。

新規または変更された機能には、`tests/Unit/`（該当する場合は `tests/Integration/`）にPHPUnitテストを追加してください。

## ドキュメントの言語

README、CHANGELOG、CONTRIBUTING、SECURITY は、英語・フランス語（`.fr.md`）・日本語（`.ja.md`）で提供されています（翻訳は `docs/i18n/` にあります）。詳細なドキュメントである[wiki](https://github.com/AudricSan/Kintai/wiki)も同じ3言語で提供されています。英語版が正となるバージョンです — 英語のドキュメントを変更した場合、翻訳の更新は歓迎されますが、PRのマージに必須ではありません。`php scripts/check-translations.php` が、翻訳の欠落や更新漏れを報告します（非ブロッキングで、push のたびにCIで実行されます）。

## まず見るべき場所

- [アーキテクチャ (wiki)](https://github.com/AudricSan/Kintai/wiki/ja/%E3%82%A2%E3%83%BC%E3%82%AD%E3%83%86%E3%82%AF%E3%83%81%E3%83%A3) — フレームワークの構成
- [データベース戦略 (wiki)](https://github.com/AudricSan/Kintai/wiki/ja/%E3%83%87%E3%83%BC%E3%82%BF%E3%83%99%E3%83%BC%E3%82%B9%E6%88%A6%E7%95%A5) — モデル、リポジトリ、マイグレーション
- [CHANGELOG.ja.md](CHANGELOG.ja.md) — 最近の変更内容
