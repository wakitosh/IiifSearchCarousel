<?php

namespace IiifSearchCarousel\Job;

use Omeka\Job\AbstractJob;
use Laminas\Http\Client as HttpClient;

/**
 * Job to rebuild the image pool for the IIIF Search Carousel.
 *
 * Fetches IIIF manifests, selects canvases according to rules, and stores
 * resulting Image API URLs and metadata into the iiif_sc_images table.
 */
class RebuildImagesJob extends AbstractJob {

  /**
   * Execute the job: rebuild images table from configured manifests.
   */
  public function perform(): void {
    $services = $this->getServiceLocator();
    $settings = $services->get('Omeka\Settings');
    $connection = $services->get('Omeka\Connection');
    $logger = $services->get('Omeka\Logger');

    $number = (int) ($settings->get('iiif_sc.number_of_images') ?? 5);
    $size = (int) ($settings->get('iiif_sc.image_size') ?? 1600);
    $rules = (string) ($settings->get('iiif_sc.selection_rules') ?? "1 => 1\n2 => 2\n3+ => random(2-last-1)");
    $manifests = array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($settings->get('iiif_sc.manifest_urls') ?? ''))));
    if (!$manifests) {
      $logger->warn('No manifest URLs configured.');
      return;
    }

    // Randomize manifests and pick up to $number canvases across them.
    shuffle($manifests);

    $connection->executeStatement('TRUNCATE TABLE iiif_sc_images');

    $http = new HttpClient();
    $http->setOptions(['timeout' => 20]);

    $collected = [];
    foreach ($manifests as $mu) {
      if (count($collected) >= $number) {
        break;
      }
      try {
        $http->setUri($mu);
        $res = $http->send();
        if (!$res->isSuccess()) {
          continue;
        }
        $json = json_decode($res->getBody(), TRUE);
        if (!is_array($json)) {
          continue;
        }

        // IIIF v3 or v2 canvases.
        $canvases = [];
        if (!empty($json['items'])) {
          // v3.
          $canvases = $json['items'];
        }
        elseif (!empty($json['sequences'][0]['canvases'])) {
          // v2.
          $canvases = $json['sequences'][0]['canvases'];
        }
        else {
          continue;
        }

        $selected = $this->pickCanvasByRules($canvases, $rules);
        if (!$selected) {
          continue;
        }

        // Build Image API URL.
        $imageUrl = $this->buildImageUrl($selected, $size);
        if (!$imageUrl) {
          continue;
        }

        // Related URL (prefer canvas-level, fallback to manifest-level).
        $related = $this->extractRelatedUrl($json, $selected);
        if (!$related) {
          // Final fallback to the manifest URL.
          $related = $mu;
        }

        // Label.
        $label = $this->extractLabel($json['label'] ?? NULL);

        $collected[] = [
          'image_url' => $imageUrl,
          'manifest_url' => $mu,
          'related_url' => $related,
          'label' => $label,
        ];
      }
      catch (\Throwable $e) {
        $logger->warn('Manifest fetch failed: ' . $mu . ' :: ' . $e->getMessage());
        continue;
      }
    }

    // Insert to DB with positions.
    $pos = 0;
    foreach ($collected as $row) {
      $connection->insert('iiif_sc_images', [
        'image_url' => $row['image_url'],
        'manifest_url' => $row['manifest_url'],
        'related_url' => $row['related_url'],
        'label' => $row['label'],
        'position' => $pos++,
        'created' => (new \DateTime('now'))->format('Y-m-d H:i:s'),
      ]);
    }
  }

  /**
   * Extract a human-friendly label from IIIF v2/v3 structures.
   */
  private function extractLabel($label): ?string {
    if (is_string($label)) {
      return $label;
    }
    if (is_array($label)) {
      // v3 language map.
      foreach (['ja', 'en', 'none'] as $lang) {
        if (!empty($label[$lang][0])) {
          return (string) $label[$lang][0];
        }
      }
      // v2.
      if (!empty($label['@value'])) {
        return (string) $label['@value'];
      }
    }
    return NULL;
  }

  /**
   * Build a IIIF Image API URL for a canvas (v2 or v3), at a given size.
   */
  private function buildImageUrl(array $canvas, int $size): ?string {
    // v3.
    if (!empty($canvas['items'][0]['items'][0]['body'])) {
      $body = $canvas['items'][0]['items'][0]['body'];
      if (isset($body['service'])) {
        $svc = $body['service'];
      }
      elseif (!empty($body['service'][0])) {
        $svc = $body['service'][0];
      }
      if (!empty($svc['type']) && strpos($svc['type'], 'ImageService3') !== FALSE && !empty($svc['id'])) {
        return rtrim($svc['id'], '/') . '/full/' . $size . ',/0/default.jpg';
      }
    }
    // v2.
    if (!empty($canvas['images'][0]['resource'])) {
      $res = $canvas['images'][0]['resource'];
      if (!empty($res['service']['@id'])) {
        return rtrim($res['service']['@id'], '/') . '/full/' . $size . ',/0/default.jpg';
      }
    }
    return NULL;
  }

  /**
   * Extract a best-effort related URL for a canvas.
   *
   * Preference order:
   *  - v3 canvas: homepage[0].id, seeAlso[0].id, id (canvas URI)
   *  - v2 canvas: related['@id']
   *  - manifest-level: homepage[0].id, related['@id']
   */
  private function extractRelatedUrl(array $manifest, array $canvas): ?string {
    // Try to derive Omeka media id from v3 body/service.
    if (!empty($canvas['items'][0]['items'][0]['body'])) {
      $body = $canvas['items'][0]['items'][0]['body'];
      $svc = NULL;
      if (isset($body['service'])) {
        $svc = $body['service'];
      }
      elseif (!empty($body['service'][0])) {
        $svc = $body['service'][0];
      }
      $candidates = [];
      if (is_array($svc) && !empty($svc['id']) && is_string($svc['id'])) {
        $candidates[] = (string) $svc['id'];
      }
      if (!empty($body['id']) && is_string($body['id'])) {
        $candidates[] = (string) $body['id'];
      }
      foreach ($candidates as $cand) {
        // Omeka media route.
        if (preg_match('#/media/(\d+)(?:/|$)#', $cand, $m)) {
          return 'omeka:media:' . $m[1];
        }
        // Omeka IIIF Server service id like
        // /iiif/3/{mediaId} or /iiif/2/{mediaId}.
        if (preg_match('#/(?:iiif|iiif-img)/(?:2|3)/(\d+)(?:/|$)#', $cand, $m)) {
          return 'omeka:media:' . $m[1];
        }
      }
    }
    // Try to derive Omeka media id from v2 image resource service.
    if (!empty($canvas['images'][0]['resource'])) {
      $res = $canvas['images'][0]['resource'];
      $ids = [];
      if (!empty($res['service']['@id']) && is_string($res['service']['@id'])) {
        $ids[] = (string) $res['service']['@id'];
      }
      if (!empty($res['@id']) && is_string($res['@id'])) {
        $ids[] = (string) $res['@id'];
      }
      foreach ($ids as $cand) {
        if (preg_match('#/media/(\d+)(?:/|$)#', $cand, $m)) {
          return 'omeka:media:' . $m[1];
        }
        if (preg_match('#/(?:iiif|iiif-img)/(?:2|3)/(\d+)(?:/|$)#', $cand, $m)) {
          return 'omeka:media:' . $m[1];
        }
      }
    }
    // v3 canvas-level links.
    if (!empty($canvas['homepage'][0]['id'])) {
      return (string) $canvas['homepage'][0]['id'];
    }
    if (!empty($canvas['seeAlso'][0]['id'])) {
      return (string) $canvas['seeAlso'][0]['id'];
    }
    if (!empty($canvas['id']) && is_string($canvas['id'])) {
      return (string) $canvas['id'];
    }
    // v2 canvas-level.
    if (!empty($canvas['related']['@id'])) {
      return (string) $canvas['related']['@id'];
    }
    // Try to derive Omeka item id from manifest id (v3) or @id (v2).
    $manIds = [];
    if (!empty($manifest['id']) && is_string($manifest['id'])) {
      $manIds[] = (string) $manifest['id'];
    }
    if (!empty($manifest['@id']) && is_string($manifest['@id'])) {
      $manIds[] = (string) $manifest['@id'];
    }
    foreach ($manIds as $mid) {
      if (preg_match('#/item/(\d+)/manifest(?:\.json)?$#', $mid, $m)) {
        return 'omeka:item:' . $m[1];
      }
    }
    // manifest-level fallback.
    if (!empty($manifest['homepage'][0]['id'])) {
      return (string) $manifest['homepage'][0]['id'];
    }
    if (!empty($manifest['related']['@id'])) {
      return (string) $manifest['related']['@id'];
    }
    return NULL;
  }

  /**
   * Pick a canvas from a list based on simple rule expressions.
   *
   * Supported conditions: "N", "A-B", "N+"; actions: "last", "random",
   * "random(A-B)", "random(A-last[-O])", or a 1-based index number.
   */
  private function pickCanvasByRules(array $canvases, string $rules): ?array {
    $n = count($canvases);
    $lines = preg_split('/\r?\n/', $rules);
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || !str_contains($line, '=>')) {
        continue;
      }
      [$cond, $act] = array_map('trim', explode('=>', $line, 2));
      // Parse condition.
      $matched = FALSE;
      if (preg_match('/^(\d+)\+$/', $cond, $m)) {
        $matched = $n >= (int) $m[1];
      }
      // Range condition like "A-B" with various hyphen chars.
      elseif (preg_match('/^(\d+)\s*[-−–—－]\s*(\d+)$/u', $cond, $m)) {
        $matched = $n >= (int) $m[1] && $n <= (int) $m[2];
      }
      elseif (preg_match('/^\d+$/', $cond)) {
        $matched = $n === (int) $cond;
      }
      if (!$matched) {
        continue;
      }

      // Action.
      $index = NULL;
      if ($act === 'last') {
        $index = $n - 1;
      }
      elseif ($act === 'random') {
        $index = random_int(0, $n - 1);
      }
      // random(A-B) with various hyphen chars and spaces.
      elseif (preg_match('/^random\(\s*(\d+)\s*[-−–—－]\s*(\d+)\s*\)$/iu', $act, $m)) {
        $min = max(1, (int) $m[1]);
        $max = min($n, (int) $m[2]);
        if ($min <= $max) {
          $index = random_int($min - 1, $max - 1);
        }
      }
      // random(A-last[-O]) with various hyphen chars and spaces.
      elseif (preg_match('/^random\(\s*(\d+)\s*[-−–—－]\s*last(?:\s*[-−–—－]\s*(\d+))?\s*\)$/iu', $act, $m)) {
        $start = max(1, (int) $m[1]);
        $offset = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $max = $n - $offset;
        if ($start <= $max) {
          $index = random_int($start - 1, $max - 1);
        }
      }
      elseif (preg_match('/^\d+$/', $act)) {
        $val = (int) $act;
        if ($val >= 1 && $val <= $n) {
          $index = $val - 1;
        }
      }

      if ($index !== NULL) {
        return $canvases[$index];
      }
    }

    // Fallback.
    if ($n > 0) {
      return $canvases[random_int(0, $n - 1)];
    }
    return NULL;
  }

}
