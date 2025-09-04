# IIIF Search Carousel

A simple Omeka S module that provides a page block with a full-width image carousel (sourced from IIIF manifests) and a search box overlay. Supports multi-target search (Items, Media, Item sets), auto-rotation, optional auto-rebuild of the image pool, and per-block IIIF Image API trimming by percentage (top/right/bottom/left).

- Omeka S: 4.x
- License: GPL-3.0-or-later

## Features

- Background carousel built from IIIF manifest(s)
- Search overlay that uses the native browse route `search` parameter
- Multi-target search (Items / Media / Item sets) as checkboxes in the block form
- Per-block custom CSS (scoped via unique block id)
- Auto-rotation interval (site setting)
- Optional auto-rebuild of image pool on front-end page views (interval-based)
- Per-block trimming using IIIF Image API `pct:x,y,w,h` region derived from top/right/bottom/left percentages

## Installation

1. Place the module directory `IiifSearchCarousel` under Omeka S `modules/`.
2. Enable the module in Omeka S admin.
3. Configure module settings at: Admin → IIIF Search Carousel.

## Configuration (Admin)

- Number of images: how many images to keep in the pool.
- Carousel duration: seconds per slide for auto-rotation.
- Image size: pixel width for building IIIF Image API URLs.
- Aspect ratio: choose 1:1, 4:3, 16:9, or custom (W/H).
- Selection rules: simple rule lines to pick canvases across manifests.
- Manifest URLs: one per line.
- Auto rebuild: enable and interval (minutes). When enabled, the module triggers a rebuild job on front-end render if the interval has elapsed.

## Page Block (Site → Pages)

Add block: "IIIF Search Carousel".

- Search targets: choose one or more of Items / Media / Item sets (checkboxes).
- Custom CSS (scoped): CSS applied only to this block. The block gets a unique id `#iiif-sc-<id>`.
- Trim top/right/bottom/left (%): percentages used to compute an Image API `pct:` region replacing `/full/`.

Front-end behavior:

- The carousel rotates automatically. Clicking an image opens the related URL (if present in the manifest).
- The search form posts to the selected resource's browse route using the `search` parameter (substring match across properties).
- If multiple search targets are configured, the search overlay respects those targets on submit (no front-end selector needed).

Selection rules:
- Define rules like `1 => 1`, `2 => 2`, `5+ => random(3-last-1)`. Unicode dashes and spaces are accepted (e.g., `3 - last - 1`). The first matching rule applies; otherwise a full random fallback is used.

## Known limitations

- The auto-rebuild trigger is best-effort and runs on page render; heavy rebuilds should be scheduled via cron in production.
- Carousel images depend on IIIF endpoints’ availability and performance.

## Development

- Table: `iiif_sc_images` stores image_url, manifest_url, related_url, label, position, created.
- Job: `RebuildImagesJob` fetches manifests (IIIF v2/v3), builds image URLs, and populates the table.
- Block: `SearchCarouselBlock` renders the carousel, search overlay, and exposes block settings.

---

# IIIF サーチ・カルーセル（日本語）

IIIF マニフェストから画像を取得して背景カルーセルを作り、その上に検索ボックスを重ねて表示する Omeka S 用のブロックを提供します。検索対象（アイテム／メディア／アイテムセット）の複数選択、スライドの自動ローテーション、画像プールの自動リビルド、上下左右のパーセント指定トリミングに対応しています。

- 対応 Omeka S: 4.x
- ライセンス: GPL-3.0-or-later

## 特長

- IIIF マニフェストから背景カルーセルを構築
- `search` クエリ（browse ルート）を使ったシンプルな全文検索風の入力
- 検索対象の複数選択（アイテム／メディア／アイテムセット）
- ブロック単位のカスタム CSS（ブロック id でスコープ）
- 自動ローテーション間隔を設定可能
- 表示時に一定間隔で画像プールの自動リビルド（任意）
- ブロック単位で上下左右のトリミング幅（%）を設定、Image API の `pct:` 領域を使用

## インストール

1. モジュール `IiifSearchCarousel` を Omeka S の `modules/` に配置。
2. 管理画面で有効化。
3. 設定は 管理 → IIIF Search Carousel から行います。

## 設定（管理画面）

- 画像数、スライド時間、画像サイズ、アスペクト比（既定 or 指定）、選択ルール、マニフェスト URL 群。
- 自動リビルド（有効化＋間隔）。フロント表示時に間隔経過でジョブを投げます。

## ページブロック（サイト → ページ）

- 検索対象: アイテム／メディア／アイテムセットからチェックボックスで選択。
- カスタム CSS（スコープ済み）: ブロック固有の id `#iiif-sc-<id>` を利用できます。
- トリミング: 上下左右の%を指定（`/full/` の部分を `pct:` 領域に置換）。

フロントの動作:

- カルーセルは自動で切り替わります。画像をクリックするとマニフェストの関連 URL があれば遷移します。
- 複数の検索対象を設定した場合でも、オーバーレイは設定された対象を尊重して検索を行います（フロント側のターゲット選択UIは不要）。

選択ルール:
- `1 => 1`, `2 => 2`, `5+ => random(3-last-1)` のように記述します。全角ダッシュやスペースを含んでも解釈されます（例: `3 - last - 1`）。最初にマッチしたルールを適用し、どれにもマッチしない場合は完全ランダムで選択します。

## 既知の制限

- 自動リビルドはページ描画タイミングのベストエフォートです。重い更新は cron 等の運用を検討してください。
- 画像の取得は外部の IIIF エンドポイントに依存します。

## ライセンス

GPL-3.0-or-later
