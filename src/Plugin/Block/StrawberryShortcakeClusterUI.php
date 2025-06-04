<?php

namespace Drupal\strawberry_shortcake\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\strawberry_shortcake\Form\StrawberryShortCakeClusterUIForm;

/**
 * Provides the basic UI for managing Clusters.
 */

#[Block(
  id: "strawberry_shortcake_ui",
  admin_label: new TranslatableMarkup("Strawberry Shortcake Clustering UI"),
  category: new TranslatableMarkup("Archipelago")
)]

class StrawberryShortcakeClusterUI extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  private FormBuilderInterface $formBuilder;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, FormBuilderInterface $form_builder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder')
    );
  }
  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [
      'form' => $this->formBuilder->getForm(StrawberryShortCakeClusterUIForm::class),
      '#cache' => [
        'contexts' => ['session'],
        'max-age' => 0
      ],
    ];
    return $build;
  }
  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    if ($account->isAnonymous()) {
      $access = AccessResult::forbidden()->setReason('The chosen Action only acts on entities of type node')->setCacheMaxAge(0);

    }
    else {
      $access =  AccessResult::allowedIfHasPermission($account,
        'execute ML clustering');
    }
    return $access;
  }

}