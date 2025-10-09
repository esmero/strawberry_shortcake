<?php

namespace Drupal\strawberry_shortcake\Form;

use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
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
        '#type' => 'html_tag',
        '#tag' => 'img',
      ];
      $attribute = new Attribute();
      $attribute->setAttribute('data-sbf-annotations-fileuuid', $splitted_id[3] ?? NULL);
      $attribute->setAttribute('data-sbf-annotations-flavorid', $sbf_id);
      $attribute->setAttribute('data-sbf-annotations-nodeuuid', $ado->uuid());
      $attribute->setAttribute('data-sbf-annotations-notinteractive',TRUE);
      $attribute->addClass('rounded');
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
          $img['#attributes'] = $attribute->toArray();
        }
      }
      else {
        return NULL;
        // File not found?
      }
    }

    return $img;
  }

  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL) {
    $wrapper_id= 'strawberry-shortcake_ui-manager-form-wrapper';
    $form['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['#suffix'] = '</div>';
    $cluster_count = 0;

    $first_member = NULL;
    $all_clusters = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTERS_COLLECTION)->get('all_clusters') ?? [];
    $cluster_label = $form_state->getValue('shortcake_active_cluster') ?? NULL;
    if ($cluster_label && in_array($cluster_label, array_keys($all_clusters))) {
      $active_cluster = ['label' => $cluster_label, 'uuid' => $all_clusters[$cluster_label]];
    }
    else {
      $first_cluster_label = array_key_first($all_clusters);
      $active_cluster = ['label' => $first_cluster_label, 'uuid' => $all_clusters[$first_cluster_label]];
    }



    if (isset($active_cluster['label']) && isset($active_cluster['uuid'])) {
      $active_cluster_label = $active_cluster['label'];
      $cluster_members = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->get("cluster:".$active_cluster['uuid']) ?? [];
      $member_ids = array_keys($cluster_members);
      $first_member = reset($member_ids);
      $cluster_count = count($cluster_members);
    }
    if ($first_member) {
      $form['items'] = $this->renderItem($first_member);
    }


    $active_cluster_label_count = $active_cluster_label ? "true" : "false";
    $active_cluster_label = $active_cluster_label ?? 'No Active Cluster';

    $markup = '<span data-drupal-strawberry-shortcake-counter="'.$active_cluster_label_count.'" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
    '.$cluster_count.'<span class="visually-hidden">'.$active_cluster_label.' Count</span>
  </span>';
    $all_clusters = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTERS_COLLECTION)->get('all_clusters') ?? [];
    $all_clusters_options = array_combine(array_keys($all_clusters), array_keys($all_clusters));

    $form['shortcake_active_cluster'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Clusters'),
      '#options' => $all_clusters_options,
      '#default_value' => $active_cluster_label,
      '#wrapper_attributes' => [
        'class' => ['container-inline'],
      ],
      '#access' => !empty($all_clusters_options),
      '#ajax' => [
        'callback' => '::selectCluster',
        'event' => 'change',
        'effect' => 'fade',
        'wrapper' => $wrapper_id,
      ],
    ];
    $form['current'] = [
      '#type' => 'item',
      '#title' => $this->t('Active Cluster: @label', ['@label' => $active_cluster_label]),
      '#markup' => $markup,
      '#wrapper_attributes' => [
        'class' => ['container-inline btn button btn-secondary position-relative'],
      ],
    ];




    $form['#attached']['library'][] = 'strawberry_shortcake/clustering_strawberry_shortcake';
    return $form;
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
  public function selectCluster(array &$form, FormStateInterface $form_state) {
    $selector = '#strawberry-shortcake_ui-manager-form-wrapper';
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand($selector, $form));
    return $response;
  }


}
