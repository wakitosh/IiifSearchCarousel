<?php

namespace IiifSearchCarousel\Form;

use Laminas\Form\Element\Checkbox;
use Laminas\Form\Element\Number;
use Laminas\Form\Element\Select;
use Laminas\Form\Element\Submit;
use Laminas\Form\Element\Text;
use Laminas\Form\Element\Textarea;
use Laminas\Form\Form;

/**
 * Settings form for IIIF Search Carousel.
 */
class SettingsForm extends Form {

  /**
   * {@inheritDoc} */
  public function init(): void {
    // Determine locale (ja vs others). Avoid ext-intl hard dependency.
    $locale = 'en';
    if (class_exists('Locale')) {
      $locale = (string) \Locale::getDefault();
    }
    elseif (function_exists('locale_get_default')) {
      // Polyfill function name.
      $locale = (string) locale_get_default();
    }
    $isJa = (strpos(strtolower($locale), 'ja') === 0);

    // Help text for Selection rules (HTML with line breaks preserved).
    $rulesHelp = $isJa
      ? <<<'HTML'
<p>形式: 1行に1ルール <code>条件 =&gt; 動作</code></p>
<ul>
<li>条件: <code>N</code>（ちょうどN）、<code>A-B</code>（範囲）、<code>N+</code>（N以上）。</li>
<li>動作: <code>last</code>、<code>random</code>、<code>random(A-B)</code>、<code>random(A-last[-O])</code>、または番号（1始まり）。</li>
<li>最初に一致したルールが適用され、それ以外はランダムに選ばれます。</li>
  </ul>
<p>例:</p>
<pre><code>1 =&gt; 1
2 =&gt; 2
3+ =&gt; random(2-last-1)</code></pre>
HTML
      : <<<'HTML'
<p>Format: one rule per line: <code>CONDITION =&gt; ACTION</code></p>
<ul>
<li>CONDITION: <code>N</code> exact; <code>A-B</code> range; <code>N+</code> N or more.</li>
<li>ACTION: <code>last</code>, <code>random</code>, <code>random(A-B)</code>, <code>random(A-last[-O])</code>, or number (1-based).</li>
<li>First matching rule is applied; otherwise a random canvas is chosen.</li>
</ul>
<p>Examples:</p>
<pre><code>1 =&gt; 1
2 =&gt; 2
3+ =&gt; random(2-last-1)</code></pre>
HTML;
    // Sources & rules.
    $this->add([
      'name' => 'manifest_urls',
      'type' => Textarea::class,
      'options' => ['label' => $isJa ? 'マニフェストURL（1行に1件）' : 'Manifest URLs (one per line)'],
      'attributes' => ['rows' => 8],
    ]);

    $this->add([
      'name' => 'selection_rules',
      'type' => Textarea::class,
      'options' => [
        'label' => $isJa ? '抽出ルール' : 'Selection rules',
        'info' => $rulesHelp,
        // Allow raw HTML in info (Omeka form-row checks 'escape_info').
        'escape_info' => FALSE,
      ],
      'attributes' => ['rows' => 5, 'placeholder' => "1 => 1\n2 => 2\n3+ => random(2-last-1)"],
    ]);

    // Pool sizing.
    $this->add([
      'name' => 'identifier_property',
      'type' => Text::class,
      'options' => [
        'label' => $isJa ? '識別子プロパティの語' : 'Identifier property term',
        'info' => $isJa ? 'IIIF識別子のセグメントをOmekaアイテムに解決するためのプロパティ語（既定 dcterms:identifier）。' : 'Property term used to resolve IIIF identifier segments to Omeka items (default dcterms:identifier).',
      ],
      'attributes' => [
        'placeholder' => 'dcterms:identifier',
      ],
    ]);
    $this->add([
      'name' => 'number_of_images',
      'type' => Number::class,
      'options' => ['label' => $isJa ? '画像の枚数' : 'Number of images'],
      'attributes' => ['min' => 1, 'step' => 1, 'required' => TRUE],
    ]);

    $this->add([
      'name' => 'image_size',
      'type' => Number::class,
      'options' => ['label' => $isJa ? 'IIIF画像サイズ（px）' : 'IIIF image size (px)'],
      'attributes' => ['min' => 200, 'step' => 10, 'required' => TRUE],
    ]);

    // Carousel behavior and appearance.
    $this->add([
      'name' => 'carousel_duration',
      'type' => Number::class,
      'options' => ['label' => $isJa ? '切替間隔（秒）' : 'Carousel duration (sec)'],
      'attributes' => ['min' => 1, 'step' => 1, 'required' => TRUE],
    ]);

    $this->add([
      'name' => 'aspect_ratio_mode',
      'type' => Select::class,
      'options' => [
        'label' => $isJa ? 'アスペクト比' : 'Aspect ratio',
        'value_options' => [
          '1:1' => '1:1',
          '4:3' => '4:3',
          '16:9' => '16:9',
          'custom' => $isJa ? 'カスタム' : 'Custom',
        ],
      ],
      'attributes' => ['required' => TRUE],
    ]);

    $this->add([
      'name' => 'aspect_ratio_w',
      'type' => Number::class,
      'options' => ['label' => $isJa ? 'カスタム幅' : 'Custom width'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_h',
      'type' => Number::class,
      'options' => ['label' => $isJa ? 'カスタム高' : 'Custom height'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    // Responsive aspect ratios.
    $this->add([
      'name' => 'aspect_ratio_breakpoint_sm',
      'type' => Number::class,
      'options' => ['label' => $isJa ? 'ブレークポイント（小: max-width px）' : 'Breakpoint (small, max-width px)'],
      'attributes' => ['min' => 320, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_mode_sm',
      'type' => Select::class,
      'options' => [
        'label' => $isJa ? 'アスペクト比（小画面）' : 'Aspect ratio (small screens)',
        'value_options' => [
          'inherit' => $isJa ? '継承（既定を使用）' : 'Inherit (use default)',
          '1:1' => '1:1',
          '4:3' => '4:3',
          '16:9' => '16:9',
          'custom' => $isJa ? 'カスタム' : 'Custom',
        ],
      ],
    ]);

    $this->add([
      'name' => 'aspect_ratio_w_sm',
      'type' => Number::class,
      'options' => ['label' => $isJa ? 'カスタム幅（小）' : 'Custom width (small)'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_h_sm',
      'type' => Number::class,
      'options' => ['label' => $isJa ? 'カスタム高（小）' : 'Custom height (small)'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_breakpoint_md',
      'type' => Number::class,
      'options' => ['label' => $isJa ? 'ブレークポイント（中: max-width px）' : 'Breakpoint (medium, max-width px)'],
      'attributes' => ['min' => 480, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_mode_md',
      'type' => Select::class,
      'options' => [
        'label' => $isJa ? 'アスペクト比（中画面）' : 'Aspect ratio (medium screens)',
        'value_options' => [
          'inherit' => $isJa ? '継承（既定を使用）' : 'Inherit (use default)',
          '1:1' => '1:1',
          '4:3' => '4:3',
          '16:9' => '16:9',
          'custom' => $isJa ? 'カスタム' : 'Custom',
        ],
      ],
    ]);

    $this->add([
      'name' => 'aspect_ratio_w_md',
      'type' => Number::class,
      'options' => ['label' => $isJa ? 'カスタム幅（中）' : 'Custom width (medium)'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_h_md',
      'type' => Number::class,
      'options' => ['label' => $isJa ? 'カスタム高（中）' : 'Custom height (medium)'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    // Titles.
    $this->add([
      'name' => 'truncate_title_length',
      'type' => Number::class,
      'options' => [
        'label' => $isJa ? 'タイトルの最大文字数' : 'Max title length',
        'info' => $isJa ? '長いリンクタイトル（管理プレビュー/フロントのキャプション）を省略します。0 は省略なし。' : 'Truncate long link titles (admin preview and front captions). 0 = no truncation.',
      ],
      'attributes' => ['min' => 0, 'step' => 1],
    ]);

    // Auto rebuild (poor-man's cron on visit)
    $this->add([
      'name' => 'auto_rebuild_enable',
      'type' => Checkbox::class,
      'options' => ['label' => $isJa ? '一定間隔で自動再生成（アクセス時）' : 'Auto rebuild images periodically (on visit)'],
      'attributes' => [],
    ]);
    $this->add([
      'name' => 'auto_rebuild_interval',
      'type' => Number::class,
      'options' => ['label' => $isJa ? '自動再生成の間隔（分）' : 'Auto rebuild interval (minutes)'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    // Submit.
    $this->add([
      'name' => 'rebuild_now',
      'type' => Submit::class,
      'attributes' => ['value' => $isJa ? '保存して今すぐ再生成' : 'Save & Rebuild now', 'class' => 'button'],
    ]);
  }

}
