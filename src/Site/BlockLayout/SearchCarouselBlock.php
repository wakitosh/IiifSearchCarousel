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
    try {
      $translator = $this->services->get('MvcTranslator');
      return (string) $translator->translate('IIIF Search Carousel', 'iiif-search-carousel');
    }
    catch (\Throwable $e) {
      return 'IIIF Search Carousel';
    }
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

    // Build example keywords from fulltext index with tokenization rules.
    // Rules: cut at brackets and punctuation,
    // split when character class changes
    // (Kanji/Hiragana/Katakana/Latin/Digit). Filter too-short tokens.
    // Additionally, exclude stopwords across ALL supported languages.
    // This is independent of the current site UI language, and uses
    // config/stopwords.json.
    $exampleTerms = [];
    try {
      // Detect fulltext_search backend engine to align minimum token length
      // with the actual searchable threshold (e.g., InnoDB default is 4,
      // Mroonga often allows 1-2 with n-gram/token filters).
      $latinMinLen = 4;
      $digitMinLen = 4;
      try {
        $row = $connection->fetchAssociative('SHOW TABLE STATUS LIKE :t', ['t' => 'fulltext_search']);
        $engine = isset($row['Engine']) ? (string) $row['Engine'] : '';
        if (strcasecmp($engine, 'Mroonga') === 0) {
          $latinMinLen = 2;
          $digitMinLen = 2;
        }
      }
      catch (\Throwable $e) {
        // Best-effort: keep defaults.
      }
      // Helper: classify a single UTF-8 character.
      $classify = function (string $ch): string {
        if ($ch === '') {
          return 'Other';
        }
        if (preg_match('/\p{Han}/u', $ch)) {
          return 'Han';
        }
        if (preg_match('/\p{Hiragana}/u', $ch)) {
          return 'Hira';
        }
        if (preg_match('/\p{Katakana}/u', $ch)) {
          return 'Kata';
        }
        if (preg_match('/[A-Za-z]/u', $ch)) {
          return 'Latin';
        }
        if (preg_match('/[0-9]/u', $ch)) {
          return 'Digit';
        }
        return preg_match('/\p{Nd}/u', $ch) ? 'Digit' : 'Other';
      };

      // Helper: return true if punctuation or separator (spaces etc.).
      $isSep = function (string $ch): bool {
        if ($ch === '') {
          return TRUE;
        }
        // Unicode punctuation or separators.
        $punct = '/[\p{P}\p{Z}]/u';
        if (preg_match($punct, $ch)) {
          return TRUE;
        }
        // Additional CJK brackets/marks.
        $marks = '/[（）\(\)［\]【】〈〉《》〔〕｛｝{}＜＞・、。„“‟„「」『』—–‐‑〜]/u';
        if (preg_match($marks, $ch)) {
          return TRUE;
        }
        return FALSE;
      };

      // Normalize a token for comparison with stopwords.
      // For Latin, use lowercase; for others, use verbatim.
      // Trim surrounding spaces.
      $normalizeToken = function (string $tok) use ($classify): string {
        $tok = trim($tok);
        if ($tok === '') {
          return '';
        }
        $head = function_exists('mb_substr')
          ? mb_substr($tok, 0, 1, 'UTF-8')
          : substr($tok, 0, 1);
        $cls = $classify($head);
        if ($cls === 'Latin') {
          if (function_exists('mb_strtolower')) {
            return mb_strtolower($tok, 'UTF-8');
          }
          return strtolower($tok);
        }
        return $tok;
      };

      // Load stopwords.json and build a merged set across all languages.
      static $stopwordSet = NULL;
      if ($stopwordSet === NULL) {
        $stopwordSet = [];
        $path = __DIR__ . '/../../../config/stopwords.json';
        try {
          if (is_readable($path)) {
            $json = file_get_contents($path);
            $data = json_decode((string) $json, TRUE);
            if (is_array($data)) {
              foreach ($data as $list) {
                if (!is_array($list)) {
                  continue;
                }
                foreach ($list as $w) {
                  if (!is_string($w)) {
                    continue;
                  }
                  $nw = $normalizeToken($w);
                  if ($nw !== '') {
                    $stopwordSet[$nw] = TRUE;
                  }
                }
              }
            }
          }
        }
        catch (\Throwable $e) {
          // If loading fails, just proceed with an empty set.
          $stopwordSet = [];
        }
      }
      $isStopword = function (string $tok) use ($normalizeToken, $stopwordSet): bool {
        $nw = $normalizeToken($tok);
        if ($nw === '') {
          // Treat empty as stopword-equivalent to ease filtering.
          return TRUE;
        }
        return isset($stopwordSet[$nw]);
      };

      // Extract tokens from a title according to the rules above.
      $extractTokens = function (string $title) use ($classify, $isSep, $isStopword, $latinMinLen, $digitMinLen): array {
        $title = trim($title);
        if ($title === '') {
          return [];
        }
        // Cut at first bracket entirely.
        if (preg_match('/[\(\)（）［\]【】〈〉《》〔〕｛｝{}＜＞]/u', $title, $m, PREG_OFFSET_CAPTURE)) {
          $pos = isset($m[0][1]) ? (int) $m[0][1] : 0;
          if ($pos > 0) {
            $title = function_exists('mb_substr') ? mb_substr($title, 0, $pos, 'UTF-8') : substr($title, 0, $pos);
          }
        }
        $len = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
        $tokens = [];
        $buf = '';
        $prevClass = '';
        for ($i = 0; $i < $len; $i++) {
          $ch = function_exists('mb_substr')
            ? mb_substr($title, $i, 1, 'UTF-8')
            : substr($title, $i, 1);
          if ($isSep($ch)) {
            if ($buf !== '') {
              $tokens[] = $buf;
              $buf = '';
              $prevClass = '';
            }
            continue;
          }
          $cls = $classify($ch);
          if ($buf === '') {
            $buf = $ch;
            $prevClass = $cls;
            continue;
          }
          if ($cls !== $prevClass) {
            // Split on character-class boundary.
            $tokens[] = $buf;
            $buf = $ch;
            $prevClass = $cls;
          }
          else {
            $buf .= $ch;
          }
        }
        if ($buf !== '') {
          $tokens[] = $buf;
        }
        // Filter tokens: drop too-short by class, then trim.
        $out = [];
        foreach ($tokens as $tok) {
          $tok = trim($tok);
          if ($tok === '') {
            continue;
          }
          $head = function_exists('mb_substr')
            ? mb_substr($tok, 0, 1, 'UTF-8')
            : substr($tok, 0, 1);
          $cls = $classify($head);
          $n = function_exists('mb_strlen') ? mb_strlen($tok, 'UTF-8') : strlen($tok);
          if (in_array($cls, ['Han', 'Hira', 'Kata'], TRUE)) {
            if ($n < 2) {
              // 1文字は弱い。
              continue;
            }
          }
          elseif ($cls === 'Latin') {
            if ($n < $latinMinLen) {
              // 1文字は除外（例: "N"）。
              continue;
            }
          }
          elseif ($cls === 'Digit') {
            if ($n < $digitMinLen) {
              continue;
            }
          }
          else {
            if ($n < 2) {
              continue;
            }
          }
          // Exclude stopwords (all languages).
          if ($isStopword($tok)) {
            continue;
          }
          $out[] = $tok;
        }
        return $out;
      };

      // Collect up to 60 random titles and extract tokens.
      $sql = "SELECT title FROM fulltext_search WHERE title IS NOT NULL AND title <> '' ORDER BY RAND() LIMIT 60";
      $titles = (array) $connection->fetchFirstColumn($sql);
      $pool = [];
      foreach ($titles as $t) {
        foreach ($extractTokens((string) $t) as $tok) {
          if (!in_array($tok, $pool, TRUE)) {
            $pool[] = $tok;
          }
          if (count($pool) >= 32) {
            // Keep pool small.
            break;
          }
        }
        if (count($pool) >= 32) {
          break;
        }
      }
      if ($pool) {
        // Locale/device-aware quotas:
        // - Split pool into CJK vs Latin (and neutral)
        // - Select according to UI locale and device class.
        $isCjkToken = function (string $tok) use ($classify): bool {
          if ($tok === '') {
            return FALSE;
          }
          $head = function_exists('mb_substr') ? mb_substr($tok, 0, 1, 'UTF-8') : substr($tok, 0, 1);
          $cls = $classify($head);
          return in_array($cls, ['Han', 'Hira', 'Kata'], TRUE);
        };
        $isLatinToken = function (string $tok) use ($classify): bool {
          if ($tok === '') {
            return FALSE;
          }
          $head = function_exists('mb_substr') ? mb_substr($tok, 0, 1, 'UTF-8') : substr($tok, 0, 1);
          return $classify($head) === 'Latin';
        };
        $isDigitOrOther = function (string $tok) use ($classify): bool {
          if ($tok === '') {
            return TRUE;
          }
          $head = function_exists('mb_substr') ? mb_substr($tok, 0, 1, 'UTF-8') : substr($tok, 0, 1);
          $cls = $classify($head);
          return in_array($cls, ['Digit', 'Other'], TRUE);
        };

        $poolCjk = [];
        $poolLat = [];
        $poolNeutral = [];
        foreach ($pool as $tok) {
          if ($isCjkToken($tok)) {
            $poolCjk[] = $tok;
          }
          elseif ($isLatinToken($tok)) {
            $poolLat[] = $tok;
          }
          elseif ($isDigitOrOther($tok)) {
            $poolNeutral[] = $tok;
          }
          else {
            $poolNeutral[] = $tok;
          }
        }
        // Randomize within groups for variety.
        if ($poolCjk) {
          shuffle($poolCjk);
        }
        if ($poolLat) {
          shuffle($poolLat);
        }
        if ($poolNeutral) {
          shuffle($poolNeutral);
        }

        // Determine UI locale (ja = CJK primary; else Latin primary).
        $uiLang = '';
        try {
          $uiLang = (string) $view->lang();
        }
        catch (\Throwable $e) {
          $uiLang = '';
        }
        $primaryGroup = (strpos($uiLang, 'ja') === 0) ? 'cjk' : 'latin';

        // Quotas for 5 outputs (desktop up to 5; tablet/mobile CSS limits):
        // - First three: 2 primary + 1 other.
        // - Remaining two: flexible; prefer primary (~3+1 or 4+1).
        // On mobile (CSS shows 3), users still see 2+1 first.
        $totalOut = 5;
        $quotaP = 3;
        $quotaO = 1;

        $pickFrom = function (array &$arr, int $n): array {
          $out = [];
          for ($i = 0; $i < $n && !empty($arr); $i++) {
            $out[] = array_shift($arr);
          }
          return $out;
        };

        // Prepare group refs based on primary.
        $primaryRef = ($primaryGroup === 'cjk') ? $poolCjk : $poolLat;
        $otherRef = ($primaryGroup === 'cjk') ? $poolLat : $poolCjk;

        // Clone arrays for consumption.
        $pri = $primaryRef;
        $oth = $otherRef;
        $neu = $poolNeutral;

        // Determine counts honoring availability.
        $wantP = min($quotaP, count($pri));
        $wantO = min($quotaO, count($oth));
        // If no other-language tokens exist, we cannot satisfy
        // retention; fill with primary.
        if ($wantO <= 0) {
          $wantP = min($quotaP + $quotaO, count($pri));
          $wantO = 0;
        }
        $ordered = [];
        if ($totalOut >= 1 && $wantP > 0) {
          $ordered[] = array_shift($pri);
          $wantP--;
        }
        if ($totalOut >= 2 && $wantO > 0) {
          $ordered[] = array_shift($oth);
          $wantO--;
        }
        if ($totalOut >= 3 && $wantP > 0) {
          $ordered[] = array_shift($pri);
          $wantP--;
        }
        if ($wantP > 0) {
          foreach ($pickFrom($pri, $wantP) as $t) {
            $ordered[] = $t;
          }
          $wantP = 0;
        }
        if ($wantO > 0) {
          foreach ($pickFrom($oth, $wantO) as $t) {
            $ordered[] = $t;
          }
          $wantO = 0;
        }
        for ($i = count($ordered); $i < $totalOut; $i++) {
          $added = FALSE;
          if (!empty($pri)) {
            $ordered[] = array_shift($pri);
            $added = TRUE;
          }
          elseif (!empty($oth)) {
            $ordered[] = array_shift($oth);
            $added = TRUE;
          }
          elseif (!empty($neu)) {
            $ordered[] = array_shift($neu);
            $added = TRUE;
          }
          if (!$added) {
            break;
          }
        }

        // Truncate to totalOut and ensure non-empty unique list.
        $ordered = array_values(array_unique($ordered));
        if (count($ordered) > $totalOut) {
          $ordered = array_slice($ordered, 0, $totalOut);
        }
        $exampleTerms = $ordered;
      }
      else {
        $exampleTerms = [];
      }
    }
    catch (\Throwable $e) {
      $exampleTerms = [];
    }

    $view->headScript()->appendFile($view->assetUrl('js/iiif-sc-carousel.js', 'IiifSearchCarousel'));
    // Load a minimal multi-search enhancer so the overlay search works
    // standalone even if the active theme does not provide its own script.
    $view->headScript()->appendFile($view->assetUrl('js/iiif-sc-multi-search.js', 'IiifSearchCarousel'));
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

    // Resolve example keyword parameters with fallback priority:
    // block override > module settings > hardcoded defaults.
    $blockCjk = $block->dataValue('cjk_max_len');
    if ($blockCjk !== NULL && $blockCjk !== '') {
      $cjkMaxLenEff = (int) $blockCjk;
    }
    else {
      $cjkMaxLenEff = (int) ($settings->get('iiif_sc.cjk_max_len') ?? 8);
    }
    if ($cjkMaxLenEff < 2) {
      $cjkMaxLenEff = 2;
    }
    if ($cjkMaxLenEff > 32) {
      $cjkMaxLenEff = 32;
    }

    // head_bias_decay 設定は廃止。内部固定値を使用.
    $headBiasDecayEff = 0.82;

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
      'exampleTerms' => $exampleTerms,
      // Example keyword parameters (effective values after fallback).
      'cjkMaxLen' => $cjkMaxLenEff,
      'headBiasDecay' => $headBiasDecayEff,
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
    $chk->setLabel($view->translate('Search targets', 'iiif-search-carousel'));
    $chk->setValueOptions([
      'items' => $view->translate('Items', 'iiif-search-carousel'),
      'media' => $view->translate('Media', 'iiif-search-carousel'),
      'item_sets' => $view->translate('Item sets', 'iiif-search-carousel'),
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
    $show->setLabel($view->translate('Show search box', 'iiif-search-carousel'));
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
    $el->setLabel($view->translate('Custom CSS (scoped)', 'iiif-search-carousel'));
    $el->setAttribute('rows', 6);
    // Place the hint directly under the textarea as info text.
    if ($block) {
      $hintId = (int) $block->id();
      $el->setOption('info', sprintf($view->translate('Use selector %s to scope your CSS to this block.', 'iiif-search-carousel'), '#iiif-sc-' . $hintId));
    }
    else {
      $el->setOption('info', sprintf($view->translate('After saving, this block will have a unique id like %s for scoping.', 'iiif-search-carousel'), '#iiif-sc-123'));
    }
    if ($block) {
      $el->setValue((string) $block->dataValue('custom_css', ''));
    }
    $form->add($el);
    // Trim controls (percentages per side).
    $top = new Number('o:block[__blockIndex__][o:data][trim_top]');
    $top->setLabel($view->translate('Trim top (%)', 'iiif-search-carousel'));
    $top->setAttributes(['min' => 0, 'max' => 100, 'step' => '0.1']);
    if ($block) {
      $top->setValue((string) ($block->dataValue('trim_top', 0)));
    }
    $form->add($top);

    $right = new Number('o:block[__blockIndex__][o:data][trim_right]');
    $right->setLabel($view->translate('Trim right (%)', 'iiif-search-carousel'));
    $right->setAttributes(['min' => 0, 'max' => 100, 'step' => '0.1']);
    if ($block) {
      $right->setValue((string) ($block->dataValue('trim_right', 0)));
    }
    $form->add($right);

    $bottom = new Number('o:block[__blockIndex__][o:data][trim_bottom]');
    $bottom->setLabel($view->translate('Trim bottom (%)', 'iiif-search-carousel'));
    $bottom->setAttributes(['min' => 0, 'max' => 100, 'step' => '0.1']);
    if ($block) {
      $bottom->setValue((string) ($block->dataValue('trim_bottom', 0)));
    }
    $form->add($bottom);

    $left = new Number('o:block[__blockIndex__][o:data][trim_left]');
    $left->setLabel($view->translate('Trim left (%)', 'iiif-search-carousel'));
    $left->setAttributes(['min' => 0, 'max' => 100, 'step' => '0.1']);
    if ($block) {
      $left->setValue((string) ($block->dataValue('trim_left', 0)));
    }
    $form->add($left);
    // Note: No fieldset/grouping; show fields directly as headings.
    // CJK maximum display length (graphemes)
    $cjkMax = new Number('o:block[__blockIndex__][o:data][cjk_max_len]');
    $cjkMax->setLabel($view->translate('CJKのキーワード最大表示長（グラフェム）', 'iiif-search-carousel'));
    $cjkMax->setAttributes(['min' => 2, 'max' => 32, 'step' => '1']);
    // Do not set a value by default to allow inheritance when left blank.
    // Show module default as a placeholder.
    try {
      $moduleDefaultCjk = (int) ($this->services->get('Omeka\Settings')->get('iiif_sc.cjk_max_len') ?? 8);
    }
    catch (\Throwable $e) {
      $moduleDefaultCjk = 8;
    }
    if ($block) {
      $existing = $block->dataValue('cjk_max_len');
      if ($existing !== NULL && $existing !== '') {
        $cjkMax->setValue((string) $existing);
      }
    }
    $cjkMax->setAttribute('placeholder', (string) $moduleDefaultCjk);
    $infoCjk = sprintf(
      $view->translate(
        '空欄の場合はモジュール設定を継承します（現在の既定: %s）。',
        'iiif-search-carousel'
      ),
      (string) $moduleDefaultCjk
    );
    $cjkMax->setOption('info', $infoCjk);
    $form->add($cjkMax);

    // Head-biased selection decay (0.5–0.99). head_bias_decay のフォーム項目は削除.
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
      // Convert internal tokens or known API URLs into site public URLs.
      // Ensure we never return an id-less /item/show or /media/show link.
      $makeItem = function (int $id) use ($view, $siteSlug) {
        if ($id <= 0) {
          return NULL;
        }
        // CleanUrl対応の resource-id ルートを使用（CleanUrlが有効なら識別子URLに変換).
        $url = $view->url('site/resource-id', [
          'site-slug' => $siteSlug,
          'controller' => 'item',
          'id' => $id,
        ]);
        return $url;
      };
      $makeMedia = function (int $id) use ($view, $siteSlug) {
        if ($id <= 0) {
          return NULL;
        }
        $url = $view->url('site/resource-id', [
          'site-slug' => $siteSlug,
          'controller' => 'media',
          'id' => $id,
        ]);
        return $url;
      };
      if (!is_string($related) || $related === '') {
        return NULL;
      }
      $related = trim($related);
      if (preg_match('/^omeka:item:(\d+)$/', $related, $m)) {
        return $makeItem((int) $m[1]);
      }
      if (preg_match('/^omeka:media:(\d+)$/', $related, $m)) {
        return $makeMedia((int) $m[1]);
      }
      if (preg_match('#/api/items/(\d+)(?:$|[/?])#', $related, $m)) {
        return $makeItem((int) $m[1]);
      }
      // Pretty item or media paths (allow site slug prefix)
      if (preg_match('#/(?:s/[^/]+/)?item/(\d+)(?:$|[/?])#', $related, $m)) {
        return $makeItem((int) $m[1]);
      }
      if (preg_match('#/(?:s/[^/]+/)?media/(\d+)(?:$|[/?])#', $related, $m)) {
        return $makeMedia((int) $m[1]);
      }
      // Reject unsafe id-less show pages.
      if (preg_match('#/(?:s/[^/]+/)?(?:item|media)/show(?:[?#].*)?$#', $related)) {
        return NULL;
      }
      return $related;
    };

    $html .= "\n<fieldset class=\"field\">\n  <legend>" . $esc($view->translate('Current selection (max 50)', 'iiif-search-carousel')) . "</legend>\n  <div class=\"value\">";
    if ($rows) {
      $html .= "\n    <table class=\"tablesaw tablesaw-stack\">\n      <thead>\n        <tr>\n          <th>" . $esc($view->translate('Manifest title', 'iiif-search-carousel')) . "</th>\n          <th>" . $esc($view->translate('Image link', 'iiif-search-carousel')) . "</th>\n          <th>" . $esc($view->translate('Manifest', 'iiif-search-carousel')) . "</th>\n          <th>" . $esc($view->translate('Page', 'iiif-search-carousel')) . "</th>\n        </tr>\n      </thead>\n      <tbody>";
      foreach ($rows as $r) {
        $label = $r['label'] ?? '';
        $labelShort = $truncateTitle((string) $label);
        $img = $r['image_url'] ?? '';
        $man = $r['manifest_url'] ?? '';
        $rel = $r['related_url'] ?? '';
        $relHref = $buildResourceHref($rel);
        $html .= "\n        <tr>"
          . '<td' . ($labelShort !== $label ? ' title="' . $esc($label) . '"' : '') . '>' . $esc($labelShort) . '</td>'
          . '<td>' . ($img ? '<a href="' . $esc($img) . '" target="_blank" rel="noopener">' . $esc($view->translate('Image', 'iiif-search-carousel')) . '</a>' : '') . '</td>'
          . '<td>' . ($man ? '<a href="' . $esc($man) . '" target="_blank" rel="noopener">' . $esc($view->translate('manifest', 'iiif-search-carousel')) . '</a>' : '') . '</td>'
          . '<td>' . ($relHref ? '<a href="' . $esc($relHref) . '" target="_blank" rel="noopener">' . $esc($view->translate('page', 'iiif-search-carousel')) . '</a>' : '') . '</td>'
        . '</tr>';
      }
      $html .= "\n      </tbody>\n    </table>";
    }
    else {
      $html .= "\n    <p>" . $esc($view->translate('No images selected yet. Register manifests on the settings page and run rebuild.', 'iiif-search-carousel')) . "</p>";
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

    // Normalize CJK maximum display length (int, 2..32) only if provided.
    if (array_key_exists('cjk_max_len', $data) && $data['cjk_max_len'] !== '' && $data['cjk_max_len'] !== NULL) {
      $v = (int) $data['cjk_max_len'];
      if ($v < 2) {
        $v = 2;
      }
      if ($v > 32) {
        $v = 32;
      }
      $data['cjk_max_len'] = $v;
    }
    else {
      // Unset to inherit module default at render time.
      unset($data['cjk_max_len']);
    }

    // head_bias_decay は廃止。何か残っていたら無視するためunset.
    unset($data['head_bias_decay']);

    $block->setData($data);
  }

}
