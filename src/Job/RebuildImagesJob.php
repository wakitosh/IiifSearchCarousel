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
   * Property id used to resolve items from IIIF identifiers.
   *
   * Defaults to 10 (dcterms:identifier) in a standard install when
   * CleanUrl is not configured.
   */
  private int $identifierPropertyId = 10;

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
    // Auto-detect identifier property id (CleanUrl), fallback to 10.
    $this->identifierPropertyId = $this->getCleanUrlItemPropertyId();
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

        // Get width and height from the canvas info.json and use the
        // smallest of width, height and the configured size as the
        // IIIF image size.
        $canvasServiceId = NULL;
        // v3 body.service can be an object or an array; try to reuse the
        // same heuristics as buildImageUrl to find a usable service id.
        if (!empty($selected['items'][0]['items'][0]['body'])) {
          $body = $selected['items'][0]['items'][0]['body'];
          $svc = NULL;
          if (isset($body['service'])) {
            $s = $body['service'];
            if (is_array($s)) {
              if (array_keys($s) !== range(0, count($s) - 1)) {
                $svc = $s;
              }
              elseif (!empty($s[0]) && is_array($s[0])) {
                $svc = $s[0];
              }
            }
          }
          if ($svc === NULL && isset($body['services']) && is_array($body['services']) && !empty($body['services'][0])) {
            $svc = $body['services'][0];
          }
          if (is_array($svc)) {
            $canvasServiceId = $svc['id'] ?? ($svc['@id'] ?? NULL);
          }
          if (!$canvasServiceId && !empty($body['id']) && is_string($body['id'])) {
            $canvasServiceId = $body['id'];
          }
        }
        // v2 fallback: images[0].resource.service.@id.
        if (!$canvasServiceId && !empty($selected['images'][0]['resource']['service']['@id'])) {
          $canvasServiceId = $selected['images'][0]['resource']['service']['@id'];
        }
        // As a last resort, try v2 thumbnail @id.
        if (!$canvasServiceId && !empty($selected['thumbnail']['@id'])) {
          $canvasServiceId = $selected['thumbnail']['@id'];
        }
        $canvasWidth = NULL;
        $canvasHeight = NULL;
        if ($canvasServiceId) {
          try {
            $http->setUri(rtrim($canvasServiceId, '/') . "/info.json");
            $infoRes = $http->send();
            if ($infoRes->isSuccess()) {
              $infoJson = json_decode($infoRes->getBody(), TRUE);
              // Prefer top-level width/height when present (original image
              // dimensions). If missing, try to derive max available width
              // and height from the sizes[] list (common in some IIIF
              // servers).
              if (!empty($infoJson['width'])) {
                $canvasWidth = (int) $infoJson['width'];
              }
              if (!empty($infoJson['height'])) {
                $canvasHeight = (int) $infoJson['height'];
              }
              if ((empty($canvasWidth) || empty($canvasHeight)) && !empty($infoJson['sizes']) && is_array($infoJson['sizes'])) {
                $maxW = 0;
                $maxH = 0;
                foreach ($infoJson['sizes'] as $s) {
                  if (!empty($s['width'])) {
                    $maxW = max($maxW, (int) $s['width']);
                  }
                  if (!empty($s['height'])) {
                    $maxH = max($maxH, (int) $s['height']);
                  }
                }
                if (empty($canvasWidth) && $maxW > 0) {
                  $canvasWidth = $maxW;
                }
                if (empty($canvasHeight) && $maxH > 0) {
                  $canvasHeight = $maxH;
                }
              }
            }
          }
          catch (\Throwable $e) {
            // ignore.
          }
        }
        $targetSize = $size;
        $targetByWidth = FALSE;

        // Use explicit null checks to avoid falsey issues. For vertical
        // images (width < height) request by width; otherwise request by
        // height. Always clamp to the available dimension from info.json
        // so we never request an upscaling that Cantaloupe would reject.
        if ($canvasWidth !== NULL && $canvasHeight !== NULL) {
          if ($canvasWidth < $canvasHeight) {
            // Vertical: prefer width-based request.
            $targetByWidth = TRUE;
            $targetSize = min($canvasWidth, $size);
          }
          else {
            // Horizontal or square: prefer height-based request.
            $targetByWidth = FALSE;
            $targetSize = min($canvasHeight, $size);
          }
        }
        elseif ($canvasWidth !== NULL) {
          // Only width known: request by width.
          $targetByWidth = TRUE;
          $targetSize = min($canvasWidth, $size);
        }
        elseif ($canvasHeight !== NULL) {
          // Only height known: request by height.
          $targetByWidth = FALSE;
          $targetSize = min($canvasHeight, $size);
        }

        $imageUrl = $this->buildImageUrl($selected, $targetSize, $targetByWidth);
        if (!$imageUrl) {
          continue;
        }

        // Related URL (prefer canvas-level, fallback to manifest-level).
        $related = $this->extractRelatedUrl($json, $selected);
        if (!$related) {
          // Final fallback to the manifest URL.
          $related = $mu;
        }

        // Get manifest title (best-effort, multi-language aware).
        $label = $this->extractLabel($json['label'] ?? NULL);
        if (!$label && !empty($json['label'])) {
          // v3 language map fallback.
          foreach ($json['label'] as $vals) {
            if (!empty($vals[0])) {
              $label = (string) $vals[0];
              break;
            }
          }
        }
        if (!$label && !empty($json['label']['@value'])) {
          $label = (string) $json['label']['@value'];
        }

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
  private function buildImageUrl(array $canvas, int $size, bool $byWidth = FALSE): ?string {
    // Determine target dimension and build IIIF Image API URL.
    // If $byWidth is true, the request will be width-based ("{w},").
    // Otherwise height-based (",{h}").
    $sizeParam = $byWidth ? ($size . ',') : (',' . $size);
    // v3.
    if (!empty($canvas['items'][0]['items'][0]['body'])) {
      $body = $canvas['items'][0]['items'][0]['body'];
      $svc = NULL;
      if (isset($body['service'])) {
        $s = $body['service'];
        if (is_array($s)) {
          if (array_keys($s) !== range(0, count($s) - 1)) {
            $svc = $s;
          }
          elseif (!empty($s[0]) && is_array($s[0])) {
            $svc = $s[0];
          }
        }
      }
      if ($svc === NULL && isset($body['services']) && is_array($body['services']) && !empty($body['services'][0])) {
        $svc = $body['services'][0];
      }
      if (is_array($svc)) {
        $sid = $svc['id'] ?? ($svc['@id'] ?? NULL);
        $stype = isset($svc['type']) ? (string) $svc['type'] : '';
        $isImageService = $sid && (
              ($stype !== '' && stripos($stype, 'ImageService3') !== FALSE)
              || !empty($svc['profile'])
              || !empty($svc['@context'])
          );
        if ($isImageService) {
          return rtrim((string) $sid, '/') . '/full/' . $sizeParam . '/0/default.jpg';
        }
      }
      if (!empty($canvas['thumbnail'][0]['id']) && is_string($canvas['thumbnail'][0]['id'])) {
        return (string) $canvas['thumbnail'][0]['id'];
      }
    }
    // v2.
    if (!empty($canvas['images'][0]['resource'])) {
      $res = $canvas['images'][0]['resource'];
      $sid = $res['service']['@id'] ?? ($res['service']['id'] ?? NULL);
      if ($sid) {
        return rtrim((string) $sid, '/') . '/full/' . $sizeParam . '/0/default.jpg';
      }
      if (!empty($canvas['thumbnail']['@id']) && is_string($canvas['thumbnail']['@id'])) {
        return (string) $canvas['thumbnail']['@id'];
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
        $s = $body['service'];
        if (is_array($s)) {
          if (array_keys($s) !== range(0, count($s) - 1)) {
            $svc = $s;
          }
          elseif (!empty($s[0]) && is_array($s[0])) {
            $svc = $s[0];
          }
        }
      }
      if ($svc === NULL && isset($body['services']) && is_array($body['services']) && !empty($body['services'][0])) {
        $svc = $body['services'][0];
      }
      $candidates = [];
      if (is_array($svc)) {
        $sid = $svc['id'] ?? ($svc['@id'] ?? NULL);
        if (is_string($sid) && $sid !== '') {
          $candidates[] = $sid;
        }
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
    // v3 canvas-level links and pointers.
    // Try to derive the parent item from canvas.partOf (points to manifest).
    if (!empty($canvas['partOf'][0]['id']) && is_string($canvas['partOf'][0]['id'])) {
      $pid = (string) $canvas['partOf'][0]['id'];
      if (preg_match('#/(?:iiif|iiif-img)/(?:2|3)/([^/]+)/manifest(?:\.json)?$#', $pid, $m)) {
        $seg = $m[1];
        if (ctype_digit($seg)) {
          return 'omeka:item:' . $seg;
        }
        $iid = $this->resolveItemIdFromIdentifier($seg);
        if ($iid) {
          return 'omeka:item:' . $iid;
        }
      }
    }
    // Direct homepage/seeAlso when available.
    if (!empty($canvas['homepage'][0]['id'])) {
      $u = (string) $canvas['homepage'][0]['id'];
      // Skip unsafe show pages without id.
      if (!preg_match('#/(?:s/[^/]+/)?(?:item|media)/show/?$#', $u)) {
        return $u;
      }
    }
    if (!empty($canvas['seeAlso'][0]['id'])) {
      $u = (string) $canvas['seeAlso'][0]['id'];
      if (!preg_match('#/(?:s/[^/]+/)?(?:item|media)/show/?$#', $u)) {
        return $u;
      }
    }
    // If canvas id includes Omeka's IIIF route, derive the item id
    // instead of returning the canvas URI.
    if (!empty($canvas['id']) && is_string($canvas['id'])) {
      $cid = (string) $canvas['id'];
      if (preg_match('#/(?:iiif|iiif-img)/(?:2|3)/([^/]+)/canvas/#', $cid, $m)) {
        $seg = $m[1];
        if (ctype_digit($seg)) {
          return 'omeka:item:' . $seg;
        }
        $iid = $this->resolveItemIdFromIdentifier($seg);
        if ($iid) {
          return 'omeka:item:' . $iid;
        }
      }
      // Do not return canvas URI (would lead to 404). Try other fallbacks.
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
      // Omeka IIIF Server default manifest route; segment may be internal id
      // or external identifier.
      if (preg_match('#/(?:iiif|iiif-img)/(?:2|3)/([^/]+)/manifest(?:\.json)?$#', $mid, $m)) {
        $seg = $m[1];
        if (ctype_digit($seg)) {
          return 'omeka:item:' . $seg;
        }
        $iid = $this->resolveItemIdFromIdentifier($seg);
        if ($iid) {
          return 'omeka:item:' . $iid;
        }
      }
      // Some custom routes may include /item/{id}/manifest.
      if (preg_match('#/item/(\d+)/manifest(?:\.json)?$#', $mid, $m)) {
        return 'omeka:item:' . $m[1];
      }
    }
    // manifest-level seeAlso: prefer Omeka API item link when present.
    if (!empty($manifest['seeAlso'][0]['id']) && is_string($manifest['seeAlso'][0]['id'])) {
      $sid = (string) $manifest['seeAlso'][0]['id'];
      if (preg_match('#/api/items/(\d+)(?:$|[/?])#', $sid, $m)) {
        return 'omeka:item:' . $m[1];
      }
    }
    // manifest-level fallback.
    if (!empty($manifest['homepage'][0]['id'])) {
      $home = (string) $manifest['homepage'][0]['id'];
      // Avoid using a site-root homepage that matches provider id.
      $provId = NULL;
      if (!empty($manifest['provider'][0]['id']) && is_string($manifest['provider'][0]['id'])) {
        $provId = (string) $manifest['provider'][0]['id'];
      }
      if ($provId && rtrim($home, '/') === rtrim($provId, '/')) {
        // Skip returning the site root; try other fallbacks.
      }
      // Also skip unsafe show pages without id.
      elseif (!preg_match('#/(?:s/[^/]+/)?(?:item|media)/show/?$#', $home)) {
        return $home;
      }
    }
    if (!empty($manifest['related']['@id'])) {
      return (string) $manifest['related']['@id'];
    }
    return NULL;
  }

  /**
   * Resolve an Omeka item id from a IIIF identifier segment.
   *
   * Attempts exact match against dcterms:identifier (raw and URL-decoded).
   */
  private function resolveItemIdFromIdentifier(string $identifier): ?int {
    $services = $this->getServiceLocator();
    /** @var \Omeka\Api\Manager $api */
    $api = $services->get('Omeka\ApiManager');
    $cands = [$identifier];
    $decoded = urldecode($identifier);
    if ($decoded !== $identifier) {
      $cands[] = $decoded;
    }
    $primaryPropertyId = (int) ($this->identifierPropertyId ?: 10);
    $fallbackPropertyId = $primaryPropertyId === 10 ? NULL : 10;
    foreach ($cands as $idv) {
      try {
        $propertyFilter = [
          [
            'property' => $primaryPropertyId,
            'type' => 'eq',
            'text' => $idv,
          ],
        ];
        $params = [
          'property' => $propertyFilter,
          'limit' => 1,
        ];
        $ids = $api->search('items', $params, ['returnScalar' => 'id'])->getContent();
        if (is_array($ids) && !empty($ids[0])) {
          return (int) $ids[0];
        }

        // Fallback: if configured property produced no result,
        // try dcterms:identifier.
        if ($fallbackPropertyId) {
          $fallbackFilter = [
            [
              'property' => $fallbackPropertyId,
              'type' => 'eq',
              'text' => $idv,
            ],
          ];
          $fallbackParams = [
            'property' => $fallbackFilter,
            'limit' => 1,
          ];
          $ids = $api->search('items', $fallbackParams, ['returnScalar' => 'id'])->getContent();
          if (is_array($ids) && !empty($ids[0])) {
            return (int) $ids[0];
          }
        }
      }
      catch (\Throwable $e) {
        // Ignore and continue.
      }
    }
    return NULL;
  }

  /**
   * Get the CleanUrl configured property id for items.
   *
   * Returns 10 (dcterms:identifier) when CleanUrl is not installed or not
   * configured with a property.
   */
  private function getCleanUrlItemPropertyId(): int {
    try {
      $services = $this->getServiceLocator();
      /** @var \Omeka\Settings\Settings $settings */
      $settings = $services->get('Omeka\Settings');
      $opt = $settings->get('cleanurl_item');
      if (is_array($opt) && !empty($opt['property'])) {
        $pid = (int) $opt['property'];
        if ($pid > 0) {
          return $pid;
        }
      }
    }
    catch (\Throwable $e) {
      // Ignore and use default.
    }
    return 10;
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
