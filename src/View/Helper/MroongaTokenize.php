<?php

namespace IiifSearchCarousel\View\Helper;

use Doctrine\DBAL\Connection;
use Laminas\View\Helper\AbstractHelper;

/**
 * View helper to tokenize Japanese text via Mroonga/TokenMecab when available.
 *
 * Returns a flat array of token strings or an empty array if unavailable.
 */
class MroongaTokenize extends AbstractHelper {
  /**
   * Database connection.
   *
   * @var \Doctrine\DBAL\Connection
   */
  private Connection $connection;

  /**
   * Constructor.
   *
   * @param \Doctrine\DBAL\Connection $connection
   *   Database connection.
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

  /**
   * Invoke helper.
   *
   * @param string $text
   *   Input text.
   *
   * @return string[]
   *   Token strings (may be empty).
   */
  public function __invoke(string $text): array {
    try {
      // Treat as effectively active when the fulltext_search table
      // engine is Mroonga.
      $row = $this->connection->fetchAssociative('SHOW TABLE STATUS LIKE :t', ['t' => 'fulltext_search']);
      $engine = is_array($row) && isset($row['Engine']) ? (string) $row['Engine'] : '';
      if (strcasecmp($engine, 'Mroonga') !== 0) {
        return [];
      }
      // Escape string for Groonga command argument.
      $arg = strtr($text, [
        "\\" => "\\\\",
        '"' => '\\"',
        "\n" => "\\n",
        "\r" => "\\r",
        "\t" => "\\t",
      ]);
      $cmd = 'tokenize --tokenizer TokenMecab --string "' . $arg . '" --output_type json';
      $raw = $this->connection->fetchOne('SELECT mroonga_command(?)', [$cmd]);
      if (!is_string($raw) || $raw === '') {
        return [];
      }
      $decoded = json_decode($raw, TRUE);
      if (!is_array($decoded)) {
        return [];
      }
      // Flatten any nested arrays, collecting string leaves or
      // ['value'=>string].
      $tokens = [];
      $stack = [$decoded];
      while ($stack) {
        $node = array_pop($stack);
        if (is_array($node)) {
          if (isset($node['value']) && is_string($node['value'])) {
            $tokens[] = $node['value'];
          }
          else {
            foreach ($node as $child) {
              $stack[] = $child;
            }
          }
        }
        elseif (is_string($node)) {
          $tokens[] = $node;
        }
      }
      return $tokens;
    }
    catch (\Throwable $e) {
      return [];
    }
  }

}
