<?php

namespace IiifSearchCarousel\Form;

use Laminas\Form\Element\Number;
use Laminas\Form\Element\Select;
use Laminas\Form\Element\Textarea;
use Laminas\Form\Element\Submit;
use Laminas\Form\Element\Text;
use Laminas\Form\Form;
use Laminas\Form\Element\Checkbox;

/**
 * Settings form for IIIF Search Carousel.
 */
class SettingsForm extends Form {

  /**
   * {@inheritDoc} */
  public function init(): void {
    // Help text for Selection rules (HTML with line breaks preserved).
    $rulesHelp = <<<'HTML'
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
      'options' => ['label' => 'Manifest URLs (one per line)'],
      'attributes' => ['rows' => 8],
    ]);

    $this->add([
      'name' => 'selection_rules',
      'type' => Textarea::class,
      'options' => [
        'label' => 'Selection rules',
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
        'label' => 'Identifier property term',
        'info' => 'Property term used to resolve IIIF identifier segments to Omeka items (default dcterms:identifier).',
      ],
      'attributes' => [
        'placeholder' => 'dcterms:identifier',
      ],
    ]);
    $this->add([
      'name' => 'number_of_images',
      'type' => Number::class,
      'options' => ['label' => 'Number of images'],
      'attributes' => ['min' => 1, 'step' => 1, 'required' => TRUE],
    ]);

    $this->add([
      'name' => 'image_size',
      'type' => Number::class,
      'options' => ['label' => 'IIIF image size (px)'],
      'attributes' => ['min' => 200, 'step' => 10, 'required' => TRUE],
    ]);

    // Carousel behavior and appearance.
    $this->add([
      'name' => 'carousel_duration',
      'type' => Number::class,
      'options' => ['label' => 'Carousel duration (sec)'],
      'attributes' => ['min' => 1, 'step' => 1, 'required' => TRUE],
    ]);

    $this->add([
      'name' => 'aspect_ratio_mode',
      'type' => Select::class,
      'options' => [
        'label' => 'Aspect ratio',
        'value_options' => [
          '1:1' => '1:1',
          '4:3' => '4:3',
          '16:9' => '16:9',
          'custom' => 'Custom',
        ],
      ],
      'attributes' => ['required' => TRUE],
    ]);

    $this->add([
      'name' => 'aspect_ratio_w',
      'type' => Number::class,
      'options' => ['label' => 'Custom width'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_h',
      'type' => Number::class,
      'options' => ['label' => 'Custom height'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    // Responsive aspect ratios.
    $this->add([
      'name' => 'aspect_ratio_breakpoint_sm',
      'type' => Number::class,
      'options' => ['label' => 'Breakpoint (small, max-width px)'],
      'attributes' => ['min' => 320, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_mode_sm',
      'type' => Select::class,
      'options' => [
        'label' => 'Aspect ratio (small screens)',
        'value_options' => [
          'inherit' => 'Inherit (use default)',
          '1:1' => '1:1',
          '4:3' => '4:3',
          '16:9' => '16:9',
          'custom' => 'Custom',
        ],
      ],
    ]);

    $this->add([
      'name' => 'aspect_ratio_w_sm',
      'type' => Number::class,
      'options' => ['label' => 'Custom width (small)'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_h_sm',
      'type' => Number::class,
      'options' => ['label' => 'Custom height (small)'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_breakpoint_md',
      'type' => Number::class,
      'options' => ['label' => 'Breakpoint (medium, max-width px)'],
      'attributes' => ['min' => 480, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_mode_md',
      'type' => Select::class,
      'options' => [
        'label' => 'Aspect ratio (medium screens)',
        'value_options' => [
          'inherit' => 'Inherit (use default)',
          '1:1' => '1:1',
          '4:3' => '4:3',
          '16:9' => '16:9',
          'custom' => 'Custom',
        ],
      ],
    ]);

    $this->add([
      'name' => 'aspect_ratio_w_md',
      'type' => Number::class,
      'options' => ['label' => 'Custom width (medium)'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    $this->add([
      'name' => 'aspect_ratio_h_md',
      'type' => Number::class,
      'options' => ['label' => 'Custom height (medium)'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    // Titles.
    $this->add([
      'name' => 'truncate_title_length',
      'type' => Number::class,
      'options' => [
        'label' => 'Max title length',
        'info' => 'Truncate long link titles (admin preview and front captions). 0 = no truncation.',
      ],
      'attributes' => ['min' => 0, 'step' => 1],
    ]);

    // Auto rebuild (poor-man's cron on visit)
    $this->add([
      'name' => 'auto_rebuild_enable',
      'type' => Checkbox::class,
      'options' => ['label' => 'Auto rebuild images periodically (on visit)'],
      'attributes' => [],
    ]);
    $this->add([
      'name' => 'auto_rebuild_interval',
      'type' => Number::class,
      'options' => ['label' => 'Auto rebuild interval (minutes)'],
      'attributes' => ['min' => 1, 'step' => 1],
    ]);

    // Submit.
    $this->add([
      'name' => 'rebuild_now',
      'type' => Submit::class,
      'attributes' => ['value' => 'Save & Rebuild now', 'class' => 'button'],
    ]);
  }

}
