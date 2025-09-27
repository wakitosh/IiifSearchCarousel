# IIIF Search Carousel

A versatile Omeka S module that provides a site page block with a full-width image carousel, built from a pool of IIIF manifests, and an overlaid search box.

It features multi-target search, auto-rotation, optional auto-rebuild of the image pool, and per-block image trimming via the IIIF Image API. The module intelligently selects canvases based on configurable rules and extracts related links, including direct links to Omeka S resources.

- **Omeka S compatibility:** 4.x
- **License:** GPL-3.0-or-later

## Features

- **Dynamic Background Carousel:** Displays a full-width carousel of images sourced from one or more IIIF manifests.
- **Overlay Search Box:** Provides a clean search interface on top of the carousel.
    - **Multi-Target Search:** Configure searches across Items, Media, and/or Item Sets.
    - **Show/Hide Option:** The search box can be hidden for a display-only carousel.
    - **AND-only Logic:** The search operates with AND-only semantics. The OR control is displayed for clarity but disabled.
    - **Example Query Links:** Example links are generated from site search terms. The displayed label equals the submitted query and the tooltip matches the label. Multilingual stopwords and numeric-only tokens are skipped. For CJK text, labels are grapheme-safely truncated with a configurable limit (default 8). One-word examples are selected via a noun-preferred score and a head-biased weighted random (configurable decay; default 0.82). When the decay is small (≤ 0.6), the head-bias is further strengthened. Visible examples by viewport: Desktop 5, Tablet 4, Mobile 3.
- **Advanced Image Control:**
    - **Flexible Canvas Selection:** Use powerful rules to select which canvas to display from a manifest (e.g., "the 2nd canvas," "a random canvas from the 3rd to the last-but-one").
    - **IIIF Image Trimming:** Trim images by percentage from any side (top, right, bottom, left) using the `pct:x,y,w,h` region parameter of the IIIF Image API.
- **Smart Link Extraction:** Automatically finds the best "related URL" for an image, prioritizing `homepage`, `seeAlso`, and even detecting links to Omeka S items/media (e.g., `/items/123`).
- **Automated Image Pool Management:**
    - **Manual & Auto Rebuild:** Rebuild the image pool manually from the admin dashboard or enable periodic, interval-based automatic rebuilds triggered by site visits.
- **Customization:**
    - **Aspect Ratio Control:** Set a fixed (1:1, 4:3, 16:9) or custom aspect ratio for the carousel.
    - **Per-Block Custom CSS:** Apply custom CSS scoped to each individual block.
    - **Responsive Aspect Ratios (new):** Optionally define different aspect ratios for common mobile sizes via two breakpoints (Small/Medium). The module outputs per-block scoped CSS with media queries to switch the container's aspect-ratio at those widths.
    - **Title Truncation (new):** Set a global maximum length for link titles/captions. Front-end captions are truncated safely (UTF-8) and show the full title via tooltip and aria-label. Set 0 to disable.

## Installation

1.  Download the module and place the `IiifSearchCarousel` directory in your Omeka S `modules/` folder.
2.  Log in to your Omeka S admin panel, navigate to **Modules**, and activate "IIIF Search Carousel".
3.  Configure the module's global settings by navigating to **IIIF Search Carousel** in the left-hand admin menu.

## Configuration

Configuration is split into two levels: global settings for the entire site and per-block settings for each instance of the carousel.

### Global Settings (Admin Dashboard)

These settings control the default behavior and image pool for all carousels. Access them from the **IIIF Search Carousel** menu.

- **Number of images:** The total number of images to fetch and keep in the image pool.
- **Carousel duration:** The time (in seconds) each slide is displayed before auto-rotating.
- **IIIF image size:** The width (in pixels) to request from the IIIF Image API (e.g., `1600`).
- **Aspect ratio:** The aspect ratio for the carousel container. Choose a preset or "Custom" to define your own width and height ratio.
- **Responsive aspect ratios:** Define two breakpoints and optional aspect ratios for Small and Medium screens. Set "Inherit" to keep the default ratio, or pick a preset/custom ratio to override at that width.
- **Title truncation:** Maximum number of characters for link titles. 0 disables truncation. Applies to admin preview and front captions (with full title preserved in tooltip/aria-label).
- **Selection rules:** Define rules to pick a canvas from a manifest based on the number of canvases it contains. See the "Canvas Selection Rules" section below for details.
- **Manifest URLs:** A list of IIIF manifest URLs, one per line. The module will fetch images from these sources.
// CleanUrl: Identifier property is auto-detected from the CleanUrl module settings (item property id). No dedicated setting here; falls back to dcterms:identifier when missing.
- **Auto rebuild:**
    - **Enable:** Check to enable automatic image pool rebuilding.
    - **Interval:** Set the minimum interval (in minutes) between automatic rebuilds. This is a "poor-man's cron" triggered on page visits.

### Block Settings (Site Page Editor)

When you add a "IIIF Search Carousel" block to a site page, you can override or specify settings for that specific instance.

- **Search targets:** Check the resource types (Items, Media, Item sets) this block's search box should query.
- **Show search box:** Uncheck this to hide the search box and use the block as a purely decorative image carousel.
- **Custom CSS (scoped):** Add CSS rules that will only apply to this block. A unique ID selector (e.g., `#iiif-sc-123`) is provided for easy scoping.
- **Trim (top, right, bottom, left) (%):** Specify a percentage to trim from each side of the image. For example, setting "Trim top" to `10` will cut off the top 10% of the image. This uses the IIIF Image API's `pct:` region parameter.
// Examples configuration
- **CJK maximum display length (graphemes):** Grapheme-safe truncation length for example keywords. Default 8. Allowed range: 2–32.
- **Head-biased selection decay:** Earlier tokens get higher weight. Default 0.82. Smaller values bias more strongly to the head. Allowed range: 0.50–0.99. Additional strengthening applies when ≤ 0.6.

#### Admin-only: Current Selection List (Preview)

At the bottom of the block form, an admin-only preview lists up to 50 currently selected entries from the image pool:

- Manifest Title
- Image link (direct IIIF image URL)
- Manifest link (manifest URL)
- Resource page link

For internal links detected as `omeka:item:{id}` or `omeka:media:{id}`, the "Resource page" points to the public site page (not the admin UI). Links are CleanUrl-aware: when the CleanUrl module is active, URLs are generated using the site's CleanUrl routing. If the list is empty, configure manifest URLs in the module settings and run "Rebuild" to populate the pool.

### Search Box Size

The overlaid search form scales with viewport width. By default it uses a responsive max-width with `clamp()` so it stays proportionate to the carousel on small/medium screens. You can further tailor its width per block with the "Custom CSS (scoped)" field; target `#iiif-sc-{id} .iiif-sc__search`. The example links below the input show up to 5/4/3 items on Desktop/Tablet/Mobile.

## Advanced Features

### Canvas Selection Rules

The "Selection rules" allow you to precisely control which canvas is chosen from a manifest. The module applies the first matching rule. If no rules match, a random canvas is selected as a fallback.

**Format:** `CONDITION => ACTION` (one rule per line)

-   **CONDITION:**
    -   `N`: Matches if the manifest has exactly `N` canvases.
    -   `A-B`: Matches if the canvas count is between `A` and `B` (inclusive).
    -   `N+`: Matches if the canvas count is `N` or more.
-   **ACTION:**
    -   `N`: Selects the Nth canvas (1-based index).
    -   `last`: Selects the last canvas.
    -   `random`: Selects a random canvas from all available.
    -   `random(A-B)`: Selects a random canvas between the Ath and Bth (inclusive, 1-based).
    -   `random(A-last-O)`: Selects a random canvas from the Ath to the last, minus an offset `O`. For example, `random(2-last-1)` selects from the 2nd canvas to the second-to-last.

**Example Rules:**

```
1 => 1
2 => 2
3-5 => random(2-last)
6+ => random(3-last-1)
```

-   If a manifest has 1 canvas, select the 1st.
-   If it has 2 canvases, select the 2nd.
-   If it has 3 to 5, select a random one from the 2nd to the last.
-   If it has 6 or more, select a random one from the 3rd to the second-to-last.

### Smart Related URL Extraction

When an image is clicked, the user is taken to a "related URL." The module intelligently extracts this URL from the manifest data with the following priority:

1.  **Omeka S Resource Link (from Service/Image URL):** Detects URLs pointing to Omeka S media (e.g., `/iiif/3/456`) and creates a direct link (`omeka:media:456`).
2.  **Canvas-level Links:**
    -   `homepage` (v3)
    -   `seeAlso` (v3)
    -   `related` (v2)
    -   The canvas's own URI (`id`).
3.  **Manifest-level Links:**
    -   Detects Omeka S item manifest URLs (e.g., `/item/123/manifest`) and links to the item (`omeka:item:123`).
    -   `homepage` (v3)
    -   `related` (v2)
4.  **Fallback:** The manifest URL itself.

## Known Limitations

-   The auto-rebuild feature is triggered by user visits and is not a substitute for a true cron job. For mission-critical, frequent updates, consider triggering the rebuild job via a system cron task.
-   Carousel performance depends on the speed and availability of the remote IIIF image servers.

---

# IIIF サーチ・カルーセル (IiifSearchCarousel)

IIIFマニフェストから取得した画像で構成される全幅の画像カルーセルと、その上に重ねて表示される検索ボックスを、サイトのページブロックとして提供する多機能なOmeka Sモジュールです。

複数リソースを対象とした検索、スライドの自動回転、画像プールの自動リビルド、IIIF Image APIを利用したブロック単位での画像トリミングに対応しています。また、設定可能なルールに基づいて表示するキャンバスを賢く選択し、Omeka S内部リソースへの直接リンクを含む関連リンクを自動で抽出します。

- **Omeka S互換性:** 4.x
- **ライセンス:** GPL-3.0-or-later

## 主な機能

- **動的な背景カルーセル:** 複数のIIIFマニフェストをソースとして、全幅の画像カルーセルを表示します。
- **オーバーレイ検索ボックス:** カルーセルの上にシンプルな検索インターフェースを提供します。
    - **複数対象検索:** アイテム、メディア、アイテムセットを横断して検索するよう設定できます。
    - **表示/非表示オプション:** 検索ボックスを非表示にして、ディスプレイ専用のカルーセルとしても利用可能です。
    - **AND専用ロジック:** 検索は AND のみで動作します。OR コントロールは視覚的には表示しますが無効化されています。
    - **例リンク:** サイトの検索語から例リンクを生成します。表示テキスト＝送信クエリで、ツールチップも表示と同一。多言語ストップワードや数字のみの語は除外します。CJKテキストはグラフェム安全に省略し、最大長は設定可能（既定値8）。名詞寄りスコア＋先頭寄り重み付け（減衰率は設定可能、既定値0.82）で1語を選び、減衰率が小さい（≤0.6）場合は先頭寄りがさらに強化されます。表示件数はPC/タブレット/モバイルで5/4/3。
- **高度な画像コントロール:**
    - **柔軟なキャンバス選択:** 「2枚目のキャンバス」「3枚目から最後から2枚目までのうちランダムな1枚」など、マニフェストからどのキャンバスを表示するかを強力なルールで指定できます。
    - **IIIF画像トリミング:** IIIF Image APIの`pct:x,y,w,h`領域パラメータを利用して、画像の上下左右をパーセンテージでトリミングできます。
- **スマートなリンク抽出:** 画像に最適な「関連URL」を自動で発見します。`homepage`や`seeAlso`を優先し、Omeka Sのアイテムやメディアへのリンク（例: `/items/123`）も検出します。
- **画像プールの自動管理:**
    - **手動＆自動リビルド:** 管理画面から手動で画像プールを再構築できるほか、サイトへの訪問をトリガーとした、一定間隔での自動リビルドも設定可能です。
- **カスタマイズ:**
    - **アスペクト比制御:** 固定（1:1, 4:3, 16:9）またはカスタムのアスペクト比をカルーセルに設定できます。
    - **ブロック単位のカスタムCSS:** 個々のブロックにのみ適用されるカスタムCSSを追加できます。
    - **レスポンシブなアスペクト比（新）:** Small/Medium の2つのブレークポイントで、画面幅に応じて異なるアスペクト比を適用できます。各ブロックにスコープされたCSSとメディアクエリにより、指定した幅で自動的に切り替わります。
    - **タイトル省略（新）:** リンクタイトル/キャプションの最大文字数を全体設定で指定できます。フロントではUTF-8安全に省略し、ツールチップとaria-labelで全文を保持します。0で無効。

## インストール

1.  モジュールをダウンロードし、`IiifSearchCarousel`ディレクトリをOmeka Sの`modules/`フォルダに配置します。
2.  Omeka Sの管理パネルにログインし、**モジュール**に移動して「IIIF Search Carousel」を有効化します。
3.  左側の管理メニューから**IIIF Search Carousel**に移動し、モジュールの全体設定を行います。

## 設定

設定は、サイト全体に適用される「全体設定」と、カルーセルの各インスタンスに適用される「ブロック単位設定」の2つのレベルに分かれています。

### 全体設定（管理ダッシュボード）

すべてのカルーセルのデフォルトの動作と画像プールを制御します。**IIIF Search Carousel**メニューからアクセスします。

- **画像数:** 画像プールに取得・保持する画像の総数。
- **カルーセル表示時間:** 各スライドが自動で切り替わるまでの表示時間（秒）。
- **IIIF画像サイズ:** IIIF Image APIに要求する画像の幅（ピクセル単位、例: `1600`）。
- **アスペクト比:** カルーセルコンテナのアスペクト比。プリセットから選択するか、「カスタム」で独自の幅と高さの比率を定義します。
- **レスポンシブなアスペクト比:** Small/Medium の2つのブレークポイント値と、それぞれの比率（継承/プリセット/カスタム）を設定できます。「継承」を選ぶとデフォルトの比率を維持します。
- **タイトル省略:** リンクタイトルの最大文字数。0で無効。管理プレビューとフロントのキャプションに適用され、全文はツールチップ/aria-labelで保持されます。
- **選択ルール:** マニフェストに含まれるキャンバス数に基づいて、表示するキャンバスを選択するルールを定義します。詳細は下記の「キャンバス選択ルール」セクションを参照してください。
- **マニフェストURL:** IIIFマニフェストのURLを1行に1つずつリストします。モジュールはこれらのソースから画像を取得します。
// CleanUrl: 識別子プロパティは CleanUrl モジュールの設定（アイテムのプロパティID）から自動検出します。ここでの専用設定は不要です。未設定時は dcterms:identifier にフォールバックします。
- **自動リビルド:**
    - **有効化:** 画像プールの自動リビルドを有効にする場合にチェックします。
    - **間隔:** 自動リビルドを実行する最小間隔（分）を設定します。これはページ訪問時にトリガーされる簡易的なcron機能です。

### ブロック設定（サイトページ編集画面）

サイトページに「IIIF Search Carousel」ブロックを追加すると、その特定のインスタンスに対して設定を上書き・指定できます。

- **検索対象:** このブロックの検索ボックスがクエリすべきリソース種別（アイテム、メディア、アイテムセット）にチェックを入れます。
- **検索ボックスを表示:** チェックを外すと検索ボックスが非表示になり、純粋な装飾用画像カルーセルとして使用できます。
- **カスタムCSS（スコープ済み）:** このブロックにのみ適用されるCSSルールを追加します。スコープを容易にするため、ユニークなIDセレクタ（例: `#iiif-sc-123`）が提供されます。
- **トリミング（上下左右）（%）:** 画像の各辺からトリミングするパーセンテージを指定します。例えば、「上をトリミング」に`10`と設定すると、画像の上部10%がカットされます。これはIIIF Image APIの`pct:`領域指定を利用します。
// 例示の設定
- **CJKの最大表示長（グラフェム）:** 例示キーワードの安全な省略長。既定値8。許容範囲: 2〜32。
- **先頭寄り重み付けの減衰率:** 文頭に近い語ほど重みを高くする係数。既定値0.82。小さいほど先頭に強く偏ります。許容範囲: 0.50〜0.99。0.6以下では先頭寄りがより強化されます。

#### 管理画面のみ: 現在の選択リスト（プレビュー）

ブロック設定フォームの下部に、画像プールから現在選択されている最大50件の一覧を表示します。

- マニフェストのタイトル
- 画像リンク（IIIF画像のURL）
- マニフェストへのリンク（manifest URL）
- 資料ページへのリンク

`omeka:item:{id}` / `omeka:media:{id}` と検出された内部リンクは、管理画面ではなくサイト公開ページへのURLに変換して表示します。CleanUrl モジュールが有効な場合は CleanUrl に準拠したURLで表示します。リストが空の場合は、モジュール設定でマニフェストURLを登録し、「再構築」を実行して画像プールを作成してください。

### 検索ボックスのサイズ

オーバーレイの検索フォームはビューポート幅に応じてスケールします。既定では `clamp()` を用いたレスポンシブな最大幅を設定しており、Small/Medium でもカルーセルに対して大きすぎないように調整されています。さらに細かく調整したい場合は、ブロックの「カスタムCSS（スコープ済み）」で `#iiif-sc-{id} .iiif-sc__search` をターゲットに上書きしてください。入力欄の下に表示される例リンクは、PC/タブレット/モバイルで 5/4/3 件表示されます。

### Tokenization and Fallbacks / トークナイズとフォールバック

- When available, example keywords prefer morphological tokens via Mroonga TokenMecab.
- On environments without Mroonga/MeCab, the module falls back to regex-based segmentation (Kanji/Hiragana/Katakana/Latin-numeric) and applies the same noun/length scoring and head-biased weighting.
- In all cases, links are CleanUrl-aware and use AND-only search.

## 高度な機能

### キャンバス選択ルール

「選択ルール」により、マニフェストからどのキャンバスが選ばれるかを正確に制御できます。最初に一致したルールが適用され、どのルールにも一致しない場合は、フォールバックとしてランダムなキャンバスが選択されます。

**フォーマット:** `条件 => アクション` （1行に1ルール）

-   **条件:**
    -   `N`: マニフェストがちょうど`N`個のキャンバスを持つ場合に一致。
    -   `A-B`: キャンバス数が`A`から`B`の間（両端を含む）の場合に一致。
    -   `N+`: キャンバス数が`N`以上の場合に一致。
-   **アクション:**
    -   `N`: N番目のキャンバスを選択（1ベースのインデックス）。
    -   `last`: 最後のキャンバスを選択。
    -   `random`: 利用可能なすべてのキャンバスからランダムに1つ選択。
    -   `random(A-B)`: A番目からB番目の間（両端を含む、1ベース）でランダムに1つ選択。
    -   `random(A-last-O)`: A番目から、最後からオフセット`O`を引いた位置までの間でランダムに1つ選択。例: `random(2-last-1)`は、2番目から最後から2番目までの間で選択します。

**ルール例:**

```
1 => 1
2 => 2
3-5 => random(2-last)
6+ => random(3-last-1)
```

-   マニフェストにキャンバスが1つしかない場合、1枚目を選択。
-   2つある場合、2枚目を選択。
-   3〜5つある場合、2枚目から最後の間でランダムに選択。
-   6つ以上ある場合、3枚目から最後から2番目の間でランダムに選択。

### スマートな関連URL抽出

画像がクリックされると、ユーザーは「関連URL」に遷移します。このURLは、以下の優先順位でマニフェストデータからインテリジェントに抽出されます。

1.  **Omeka Sリソースリンク（Service/Image URLから）:** Omeka Sのメディアを指すURL（例: `/iiif/3/456`）を検出し、直接リンク（`omeka:media:456`）を生成します。
2.  **キャンバスレベルのリンク:**
    -   `homepage` (v3)
    -   `seeAlso` (v3)
    -   `related` (v2)
    -   キャンバス自身のURI (`id`)
3.  **マニフェストレベルのリンク:**
    -   Omeka SのアイテムマニフェストURL（例: `/item/123/manifest`）を検出し、アイテム（`omeka:item:123`）にリンクします。
    -   `homepage` (v3)
    -   `related` (v2)
4.  **フォールバック:** マニフェストURL自体。

## 既知の制約

-   自動リビルド機能はユーザーの訪問によってトリガーされるものであり、本格的なcronジョブの代替にはなりません。ミッションクリティカルで頻繁な更新が必要な場合は、システムのcronタスク経由でリビルドジョブをトリガーすることを検討してください。
-   カルーセルのパフォーマンスは、リモートにあるIIIF画像サーバーの速度と可用性に依存します。
