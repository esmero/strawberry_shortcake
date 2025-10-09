<?php

namespace Drupal\strawberry_shortcake\Form;

use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\strawberry_shortcake\Controller\ClusterAnnotationController;
use Drupal\Component\Utility\UrlHelper;


/**
 * Builds the UI for Cluster export/commit
 */
class StrawberryShortCakeClusterManagerUIForm extends FormBase {


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
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs The shortcake UI
   *
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $temp_store_factory
   */
  public function __construct(SharedTempStoreFactory $temp_store_factory, PrivateTempStoreFactory $private_temp_store_factory, KeyValueFactoryInterface $key_value, EntityTypeManagerInterface $entitytype_manager) {
    $this->tempStoreShared = $temp_store_factory->get('strawberry_shortcake');
    $this->tempStorePrivate = $private_temp_store_factory->get('strawberry_shortcake_private');
    $this->keyValue = $key_value;
    $this->entityTypeManager = $entitytype_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tempstore.shared'),
      $container->get('tempstore.private'),
      $container->get('strawberryfield.keyvalue.database'),
      $container->get('entity_type.manager')
    );
  }

  public function getFormId() {
    return 'strawberry_shortcake_ui_manager_form';
  }

  public function renderItem($sbf_id) {

    [$unused, $raw_id] = Utility::splitCombinedId($sbf_id);
    $id = $raw_id;
    $img = NULL;
    // $sbf_id = $entity->id() . ':'.$sequence .':'.$translation_id.':'.$file->uuid().':'.$data->plugin_config_entity_id;
    // check if the NODE is there
    $splitted_id = explode(':', $id);
    $ado =  $this->entityTypeManager->getStorage('node')->load($splitted_id[0] ?? NULL);
    if ($ado) {
      $img = [
        '#type' => 'container',
        'cluster_member' => [
          '#type' => 'html_tag',
          '#tag' => 'img',
        ]
      ];

      $attribute = new Attribute();
      $attribute->setAttribute('data-sbf-annotations-fileuuid', $splitted_id[3] ?? NULL);
      $attribute->setAttribute('data-sbf-annotations-flavorid', $sbf_id);
      $attribute->setAttribute('data-sbf-annotations-nodeuuid', $ado->uuid());
      $attribute->setAttribute('data-sbf-annotations-notinteractive',TRUE);
      $attribute->addClass('rounded');
      $attribute->addClass('col');
      $url = $this->config('format_strawberryfield.iiif_settings')
        ->get('pub_server_url');
      $files = $this->entityTypeManager->getStorage('file')
        ->loadByProperties(['uuid' => $splitted_id[3]]);
      /** @var \Drupal\file\Entity\File $file */
      $storage_scheme = $this->config('strawberryfield.storage_settings')
        ->get('file_scheme') ?? "s3";
      $file = reset($files);
      if ($file) {
        $file_url = $file->getFileUri();
        if (str_starts_with($file_url, $storage_scheme)) {
          $file_url = str_replace($storage_scheme . '://', "", $file_url);
          $url = $url . "/" . urlencode($file_url) . "/full/320,/0/default.jpg";
          $attribute->setAttribute("src", $url);
          $attribute->setAttribute("id", 'sbf-cluster-entry-' . md5($sbf_id));
          $img['cluster_member']['#attributes'] = $attribute->toArray();
        }
      }
      else {
        return NULL;
        // File not found?
      }
    }

    return $img;
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $wrapper_id= 'strawberry-shortcake_ui-manager-form-wrapper';
    $cluster_count = 0;
    $first_member = NULL;
    $all_clusters = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTERS_COLLECTION)->get('all_clusters') ?? [];
    $all_clusters_options = array_combine(array_keys($all_clusters), array_keys($all_clusters));
    $cluster_label = $form_state->getValue('shortcake_active_cluster') ?? NULL;
    // For the pager.
    if (!$cluster_label) {
      $cluster_label = $this->getRequest()->query->get('active_cluster', NULL);
    }
    if ($cluster_label && in_array($cluster_label, array_keys($all_clusters))) {
      $active_cluster = ['label' => $cluster_label, 'uuid' => $all_clusters[$cluster_label]];
    }
    else {
      $first_cluster_label = array_key_first($all_clusters);
      $active_cluster = ['label' => $first_cluster_label, 'uuid' => $all_clusters[$first_cluster_label]];
    }
    if (isset($active_cluster['label']) && isset($active_cluster['uuid'])) {
      $active_cluster_label = $active_cluster['label'];
      $active_cluster_uuid = $active_cluster['uuid'];
      $cluster_members = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->get("cluster:".$active_cluster['uuid']) ?? [];
      $member_ids = array_unique(array_keys($cluster_members));
      $cluster_count = $total_rows = count($member_ids);

      $markup = '<span data-drupal-strawberry-shortcake-counter="true" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
    '.$cluster_count.'<span class="visually-hidden">'.$active_cluster_label.' Count</span>
  </span>';
      $form['clusters']['shortcake_active_cluster'] = [
        '#type' => 'select',
        '#title' => $this->t('Select Clusters'),
        '#options' => $all_clusters_options,
        '#default_value' => $active_cluster_label,
        '#wrapper_attributes' => [
          'class' => ['container-inline'],
        ],
        '#access' => !empty($all_clusters_options),
      ];
      $form['clusters']['current'] = [
        '#type' => 'item',
        '#title' => $this->t('Active Cluster: @label', ['@label' => $active_cluster_label]),
        '#markup' => $markup,
        '#wrapper_attributes' => [
          'class' => ['text-white badge rounded p-3 position-relative position-relative'],
          'style' => ['background-color:var(--bs-secondary, rgb(97, 211, 157))']
        ],
      ];
      $form['clusters']['actions'] = [
        '#type' => 'submit',
        '#title' => $this->t('Select Cluster'),
        '#value' => $this->t('Select Cluster'),
        '#wrapper_attributes' => [
          'class' => ['container-inline btn button btn-primary position-relative'],
        ],
      ];


      $total_rows = $total_rows - 1;
      $num_per_page = 10;
      /// Drupal IS SO BAD. Ajax taints the pager. Dam
      $pager = \Drupal::service('pager.manager')->createPager($total_rows, $num_per_page);
      $page = $pager->getCurrentPage();
      $offset = $num_per_page * $page;
      $tobe_rendered_members = [];
      if ($cluster_count > $num_per_page) {
        // Get a slice of the array of $num_per_page size
        // Stupid Drupal and ajax wrapper permeating over the place
        // This sets a fixed #parameters for the wrapper so we don't keep having AJAX forms
        // @see https://www.drupal.org/project/drupal/issues/2934463
        $form['clusters']['#attributes']['id'] = $wrapper_id;
        $form['clusters']['#type'] = 'container';
        $form['clusters']['pager_top'] = [
          '#type' => 'pager',
          '#parameters' => ['active_cluster' => $active_cluster_label]
        ];

        $form['clusters']['offset'] = [
          '#type' => 'value',
          '#value' => $offset,
        ];
        $form['clusters']['num_per_page'] = [
          '#type' => 'value',
          '#value' => $num_per_page,
        ];
        $form['clusters']['total_rows'] = [
          '#type' => 'value',
          '#value' => $total_rows,
        ];
      }
    }
    $tobe_rendered_members = array_slice($member_ids, $offset, $num_per_page, FALSE);
    if (!empty($tobe_rendered_members)) {
      foreach($tobe_rendered_members as $id => $sbfid) {
        if (is_string($sbfid)) {
          $form['clusters']['items']['item' . $id] = $this->renderItem($sbfid);
        }
      }
    }




    $form['#attached']['library'][] = 'strawberry_shortcake/clustering_strawberry_shortcake';
    $form['#attached']['drupalSettings']['strawberry_shortcake']['active_cluster'] = $active_cluster['uuid'] ?? NULL;
    return $form;
  }


  /**
   * @param array                                $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
  }


}
