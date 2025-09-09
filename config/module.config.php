<?php

/**
 * @file
 * IIIF Search Carousel module configuration.
 */

declare(strict_types=1);

use Laminas\ServiceManager\Factory\InvokableFactory;
use Psr\Container\ContainerInterface;
use IiifSearchCarousel\Controller\Admin\ConfigController;
use IiifSearchCarousel\Site\BlockLayout\SearchCarouselBlock;
use IiifSearchCarousel\Form\SettingsForm;

return [
  'block_layouts' => [
    'factories' => [
      SearchCarouselBlock::class => function (ContainerInterface $container) {
        return new SearchCarouselBlock($container);
      },
    ],
  ],

  'controllers' => [
    'factories' => [
      ConfigController::class => InvokableFactory::class,
    ],
  ],

  'form_elements' => [
    'factories' => [
      SettingsForm::class => InvokableFactory::class,
    ],
  ],

  'router' => [
    'routes' => [
      'iiif-search-carousel-admin' => [
        'type' => 'Literal',
        'options' => [
          'route' => '/admin/iiif-search-carousel',
          'defaults' => [
            '__NAMESPACE__' => 'IiifSearchCarousel\\Controller\\Admin',
            'controller' => ConfigController::class,
            'action' => 'index',
            '__ADMIN__' => TRUE,
          ],
        ],
        'may_terminate' => TRUE,
        'child_routes' => [
          'rebuild' => [
            'type' => 'Literal',
            'options' => [
              'route' => '/rebuild',
              'defaults' => [
                'action' => 'rebuild',
              ],
            ],
          ],
        ],
      ],
    ],
  ],

  'navigation' => [
    'AdminGlobal' => [
      [
        'label' => 'IIIF Search Carousel',
        'route' => 'iiif-search-carousel-admin',
        'resource' => ConfigController::class,
        'pages' => [],
      ],
    ],
  ],

  'view_manager' => [
    'template_path_stack' => [
      __DIR__ . '/../view',
    ],
  ],
];
