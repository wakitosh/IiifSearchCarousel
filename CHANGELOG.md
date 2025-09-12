# Changelog

## 0.2.10 - 2025-09-12
EN:
- Mobile: Widen overlay search on small screens while capping to container, allow wrapping and right-align wrapped controls (AND/OR + button).
- Unify overlay search form classes/structure with theme header search so shared JS/CSS apply consistently.

JA:
- モバイル: 小画面でオーバーレイ検索を広げつつコンテナ幅に収め、折り返し時に（AND/OR＋ボタン）を右寄せで整列。
- オーバーレイ検索フォームのクラス/構造をテーマのヘッダー検索と統一し、共通のJS/CSSを適用。

## 0.2.9 - 2025-09-11
EN:
- New global setting `identifier_property` allowing a custom property term for resolving IIIF identifier segments to items (falls back to `dcterms:identifier`).
- Settings form field added; job resolution now queries configured property then fallback.
- Documentation updated (README bilingual) for new setting.
- Style/indent cleanup (PSR-12) in job and admin controller.

JA:
- 新しい全体設定 `identifier_property` を追加。IIIF識別子セグメントをアイテムへ解決する際のプロパティ語を指定可能（`dcterms:identifier` へフォールバック）。
- 設定フォームに入力フィールドを追加し、ジョブ側で指定プロパティ→フォールバックの順に検索。
- README（英日）に該当説明を追記。
- ジョブおよび管理コントローラのインデント/スタイル（PSR-12）整理。

## 0.2.8 - 2025-09-11
EN:
- IIIF v3 image extraction: Handle `body.service` as object/array and `services` (plural), accept `id`/`@id`, and fall back to canvas `thumbnail` when no ImageService is present. Also accept v2 `service['id']`.
- Related URL extraction: Consider v3 `body.service` and `body.id` as candidates in addition to existing patterns.
- Minor PSR-12 style fixes.


JA:
- IIIF v3 画像抽出の強化: `body.service` の配列/オブジェクト両対応や `services`（複数形）をサポートし、`id`/`@id` を許容。ImageService が無い場合は Canvas の `thumbnail` をフォールバックとして使用。v2 でも `service['id']` を許容。
- 関連URL抽出の改善: 既存の検出に加えて、v3 の `body.service` と `body.id` を候補に追加。
 - 新しい全体設定 `identifier_property`: IIIF識別子セグメントをアイテムへ解決する際に利用するプロパティ語を指定（ヒットしない場合は `dcterms:identifier` へフォールバック）。

## 0.2.7 - 2025-09-11
EN:
- Mobile caption spacing: On small screens, caption boxes now keep equal left/right insets. Short titles remain content-width; long titles wrap before the right edge with a 1rem margin preserved.

JA:
- モバイル時のキャプション余白: 小画面でキャプションボックスが右端に張り付かないよう調整。短文は内容幅のまま、長文は右側1remの余白を保ったまま折り返します。

## 0.2.6 - 2025-09-10
EN:
- Title truncation: New global setting `truncate_title_length` to shorten long link titles/captions (UTF-8 safe). Front captions display truncated text with full title preserved in tooltip and aria-label. Admin preview list also applies truncation.
- Front search box sizing: Improved responsive sizing with `clamp()`-based max-widths and a medium breakpoint rule so the search box remains proportionate on small/medium screens.
- PSR-12 cleanups around render() and view variable safety.

JA:
- タイトル省略: 長いリンクタイトル/キャプションを短縮する全体設定 `truncate_title_length` を追加（UTF-8安全）。フロントでは省略表示しつつ、ツールチップと aria-label で全文を保持。管理プレビューでも省略を適用。
- フロントの検索ボックスのサイズ: `clamp()` を用いた最大幅と Medium 向けの調整を追加し、Small/Medium でカルーセルに対して大きすぎないように最適化。
- render() 周辺のPSR-12整備およびビュー変数の安全化。

## 0.2.5 - 2025-09-10
EN:
- Responsive aspect ratios: Added Small/Medium breakpoint settings and per-breakpoint ratios (inherit/preset/custom). Template now emits per-block scoped CSS with media queries to switch `aspect-ratio` accordingly.
- Fixed PSR-12 indentation in admin ConfigController.

JA:
- レスポンシブなアスペクト比: Small/Medium のブレークポイント設定と、各幅での比率（継承/プリセット/カスタム）を追加。テンプレートでブロック単位のスコープ付きCSSとメディアクエリを出力し、`aspect-ratio` を幅に応じて切り替えるようにしました。
- 管理用ConfigControllerのインデント（PSR-12）を修正。

## 0.2.4 - 2025-09-09
EN:
- Block admin form: Added an admin-only "Current selection list" preview (up to 50 entries) displaying Manifest Title, Image link, Manifest link, and Resource page link. Internal references like `omeka:item:{id}` / `omeka:media:{id}` now resolve to the public site URLs in the preview.
- Block DI cleanup: Inject the service container into the block layout; removed deprecated `getServiceLocator()` usage to avoid Omeka S 4.0.4 deprecation warnings.
- Minor PSR-12 style fixes.

JA:
- ブロック管理フォーム: 管理画面のみの「現在の選択リスト」プレビュー（最大50件）を追加。マニフェストのタイトル／画像リンク／マニフェストリンク／資料ページリンクを表示。`omeka:item:{id}` / `omeka:media:{id}` の内部参照は、プレビュー内でサイト公開ページのURLに解決されます。
- ブロックのDI整理: ブロックレイアウトにサービスコンテナを注入し、非推奨の `getServiceLocator()` を廃止（Omeka S 4.0.4 の非推奨警告を解消）。
- 軽微なPSR-12スタイル修正。

## 0.2.1 - 2025-09-04
- Selection rule parser: accept Unicode hyphen variants and optional spaces in conditions (`A-B`) and actions (`random(A-B)`, `random(A-last[-O])`). Prevents rules from failing to match and falling back to full random.
- Settings form: render Selection rules help as HTML (`escape_info` = false).
- Minor code style and docs tweaks.

## 0.2.0 - 2025-09-01
- Multi-target search via checkboxes in block form; legacy single target fallback.
- Per-canvas related URL extraction improved; tokens resolved to Omeka routes on front-end.
- Pointer-events fix so only active slide is clickable.
- IIIF Image API `pct:` trimming (top/right/bottom/left) per block.
- Auto rebuild trigger on front render based on interval setting.

## 0.1.0 - 2025-08-xx
- Initial public module draft: background carousel from IIIF manifests, search overlay, admin settings and rebuild job.
