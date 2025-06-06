<?php

namespace Drupal\strawberry_shortcake\Controller;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Cache\CacheableJsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Url;

class ClusterAnnotationController extends ControllerBase {

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
    ParseModePluginManager $parse_mode_manager
  ) {
    $this->requestStack = $request_stack;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->entityTypeManager = $entitytype_manager;
    $this->renderer = $renderer;
    $this->mimeTypeGuesser = $mime_type_guesser;
    $this->tempStoreShared = $temp_store_factory->get('strawberry_shortcake');
    $this->tempStorePrivate = $private_temp_store_factory->get('strawberry_shortcake_private');
    $this->parseModeManager = $parse_mode_manager;
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
            $cluster_members = $this->tempStoreShared->get("cluster:".$active_cluster['uuid']) ?? [];
            $cluster_members[$flavorid] = TRUE;
            $cluster_count = count($cluster_members);
            $cluster_member_info = $this->tempStoreShared->get($flavorid) ?? [];
            // Foreach Cluster UUID (I am  not silly to think, I can use the label as ID! but we enforce labels to be unique too)
            // We store the update time too. Might be handy?
            $otherclusters = [];
            $all_clusters = $this->tempStoreShared->get('all_clusters') ?? [];
            $all_clusters = array_flip($all_clusters);
            foreach ($cluster_member_info as $uuid => $timestamp) {
              $otherclusters[] = $all_clusters[$uuid];
            }
            $cluster_member_info[$active_cluster['uuid']] = \Drupal::time()->getRequestTime();
            $this->tempStoreShared->set("cluster:".$active_cluster['uuid'], $cluster_members);
            $this->tempStoreShared->set($flavorid, $cluster_member_info);

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
            $cluster_members = $this->tempStoreShared->get("cluster:".$active_cluster['uuid']) ?? [];
            unset($cluster_members[$flavorid]);
            $cluster_count = count($cluster_members);
            $cluster_member_info = $this->tempStoreShared->get($flavorid) ?? [];
            // Foreach Cluster UUID (I am  not silly to think, I can use the label as ID! but we enforce labels to be unique too)
            // We store the update time too. Might be handy?
            unset($cluster_member_info[$active_cluster['uuid']]);
            if (!count($cluster_member_info)) {
              $this->tempStoreShared->delete($flavorid);
            }
            else {
              $this->tempStoreShared->set($flavorid, $cluster_member_info);
            }
            $this->tempStoreShared->set("cluster:".$active_cluster['uuid'], $cluster_members);

            $otherclusters = [];
            $all_clusters = $this->tempStoreShared->get('all_clusters') ?? [];
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
          $active_cluster = $this->tempStorePrivate->get('active_cluster') ?? [];
          $active_cluster_uuid = $active_cluster['uuid'] ?? NULL;
          $cluster_member_info = $this->tempStoreShared->get($flavorid) ?? [];
          $all_clusters = $this->tempStoreShared->get('all_clusters') ?? [];
          $all_clusters = array_flip($all_clusters);
          $incurrentcluster =  FALSE;
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


}