<?php

namespace IiifSearchCarousel\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Psr\Container\ContainerInterface;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Stdlib\ErrorStore;
use Laminas\Form\Form;
use Laminas\Form\Element\Textarea;
use Laminas\Form\Element\Number;
use Laminas\Form\Element\MultiCheckbox;
use Laminas\Form\Element\Checkbox;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use IiifSearchCarousel\Job\RebuildImagesJob;

/**
 * Block layout: IIIF Search Carousel.
 */
class SearchCarouselBlock extends AbstractBlockLayout {
  /**
   * Service container.
   *
   * @var \Psr\Container\ContainerInterface
   */
  private $services;

  public function __construct(ContainerInterface $services) {
    $this->services = $services;
  }

  /**
   * {@inheritDoc} */
  public function getLabel() {
    return 'IIIF Search Carousel';
  }

  /**
   * {@inheritDoc} */
  public function render(PhpRenderer $view, SitePageBlockRepresentation $block) {
    // Use the injected container instead of deprecated getServiceLocator().
    $services = $this->services;
    $settings = $services->get('Omeka\Settings');
    $connection = $services->get('Omeka\Connection');
    // Auto-rebuild trigger on visit if enabled and interval elapsed.
    $autoEnabled = (bool) ($settings->get('iiif_sc.auto_rebuild_enable') ?? FALSE);
    if ($autoEnabled) {
      $intervalMin = (int) ($settings->get('iiif_sc.auto_rebuild_interval') ?? 60);
      $last = (int) ($settings->get('iiif_sc.auto_rebuild_last') ?? 0);
      $now = time();
      if ($now - $last >= max(60, $intervalMin * 60)) {
        try {
          $dispatcher = $services->get('Omeka\Job\Dispatcher');
          $dispatcher->dispatch(RebuildImagesJob::class, []);
          $settings->set('iiif_sc.auto_rebuild_last', $now);
        }
        catch (\Throwable $e) {
          // best-effort: ignore errors on front render.
        }
      }
    }

    $duration = (int) ($settings->get('iiif_sc.carousel_duration') ?? 6);
    $aspect = (string) ($settings->get('iiif_sc.aspect_ratio_mode') ?? '16:9');
    $w = (int) ($settings->get('iiif_sc.aspect_ratio_w') ?? 16);
    $h = (int) ($settings->get('iiif_sc.aspect_ratio_h') ?? 9);

    $toRatio = function (string $mode, int $rw, int $rh): string {
      switch ($mode) {
        case '1:1':
          return '1 / 1';

        case '4:3':
          return '4 / 3';

        case '16:9':
          return '16 / 9';

        case 'custom':
          return ($rw > 0 && $rh > 0) ? ($rw . ' / ' . $rh) : '16 / 9';

        default:
          return '16 / 9';
      }
    };

    $ratioDefault = $toRatio($aspect, $w, $h);

    // Responsive aspect ratios from settings.
    $bpSm = (int) ($settings->get('iiif_sc.aspect_ratio_breakpoint_sm') ?? 600);
    $modeSm = (string) ($settings->get('iiif_sc.aspect_ratio_mode_sm') ?? 'inherit');
    $wSm = (int) ($settings->get('iiif_sc.aspect_ratio_w_sm') ?? 16);
    $hSm = (int) ($settings->get('iiif_sc.aspect_ratio_h_sm') ?? 9);
    $ratioSm = $modeSm !== 'inherit' ? $toRatio($modeSm, $wSm, $hSm) : NULL;

    $bpMd = (int) ($settings->get('iiif_sc.aspect_ratio_breakpoint_md') ?? 900);
    $modeMd = (string) ($settings->get('iiif_sc.aspect_ratio_mode_md') ?? 'inherit');
    $wMd = (int) ($settings->get('iiif_sc.aspect_ratio_w_md') ?? 16);
    $hMd = (int) ($settings->get('iiif_sc.aspect_ratio_h_md') ?? 9);
    $ratioMd = $modeMd !== 'inherit' ? $toRatio($modeMd, $wMd, $hMd) : NULL;

    $rows = $connection->fetchAllAssociative('SELECT * FROM iiif_sc_images ORDER BY position ASC');

    // Title truncation length (front captions/aria). 0 = no truncation.
    $truncateLen = (int) ($settings->get('iiif_sc.truncate_title_length') ?? 0);

    $view->headScript()->appendFile($view->assetUrl('js/iiif-sc-carousel.js', 'IiifSearchCarousel'));
    $view->headLink()->appendStylesheet($view->assetUrl('css/iiif-sc-carousel.css', 'IiifSearchCarousel'));

    // Resource targets for search (items, media, item-set). Allow multiple.
    $allowedTargets = ['items', 'media', 'item_sets'];
    $resourceTargets = $block->dataValue('resource_targets') ?? [];
    if (is_string($resourceTargets)) {
      $resourceTargets = [$resourceTargets];
    }
    if (!is_array($resourceTargets) || !$resourceTargets) {
      // Fallback to legacy single target.
      $legacy = (string) $block->dataValue('resource_target', 'items');
      $resourceTargets = [$legacy];
    }
    // Filter to allowed and ensure not empty.
    $resourceTargets = array_values(array_unique(array_intersect($resourceTargets, $allowedTargets)));
    if (!$resourceTargets) {
      $resourceTargets = ['items'];
    }

    // Trim percentages (per block): top, right, bottom, left.
    $trimTop = (float) ($block->dataValue('trim_top', 0));
    $trimRight = (float) ($block->dataValue('trim_right', 0));
    $trimBottom = (float) ($block->dataValue('trim_bottom', 0));
    $trimLeft = (float) ($block->dataValue('trim_left', 0));

    return $view->partial('common/block-layout/iiif-search-carousel', [
      'rows' => $rows,
      'ratioDefault' => $ratioDefault,
      'ratioSm' => $ratioSm,
      'ratioMd' => $ratioMd,
      'bpSm' => $bpSm,
      'bpMd' => $bpMd,
      'duration' => $duration * 1000,
      'blockId' => (int) $block->id(),
      'customCss' => (string) $block->dataValue('custom_css', ''),
      'truncateLen' => $truncateLen,
      'resourceTargets' => $resourceTargets,
      'trimTop' => $trimTop,
      'trimRight' => $trimRight,
      'trimBottom' => $trimBottom,
      'trimLeft' => $trimLeft,
      'showSearch' => (bool) $block->dataValue('show_search', TRUE),
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function form(
    PhpRenderer $view,
    SiteRepresentation $site,
    ?SitePageRepresentation $page = NULL,
    ?SitePageBlockRepresentation $block = NULL,
  ) {
    $form = new Form();
    // Search targets (multiple via checkboxes).
    $chk = new MultiCheckbox('o:block[__blockIndex__][o:data][resource_targets]');
    $chk->setLabel('Search targets');
    $chk->setValueOptions([
      'items' => 'Items',
      'media' => 'Media',
      'item_sets' => 'Item sets',
    ]);
    if ($block) {
      $vals = $block->dataValue('resource_targets');
      if (is_string($vals)) {
        $vals = [$vals];
      }
      if (!is_array($vals) || !$vals) {
        $vals = [(string) $block->dataValue('resource_target', 'items')];
      }
      $chk->setValue($vals);
    }
    $form->add($chk);

    // Show/hide search box.
    $show = new Checkbox('o:block[__blockIndex__][o:data][show_search]');
    $show->setLabel('Show search box');
    $show->setUseHiddenElement(TRUE);
    $show->setCheckedValue('1');
    $show->setUncheckedValue('0');
    if ($block) {
      $show->setChecked((bool) $block->dataValue('show_search', TRUE));
    }
    else {
      $show->setChecked(TRUE);
    }
    $form->add($show);

    $el = new Textarea('o:block[__blockIndex__][o:data][custom_css]');
    $el->setLabel('Custom CSS (scoped)');
    $el->setAttribute('rows', 6);
    // Place the hint directly under the textarea as info text.
    if ($block) {
      $hintId = (int) $block->id();
      $el->setOption('info', sprintf('Use selector #iiif-sc-%d to scope your CSS to this block.', $hintId));
    }
    else {
      $el->setOption('info', 'After saving, this block will have a unique id like #iiif-sc-123 for scoping.');
    }
    if ($block) {
      $el->setValue((string) $block->dataValue('custom_css', ''));
    }
    $form->add($el);
    // Trim controls (percentages per side).
    $top = new Number('o:block[__blockIndex__][o:data][trim_top]');
    $top->setLabel('Trim top (%)');
    $top->setAttributes(['min' => 0, 'max' => 100, 'step' => '0.1']);
    if ($block) {
      $top->setValue((string) ($block->dataValue('trim_top', 0)));
    }
    $form->add($top);

    $right = new Number('o:block[__blockIndex__][o:data][trim_right]');
    $right->setLabel('Trim right (%)');
    $right->setAttributes(['min' => 0, 'max' => 100, 'step' => '0.1']);
    if ($block) {
      $right->setValue((string) ($block->dataValue('trim_right', 0)));
    }
    $form->add($right);

    $bottom = new Number('o:block[__blockIndex__][o:data][trim_bottom]');
    $bottom->setLabel('Trim bottom (%)');
    $bottom->setAttributes(['min' => 0, 'max' => 100, 'step' => '0.1']);
    if ($block) {
      $bottom->setValue((string) ($block->dataValue('trim_bottom', 0)));
    }
    $form->add($bottom);

    $left = new Number('o:block[__blockIndex__][o:data][trim_left]');
    $left->setLabel('Trim left (%)');
    $left->setAttributes(['min' => 0, 'max' => 100, 'step' => '0.1']);
    if ($block) {
      $left->setValue((string) ($block->dataValue('trim_left', 0)));
    }
    $form->add($left);

    // Append current selection preview (read-only list from iiif_sc_images).
    // Show up to 50 entries for performance.
    $html = $view->formCollection($form, FALSE);
    try {
      $conn = $this->services->get('Omeka\Connection');
      $rows = $conn->fetchAllAssociative('SELECT * FROM iiif_sc_images ORDER BY position ASC LIMIT 50');
    }
    catch (\Throwable $e) {
      $rows = [];
    }

    $esc = function ($s) use ($view) {
      return $view->escapeHtml((string) $s);
    };

    // Truncation length from settings (0 = no truncation).
    try {
      $truncateLen = (int) ($this->services->get('Omeka\Settings')->get('iiif_sc.truncate_title_length') ?? 0);
    }
    catch (\Throwable $e) {
      $truncateLen = 0;
    }
    $truncateTitle = function (string $s) use ($truncateLen) {
      if ($truncateLen <= 0) {
        return $s;
      }
      // Use mb_* if available for multibyte safety.
      if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($s, 'UTF-8') > $truncateLen) {
          return mb_substr($s, 0, $truncateLen, 'UTF-8') . '…';
        }
        return $s;
      }
      return strlen($s) > $truncateLen ? substr($s, 0, $truncateLen) . '…' : $s;
    };

    $siteSlug = $site->slug();
    $buildResourceHref = function ($related) use ($view, $siteSlug) {
      $href = NULL;
      if (is_string($related)) {
        if (strpos($related, 'omeka:media:') === 0) {
          $id = (int) substr($related, strlen('omeka:media:'));
          $href = $view->url('site/resource', [
            'site-slug' => $siteSlug,
            'controller' => 'media',
            'action' => 'show',
            'id' => $id,
          ]);
        }
        elseif (strpos($related, 'omeka:item:') === 0) {
          $id = (int) substr($related, strlen('omeka:item:'));
          $href = $view->url('site/resource', [
            'site-slug' => $siteSlug,
            'controller' => 'item',
            'action' => 'show',
            'id' => $id,
          ]);
        }
        else {
          $href = $related;
        }
      }
      return $href;
    };

    $html .= "\n<fieldset class=\"field\">\n  <legend>現在の選択リスト（最大50件）</legend>\n  <div class=\"value\">";
    if ($rows) {
      $html .= "\n    <table class=\"tablesaw tablesaw-stack\">\n      <thead>\n        <tr>\n          <th>マニフェストのタイトル</th>\n          <th>画像リンク</th>\n          <th>マニフェスト</th>\n          <th>資料ページ</th>\n        </tr>\n      </thead>\n      <tbody>";
      foreach ($rows as $r) {
        $label = $r['label'] ?? '';
        $labelShort = $truncateTitle((string) $label);
        $img = $r['image_url'] ?? '';
        $man = $r['manifest_url'] ?? '';
        $rel = $r['related_url'] ?? '';
        $relHref = $buildResourceHref($rel);
        $html .= "\n        <tr>"
          . '<td' . ($labelShort !== $label ? ' title="' . $esc($label) . '"' : '') . '>' . $esc($labelShort) . '</td>'
          . '<td>' . ($img ? '<a href="' . $esc($img) . '" target="_blank" rel="noopener">画像</a>' : '') . '</td>'
          . '<td>' . ($man ? '<a href="' . $esc($man) . '" target="_blank" rel="noopener">manifest</a>' : '') . '</td>'
          . '<td>' . ($relHref ? '<a href="' . $esc($relHref) . '" target="_blank" rel="noopener">ページ</a>' : '') . '</td>'
        . '</tr>';
      }
      $html .= "\n      </tbody>\n    </table>";
    }
    else {
      $html .= "\n    <p>まだ選択された画像はありません。設定ページでマニフェストを登録し、画像の再構築を実行してください。</p>";
    }
    $html .= "\n  </div>\n</fieldset>";

    return $html;
  }

  /**
   * {@inheritDoc}
   */
  public function onHydrate(SitePageBlock $block, ErrorStore $errorStore) {
    $data = $block->getData() ?: [];
    if (isset($data['custom_css']) && is_string($data['custom_css'])) {
      // Simple sanitize: neutralize </style> and drop trailing nulls.
      $css = str_replace(['</style', '</STYLE'], ['</ sty_le', '</ STY_LE'], $data['custom_css']);
      // Limit CSS size to 20KB.
      if (strlen($css) > 20 * 1024) {
        $css = substr($css, 0, 20 * 1024);
      }
      $data['custom_css'] = $css;
    }
    // Validate and normalize resource targets (allow multiple).
    $allowed = ['items', 'media', 'item_sets'];
    $targets = $data['resource_targets'] ?? NULL;
    if (is_string($targets)) {
      $targets = [$targets];
    }
    if (!is_array($targets) || !$targets) {
      $legacy = isset($data['resource_target']) && is_string($data['resource_target']) ? $data['resource_target'] : 'items';
      $targets = [$legacy];
    }
    $targets = array_values(array_unique(array_intersect($targets, $allowed)));
    if (!$targets) {
      $targets = ['items'];
    }
    $data['resource_targets'] = $targets;
    unset($data['resource_target']);

    // Normalize trim values (0-100%).
    foreach (['trim_top', 'trim_right', 'trim_bottom', 'trim_left'] as $k) {
      $raw = $data[$k] ?? 0;
      $v = (float) $raw;
      if (!is_numeric((string) $raw)) {
        $v = 0.0;
      }
      if ($v < 0) {
        $v = 0.0;
      }
      if ($v > 100) {
        $v = 100.0;
      }
      // Round to one decimal place to keep URL short.
      $data[$k] = round($v, 1);
    }
    // Normalize show_search to boolean, default TRUE.
    if (!isset($data['show_search'])) {
      $data['show_search'] = TRUE;
    }
    else {
      $data['show_search'] = (bool) $data['show_search'];
    }
    $block->setData($data);
  }

}
