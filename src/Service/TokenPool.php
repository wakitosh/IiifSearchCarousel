<?php

declare(strict_types=1);

namespace IiifSearchCarousel\Service;

use IiifSearchCarousel\Job\RefreshTokenPoolJob;
use Laminas\Log\LoggerInterface;
use Omeka\Job\Dispatcher;
use Omeka\Job\DispatchStrategy\StrategyInterface;

/**
 * Builds and serves the cached search-example token pool.
 *
 * Public rendering never builds the pool. It returns the current value,
 * including a stale value, and best-effort dispatches a background refresh.
 */
class TokenPool
{
  public const CACHE_KEY = 'iiif_sc.token_pool';
  public const CACHE_SCOPE = 'public_items_v1';
  public const TTL_KEY = 'iiif_sc.token_pool_ttl';
  public const LEASE_KEY = 'iiif_sc.token_pool_refresh_lease';
  public const DEFAULT_TTL = 3600;
  public const HARD_STALE_SECONDS = 86400;
  public const LEASE_SECONDS = 300;
  public const WORKER_LOCK_WAIT = 60;
  public const TITLE_SAMPLE_SIZE = 300;
  public const POOL_SIZE = 300;
  public const RENDER_SIZE = 32;

  /** @var mixed */
  private $connection;

  private Dispatcher $dispatcher;
  private StrategyInterface $phpCliStrategy;
  private LoggerInterface $logger;
  private string $stopwordsPath;
  private ?array $stopwordSet = NULL;
  private ?string $lockName = NULL;

  /**
   * @param mixed $connection
   *   Doctrine DBAL connection.
   */
  public function __construct(
    $connection,
    Dispatcher $dispatcher,
    StrategyInterface $phpCliStrategy,
    LoggerInterface $logger,
    string $stopwordsPath
  ) {
    $this->connection = $connection;
    $this->dispatcher = $dispatcher;
    $this->phpCliStrategy = $phpCliStrategy;
    $this->logger = $logger;
    $this->stopwordsPath = $stopwordsPath;
  }

  /**
   * Return a fresh or stale pool without doing expensive work in the request.
   */
  public function getForRender(): array
  {
    $now = time();
    $cached = $this->normalizeCache($this->readSetting(self::CACHE_KEY));

    if ($this->isFresh($cached, $now)) {
      return $this->renderSlice($cached['tokens']);
    }

    // This only takes a non-blocking advisory lock, records a short lease, and
    // starts an explicit PHP-CLI Omeka job. The expensive query is never run in
    // the public request.
    $this->dispatchRefresh($now);

    // Stale-while-revalidate: an expired value is still safe to display. On a
    // first-ever miss there is no pool to return; the block template can use
    // its existing carousel-label fallback until the worker fills the cache.
    return $this->canServeStale($cached, $now)
      ? $this->renderSlice($cached['tokens'])
      : [];
  }

  /**
   * Refresh the pool for the lease owned by a background job.
   */
  public function refresh(string $leaseToken): void
  {
    if ($leaseToken === '') {
      $this->logger->warn('IIIF Search Carousel token refresh skipped: missing lease token.');
      return;
    }

    // A worker may start before the dispatching request releases its lock, so
    // wait here without affecting the public response. Public requests always
    // use a zero-second wait.
    if (!$this->acquireLock(self::WORKER_LOCK_WAIT)) {
      $this->logger->warn('IIIF Search Carousel token refresh skipped: advisory lock unavailable.');
      return;
    }

    try {
      $now = time();
      $lease = $this->readSetting(self::LEASE_KEY);
      if (!$this->leaseMatches($lease, $leaseToken, $now)) {
        return;
      }

      // A different successful path may have refreshed the cache while this
      // job was starting. Do not repeat the full-table random sort.
      $cached = $this->normalizeCache($this->readSetting(self::CACHE_KEY));
      if ($this->isFresh($cached, $now)) {
        $this->deleteLease($leaseToken);
        return;
      }

      try {
        $tokens = $this->build();
        $this->writeSetting(self::CACHE_KEY, [
          'tokens' => $tokens,
          'time' => time(),
          'scope' => self::CACHE_SCOPE,
        ]);
        $this->deleteLease($leaseToken);
      }
      catch (\Throwable $e) {
        // Keep the lease until it expires so a persistent database failure
        // cannot create one failed job per page view.
        $this->logger->err(
          'IIIF Search Carousel token refresh failed: ' . $e->getMessage()
        );
        throw $e;
      }
    }
    finally {
      $this->releaseLock();
    }
  }

  public function isCjkToken(string $token): bool
  {
    return in_array($this->classifyToken($token), ['Han', 'Hira', 'Kata'], TRUE);
  }

  public function isLatinToken(string $token): bool
  {
    return $this->classifyToken($token) === 'Latin';
  }

  public function isDigitOrOtherToken(string $token): bool
  {
    return in_array($this->classifyToken($token), ['Digit', 'Other'], TRUE);
  }

  /**
   * Dispatch at most one worker during a five-minute lease.
   */
  private function dispatchRefresh(int $now): void
  {
    if (!$this->acquireLock(0)) {
      return;
    }

    try {
      // Re-read directly from the database while holding the lock. Omeka's
      // Settings service caches a request-local snapshot, which is unsuitable
      // for coordinating concurrent PHP processes.
      $cached = $this->normalizeCache($this->readSetting(self::CACHE_KEY));
      if ($this->isFresh($cached, $now)) {
        return;
      }

      $lease = $this->readSetting(self::LEASE_KEY);
      if ($this->isActiveLease($lease, $now)) {
        return;
      }

      $leaseToken = $this->newLeaseToken();
      $this->writeSetting(self::LEASE_KEY, [
        'token' => $leaseToken,
        'expires' => $now + self::LEASE_SECONDS,
      ]);

      try {
        // Pass the PHP-CLI strategy explicitly so a site-wide synchronous alias
        // cannot move the expensive query back into the public request.
        $this->dispatcher->dispatch(
          RefreshTokenPoolJob::class,
          ['lease_token' => $leaseToken],
          $this->phpCliStrategy
        );
      }
      catch (\Throwable $e) {
        $this->deleteLease($leaseToken);
        $this->logger->err(
          'IIIF Search Carousel token refresh dispatch failed: ' . $e->getMessage()
        );
      }
    }
    catch (\Throwable $e) {
      // Cache coordination must never break public rendering.
      $this->logger->err(
        'IIIF Search Carousel token refresh coordination failed: ' . $e->getMessage()
      );
    }
    finally {
      $this->releaseLock();
    }
  }

  /**
   * Build the complete token pool. This method is worker-only.
   */
  private function build(): array
  {
    [$latinMinLen, $digitMinLen] = $this->minimumTokenLengths();

    // Item titles are the public catalogue-level search examples users expect.
    // Driving the join from item avoids scanning/sorting millions of media rows.
    $titles = (array) $this->connection->fetchFirstColumn(
      "SELECT fts.title FROM item AS item_source "
      . "STRAIGHT_JOIN resource AS item_resource "
      . "ON item_resource.id = item_source.id AND item_resource.is_public = 1 "
      . "STRAIGHT_JOIN fulltext_search AS fts "
      . "ON fts.id = item_source.id AND fts.resource = 'items' "
      . "WHERE fts.is_public = 1 "
      . "AND fts.title IS NOT NULL AND fts.title <> '' "
      . "ORDER BY RAND() LIMIT " . self::TITLE_SAMPLE_SIZE
    );

    $tokens = [];
    foreach ($titles as $title) {
      foreach ($this->extractTokens((string) $title, $latinMinLen, $digitMinLen) as $token) {
        if (!in_array($token, $tokens, TRUE)) {
          $tokens[] = $token;
        }
        if (count($tokens) >= self::POOL_SIZE) {
          break 2;
        }
      }
    }

    return $tokens;
  }

  private function minimumTokenLengths(): array
  {
    $latinMinLen = 4;
    $digitMinLen = 4;

    try {
      $row = $this->connection->fetchAssociative(
        'SHOW TABLE STATUS LIKE :table_name',
        ['table_name' => 'fulltext_search']
      );
      $engine = isset($row['Engine']) ? (string) $row['Engine'] : '';
      if (strcasecmp($engine, 'Mroonga') === 0) {
        $latinMinLen = 2;
        $digitMinLen = 2;
      }
    }
    catch (\Throwable $e) {
      // Best effort: retain the MySQL/InnoDB-compatible defaults.
    }

    return [$latinMinLen, $digitMinLen];
  }

  private function extractTokens(string $title, int $latinMinLen, int $digitMinLen): array
  {
    $title = trim($title);
    if ($title === '') {
      return [];
    }

    // Preserve the existing search-example segmentation rules.
    if (preg_match('/[\(\)（）［\]【】〈〉《》〔〕｛｝{}＜＞]/u', $title, $matches, PREG_OFFSET_CAPTURE)) {
      $position = isset($matches[0][1]) ? (int) $matches[0][1] : 0;
      if ($position > 0) {
        $title = function_exists('mb_substr')
          ? mb_substr($title, 0, $position, 'UTF-8')
          : substr($title, 0, $position);
      }
    }

    $length = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
    $tokens = [];
    $buffer = '';
    $previousClass = '';

    for ($i = 0; $i < $length; $i++) {
      $character = function_exists('mb_substr')
        ? mb_substr($title, $i, 1, 'UTF-8')
        : substr($title, $i, 1);

      if ($this->isSeparator($character)) {
        if ($buffer !== '') {
          $tokens[] = $buffer;
          $buffer = '';
          $previousClass = '';
        }
        continue;
      }

      $class = $this->classifyCharacter($character);
      if ($buffer === '') {
        $buffer = $character;
        $previousClass = $class;
      }
      elseif ($class !== $previousClass) {
        $tokens[] = $buffer;
        $buffer = $character;
        $previousClass = $class;
      }
      else {
        $buffer .= $character;
      }
    }

    if ($buffer !== '') {
      $tokens[] = $buffer;
    }

    $filtered = [];
    foreach ($tokens as $token) {
      $token = trim($token);
      if ($token === '') {
        continue;
      }

      $class = $this->classifyToken($token);
      $length = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
      if (in_array($class, ['Han', 'Hira', 'Kata'], TRUE)) {
        if ($length < 2) {
          continue;
        }
      }
      elseif ($class === 'Latin') {
        if ($length < $latinMinLen) {
          continue;
        }
      }
      elseif ($class === 'Digit') {
        if ($length < $digitMinLen) {
          continue;
        }
      }
      elseif ($length < 2) {
        continue;
      }

      if ($this->isStopword($token)) {
        continue;
      }
      $filtered[] = $token;
    }

    return $filtered;
  }

  private function classifyToken(string $token): string
  {
    if ($token === '') {
      return 'Other';
    }
    $head = function_exists('mb_substr')
      ? mb_substr($token, 0, 1, 'UTF-8')
      : substr($token, 0, 1);
    return $this->classifyCharacter($head);
  }

  private function classifyCharacter(string $character): string
  {
    if ($character === '') {
      return 'Other';
    }
    if (preg_match('/\p{Han}/u', $character)) {
      return 'Han';
    }
    if (preg_match('/\p{Hiragana}/u', $character)) {
      return 'Hira';
    }
    if (preg_match('/\p{Katakana}/u', $character)) {
      return 'Kata';
    }
    if (preg_match('/[A-Za-z]/u', $character)) {
      return 'Latin';
    }
    if (preg_match('/[0-9]/u', $character)) {
      return 'Digit';
    }
    return preg_match('/\p{Nd}/u', $character) ? 'Digit' : 'Other';
  }

  private function isSeparator(string $character): bool
  {
    if ($character === '') {
      return TRUE;
    }
    if (preg_match('/[\p{P}\p{Z}]/u', $character)) {
      return TRUE;
    }
    return (bool) preg_match('/[（）\(\)［\]【】〈〉《》〔〕｛｝{}＜＞・、。„“‟„「」『』—–‐‑〜]/u', $character);
  }

  private function isStopword(string $token): bool
  {
    $normalized = $this->normalizeToken($token);
    if ($normalized === '') {
      return TRUE;
    }
    return isset($this->stopwords()[$normalized]);
  }

  private function normalizeToken(string $token): string
  {
    $token = trim($token);
    if ($token === '') {
      return '';
    }
    if ($this->classifyToken($token) === 'Latin') {
      return function_exists('mb_strtolower')
        ? mb_strtolower($token, 'UTF-8')
        : strtolower($token);
    }
    return $token;
  }

  private function stopwords(): array
  {
    if ($this->stopwordSet !== NULL) {
      return $this->stopwordSet;
    }

    $this->stopwordSet = [];
    if (!is_readable($this->stopwordsPath)) {
      return $this->stopwordSet;
    }

    try {
      $data = json_decode((string) file_get_contents($this->stopwordsPath), TRUE);
      if (!is_array($data)) {
        return $this->stopwordSet;
      }
      foreach ($data as $list) {
        if (!is_array($list)) {
          continue;
        }
        foreach ($list as $word) {
          if (!is_string($word)) {
            continue;
          }
          $normalized = $this->normalizeToken($word);
          if ($normalized !== '') {
            $this->stopwordSet[$normalized] = TRUE;
          }
        }
      }
    }
    catch (\Throwable $e) {
      $this->stopwordSet = [];
    }

    return $this->stopwordSet;
  }

  private function normalizeCache($value): ?array
  {
    if (!is_array($value)
      || !array_key_exists('tokens', $value)
      || !is_array($value['tokens'])
      || !isset($value['time'])
      || ($value['scope'] ?? '') !== self::CACHE_SCOPE
    ) {
      return NULL;
    }

    $tokens = [];
    foreach ($value['tokens'] as $token) {
      if (is_string($token) && $token !== '') {
        $tokens[] = $token;
      }
    }

    return [
      'tokens' => array_values(array_unique($tokens)),
      'time' => (int) $value['time'],
    ];
  }

  private function isFresh(?array $cached, int $now): bool
  {
    if ($cached === NULL) {
      return FALSE;
    }
    $age = max(0, $now - (int) $cached['time']);
    return $age < $this->ttl() && $age < self::HARD_STALE_SECONDS;
  }

  private function canServeStale(?array $cached, int $now): bool
  {
    return $cached !== NULL
      && max(0, $now - (int) $cached['time']) < self::HARD_STALE_SECONDS;
  }

  private function ttl(): int
  {
    $override = (int) $this->readSetting(self::TTL_KEY);
    return $override > 0 ? $override : self::DEFAULT_TTL;
  }

  private function renderSlice(array $tokens): array
  {
    if (count($tokens) > 1) {
      shuffle($tokens);
    }
    return array_slice($tokens, 0, self::RENDER_SIZE);
  }

  private function isActiveLease($lease, int $now): bool
  {
    return is_array($lease)
      && isset($lease['token'])
      && is_string($lease['token'])
      && $lease['token'] !== ''
      && isset($lease['expires'])
      && (int) $lease['expires'] > $now;
  }

  private function leaseMatches($lease, string $leaseToken, int $now): bool
  {
    return $this->isActiveLease($lease, $now)
      && hash_equals((string) $lease['token'], $leaseToken);
  }

  private function newLeaseToken(): string
  {
    try {
      return bin2hex(random_bytes(16));
    }
    catch (\Throwable $e) {
      return sha1(uniqid('iiif-sc-', TRUE) . mt_rand());
    }
  }

  private function acquireLock(int $waitSeconds): bool
  {
    try {
      return (int) $this->connection->fetchOne(
        'SELECT GET_LOCK(?, ?)',
        [$this->advisoryLockName(), $waitSeconds]
      ) === 1;
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

  private function releaseLock(): void
  {
    try {
      $this->connection->fetchOne(
        'SELECT RELEASE_LOCK(?)',
        [$this->advisoryLockName()]
      );
    }
    catch (\Throwable $e) {
      // Connection-scoped advisory locks are also released on disconnect.
    }
  }

  private function advisoryLockName(): string
  {
    if ($this->lockName !== NULL) {
      return $this->lockName;
    }

    try {
      $database = (string) $this->connection->fetchOne('SELECT DATABASE()');
    }
    catch (\Throwable $e) {
      $database = '';
    }
    $this->lockName = 'iiif_sc.token_pool.' . substr(sha1($database), 0, 16);
    return $this->lockName;
  }

  /**
   * Read JSON through DBAL rather than Omeka Settings' request-local cache.
   *
   * @return mixed
   */
  private function readSetting(string $key)
  {
    $raw = $this->connection->fetchOne(
      'SELECT value FROM setting WHERE id = ?',
      [$key]
    );
    if ($raw === FALSE || $raw === NULL) {
      return NULL;
    }
    return json_decode((string) $raw, TRUE);
  }

  /**
   * Store a JSON setting while an advisory lock protects coordination values.
   *
   * @param mixed $value
   */
  private function writeSetting(string $key, $value): void
  {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      throw new \RuntimeException('Unable to encode token-pool setting as JSON.');
    }

    $this->connection->executeStatement(
      'INSERT INTO setting (id, value) VALUES (:id, :value) '
      . 'ON DUPLICATE KEY UPDATE value = :updated_value',
      [
        'id' => $key,
        'value' => $json,
        'updated_value' => $json,
      ]
    );
  }

  private function deleteLease(string $leaseToken): void
  {
    $lease = $this->readSetting(self::LEASE_KEY);
    if (is_array($lease)
      && isset($lease['token'])
      && hash_equals((string) $lease['token'], $leaseToken)
    ) {
      $this->connection->executeStatement(
        'DELETE FROM setting WHERE id = ?',
        [self::LEASE_KEY]
      );
    }
  }
}
