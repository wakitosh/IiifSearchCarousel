<?php

namespace IiifSearchCarousel\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use IiifSearchCarousel\Form\SettingsForm;
use IiifSearchCarousel\Job\RebuildImagesJob;

/**
 * Admin config for IIIF Search Carousel.
 */
class ConfigController extends AbstractActionController {

  /**
   * Display and process the settings form.
   */

  /**
   * Settings page.
   */
  public function indexAction() {
    // Display and process form.
    $services = $this->getEvent()->getApplication()->getServiceManager();
    $settings = $services->get('Omeka\Settings');

    $data = [
      'number_of_images' => (int) ($settings->get('iiif_sc.number_of_images') ?? 5),
      'carousel_duration' => (int) ($settings->get('iiif_sc.carousel_duration') ?? 6),
      'image_size' => (int) ($settings->get('iiif_sc.image_size') ?? 1600),
      'aspect_ratio_mode' => (string) ($settings->get('iiif_sc.aspect_ratio_mode') ?? '16:9'),
      'aspect_ratio_w' => (int) ($settings->get('iiif_sc.aspect_ratio_w') ?? 16),
      'aspect_ratio_h' => (int) ($settings->get('iiif_sc.aspect_ratio_h') ?? 9),
      // Responsive aspect ratios (small/medium).
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
    ];

    $form = $services->get('FormElementManager')->get(SettingsForm::class);
    $form->setData($data);

    $request = $this->getRequest();
    $method = method_exists($request, 'getMethod') ? strtoupper((string) $request->getMethod()) : '';
    if ($method === 'POST') {
      $post = $this->params()->fromPost();
      $form->setData($post);
      if ($form->isValid()) {
        $values = $form->getData();
        $settings->set('iiif_sc.number_of_images', (int) $values['number_of_images']);
        $settings->set('iiif_sc.carousel_duration', (int) $values['carousel_duration']);
        $settings->set('iiif_sc.image_size', (int) $values['image_size']);
        $settings->set('iiif_sc.aspect_ratio_mode', (string) $values['aspect_ratio_mode']);
        $settings->set('iiif_sc.aspect_ratio_w', (int) $values['aspect_ratio_w']);
        $settings->set('iiif_sc.aspect_ratio_h', (int) $values['aspect_ratio_h']);
        // Responsive aspect ratios (small/medium).
        $settings->set('iiif_sc.aspect_ratio_breakpoint_sm', (int) ($values['aspect_ratio_breakpoint_sm'] ?? 600));
        $settings->set('iiif_sc.aspect_ratio_mode_sm', (string) ($values['aspect_ratio_mode_sm'] ?? 'inherit'));
        $settings->set('iiif_sc.aspect_ratio_w_sm', (int) ($values['aspect_ratio_w_sm'] ?? 16));
        $settings->set('iiif_sc.aspect_ratio_h_sm', (int) ($values['aspect_ratio_h_sm'] ?? 9));
        $settings->set('iiif_sc.aspect_ratio_mode_md', (string) ($values['aspect_ratio_mode_md'] ?? 'inherit'));
        $settings->set('iiif_sc.aspect_ratio_w_md', (int) ($values['aspect_ratio_w_md'] ?? 16));
        $settings->set('iiif_sc.aspect_ratio_h_md', (int) ($values['aspect_ratio_h_md'] ?? 9));
        $settings->set('iiif_sc.selection_rules', (string) $values['selection_rules']);
        $settings->set('iiif_sc.truncate_title_length', (int) ($values['truncate_title_length'] ?? 0));
        $settings->set('iiif_sc.manifest_urls', (string) $values['manifest_urls']);
        $settings->set('iiif_sc.auto_rebuild_enable', !empty($values['auto_rebuild_enable']));
        $settings->set('iiif_sc.auto_rebuild_interval', (int) ($values['auto_rebuild_interval'] ?? 60));

        $this->messenger()->addSuccess('Settings saved.');

        if (isset($post['rebuild_now'])) {
          $dispatcher = $services->get('Omeka\Job\Dispatcher');
          $job = $dispatcher->dispatch(RebuildImagesJob::class, []);
          return $this->redirect()->toUrl($this->url()->fromRoute('admin/id', [
            'controller' => 'job',
            'action' => 'show',
            'id' => $job->getId(),
          ]));
        }

        return $this->redirect()->toRoute('iiif-search-carousel-admin');
      }
      else {
        $this->messenger()->addFormErrors($form);
      }
    }

    $view = new ViewModel([
      'form' => $form,
    ]);
    $view->setTemplate('iiif-search-carousel/admin/config/index');
    return $view;
  }

  /**
   * Trigger rebuild via route.
   */

  /**
   * Rebuild trigger.
   */
  public function rebuildAction() {
    $services = $this->getEvent()->getApplication()->getServiceManager();
    $dispatcher = $services->get('Omeka\Job\Dispatcher');
    $job = $dispatcher->dispatch(RebuildImagesJob::class, []);
    return $this->redirect()->toUrl($this->url()->fromRoute('admin/id', [
      'controller' => 'job',
      'action' => 'show',
      'id' => $job->getId(),
    ]));
  }

}
