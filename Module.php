<?php

namespace IiifSearchCarousel;

use Laminas\Mvc\MvcEvent;
use Omeka\Module\AbstractModule;
use Laminas\ServiceManager\ServiceLocatorInterface;
use IiifSearchCarousel\Controller\Admin\ConfigController;

/**
 * Module entry class for IIIF Search Carousel.
 */
class Module extends AbstractModule {

  /**
   * {@inheritDoc}
   */
  public function getConfig(): array {
    return include __DIR__ . '/config/module.config.php';
  }

  /**
   * Register ACL for admin config controller.
   */
  public function onBootstrap(MvcEvent $event): void {
    $services = $event->getApplication()->getServiceManager();
    $acl = $services->get('Omeka\\Acl');
    $resource = ConfigController::class;
    if (!$acl->hasResource($resource)) {
      $acl->addResource($resource);
    }
    $acl->allow('global_admin', $resource);
  }

  /**
   * Install DB table for carousel images.
   */
  public function install(ServiceLocatorInterface $services): void {
    $connection = $services->get('Omeka\\Connection');
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS iiif_sc_images (
    id INT AUTO_INCREMENT NOT NULL,
    image_url VARCHAR(1024) NOT NULL,
    manifest_url VARCHAR(1024) NOT NULL,
    related_url VARCHAR(1024) DEFAULT NULL,
    label VARCHAR(1024) DEFAULT NULL,
    position INT NOT NULL,
    created DATETIME NOT NULL,
    PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    $connection->executeStatement($sql);
  }

  /**
   * Drop DB table on uninstall.
   */
  public function uninstall(ServiceLocatorInterface $services): void {
    $connection = $services->get('Omeka\\Connection');
    $connection->executeStatement('DROP TABLE IF EXISTS iiif_sc_images');
  }

}
