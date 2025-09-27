<?php

namespace IiifSearchCarousel;

use Laminas\Mvc\MvcEvent;
use Omeka\Module\AbstractModule;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use IiifSearchCarousel\Controller\Admin\ConfigController;
use Psr\Container\ContainerInterface;
use IiifSearchCarousel\Form\SettingsForm;
use IiifSearchCarousel\Job\RebuildImagesJob;

/**
 * Module entry class for IIIF Search Carousel.
 */
class Module extends AbstractModule {

  /**
   * Cached service container set at bootstrap.
   */
  private ?ContainerInterface $container = NULL;

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
    // Cache container for later use (getConfigForm, etc.).
    $this->container = $services;
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

  /**
   * Render module configuration form for the standard Modules page.
   */
  public function getConfigForm(PhpRenderer $renderer) {
    // Use the cached container set during bootstrap.
    $services = $this->container;
    if (!$services && method_exists($this, 'getServiceLocator')) {
      // Secondary attempt (may be null in some environments).
      $services = $this->getServiceLocator();
    }
    if (!$services) {
      $helpers = $renderer->getHelperPluginManager();
      if (method_exists($helpers, 'getCreationContext')) {
        $services = $helpers->getCreationContext();
      }
    }
    if (!$services) {
      // As a last resort, render a minimal warning to avoid deprecated calls.
      return '<div class="messages error">Service container is not available.</div>';
    }
    $settings = $services->get('Omeka\\Settings');
    $form = $services->get('FormElementManager')->get(SettingsForm::class);
    $form->setData([
      'number_of_images' => (int) ($settings->get('iiif_sc.number_of_images') ?? 5),
      'carousel_duration' => (int) ($settings->get('iiif_sc.carousel_duration') ?? 6),
      'image_size' => (int) ($settings->get('iiif_sc.image_size') ?? 1600),
      'aspect_ratio_mode' => (string) ($settings->get('iiif_sc.aspect_ratio_mode') ?? '16:9'),
      'aspect_ratio_w' => (int) ($settings->get('iiif_sc.aspect_ratio_w') ?? 16),
      'aspect_ratio_h' => (int) ($settings->get('iiif_sc.aspect_ratio_h') ?? 9),
      'aspect_ratio_breakpoint_sm' => (int) ($settings->get('iiif_sc.aspect_ratio_breakpoint_sm') ?? 600),
      'aspect_ratio_mode_sm' => (string) ($settings->get('iiif_sc.aspect_ratio_mode_sm') ?? 'inherit'),
      'aspect_ratio_w_sm' => (int) ($settings->get('iiif_sc.aspect_ratio_w_sm') ?? 16),
      'aspect_ratio_h_sm' => (int) ($settings->get('iiif_sc.aspect_ratio_h_sm') ?? 9),
      'aspect_ratio_breakpoint_md' => (int) ($settings->get('iiif_sc.aspect_ratio_breakpoint_md') ?? 900),
      'aspect_ratio_mode_md' => (string) ($settings->get('iiif_sc.aspect_ratio_mode_md') ?? 'inherit'),
      'aspect_ratio_w_md' => (int) ($settings->get('iiif_sc.aspect_ratio_w_md') ?? 16),
      'aspect_ratio_h_md' => (int) ($settings->get('iiif_sc.aspect_ratio_h_md') ?? 9),
      'selection_rules' => (string) ($settings->get('iiif_sc.selection_rules') ?? "1 => 1\n2 => 2\n3+ => random(2-last-1)"),
      'truncate_title_length' => (int) ($settings->get('iiif_sc.truncate_title_length') ?? 0),
      'manifest_urls' => (string) ($settings->get('iiif_sc.manifest_urls') ?? ''),
      'auto_rebuild_enable' => (bool) ($settings->get('iiif_sc.auto_rebuild_enable') ?? FALSE),
      'auto_rebuild_interval' => (int) ($settings->get('iiif_sc.auto_rebuild_interval') ?? 60),
    ]);
    $form->prepare();
    return $renderer->formCollection($form);
  }

  /**
   * Handle saving of configuration form from the standard Modules page.
   */
  public function handleConfigForm(AbstractController $controller) {
    $services = $controller->getEvent()->getApplication()->getServiceManager();
    $settings = $services->get('Omeka\\Settings');
    $post = $controller->params()->fromPost();

    // Validate and persist settings (mirror of ConfigController::indexAction).
    $getInt = function ($key, $default) use ($post) {
      return (int) ($post[$key] ?? $default);
    };
    $getStr = function ($key, $default) use ($post) {
      return (string) ($post[$key] ?? $default);
    };

    $settings->set('iiif_sc.number_of_images', $getInt('number_of_images', 5));
    $settings->set('iiif_sc.carousel_duration', $getInt('carousel_duration', 6));
    $settings->set('iiif_sc.image_size', $getInt('image_size', 1600));
    $settings->set('iiif_sc.aspect_ratio_mode', $getStr('aspect_ratio_mode', '16:9'));
    $settings->set('iiif_sc.aspect_ratio_w', $getInt('aspect_ratio_w', 16));
    $settings->set('iiif_sc.aspect_ratio_h', $getInt('aspect_ratio_h', 9));
    $settings->set('iiif_sc.aspect_ratio_breakpoint_sm', $getInt('aspect_ratio_breakpoint_sm', 600));
    $settings->set('iiif_sc.aspect_ratio_mode_sm', $getStr('aspect_ratio_mode_sm', 'inherit'));
    $settings->set('iiif_sc.aspect_ratio_w_sm', $getInt('aspect_ratio_w_sm', 16));
    $settings->set('iiif_sc.aspect_ratio_h_sm', $getInt('aspect_ratio_h_sm', 9));
    $settings->set('iiif_sc.aspect_ratio_breakpoint_md', $getInt('aspect_ratio_breakpoint_md', 900));
    $settings->set('iiif_sc.aspect_ratio_mode_md', $getStr('aspect_ratio_mode_md', 'inherit'));
    $settings->set('iiif_sc.aspect_ratio_w_md', $getInt('aspect_ratio_w_md', 16));
    $settings->set('iiif_sc.aspect_ratio_h_md', $getInt('aspect_ratio_h_md', 9));
    $settings->set('iiif_sc.selection_rules', $getStr('selection_rules', "1 => 1\n2 => 2\n3+ => random(2-last-1)"));
    $settings->set('iiif_sc.truncate_title_length', $getInt('truncate_title_length', 0));
    $settings->set('iiif_sc.manifest_urls', $getStr('manifest_urls', ''));
    $settings->set('iiif_sc.auto_rebuild_enable', !empty($post['auto_rebuild_enable']));
    $settings->set('iiif_sc.auto_rebuild_interval', $getInt('auto_rebuild_interval', 60));

    // Attempt to dispatch a rebuild job after saving settings.
    try {
      /** @var \Omeka\Job\Dispatcher $dispatcher */
      $dispatcher = $services->get('Omeka\\Job\\Dispatcher');
      $dispatcher->dispatch(RebuildImagesJob::class, []);
      $controller->messenger()->addSuccess('Settings saved. Rebuild job has been queued.');
    }
    catch (\Throwable $e) {
      $controller->messenger()->addWarning('Settings saved, but failed to queue rebuild job: ' . $e->getMessage());
    }
    return TRUE;
  }

}
