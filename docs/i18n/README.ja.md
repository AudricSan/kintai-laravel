# 🕒 Kintai

🌐 [English](../../README.md) · [Français](README.fr.md) · **日本語**

**複数店舗を運営する企業向けの、オープンソースなシフト・勤怠・労務管理システム。**

[![Version](https://img.shields.io/github/v/release/AudricSan/Kintai?include_prereleases&label=version&color=purple)](https://github.com/AudricSan/Kintai/releases)
[![License: AGPL v3](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.3-8892bf.svg)](https://php.net)
[![Tests](https://github.com/AudricSan/Kintai/actions/workflows/tests.yml/badge.svg)](https://github.com/AudricSan/Kintai/actions/workflows/tests.yml)
[![Architecture](https://img.shields.io/badge/architecture-custom%20MVC-orange.svg)]()

Kintaiは、Excelの勤怠表と大企業向けERPの間を埋めるツールです。複数店舗を展開する小売・飲食業向けに、シフト管理、勤怠打刻、休暇申請、シフト交換、日報、給与概算までを一括で提供します。自社インフラ上で、自社データを保持したまま運用できます。

> **ステータス：ベータ版（1.0 未満）。** Kintaiは実用段階にあり、下記のデモ環境でも日常的に稼働していますが、安定版 `1.0.0` に至るまでAPIやデータスキーマが変更される可能性があります。詳細は [CHANGELOG.ja.md](CHANGELOG.ja.md) をご覧ください。

---

## 🌍 デモを試す

**デモ環境：** [kintai-lv1b.onrender.com](https://kintai-lv1b.onrender.com)
*（無料プランのインスタンスのため、初回アクセス時は起動に30〜60秒かかることがあります。データは再起動のたびにリセットされます。）*

| 権限 | メールアドレス | パスワード |
| :--- | :--- | :--- |
| スーパー管理者 | `admin@kintai.local` | `Admin1234!` |
| 従業員 | `alice.martin@kintai.local` | `Staff1234!` |

---

## Kintaiが選ばれる理由

- **データは自分たちのもの。** マルチテナントのブラックボックスではなく、導入ごとに専用インスタンス・専用データベースを持ちます。デモ環境から自社サーバーへの移行も、データベースのダンプと `git clone` だけで完了します。ベンダーロックインはありません。
- **本物のマルチ店舗運用を想定。** 店舗ごとのタイムゾーン、通貨、休憩ルール、人員不足のしきい値、機能の個別有効化に対応 — 単一店舗向けツールを無理やり拡張したものではありません。
- **日本市場に最適化、世界でも利用可能。** CJK対応のPDF出力、「姓 名」の氏名順、円・ユーロ・ドル表示、日本語・英語・フランス語の翻訳を標準搭載 — 日本の小売業の商習慣を前提に設計しつつ、他国でも利用可能です。
- **余計なフレームワークのコストがない。** 約600ファイルからなる独自のPHP 8.3コア（Laravel、Symfonyなし）。ORMとしてEloquentのみを利用。月額5ユーロ程度のVPSでも同様に動作します — 詳細は [アーキテクチャ (wiki)](https://github.com/AudricSan/Kintai/wiki/ja/%E3%82%A2%E3%83%BC%E3%82%AD%E3%83%86%E3%82%AF%E3%83%81%E3%83%A3) をご覧ください。
- **実際にテストされています。** コア、リポジトリ、コントローラーをカバーする589件のPHPUnitテストが、GitHub Actions上でプッシュのたびに実行されます。

---

## 主な機能

**シフト管理** — シフト/シフト種別のCRUD、リスト・カレンダー・週・日・ガントチャート形式のタイムライン表示、ドラッグ＆ドロップ、一括操作、印刷、重複コンフリクトの検出、オープンシフトとシフト応募制度、視覚解析と信頼度スコアリングによるExcelインポート。

**勤怠管理** — リアルタイムタイマー付きの打刻（クロックイン/アウト）、管理者による打刻編集、シフト・休憩・時給・控除に基づく給与自動概算。

**従業員向けセルフサービス** — 個別ダッシュボード、週次の勤務可能時間登録、休暇申請、シフト交換、iCal連携。

**コミュニケーション** — スレッド形式の社内メッセージング（SSEによるリアルタイム更新）、既読/未読状態とトースト通知を備えた通知センター。

**日報** — 売上・来客数・人件費などの店舗別KPIレポート、下書き→提出→承認のワークフロー、PDF出力（mPDF、CJKフォント対応）、自動承認スケジュール、メール送信。

**REST API** — バージョン管理された `/api/v1`、Bearerトークン認証、ページネーション対応で、主要リソースを網羅。リファレンス：[.wiki/API.md](https://github.com/AudricSan/Kintai/wiki/API) *（ベータ移行前のリポジトリから移行中）*。

**運用機能** — GitHub Releasesからのワンクリック自己更新（進捗バーとバックアップ／ロールバックの自動セーフティネット付き）、SQLite/MySQLのバックアップと復元、外部スケジューラー向けのトークン保護されたcronエンドポイント。

詳細は [アーキテクチャ (wiki)](https://github.com/AudricSan/Kintai/wiki/ja/%E3%82%A2%E3%83%BC%E3%82%AD%E3%83%86%E3%82%AF%E3%83%81%E3%83%A3) と [データベース戦略 (wiki)](https://github.com/AudricSan/Kintai/wiki/ja/%E3%83%87%E3%83%BC%E3%82%BF%E3%83%99%E3%83%BC%E3%82%B9%E6%88%A6%E7%95%A5) をご覧ください。

---

## 権限と業務フロー

| 権限 | 範囲 |
| :--- | :--- |
| スーパー管理者 | 全店舗、監査ログ、全体設定 |
| 店舗マネージャー/管理者 | 担当店舗のシフト、人員、休暇、交換、レポート、統計 |
| スタッフ | 自分のシフト、打刻、休暇、交換、メッセージ |

典型的な流れ：シフト作成・インポート → コンフリクト確認 → 従業員へ通知 → 勤怠打刻 → 日報承認 → 給与概算。

---

## はじめに

### 動作要件

- PHP 8.3以上、Composer 2.x
- `pdo_sqlite` または `pdo_mysql`、`mbstring`、`gd`、`intl`

### ローカルインストール

```bash
git clone https://github.com/AudricSan/Kintai.git
cd Kintai
composer install
php -S 127.0.0.1:8000 -t public
```

続けて `http://127.0.0.1:8000/install.php` を開いてください — Webインストーラーが `config/database.local.php` を作成し、マイグレーションを実行、管理者アカウントを作成し、選択したインスタンスの言語を保存して（`.env` の `APP_LOCALE`、後からインスタンス設定で変更可能）ログインし、設定アシスタント（`/admin/setup`）を開きます：ライセンスキーを入力するか無料プランで続行し、インストールするバンドル（機能）を選びます——ライセンスで許可された数まで（無料プランは4つ）。バンドルはバンドルカタログからダウンロードされ、インストールできなかったものはカタログから再試行できるよう一覧表示されます。 インストーラーは書き込みの前にサーバーの必要条件（PHP のバージョン、拡張、書き込み可能なフォルダー）を確認し、失敗したインストールはすべて元に戻されるため、原因を直してそのまま再試行できます。

インストーラーは、インストールに使ったアドレスをインスタンスの**公開URL**としても記録します。これはメール内リンク（パスワード再設定）に使われます。ユーザーが別のアドレスで Kintai にアクセスする場合は、オーナー設定（`/admin/owner-settings`）で確認してください。環境変数 `APP_URL` が設定されている場合はそちらが優先されます。公開URLがないと、パスワード再設定メールは送信されず、オーナーに警告が表示されます。

ブラウザーを使わずにインストールする場合（スクリプトによるデプロイ）は、`scripts/provision.php` がコマンドラインで同じ処理を行います。デフォルトは SQLite で、MySQL の場合は `--db-driver=mysql` と `--db-*` オプションを使い、環境変数がフォールバックになります（スクリプト冒頭を参照）。公開URLを推測できるリクエストがないため、`--app-url` を指定してください。インスタンスの言語は、`--locale=fr` または `--locale=ja` を指定しない限り英語です（Web インストーラーと同じ既定値）：

```bash
php scripts/provision.php --admin-email=admin@example.com --admin-password='…' --app-url=https://kintai.example.com
```

### Webサーバー

配信するのは `public/` だけです。Webサーバーのドキュメントルート（Apacheの `DocumentRoot`、nginxの `root`）をそこに向けてください——リポジトリのルートに向けてはいけません。向けてしまうと、`.env`、`storage/app/database.sqlite`、バックアップ、ソースコードがダウンロードできてしまいます。`php -S … -t public` はすでにそうなっています。Apacheでは、サーバー（または共有ホスティングの `www/` フォルダー）がそれでもリポジトリのルートを指している場合、ルートの `.htaccess` がすべてのリクエストを `public/` へ透過的に書き換えます。URLはそのままきれいに保たれ、`public/` の外側には一切アクセスできず、サブフォルダー（`/Kintai/`）からでも動作します。nginxには同等の仕組みがないため、`root` を正しく設定する必要があります。

Apache では `public/.htaccess` が HTTP のリクエストをすべて HTTPS へリダイレクトします（301）。ただし、ローカル開発用のホスト（`localhost`、`127.x`、`[::1]`、`*.test`、`*.localhost`、プライベートネットワークのアドレス）と、TLS を終端するプロキシやホスティングによってすでに HTTPS と示されたリクエスト（`X-Forwarded-Proto: https`）は除きます。本番サイトは有効な証明書を使って HTTPS で配信してください。

### アップデート

```bash
php scripts/db-migrate.php --dry-run   # 未適用のマイグレーションを確認
php scripts/db-migrate.php             # 適用する
```

オーナー権限があれば `/admin/backup` からワンクリックでアップデートも可能です。最新のGitHub Releaseを取得する前に、データベースとコードを自動でバックアップし、進捗をリアルタイムに表示します。

---

## 技術スタック

独自設計のPHP 8.3 MVC — LaravelもSymfonyも使用せず、ORMとして `illuminate/database`（Eloquent）のみを採用。SQLiteまたはMySQLを設定で切り替え可能。サーバーサイドレンダリングのPHPビュー、モジュール化されたvanilla CSS/JS、ビルドツール不要。

```
public/index.php        → フロントコントローラー
src/Core/                → フレームワーク本体：DIコンテナ、ルーター、ミドルウェア、リポジトリ、サービス
src/Bundles/*/           → 機能単位で有効化できる独立モジュール（DailyReport、Messaging）
src/UI/                  → Web/APIコントローラー、PHPビュー
public/assets/           → モジュール化されたCSS/JS、ビルド不要
database/migrations/php/ → 単一スキーマからSQLite・MySQL双方に対応するEloquentマイグレーション
```

さらに詳しく：[アーキテクチャ (wiki)](https://github.com/AudricSan/Kintai/wiki/ja/%E3%82%A2%E3%83%BC%E3%82%AD%E3%83%86%E3%82%AF%E3%83%81%E3%83%A3) · [データベース戦略 (wiki)](https://github.com/AudricSan/Kintai/wiki/ja/%E3%83%87%E3%83%BC%E3%82%BF%E3%83%99%E3%83%BC%E3%82%B9%E6%88%A6%E7%95%A5) · [マルチテナンシー (wiki)](https://github.com/AudricSan/Kintai/wiki/ja/%E3%83%9E%E3%83%AB%E3%83%81%E3%83%86%E3%83%8A%E3%83%B3%E3%82%B7%E3%83%BC)

---

## コントリビュート

バグ報告、機能提案、プルリクエストなど、あらゆる形の貢献を歓迎します。

```bash
composer install
./vendor/bin/phpunit
```

PRを開く前に [CONTRIBUTING.ja.md](CONTRIBUTING.ja.md)（コーディング規約：strict types、コンストラクタインジェクション、マイグレーションのルールなど）をご確認ください。脆弱性を発見した場合は、公開issueではなく [SECURITY.ja.md](SECURITY.ja.md) の手順に従って非公開で報告してください。

---

## ロードマップ

**次のステップ**
- [ ] スケジューリングのタイムライン上にコンフリクトを視覚的に表示
- [ ] POST系Webルートおよび公開APIエンドポイントのセキュリティ監査の強化
- [ ] 給与計算、シフト交換、オープンシフト、通知機能を対象とした重点的なテスト追加

**将来的に**
- [ ] オフライン対応を一部含むインストール可能なPWA
- [ ] 給与データのCSV/XMLエクスポート
- [ ] Slack、LINE、Teams向けの送信Webhook
- [ ] Webインターフェースの多言語対応拡充（現状はFR/EN/JAのみ）
- [ ] 複数店舗をまたいだ分析・レポートダッシュボード
- [ ] 店舗間でのシフト交換に対応した複数店舗スケジューリング
- [ ] 複数店舗をまたいだ給与・人件費最適化
- [ ] 複数店舗の在庫・売上連携（POS、ERP、ECサイト）
- [ ] 複数店舗にまたがる従業員のパフォーマンス管理・ゲーミフィケーション
- [ ] 複数店舗における労働法コンプライアンス対応（残業、休憩、祝日）
- [ ] 従業員・マネージャー向けの複数店舗対応モバイルアプリ（iOS、Android）

変更履歴の全体は [CHANGELOG.ja.md](CHANGELOG.ja.md) をご覧ください。

---

## ライセンス

[GNU Affero General Public License v3.0](LICENSE)。Kintaiは商用利用を含め自由に利用・改変・再配布できますが、改変版をネットワークサービスとして提供する場合は、そのソースコードを公開する義務があります。全文は [LICENSE](LICENSE) を参照してください。
