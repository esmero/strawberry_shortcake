<?php

namespace Drupal\strawberry_shortcake\Form;

use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Ajax\AjaxResponse;

/**
 * Builds the basic UI for Administering Clusters
 */
class StrawberryShortCakeClusterUIForm extends FormBase {


  /**
   * The Shared tempstore.
   *
   * @var \Drupal\Core\TempStore\SharedTempStore
   */
  protected \Drupal\Core\TempStore\SharedTempStore $tempStoreShared;

  /**
   * Key value service.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValue;

  /**
   * Key value service.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected \Drupal\Core\TempStore\PrivateTempStore $tempStorePrivate;


  /**
   * Constructs The shortcake UI
   *
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $temp_store_factory
   */
  public function __construct(SharedTempStoreFactory $temp_store_factory, PrivateTempStoreFactory $private_temp_store_factory, $key_value) {
    $this->tempStoreShared = $temp_store_factory->get('strawberry_shortcake');
    $this->tempStorePrivate = $private_temp_store_factory->get('strawberry_shortcake_private');
    $this->keyValue = $key_value;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tempstore.shared'),
      $container->get('tempstore.private'),
      $container->get('strawberryfield.keyvalue.database')
    );
  }

  public function getFormId() {
    return 'strawberry_shortcake_ui_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL) {
    $wrapper_id= 'strawberry-shortcake_ui-form-wrapper';
    $form['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['#suffix'] = '</div>';
    $cluster_count = 0;
    $active_cluster = $this->tempStorePrivate->get('active_cluster') ?? [];
    $active_cluster_label = $form_state->getValue('active_cluster') ?? NULL;
    if (isset($active_cluster['label']) && isset($active_cluster['uuid'])) {
      $active_cluster_label = $active_cluster['label'];
      $cluster_members = $this->tempStoreShared->get("cluster:".$active_cluster['uuid']) ?? [];
      $cluster_count = count($cluster_members);
    }
    $active_cluster_label_count = $active_cluster_label ? "true" : "false";
    $active_cluster_label = $active_cluster_label ?? 'No Active Cluster';

    $markup = '<span data-drupal-strawberry-shortcake-counter="'.$active_cluster_label_count.'" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
    '.$cluster_count.'<span class="visually-hidden">'.$active_cluster_label.' Count</span>
  </span>';

    $form['current'] = [
      '#type' => 'item',
      '#title' => $this->t('Active Cluster: @label', ['@label' => $active_cluster_label]),
      '#markup' => $markup,
      '#wrapper_attributes' => [
        'class' => ['container-inline btn button btn-secondary position-relative'],
      ],
    ];
    $all_clusters = $this->tempStoreShared->get('all_clusters') ?? [];
    $all_clusters_options = array_combine(array_keys($all_clusters), array_keys($all_clusters));
    $form['shortcake_active_cluster'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Clusters'),
      '#empty_option' => $this->t('- Create New One - '),
      '#options' => $all_clusters_options,
      '#default_value' => $active_cluster_label,
      '#wrapper_attributes' => [
        'class' => ['container-inline'],
      ],
      '#access' => !empty($all_clusters_options),
    ];

    $form['new_cluster'] = [
      '#type' => 'textfield',
      '#title' => 'Cluster Name',
      '#states' => [
        'required' => [
          ':input[name="shortcake_active_cluster"]' => ['value' => ''],
        ],
        'visible' => [
          ':input[name="shortcake_active_cluster"]' => ['value' => ''],
        ],
      ],
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create New'),
      '#button_type' => 'Primary',
      '#submit' => ['::submitFormCreate'],
      '#ajax' => [
        'callback' => '::createCluster',
        'event' => 'click',
        'effect' => 'fade',
        'wrapper' => $wrapper_id,
      ],
      '#states' => [
        'visible' => [
          ':input[name="shortcake_active_cluster"]' => ['value' => ''],
        ],
        'enabled' => [
          ':input[name="new_cluster"]' => ['!value' => ''],
        ],
      ]
    ];
    $form['actions']['select'] = [
      '#type' => 'submit',
      '#value' => $this->t('Activate'),
      '#button_type' => 'Primary',
      '#submit' => ['::selectFormCreate'],
      '#ajax' => [
        'callback' => '::selectCluster',
        'event' => 'click',
        'effect' => 'fade',
        'wrapper' => $wrapper_id,
      ],
      '#states' => [
        'visible' => [
          ':input[name="shortcake_active_cluster"]' => ['!value' => ''],
        ],
      ]
    ];
    $form['#attached']['library'][] = 'strawberry_shortcake/clustering_strawberry_shortcake';
    return $form;
  }

  /**
   * @param array                                $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function submitFormCreate(array &$form, FormStateInterface $form_state) {
    $all_clusters = $this->tempStoreShared->get('all_clusters') ?? [];
    $cluster_label = trim(($form_state->getValue('new_cluster') ?? ''));
    if (in_array($cluster_label, array_keys($all_clusters))) {
      $cluster_data = ['label' => $cluster_label, 'uuid' => $all_clusters[$cluster_label]];
    }
    else {
      $cluster_data = [
        'label' => $cluster_label,
        'uuid' => \Ramsey\Uuid\Uuid::uuid4()->toString()
      ];
      $all_clusters = $all_clusters + [$cluster_data['label'] => $cluster_data['uuid']];
    }
    $this->tempStorePrivate->set('active_cluster', $cluster_data);
    $this->tempStoreShared->set('all_clusters', $all_clusters);
    $form_state->setValue('shortcake_active_cluster', $cluster_data['label']);
    $form_state->setRebuild();
  }

  /**
   * @param array                                $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function selectFormCreate(array &$form, FormStateInterface $form_state) {
    $all_clusters = $this->tempStoreShared->get('all_clusters') ?? [];
    $cluster_label = $form_state->getValue('shortcake_active_cluster') ?? NULL;
    if ($cluster_label && in_array($cluster_label, array_keys($all_clusters))) {
      $cluster_data = ['label' => $cluster_label, 'uuid' => $all_clusters[$cluster_label]];
      $this->tempStorePrivate->set('active_cluster', $cluster_data);
    }
    $form_state->setRebuild();
  }

  /**
   * @param array                                $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild();
  }



  /**
   *
   * @param array $form
   *   The form array to remove elements from.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function createCluster(array &$form, FormStateInterface $form_state) {
    $selector = '#strawberry-shortcake_ui-form-wrapper';
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand($selector, $form));
    return $response;
  }

  /**
   *
   * @param array $form
   *   The form array to remove elements from.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function selectCluster(array &$form, FormStateInterface $form_state) {
    $selector = '#strawberry-shortcake_ui-form-wrapper';
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand($selector, $form));
    return $response;
  }


}
