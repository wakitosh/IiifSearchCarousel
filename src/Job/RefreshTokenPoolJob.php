<?php

declare(strict_types=1);

namespace IiifSearchCarousel\Job;

use IiifSearchCarousel\Service\TokenPool;
use Omeka\Job\AbstractJob;

/**
 * Refresh the public search-example token pool outside the web request.
 */
class RefreshTokenPoolJob extends AbstractJob
{
  public function perform(): void
  {
    $leaseToken = (string) $this->getArg('lease_token', '');
    $this->getServiceLocator()->get(TokenPool::class)->refresh($leaseToken);
  }
}
