<?php

namespace IiifSearchCarousel\Form;

use Laminas\Form\Element\Checkbox;
use Laminas\Form\Element\Number;
use Laminas\Form\Element\Select;
use Laminas\Form\Element\Textarea;
use Laminas\Form\Form;
use Laminas\I18n\Translator\TranslatorInterface;

/**
 * Settings form for IIIF Search Carousel.
 */
class SettingsForm extends Form {
  /**
   * Translator service.
   *
   * @var \Laminas\I18n\Translator\TranslatorInterface
   */
  private TranslatorInterface $translator;

  /**
   * Text domain used for translations.
   *
   * @var string
   */
  private string $textDomain = 'iiif-search-carousel';

  public function __construct(TranslatorInterface $translator) {
    // Note: Csrf validator's session container name only allows [A-Za-z0-9_\\].
    // Avoid hyphens in the form name to prevent InvalidArgumentException.
    parent::__construct('iiif_sc_settings');
    $this->translator = $translator;
  }

  /**
   * {@inheritDoc} */
  public function init(): void {
    // Translate helper closure.
    $tr = function (string $s): string {
      return (string) $this->translator->translate($s, $this->textDomain);
    };

    // Help text for Selection rules (HTML).
    // Translate key sentences; keep code examples literal.
    $rulesHelp = sprintf(
      '<p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul><p>%s</p>' .
        '<pre><code>1 =&gt; 1
2 =&gt; 2
3+ =&gt; random(2-last-1)</code></pre>',
      $tr('Format: one rule per line: <code>CONDITION =&gt; ACTION</code>'),
      $tr('CONDITION: <code>N</code> exact; <code>A-B</code> range; <code>N+</code> N or more.'),
      $tr('ACTION: <code>last</code>, <code>random</code>, <code>random(A-B)</code>, <code>random(A-last[-O])</code>, or number (1-based).'),
      $tr('First matching rule is applied; otherwise a random canvas is chosen.'),
      $tr('Examples:')
    );
    // Sources & rules.
    $this->add([
      'name' => 'manifest_urls',
      'type' => Textarea::class,
      'options' => [
        'label' => $tr('Manifest URLs (one per line)'),
      ],
      'attributes' => [
        'rows' => 8,
      ],
    ]);

    $this->add([
      'name' => 'selection_rules',
      'type' => Textarea::class,
      'options' => [
        'label' => $tr('Selection rules'),
        'info' => $rulesHelp,
        // Allow raw HTML in info (Omeka form-row checks 'escape_info').
        'escape_info' => FALSE,
      ],
      'attributes' => ['rows' => 5, 'placeholder' => "1 => 1\n2 => 2\n3+ => random(2-last-1)"],
    ]);

    // Pool sizing.
    $this->add([
      'name' => 'number_of_images',
      'type' => Number::class,
      'options' => ['label' => $tr('Number of images')],
      'attributes' => ['min' => 1, 'step' => 1, 'required' => TRUE],
    ]);

    $this->add([
      'name' => 'image_size',
      'type' => Number::class,
      'options' => ['label' => $tr('IIIF image size (px)')],
      'attributes' => ['min' => 200, 'step' => 10, 'required' => TRUE],
    ]);

    // Carousel behavior and appearance.
    $this->add([
      'name' => 'carousel_duration',
      'type' => Number::class,
      'options' => ['label' => $tr('Carousel duration (sec)')],
      'attributes' => ['min' => 1, 'step' => 1, 'required' => TRUE],
    ]);

    $this->add([
      'name' => 'aspect_ratio_mode',
      'type' => Select::class,
      'options' => [
        'label' => $tr('Aspect ratio'),
        'value_options' => [
          '1:1' => '1:1',
          '4:3' => '4:3',
          '16:9' => '16:9',
          'custom' => $tr('Custom'),
        ],
      ],
      'attributes' => ['required' => TRUE],
    ]);

    $this->add([
      'name' => 'aspect_ratio_w',
      'type' => Number::class,
      'options' => ['label' => $tr('Custom width')],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_h',
      'type' => Number::class,
      'options' => ['label' => $tr('Custom height')],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    // Responsive aspect ratios.
    $this->add([
      'name' => 'aspect_ratio_breakpoint_sm',
      'type' => Number::class,
      'options' => ['label' => $tr('Breakpoint (small, max-width px)')],
      'attributes' => ['min' => 320, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_mode_sm',
      'type' => Select::class,
      'options' => [
        'label' => $tr('Aspect ratio (small screens)'),
        'value_options' => [
          'inherit' => $tr('Inherit (use default)'),
          '1:1' => '1:1',
          '4:3' => '4:3',
          '16:9' => '16:9',
          'custom' => $tr('Custom'),
        ],
      ],
    ]);

    $this->add([
      'name' => 'aspect_ratio_w_sm',
      'type' => Number::class,
      'options' => ['label' => $tr('Custom width (small)')],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_h_sm',
      'type' => Number::class,
      'options' => ['label' => $tr('Custom height (small)')],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_breakpoint_md',
      'type' => Number::class,
      'options' => ['label' => $tr('Breakpoint (medium, max-width px)')],
      'attributes' => ['min' => 480, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_mode_md',
      'type' => Select::class,
      'options' => [
        'label' => $tr('Aspect ratio (medium screens)'),
        'value_options' => [
          'inherit' => $tr('Inherit (use default)'),
          '1:1' => '1:1',
          '4:3' => '4:3',
          '16:9' => '16:9',
          'custom' => $tr('Custom'),
        ],
      ],
    ]);

    $this->add([
      'name' => 'aspect_ratio_w_md',
      'type' => Number::class,
      'options' => ['label' => $tr('Custom width (medium)')],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_h_md',
      'type' => Number::class,
      'options' => ['label' => $tr('Custom height (medium)')],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    // Titles.
    $this->add([
      'name' => 'truncate_title_length',
      'type' => Number::class,
      'options' => [
        'label' => $tr('Max title length'),
        'info' => $tr('Truncate long link titles (admin preview and front captions). 0 = no truncation.'),
      ],
      'attributes' => ['min' => 0, 'step' => 1],
    ]);

    // Auto rebuild (poor-man's cron on visit)
    $this->add([
      'name' => 'auto_rebuild_enable',
      'type' => Checkbox::class,
      'options' => ['label' => $tr('Auto rebuild images periodically (on visit)')],
      'attributes' => [],
    ]);
    $this->add([
      'name' => 'auto_rebuild_interval',
      'type' => Number::class,
      'options' => ['label' => $tr('Auto rebuild interval (minutes)')],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    // Note: Rebuild is triggered automatically on save; no separate submit.
  }

}
