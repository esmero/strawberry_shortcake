<?php

namespace Drupal\strawberry_shortcake\Controller;

use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\search_api\Query\QueryInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;

use Drupal\strawberry_shortcake\Ajax\SbsBroadcastVectors;

class ClusterAnnotationController extends ControllerBase {


  const SBS_CLUSTER_ITEMS_COLLECTION = 'Strawberry_shortcake_flavor_temp';

  const SBS_CLUSTERS_COLLECTION = 'Strawberry_shortcake_all_clusters';
  /**
   * Symfony\Component\HttpFoundation\RequestStack definition.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The Strawberry Field Utility Service.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  protected $strawberryfieldUtility;

  /**
   * The Drupal Renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The MIME type guesser.
   *
   * @var \Symfony\Component\Mime\MimeTypeGuesserInterface
   */
  protected $mimeTypeGuesser;

  /**
   * The tempstore.
   *
   * @var \Drupal\Core\TempStore\SharedTempStore
   */
  protected $tempStoreShared;

  /**
   * The tempstore.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStorePrivate;

  /**
   * Key value service.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected $keyValue;

  /**
   * The parse mode manager.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager
   */
  protected $parseModeManager;

  /**
   * Cluster Annotation constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The Symfony Request Stack.
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityService $strawberryfield_utility_service
   *   The SBF Utility Service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entitytype_manager
   *   The Entity Type Manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The Drupal Renderer Service.
   * @param \Symfony\Component\Mime\MimeTypeGuesserInterface $mime_type_guesser
   *   The Drupal Mime type guesser Service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   */
  public function __construct(
    RequestStack $request_stack,
    StrawberryfieldUtilityService $strawberryfield_utility_service,
    EntityTypeManagerInterface $entitytype_manager,
    RendererInterface $renderer,
    MimeTypeGuesserInterface $mime_type_guesser,
    SharedTempStoreFactory $temp_store_factory,
    PrivateTempStoreFactory $private_temp_store_factory,
    ParseModePluginManager $parse_mode_manager,
    KeyValueFactoryInterface $keyValue
  ) {
    $this->requestStack = $request_stack;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->entityTypeManager = $entitytype_manager;
    $this->renderer = $renderer;
    $this->mimeTypeGuesser = $mime_type_guesser;
    $this->tempStoreShared = $temp_store_factory->get('strawberry_shortcake');
    $this->tempStorePrivate = $private_temp_store_factory->get('strawberry_shortcake_private');
    $this->parseModeManager = $parse_mode_manager;
    $this->keyValue = $keyValue;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('strawberryfield.utility'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('file.mime_type.guesser'),
      $container->get('tempstore.shared'),
      $container->get('tempstore.private'),
      $container->get('plugin.manager.search_api.parse_mode'),
      $container->get('strawberryfield.keyvalue.database')
    );
  }

  /**
   * Add Annotation to Cluster via (POST).
   *
   * @param \Symfony\Component\HttpFoundation\Request
   *   The Full HTTP Request
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   A Node as argument
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A simple JSON response.
   */
  public function postAddToCluster(Request $request,
    ContentEntityInterface $node
  ): JsonResponse {
    if ($this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {
      $everything = $this->requestStack->getCurrentRequest()->request->all();
      $flavorid = $everything['flavorid'] ?? NULL;
      if (!$flavorid) {
        $data = [
          'success' => FALSE
        ];
      }
      else {
        try {
          $active_cluster = $this->tempStorePrivate->get('active_cluster') ?? [];
          if (isset($active_cluster['label']) && isset($active_cluster['uuid'])) {
            // We do two things (old-fashioned double hash.)
            // - We store, per cluster all member ids.
            // - We store the actual member id holding all clusters it belongs to.
            $cluster_members = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->get("cluster:" . $active_cluster['uuid']) ?? [];
            $cluster_members[$flavorid] = TRUE;
            $cluster_count = count($cluster_members);
            $cluster_member_info = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->get($flavorid) ?? [];
            // Foreach Cluster UUID (I am  not silly to think, I can use the label as ID! but we enforce labels to be unique too)
            // We store the update time too. Might be handy?
            $otherclusters = [];
            $all_clusters =  $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTERS_COLLECTION)->get('all_clusters') ?? [];
            $all_clusters = array_flip($all_clusters);
            foreach ($cluster_member_info as $uuid => $timestamp) {
              $otherclusters[] = $all_clusters[$uuid];
            }
            $cluster_member_info[$active_cluster['uuid']] = \Drupal::time()
              ->getRequestTime();
            $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->set("cluster:" . $active_cluster['uuid'], $cluster_members);
            $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->set($flavorid, $cluster_member_info);
            $data = [
              'incurrentcluster' => TRUE,
              'otherclusters' => $otherclusters,
              'count' => $cluster_count,
              'success' => TRUE
            ];
          }
          else {
            // We could introduce maybe a message for the POST API to display?
            $data = [
              'success' => FALSE
            ];
          }
        }
        catch
        (\Drupal\Core\TempStore\TempStoreException $exception) {
          $data = [
            'success' => FALSE
          ];
        }
      }
    }
    else {
      throw new BadRequestHttpException(
        "Sorry we can't add Annotations to Cluster"
      );
    }
    $jsonresponse = new JsonResponse($data);
    $jsonresponse->headers->set('X-Drupal-Ajax-Token', 1);
    return $jsonresponse;
  }

  /**
   * Delete Annotation from Cluster via (POST).
   *
   * @param \Symfony\Component\HttpFoundation\Request
   *   The Full HTTP Request
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   A Node as argument
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A simple JSON response.
   */
  public function postDeleteFromCluster(Request $request,
    ContentEntityInterface $node
  ): JsonResponse {
    if ($this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {
      $everything = $this->requestStack->getCurrentRequest()->request->all();
      $flavorid = $everything['flavorid'] ?? NULL;
      if (!$flavorid) {
        $data = [
          'success' => FALSE
        ];
      }
      else {
        try {
          $active_cluster = $this->tempStorePrivate->get('active_cluster') ?? [];
          if (isset($active_cluster['label']) && isset($active_cluster['uuid'])) {
            // We do two things (old-fashioned double hash.)
            // - We store, per cluster all member ids.
            // - We store the actual member id holding all clusters it belongs to.
            $cluster_members = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->get("cluster:" . $active_cluster['uuid']) ?? [];
            unset($cluster_members[$flavorid]);
            $cluster_count = count($cluster_members);
            $cluster_member_info = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->get($flavorid) ?? [];
            // Foreach Cluster UUID (I am  not silly to think, I can use the label as ID! but we enforce labels to be unique too)
            // We store the update time too. Might be handy?
            unset($cluster_member_info[$active_cluster['uuid']]);
            if (!count($cluster_member_info)) {
              $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->delete($flavorid);
            }
            else {
              $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->set($flavorid, $cluster_member_info);
            }
            $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->set("cluster:" . $active_cluster['uuid'], $cluster_members);

            $otherclusters = [];
            $all_clusters = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTERS_COLLECTION)->get('all_clusters') ?? [];
            $all_clusters = array_flip($all_clusters);
            foreach ($cluster_member_info as $uuid => $timestamp) {
              $otherclusters[] = $all_clusters[$uuid];
            }

            $data = [
              'incurrentcluster' => FALSE,
              'otherclusters' => $otherclusters,
              'count' => $cluster_count,
              'success' => TRUE
            ];
          }
          else {
            // We could introduce maybe a message for the POST API to display?
            $data = [
              'success' => FALSE
            ];
          }
        }
        catch
        (\Drupal\Core\TempStore\TempStoreException $exception) {
          $data = [
            'success' => FALSE
          ];
        }
      }
    }
    else {
      throw new BadRequestHttpException(
        "Sorry we can't add Annotations to Cluster"
      );
    }
    $jsonresponse = new JsonResponse($data);
    $jsonresponse->headers->set('X-Drupal-Ajax-Token', 1);
    return $jsonresponse;
  }

  /**
   * Get Annotation Membership Info from Cluster via (POST).
   *
   * @param \Symfony\Component\HttpFoundation\Request
   *   The Full HTTP Request
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   A Node as argument
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A simple JSON response.
   */
  public function postGetClusterMemberInfo(Request $request,
    ContentEntityInterface $node
  ): JsonResponse {
    if ($this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {
      $everything = $this->requestStack->getCurrentRequest()->request->all();
      $flavorid = $everything['flavorid'] ?? NULL;
      if (!$flavorid) {
        $data = [
          'success' => TRUE,
          'incurrentcluster' => FALSE,
          'otherclusters' => [],
        ];
      }
      else {
        try {
          // IF POST passes explicitly a active cluster UUID, we use that instead of the "live session" one
          // e.g This is needed when managing cluster from Manager Cluster Form.
          if (!empty($everything['cluster_uuid'])) {
            $active_cluster_uuid = $everything['cluster_uuid'];
          }
          else {
            $active_cluster = $this->tempStorePrivate->get('active_cluster') ?? [];
            $active_cluster_uuid = $active_cluster['uuid'] ?? NULL;
          }
          $cluster_member_info = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->get($flavorid) ?? [];
          $all_clusters = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTERS_COLLECTION)->get('all_clusters') ?? [];
          $all_clusters = array_flip($all_clusters);
          $incurrentcluster = FALSE;
          $otherclusters = [];
          foreach ($cluster_member_info as $uuid => $timestamp) {
            if (isset($all_clusters[$uuid]) && $active_cluster_uuid != $uuid) {
              $otherclusters[] = $all_clusters[$uuid];
            }
            if ($active_cluster_uuid == $uuid) {
              $incurrentcluster = TRUE;
            }
          }

          $data = [
            'incurrentcluster' => $incurrentcluster,
            'otherclusters' => $otherclusters,
            'success' => TRUE
          ];
        }
        catch (\Drupal\Core\TempStore\TempStoreException $exception) {
          $data = [
            'success' => FALSE
          ];
        }
      }
    }
    else {
      throw new BadRequestHttpException(
        "Sorry we can't add Annotations to Cluster"
      );
    }
    $jsonresponse = new JsonResponse($data);
    $jsonresponse->headers->set('X-Drupal-Ajax-Token', 1);
    return $jsonresponse;
  }

  public function calculateCentroids(Request $request, $cluster = 'active') {
    $response = new AjaxResponse();
    $sum_by_processor = [];
    $count_by_processor = [];
    $size_by_processor_id = [];
    $centroid_by_processor = [];
    try {
      if ($cluster == 'active') {
        $active_cluster = $this->tempStorePrivate->get('active_cluster') ?? [];
        if (isset($active_cluster['label']) && isset($active_cluster['uuid'])) {
          // We do two things (old-fashioned double hash.)
          // - We store, per cluster all member ids.
          // - We store the actual member id holding all clusters it belongs to.
          $cluster_members = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->get("cluster:" . $active_cluster['uuid']) ?? [];
          $cluster_members = array_filter($cluster_members);
          $cluster_members = array_keys($cluster_members);
          $cluster_members_chunked = array_chunk($cluster_members, 25, TRUE);

          foreach ($cluster_members_chunked as $cluster_member_chunk) {
            $vectors_from_flavor = $this->flavorVectorfromSolrIndex([], [], [], [], $cluster_member_chunk, 0, 25);
            foreach ($vectors_from_flavor as $processor_id => $vectors) {
              $size_by_processor_id[$processor_id] = $size_by_processor_id[$processor_id] ?? count(reset($vectors) ?? []);
              $count_by_processor[$processor_id] = $count_by_processor[$processor_id] ?? 0;
              $sum_by_processor[$processor_id] = $sum_by_processor[$processor_id] ?? [];
              $this->sumVectors($vectors, $size_by_processor_id[$processor_id], $count_by_processor[$processor_id], $sum_by_processor[$processor_id]);
              $mediodBestCandidate[$processor_id] = $this->mediodVectors($vectors, $size_by_processor_id[$processor_id]);
            }
          }
          foreach ($sum_by_processor as $processor_id => $summed_vector) {
            foreach ($summed_vector as $index => $component) {
              if (isset($count_by_processor[$processor_id]) && $count_by_processor[$processor_id] > 0) {
                $centroid_by_processor[$processor_id][$index] = $component/$count_by_processor[$processor_id];
              }
            }
          }
          $centroid_by_processor = $centroid_by_processor;
        }
      }
      else {
        // we don't need the cluster label/extra info here, just the members, if any and exists..
        if (\Drupal\Component\Uuid\Uuid::isValid($cluster)) {
          $cluster_members = $this->keyValue->get(ClusterAnnotationController::SBS_CLUSTER_ITEMS_COLLECTION)->get("cluster:" . $cluster) ?? [];
          $cluster_members = array_filter($cluster_members);
          $cluster_members = array_keys($cluster_members);
          $cluster_members_chunked = array_chunk($cluster_members, 25, TRUE);
          foreach ($cluster_members_chunked as $cluster_member_chunk) {
            $this->flavorVectorfromSolrIndex([], [], [], [], $cluster_member_chunk, 0, 25);
          }
        }
      }
    }
    catch (\Exception $exception) {
      error_log($exception->getMessage());
    }

    if (count($centroid_by_processor)) {
      $response->addCommand(new MessageCommand($this->t('Centroids calculated for @processors.', ['@processors' => implode(",", array_keys($centroid_by_processor))]), '#strawberry-shortcake_ui-form-wrapper', [
        'type' => 'status',
        'announce' => 'Centroids calculated.'
      ]));
      $response->addCommand(new SbsBroadcastVectors($centroid_by_processor));
    }
    else {
      $response->addCommand(new MessageCommand('We could not calculate the Centroid.', '#strawberry-shortcake_ui-form-wrapper', [
        'type' => 'error',
        'announce' => 'We could not calculate the Centroid.'
      ]));
    }
    return $response;
  }

  private function sumVectors(array $vectors, $dimension = 512, &$numvectors = 0, &$centroid_sum = []) {
    $centroid_sum = empty($centroid_sum) ? array_fill(0, $dimension, 0) : $centroid_sum;
    $num_valid_vectors = 0;
    foreach ($vectors as $vector) {
      // Explaining a user why a vector is wrong makes no sense
      // Skip if the expected dimension does not match
      // pass the dimension as an argument by reference.
      if (count($vector) == $dimension && $dimension == count($centroid_sum)) {
        $num_valid_vectors++;
        foreach ($vector as $i => $component) {
          $centroid_sum[$i] += $component;
        }
      }
      $numvectors = $numvectors + $num_valid_vectors;
    }
  }

  private function euclideanDistance(array $vector1, array $vector2, int $dimension):float {
    $sum = 0.0;
    for ($i = 0; $i < $dimension; $i++) {
      $diff = $vector1[$i] - $vector2[$i];
      $sum += $diff * $diff;
    }
    return sqrt($sum);
  }


  private function mediodVectors(array $vectors, $dimension = 512) {
    // @see https://pure.tudelft.nl/ws/portalfiles/portal/150383726/978_3_031_26387_3_10.pdf;
    $foundMediod = null;
    $foundOutlier = null;
    $minTotalDistance = INF;
    $maxTotalDistance = 0.0;
    foreach ($vectors as $candidateID => $candidateMedoid) {
      $totalDistance = 0.0;
      foreach ($vectors as $vectorID => $vector) {
        // If i compare the candidate against itself then distance is 0. Useless computing.
       if ($candidateID != $vectorID) {
         $totalDistance += $this->euclideanDistance($candidateMedoid, $vector, $dimension);
       }
      }
      if ($totalDistance < $minTotalDistance) {
        $minTotalDistance = $totalDistance;
        $foundMediod = $candidateID;
      }
      if ($totalDistance > $maxTotalDistance) {
        // Also return the one more far away from all others.
        $maxTotalDistance = $totalDistance;
        $foundOutlier = $candidateID;
      }
    }

    return [$foundMediod,$foundOutlier];
  }

  /**
   * Fetches Flavors from the backend and converts them to Annotations
   *
   * Very similar to \Drupal\strawberryfield\Controller\StrawberryfieldFlavorDatasourceSearchController::originalocrfromSolrIndex
   * but with more moving parts (and checks)
   *
   * @param array $processors
   * @param array $file_uris
   * @param array $file_uuids
   * @param array $node_ids
   * @param array $flavor_ids
   * @param int $offset
   * @param int $limit
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function flavorVectorfromSolrIndex(array $processors, array $file_uris, array $file_uuids, array $node_ids = [], array $flavor_ids = [], $offset = 0, $limit = 100): array {
    $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();

    /* @var \Drupal\search_api\IndexInterface[] $indexes */
    $vectors_by_flavor = [];
    $result_snippets = [];
    $search_result = [];
    $annotations = [];

    foreach ($indexes as $search_api_index) {
      // Create the query.
      $query = $search_api_index->query([
        'limit' => $limit,
        'offset' => $offset,
      ]);
      $vectors_by_flavor = [];
      // Needed bc the default might Clash with a View! and Facets!
      // And end running facets and setting active values on every request!
      // @NOTE: New to me Diego. See \Drupal\facets\EventSubscriber\SearchApiSubscriber::queryAlter
      $query->setSearchId('sbf_vectors_from_solr');

      $parse_mode = $this->parseModeManager->createInstance('direct');
      $query->setParseMode($parse_mode);
      // No key set here, this is a filters query only
      $allfields_translated_to_solr = $search_api_index->getServerInstance()
        ->getBackend()
        ->getSolrFieldNames($query->getIndex());
      // @TODO research if we can do a single Query instead of multiple ones?

      if (isset($allfields_translated_to_solr['sbf_plaintext'])) {
        $query->setFulltextFields(['sbf_plaintext']);
      }
      else {
        $this->getLogger('format_strawberryfield')
          ->error('We can not execute a Content Search API query against Plain Extracted Text without a field named <em>sbf_plaintext</em> of type Full Text');
        $search_result['annotations'] = [];
        $search_result['total'] = 0;
        return $search_result;
      }

      $flavor_id_field = 'search_api_id';

      //@TODO: Should this also be a config as `iiif_content_search_api_parent_node_fields` is for example?
      $uuid_uri_field = 'file_uuid';

      $parent_conditions = $query->createConditionGroup('OR');
      $uri_conditions = $query->createConditionGroup('OR');
      $uuid_conditions = $query->createConditionGroup('OR');

      if (count($node_ids)) {
        if (count($parent_conditions->getConditions())) {
          $query->addConditionGroup($parent_conditions);
        }
      }

      $query->addCondition('search_api_datasource', 'strawberryfield_flavor_datasource');
      if (count($flavor_ids)) {
        $query->addCondition('search_api_id', $flavor_ids, 'IN');
      }
      if (count($processors)) {
        $query->addCondition('processor_id', $processors, 'IN');
      }

      $fields_to_retrieve['id'] = 'id';
      if (isset($allfields_translated_to_solr['parent_sequence_id'])) {
        $fields_to_retrieve['parent_sequence_id'] = $allfields_translated_to_solr['parent_sequence_id'];
      }
      if (isset($allfields_translated_to_solr['uuid'])) {
        $fields_to_retrieve['uuid'] = $allfields_translated_to_solr['uuid'];
      }
      if (isset($allfields_translated_to_solr['sequence_id'])) {
        $fields_to_retrieve['sequence_id'] = $allfields_translated_to_solr['sequence_id'];
        $query->sort('sequence_id', QueryInterface::SORT_ASC);
      }
      if (isset($allfields_translated_to_solr[$uuid_uri_field])) {
        $fields_to_retrieve[$uuid_uri_field] = $allfields_translated_to_solr[$uuid_uri_field];
        // Sadly we have to add the condition here, what if file_uuid is not defined?
      }
      else {
        $this->getLogger('strawberry_shortcake')
          ->warning('For Content Search API queries/WebAnnotations from Strawberryflavors, please add a search api field named <em>file_uuid</em> containing the UUID of the file entity that generated the extraction you want to search');
      }
      if (isset($allfields_translated_to_solr['fulltext'])) {
        $fields_to_retrieve['fulltext'] = $allfields_translated_to_solr['fulltext'];
      }
      else {
        $this->getLogger('strawberry_shortcake')
          ->warning('For WebAnnotations from Strawberryflavors using OCR, please add a search api field named <em>fulltext</em> containing the complete OCR as XML');
        $search_result['annotations'] = [];
        $search_result['total'] = 0;
        return $search_result;
      }

      $have_file_condition = FALSE;
      if (count($file_uris)) {
        //Note here. If we don't have any fields configured the response will contain basically ANYTHING
        // in the repo. So option 1 is make `iiif_content_search_api_file_uri_fields` required
        // bail out if empty? Or, we can add a short limit... that works too for now
        // April 2024, to enable in the future postprocessor that generate SBF but not from files (e.g WARC)>
        $iiifConfig = $this->config('format_strawberryfield.iiif_settings');
        foreach ($iiifConfig->get('iiif_content_search_api_file_uri_fields') ?? [] as $uri_field) {
          if (isset($allfields_translated_to_solr[$uri_field])) {
            $uri_conditions->addCondition($uri_field, $file_uris, 'IN');
            $fields_to_retrieve[$uri_field]
              = $allfields_translated_to_solr[$uri_field];
          }
          if (count($uri_conditions->getConditions())) {
            $have_file_condition = TRUE;
            $query->addConditionGroup($uri_conditions);
          }
        }
      }
      if (count($file_uuids)) {
        if (isset($allfields_translated_to_solr[$uuid_uri_field])) {
          $uuid_conditions->addCondition($uuid_uri_field, $file_uuids, 'IN');
        }
        if (count($uuid_conditions->getConditions())) {
          $have_file_condition = TRUE;
          $query->addConditionGroup($uuid_conditions);
        }
      }
      if (!$have_file_condition) {
        // in case no files are passed to filter, simply limit all to less?
        $query->setOption('limit', 10);
      }
      // This might/not/be/respected. (API v/s reality)
      $query->setOption('search_api_retrieved_field_values', array_values($fields_to_retrieve));
      $query->setProcessingLevel(QueryInterface::PROCESSING_FULL);
      $results = $query->execute();
      unset($fields_to_retrieve['id']);
      unset($fields_to_retrieve['parent_sequence_id']);
      if ($results->getResultCount() >= 1) {
        foreach ($results as $result) {
          $processor_id = $result->getField('processor_id')->getValues();
          $processor_id = reset($processor_id);
          if ($processor_id) {
            foreach ($result->getFields(FALSE) as $field_id => $field) {
              /** @var \Drupal\search_api\Item\Field $field */
              if (str_starts_with($field->getType(), 'densevector_') === TRUE) {
                $value = $field->getValues();
                if (count($value) > 0) {
                  // return as key the result id, since for medoid we
                  // really need to say "this doc" respresents it better.
                  $vectors_by_flavor[$processor_id][$result->getId()] = $value;
                  continue;
                }
              }
            }
          }
        }
      }
    }

    return $vectors_by_flavor;
  }



}