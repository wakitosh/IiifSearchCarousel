# Changelog

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
