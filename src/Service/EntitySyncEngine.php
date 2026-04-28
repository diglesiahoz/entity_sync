<?php

namespace Drupal\entity_sync\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\taxonomy\Entity\Term;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Engine for Entity Sync operations.
 *
 * This class contains the import/template logic so Drush commands stay thin.
 *
 * Notes:
 * - This engine currently focuses on the entity/field patterns used by this project:
 *   nodes + taxonomy terms + menu link content + simple media/file handling.
 * - It can be extended later to support additional field types/schemes.
 */
class EntitySyncEngine {
  use StringTranslationTrait;

  protected string $root = '';
  protected bool $debug = FALSE;

  protected string $defaultSiteLanguage = '';
  protected array $languagesCodes = [];
  protected LoggerChannelInterface $logger;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected LanguageManagerInterface $languageManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityRepositoryInterface $entityRepository,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected FileSystemInterface $fileSystem,
    protected RequestStack $requestStack,
    protected string $appRoot,
  ) {
    $this->logger = $loggerFactory->get('entity_sync');
  }

  /**
   * Reads a file and logs failures consistently.
   */
  protected function readFileContents(string $path, string $context): ?string {
    $raw = file_get_contents($path);
    if ($raw === FALSE) {
      $this->logger->error(sprintf('IO read failed (%s): %s', $context, $path));
      return NULL;
    }
    return $raw;
  }

  /**
   * Writes a file and logs failures consistently.
   */
  protected function writeFileContents(string $path, string $contents, string $context): bool {
    $bytes = file_put_contents($path, $contents);
    if ($bytes === FALSE) {
      $this->logger->error(sprintf('IO write failed (%s): %s', $context, $path));
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Creates a directory recursively and logs failures.
   */
  protected function ensureDirectory(string $path): bool {
    if (is_dir($path)) {
      return TRUE;
    }
    if (!mkdir($path, 0775, TRUE) && !is_dir($path)) {
      $this->logger->error('Could not create directory: ' . $path);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Copies a file and logs failures consistently.
   */
  protected function copyFile(string $source, string $target): bool {
    if (!copy($source, $target)) {
      $this->logger->warning('Could not copy source file to public storage: ' . $source);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Ensures an empty .gitkeep exists in a directory.
   */
  protected function ensureGitkeep(string $directory): void {
    $gitkeepPath = rtrim($directory, '/\\') . '/.gitkeep';
    if (is_file($gitkeepPath)) {
      return;
    }
    $this->writeFileContents($gitkeepPath, '', 'gitkeep create');
  }

  /**
   * Ensures a default dummy image exists in data/files.
   */
  protected function ensureDummyImage(string $directory): void {
    $dummyPath = rtrim($directory, '/\\') . '/dummy_image.png';
    if (is_file($dummyPath)) {
      return;
    }

    // Preferred output: black, large, and standard aspect ratio.
    if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
      $image = imagecreatetruecolor(1920, 1080);
      if ($image !== FALSE) {
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $black);
        $saved = imagepng($image, $dummyPath);
        imagedestroy($image);
        if ($saved) {
          return;
        }
      }
      $this->logger->warning('Could not generate 1920x1080 dummy_image.png using GD.');
    }

    // Fallback in environments without GD.
    $fallback = base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4////fwAJ+wP9KobjigAAAABJRU5ErkJggg==',
      TRUE
    );
    if ($fallback === FALSE) {
      $this->logger->error('Could not create fallback dummy image.');
      return;
    }
    $this->writeFileContents($dummyPath, $fallback, 'dummy image create fallback');
  }

  /**
   * Import content from entity .yml files.
   */
  public function import(array $options = []): void {
    $this->debug = !empty($options['verbose']);
    $this->initRootPath();
    $this->initLanguages();

    $files = glob($this->root . '/*.yml') ?: [];

    if (!empty($options['file'])) {
      $prefix = (string) $options['file'];
      $files = array_values(array_filter($files, function (string $file) use ($prefix): bool {
        return str_starts_with(basename($file), $prefix);
      }));
    }

    if ($files === []) {
      $this->logger->warning(sprintf('No files found to import from "%s".', $this->root));
      return;
    }

    if (!empty($options['delete'])) {
      $allData = [];
      foreach ($files as $file) {
        $raw = $this->readFileContents($file, 'delete scan');
        if ($raw === NULL) {
          continue;
        }
        $decoded = Yaml::decode($raw);
        if (is_array($decoded)) {
          $allData = array_merge($allData, $decoded);
        }
      }
      $this->deleteEntitiesFromData($allData);
    }

    foreach ($files as $file) {
      //$this->logger->notice('Importing file: ' . basename($file));
      $raw = $this->readFileContents($file, 'import');
      if ($raw === NULL) {
        continue;
      }

      $data = Yaml::decode($raw);
      if (!is_array($data)) {
        $this->logger->warning('Invalid YAML content in ' . basename($file));
        continue;
      }

      $updatedData = $this->setEntityBatch($data, $options);
      if ($updatedData !== $data) {
        $this->writeFileContents($file, Yaml::encode($updatedData), 'yaml update');
        //$this->logger->notice('Updated YAML with generated UUIDs: ' . basename($file));
      }
    }

    $this->logger->notice('Finished import.');
  }

  /**
   * Generate import templates.
   */
  public function getTemplate(array $options = []): void {
    $this->debug = !empty($options['verbose']);
    $this->initRootPath();
    $this->initLanguages();

    $entityType = $options['entity-type'] ?? NULL;
    $targetBundle = $options['bundle'] ?? NULL;
    $writeOutput = !empty($options['output']);
    $supportedEntityTypes = ['node', 'taxonomy_term', 'menu_link_content'];

    if (!empty($entityType) && !in_array($entityType, $supportedEntityTypes, TRUE)) {
      $this->logger->error(sprintf('Unsupported entity type for template generation: %s', $entityType));
      return;
    }
    $entityTypes = !empty($entityType) ? [(string) $entityType] : $supportedEntityTypes;

    $outputDir = $this->root;

    if (!is_dir($outputDir) && $writeOutput) {
      if (!$this->ensureDirectory($outputDir)) {
        return;
      }
    }

    foreach ($entityTypes as $templateEntityType) {
      if ($templateEntityType === 'menu_link_content') {
        $menuStorage = $this->entityTypeManager->getStorage('menu');
        $menus = $menuStorage->loadMultiple();
        $availableBundles = array_keys($menus);
        sort($availableBundles);
      }
      else {
        $bundleInfo = $this->bundleInfo->getBundleInfo($templateEntityType);
        $availableBundles = array_keys($bundleInfo);
        sort($availableBundles);
      }

      if (!empty($targetBundle)) {
        if (!in_array($targetBundle, $availableBundles, TRUE)) {
          $this->logger->warning(sprintf('Skipping "%s": bundle "%s" does not exist.', $templateEntityType, $targetBundle));
          continue;
        }
        $bundles = [(string) $targetBundle];
      }
      else {
        $bundles = $availableBundles;
      }

      foreach ($bundles as $bundle) {
        $info = $this->getEntityInfo($templateEntityType, $bundle, TRUE);
        if ($info === []) {
          continue;
        }

        $eLangConf = $this->getTemplateFromFields($info['fields'], $templateEntityType . '.' . $bundle, []);

        // Ensure title placeholder for node.
        if ($templateEntityType === 'node') {
          $eLangConf['title'] = '{{title}}';
        }
        if ($templateEntityType === 'menu_link_content') {
          $eLangConf['menu_name'] = $bundle;
        }

        $eConf = [
          'entity_type' => $templateEntityType,
          'bundle' => $bundle,
          'elements' => [
            [
              '__DEFAULT__' => $eLangConf,
            ],
          ],
        ];

        $template = [$eConf];

        $weight = in_array($templateEntityType, ['taxonomy_term', 'menu_link_content'], TRUE) ? '0' : '1';
        $targetFilePath = rtrim($outputDir, '/\\') . '/' . $weight . '.' . $templateEntityType . '.' . $bundle . '.yml';

        $payload = Yaml::encode($template);

        if (!$writeOutput) {
          print $payload;
        } else {
          if (is_file($targetFilePath)) {
            $this->logger->warning('Template already exists, skipping: ' . $targetFilePath);
            continue;
          }
          if ($this->writeFileContents($targetFilePath, $payload, 'template export')) {
            $this->logger->notice('Template created: ' . $targetFilePath);
          }
        }
      }
    }
  }

  /**
   * Resolves $this->root based on config entity_sync.settings.data_path.
   */
  protected function initRootPath(): void {
    $drupalProjectRoot = dirname($this->appRoot);
    $defaultRoot = $drupalProjectRoot . '/data';

    $dataPath = '';
    try {
      $dataPath = (string) $this->configFactory->get('entity_sync.settings')->get('data_path');
    }
    catch (\Throwable $e) {
      $dataPath = '';
    }

    if ($dataPath !== '') {
      if (str_starts_with($dataPath, '/')) {
        $this->root = $dataPath;
      }
      else {
        $this->root = $drupalProjectRoot . '/' . trim($dataPath, '/');
      }
    }
    else {
      $this->root = $defaultRoot;
    }

    // Ensure expected import structure exists for __SOURCE__: files/<name>.
    if (!$this->ensureDirectory($this->root)) {
      return;
    }
    $filesDir = rtrim($this->root, '/\\') . '/files';
    if ($this->ensureDirectory($filesDir)) {
      $this->ensureGitkeep($filesDir);
      $this->ensureDummyImage($filesDir);
    }
  }

  protected function initLanguages(): void {
    $langcodes = $this->languageManager->getLanguages();
    $this->languagesCodes = array_keys($langcodes);
    $this->defaultSiteLanguage = $this->languageManager->getDefaultLanguage()->getId();
  }

  /**
   * Batch import for decoded YAML array.
   *
   * @param array $data
   * @param array $options
   */
  protected function setEntityBatch(array $data, array $options): array {
    // The YAML format is an array of config entities.
    foreach ($data as $confEntityNum => $confEntity) {
      $entityType = $confEntity['entity_type'] ?? NULL;
      $bundle = $confEntity['bundle'] ?? NULL;
      $elements = $confEntity['elements'] ?? [];

      if (empty($entityType) || !is_array($elements)) {
        continue;
      }

      // Process languages/field blocks.
      $data[$confEntityNum]['elements'] = $this->applyEntityElements($entityType, $bundle, $elements, $options);
    }
    return $data;
  }

  /**
   * Delete all entities of a type+bundle.
   */
  protected function deleteEntities(string $entityType, string $bundle): int {
    switch ($entityType) {
      case 'node':
        $ids = $this->entityTypeManager->getStorage('node')->getQuery()
          ->accessCheck(TRUE)
          ->condition('type', $bundle)
          ->execute();
        $entities = Node::loadMultiple($ids);
        break;

      case 'taxonomy_term':
        $entities = $this->entityTypeManager->getStorage($entityType)->loadByProperties(['vid' => $bundle]);
        break;

      case 'paragraph':
        $ids = $this->entityTypeManager->getStorage('paragraph')->getQuery()
          ->accessCheck(TRUE)
          ->condition('type', $bundle)
          ->execute();
        $entities = Paragraph::loadMultiple($ids);
        break;

      case 'media':
        $ids = $this->entityTypeManager->getStorage('media')->getQuery()
          ->accessCheck(TRUE)
          ->condition('bundle', $bundle)
          ->execute();
        $entities = Media::loadMultiple($ids);
        break;

      case 'menu_link_content':
        $ids = $this->entityTypeManager->getStorage('menu_link_content')->getQuery()
          ->accessCheck(TRUE)
          ->execute();
        $entities = MenuLinkContent::loadMultiple($ids);
        // Filter by menu_name.
        $entities = array_filter($entities, fn(MenuLinkContent $mlc) => $mlc->getMenuName() === $bundle);
        break;

      default:
        // Not implemented for this simplified engine.
        return 0;
    }

    $deleted = 0;
    foreach ($entities as $entity) {
      try {
        $entity->delete();
        $deleted++;
      }
      catch (\Throwable $e) {
        $this->logger->warning('Could not delete entity: ' . $e->getMessage());
      }
    }
    return $deleted;
  }

  /**
   * Deletes all entity groups referenced in YAML (including nested entities).
   */
  protected function deleteEntitiesFromData(array $data): void {
    $targets = [];
    foreach ($data as $confEntity) {
      if (!is_array($confEntity)) {
        continue;
      }
      $this->collectDeleteTargetsFromEntityConfig($confEntity, $targets);
    }

    foreach ($targets as $key => $target) {
      $deleted = $this->deleteEntities($target['entity_type'], $target['bundle']);
      $this->logger->notice(sprintf(
        'Deleted %d entities for %s.%s',
        $deleted,
        $target['entity_type'],
        $target['bundle']
      ));
    }
  }

  /**
   * Collects delete targets recursively from one YAML entity config block.
   */
  protected function collectDeleteTargetsFromEntityConfig(array $confEntity, array &$targets): void {
    $entityType = $confEntity['entity_type'] ?? NULL;
    $bundle = $confEntity['bundle'] ?? NULL;
    $elements = $confEntity['elements'] ?? [];
    if (!is_string($entityType) || !is_array($elements)) {
      return;
    }

    if ($entityType === 'menu_link_content') {
      // For menu links, deletion scope is menu_name (not bundle machine name).
      $menuName = $this->inferMenuNameFromElements($elements);
      if (empty($menuName) && is_string($bundle) && $bundle !== '' && $bundle !== 'menu_link_content') {
        $menuName = $bundle;
      }
      if (!empty($menuName)) {
        $targets[$entityType . ':' . $menuName] = [
          'entity_type' => $entityType,
          'bundle' => $menuName,
        ];
      }
    }
    elseif (is_string($bundle) && $bundle !== '') {
      $targets[$entityType . ':' . $bundle] = [
        'entity_type' => $entityType,
        'bundle' => $bundle,
      ];
    }

    foreach ($elements as $elementBlock) {
      if (!is_array($elementBlock)) {
        continue;
      }
      foreach ($elementBlock as $langcode => $fields) {
        if (!is_array($fields)) {
          continue;
        }
        foreach ($fields as $value) {
          if (!is_array($value)) {
            continue;
          }
          if (isset($value['entity_type']) && isset($value['elements'])) {
            $this->collectDeleteTargetsFromEntityConfig($value, $targets);
            continue;
          }
          foreach ($value as $item) {
            if (is_array($item) && isset($item['entity_type']) && isset($item['elements'])) {
              $this->collectDeleteTargetsFromEntityConfig($item, $targets);
            }
          }
        }
      }
    }
  }

  /**
   * Apply elements blocks to create/update entities.
   */
  protected function applyEntityElements(string $entityType, ?string $bundle, array $elements, array $options): array {
    $entityInfo = $this->getEntityInfo($entityType, $bundle, TRUE);
    $fieldsInfo = $entityInfo['fields'] ?? [];

    foreach ($elements as $elementIndex => $elementBlock) {
      if (!is_array($elementBlock)) {
        continue;
      }

      foreach ($elements[$elementIndex] as $langcodeKey => &$fields) {
        if (!is_array($fields)) {
          continue;
        }

        $language = $langcodeKey === '__DEFAULT__' ? $this->defaultSiteLanguage : (string) $langcodeKey;
        if (!in_array($language, $this->languagesCodes, TRUE)) {
          continue;
        }

        $uuid = $fields['uuid'] ?? NULL;
        $existing = $this->loadEntityByUuid($entityType, is_string($uuid) ? $uuid : NULL);

        $forceNew = !empty($options['new']);
        // Default import mode is upsert by uuid. --new forces create-only.
        if ($forceNew) {
          $entity = $this->createEntity($entityType, $bundle);
          $operation = 'created';
        }
        else {
          $entity = $existing ?: $this->createEntity($entityType, $bundle);
          $operation = $existing ? 'updated' : 'created';
        }
        if ($existing && $entity->getEntityType()->hasKey('langcode')) {
          $entity->set('langcode', $language);
        }

        foreach ($fields as $fieldName => &$fieldValue) {
          if ($fieldName === 'uuid') {
            continue;
          }
          $fieldInfo = $fieldsInfo[$fieldName] ?? NULL;
          try {
            $this->applyFieldValue($entity, $entityType, $fieldName, $fieldValue, $fieldInfo, $options);
          }
          catch (\Throwable $e) {
            $this->logger->warning(sprintf('Could not set %s.%s for %s: %s', $entityType, $fieldName, $bundle ?? '', $e->getMessage()));
          }
        }
        unset($fieldValue);

        if (method_exists($entity, 'setOwnerId')) {
          $entity->setOwnerId(1);
        }

        try {
          $entity->save();
          if (method_exists($entity, 'uuid')) {
            $elements[$elementIndex][$langcodeKey]['uuid'] = $entity->uuid();
          }
          $this->logger->notice(sprintf('%s %s.%s (id:%s uuid:%s)', ucfirst($operation), $entityType, (string) ($bundle ?? ''), (string) $entity->id(), method_exists($entity, 'uuid') ? (string) $entity->uuid() : ''));
        }
        catch (\Throwable $e) {
          $this->logger->error('Entity save failed: ' . $e->getMessage());
        }
      }
      unset($fields);
    }
    return $elements;
  }

  /**
   * Applies one field value using field metadata when available.
   */
  protected function applyFieldValue(object $entity, string $entityType, string $fieldName, mixed &$fieldValue, ?array $fieldInfo, array $options): void {
    // Menu link content uses nested link values and entity fields.
    if ($entityType === 'menu_link_content' && $fieldName === 'link') {
      $entity->set('link', $fieldValue);
      return;
    }

    $fieldType = $fieldInfo['type'] ?? NULL;

    switch ($fieldType) {
      case 'link':
        $entity->set($fieldName, $this->normalizeLinkValue($fieldValue));
        return;

      case 'image':
      case 'file':
        $entity->set($fieldName, $this->resolveFileFieldValue($fieldValue, $options));
        return;

      case 'datetime':
      case 'timestamp':
      case 'created':
      case 'changed':
        $entity->set($fieldName, $this->normalizeDateValue($fieldValue, $fieldType));
        return;

      case 'entity_reference':
        $entity->set($fieldName, $this->resolveEntityReferenceValue($fieldValue, $fieldInfo, $options));
        return;

      case 'entity_reference_revisions':
        $entity->set($fieldName, $this->resolveEntityReferenceRevisionsValue($fieldValue, $options));
        return;

      default:
        $entity->set($fieldName, $fieldValue);
        return;
    }
  }

  /**
   * Normalizes link field value to Drupal item structure.
   */
  protected function normalizeLinkValue(mixed $fieldValue): array {
    if (is_string($fieldValue)) {
      return [['uri' => $fieldValue]];
    }
    if (!is_array($fieldValue)) {
      return [];
    }
    if (isset($fieldValue['uri'])) {
      return [$fieldValue];
    }
    return $fieldValue;
  }

  /**
   * Normalizes date/time values for import.
   */
  protected function normalizeDateValue(mixed $fieldValue, ?string $fieldType): mixed {
    if (is_int($fieldValue)) {
      return $fieldValue;
    }
    if (is_string($fieldValue) && $fieldValue !== '') {
      return $fieldValue;
    }
    if ($fieldType === 'timestamp' || $fieldType === 'created' || $fieldType === 'changed') {
      return time();
    }
    return gmdate('Y-m-d\\TH:i:s');
  }

  /**
   * Resolves file/image field values from __SOURCE__ placeholders.
   */
  protected function resolveFileFieldValue(mixed &$fieldValue, array $options = []): array {
    if (!is_array($fieldValue)) {
      return [];
    }
    $refreshFiles = !empty($options['refresh-files']);
    $single = isset($fieldValue['__SOURCE__']) || isset($fieldValue['uuid']) || isset($fieldValue['target_id']);
    $items = $single ? [$fieldValue] : $fieldValue;
    $resolved = [];
    foreach ($items as $idx => &$item) {
      if (!is_array($item)) {
        continue;
      }

      if ($refreshFiles && !empty($item['__SOURCE__']) && is_string($item['__SOURCE__'])) {
        unset($item['target_id'], $item['uuid'], $item['file_uuid']);
      }

      if (!$refreshFiles && !empty($item['uuid']) && is_string($item['uuid']) && \Drupal\Component\Uuid\Uuid::isValid($item['uuid'])) {
        $existing = $this->entityRepository->loadEntityByUuid('file', $item['uuid']);
        if ($existing) {
          $item['target_id'] = (int) $existing->id();
        }
      }

      if (!$refreshFiles && !empty($item['target_id'])) {
        $fileEntity = File::load((int) $item['target_id']);
        if ($fileEntity) {
          if (empty($item['uuid']) && method_exists($fileEntity, 'uuid')) {
            $item['uuid'] = $fileEntity->uuid();
          }
          $resolved[] = $item;
          continue;
        }
        // Stale target_id in YAML: allow __SOURCE__ fallback.
        unset($item['target_id']);
      }
      if (empty($item['__SOURCE__']) || !is_string($item['__SOURCE__'])) {
        continue;
      }
      $fileEntity = $this->createManagedFileFromSource($item['__SOURCE__']);
      if (!$fileEntity) {
        continue;
      }
      $item['uuid'] = $fileEntity->uuid();
      $row = ['target_id' => (int) $fileEntity->id()];
      if (isset($item['alt'])) {
        $row['alt'] = $item['alt'];
      }
      if (isset($item['title'])) {
        $row['title'] = $item['title'];
      }
      if (isset($item['description'])) {
        $row['description'] = $item['description'];
      }
      if (isset($item['display'])) {
        $row['display'] = $item['display'];
      }
      $resolved[] = $row;
    }
    unset($item);
    $fieldValue = $single ? ($items[0] ?? $fieldValue) : $items;
    return $resolved;
  }

  /**
   * Creates a managed file from local source path.
   */
  protected function createManagedFileFromSource(string $source): ?File {
    $sourcePath = $this->resolveSourcePath($source);
    if (!is_file($sourcePath)) {
      $this->logger->warning('File source not found: ' . $sourcePath);
      return NULL;
    }

    $publicRealPath = $this->fileSystem->realpath('public://');
    if (!$publicRealPath) {
      $this->logger->warning('Could not resolve public:// path for file import.');
      return NULL;
    }
    $targetDirRealPath = rtrim($publicRealPath, '/\\') . '/entity_sync';
    if (!is_dir($targetDirRealPath)) {
      if (!$this->ensureDirectory($targetDirRealPath)) {
        return NULL;
      }
    }

    $baseName = basename($sourcePath);
    $targetName = time() . '-' . $baseName;
    $targetRealPath = $targetDirRealPath . '/' . $targetName;
    if (!$this->copyFile($sourcePath, $targetRealPath)) {
      return NULL;
    }

    $uri = 'public://entity_sync/' . $targetName;
    $file = File::create([
      'uri' => $uri,
      'status' => 1,
    ]);
    $file->setPermanent();
    $file->save();

    return $file;
  }

  /**
   * Resolves __SOURCE__ path for file/media imports.
   *
   * Resolution order:
   * - Absolute path (if provided)
   * - Path relative to entity_sync data root (configured data_path)
   * - Path relative to Drupal root (backward compatibility)
   */
  protected function resolveSourcePath(string $source): string {
    $source = trim($source);
    if ($source === '') {
      return '';
    }

    if (str_starts_with($source, '/')) {
      return $source;
    }

    $relative = ltrim($source, '/');

    // Keep this as first relative strategy so "__SOURCE__: files/foo.png"
    // naturally maps to "<data_path>/files/foo.png".
    if ($this->root !== '') {
      $candidate = rtrim($this->root, '/\\') . '/' . $relative;
      if (is_file($candidate)) {
        return $candidate;
      }
    }

    return $this->appRoot . '/' . $relative;
  }

  /**
   * Resolves value for entity_reference fields.
   */
  protected function resolveEntityReferenceValue(mixed &$fieldValue, array $fieldInfo, array $options): array {
    if (!is_array($fieldValue)) {
      return [];
    }

    $targetType = $fieldInfo['target_type'] ?? '';

    // Legacy taxonomy placeholders by term name.
    if ($targetType === 'taxonomy_term') {
      $bundles = array_keys($fieldInfo['target_bundle'] ?? []);
      $termsByName = $this->getTaxonomyTerms($bundles, $this->defaultSiteLanguage);
      $resolved = [];
      foreach ($fieldValue as $raw) {
        if (is_string($raw) && isset($termsByName[$raw])) {
          $resolved[] = ['target_id' => $termsByName[$raw]];
        }
      }
      return $resolved;
    }

    if ($targetType === 'media') {
      return $this->resolveMediaReferenceValue($fieldValue, $fieldInfo, $options);
    }

    // Nested entity config case.
    if (isset($fieldValue[0]['entity_type']) || isset($fieldValue['entity_type'])) {
      $normalized = isset($fieldValue['entity_type']) ? [$fieldValue] : $fieldValue;
      $saved = $this->setEntityBatchReturningReferences($normalized, $options);
      $fieldValue = isset($fieldValue['entity_type']) ? ($normalized[0] ?? $fieldValue) : $normalized;
      return $saved;
    }

    return $fieldValue;
  }

  /**
   * Resolves media references from __SOURCE__ placeholders.
   */
  protected function resolveMediaReferenceValue(array &$fieldValue, array $fieldInfo, array $options = []): array {
    $refreshFiles = !empty($options['refresh-files']);
    $single = isset($fieldValue['__SOURCE__']) || isset($fieldValue['uuid']) || isset($fieldValue['target_id']);
    $items = $single ? [$fieldValue] : $fieldValue;
    $targetBundles = array_keys($fieldInfo['target_bundle'] ?? []);
    $bundle = (string) ($targetBundles[0] ?? 'image');
    $resolved = [];
    foreach ($items as $idx => &$item) {
      if (!is_array($item)) {
        continue;
      }

      if ($refreshFiles && !empty($item['__SOURCE__']) && is_string($item['__SOURCE__'])) {
        unset($item['target_id'], $item['uuid'], $item['file_uuid']);
      }

      if (!$refreshFiles && !empty($item['uuid']) && is_string($item['uuid']) && \Drupal\Component\Uuid\Uuid::isValid($item['uuid'])) {
        $existing = $this->entityRepository->loadEntityByUuid('media', $item['uuid']);
        if ($existing) {
          $item['target_id'] = (int) $existing->id();
        }
      }

      if (!$refreshFiles && !empty($item['target_id'])) {
        $mediaEntity = Media::load((int) $item['target_id']);
        if ($mediaEntity) {
          if (empty($item['uuid']) && method_exists($mediaEntity, 'uuid')) {
            $item['uuid'] = $mediaEntity->uuid();
          }
          $resolved[] = ['target_id' => (int) $item['target_id']];
          continue;
        }
        // Stale target_id in YAML: allow __SOURCE__ fallback.
        unset($item['target_id']);
      }
      if (empty($item['__SOURCE__']) || !is_string($item['__SOURCE__'])) {
        continue;
      }
      $mediaEntity = $this->createMediaFromSource($bundle, $item);
      if ($mediaEntity) {
        $item['uuid'] = $mediaEntity->uuid();
        $resolved[] = ['target_id' => (int) $mediaEntity->id()];
      }
    }
    unset($item);
    $fieldValue = $single ? ($items[0] ?? $fieldValue) : $items;
    return $resolved;
  }

  /**
   * Creates media entity from a source file definition.
   */
  protected function createMediaFromSource(string $bundle, array &$item): ?Media {
    $fileEntity = $this->createManagedFileFromSource((string) $item['__SOURCE__']);
    if (!$fileEntity) {
      return NULL;
    }
    $item['file_uuid'] = $fileEntity->uuid();

    $defs = $this->entityFieldManager->getFieldDefinitions('media', $bundle);
    $mediaFileField = NULL;
    foreach ($defs as $name => $definition) {
      $type = $definition->getType();
      if ($type === 'image' || $type === 'file') {
        if (str_starts_with($name, 'field_')) {
          $mediaFileField = $name;
          break;
        }
      }
    }
    if (!$mediaFileField) {
      $this->logger->warning(sprintf('No file/image field found for media bundle "%s".', $bundle));
      return NULL;
    }

    $mediaValues = [
      'bundle' => $bundle,
      'name' => $item['title'] ?? basename((string) $item['__SOURCE__']),
      $mediaFileField => [
        'target_id' => (int) $fileEntity->id(),
      ],
      'status' => 1,
    ];
    if (isset($item['alt'])) {
      $mediaValues[$mediaFileField]['alt'] = (string) $item['alt'];
    }
    if (isset($item['title'])) {
      $mediaValues[$mediaFileField]['title'] = (string) $item['title'];
    }

    $media = Media::create($mediaValues);
    $media->save();
    return $media;
  }

  /**
   * Resolves value for entity_reference_revisions fields.
   */
  protected function resolveEntityReferenceRevisionsValue(mixed &$fieldValue, array $options): array {
    if (!is_array($fieldValue)) {
      return [];
    }
    $normalized = isset($fieldValue['entity_type']) ? [$fieldValue] : $fieldValue;
    $refs = $this->setEntityBatchReturningReferences($normalized, $options, TRUE);
    $fieldValue = isset($fieldValue['entity_type']) ? ($normalized[0] ?? $fieldValue) : $normalized;
    return $refs;
  }

  /**
   * Imports nested entities and returns references to be assigned.
   *
   * @return array<int, array<string, mixed>>
   *   For entity_reference:   [['target_id' => X], ...]
   *   For revisions refs:     [['target_id' => X, 'target_revision_id' => Y], ...]
   */
  protected function setEntityBatchReturningReferences(array &$data, array $options, bool $withRevision = FALSE): array {
    $refs = [];
    foreach ($data as $confEntityIndex => $confEntity) {
      if (!is_array($confEntity)) {
        continue;
      }
      $entityType = $confEntity['entity_type'] ?? NULL;
      $bundle = $confEntity['bundle'] ?? NULL;
      $elements = $confEntity['elements'] ?? [];
      if (!$entityType || !is_array($elements)) {
        continue;
      }
      $entityInfo = $this->getEntityInfo((string) $entityType, is_string($bundle) ? $bundle : NULL, TRUE);
      $fieldsInfo = $entityInfo['fields'] ?? [];

      foreach ($elements as $elementIndex => $elementBlock) {
        if (!is_array($elementBlock)) {
          continue;
        }
        foreach ($elements[$elementIndex] as $langcodeKey => &$fields) {
          if (!is_array($fields)) {
            continue;
          }
          $uuid = $fields['uuid'] ?? NULL;
          $existing = $this->loadEntityByUuid((string) $entityType, is_string($uuid) ? $uuid : NULL);
          $forceNew = !empty($options['new']);
          if ($forceNew) {
            $entity = $this->createEntity((string) $entityType, is_string($bundle) ? $bundle : NULL);
          }
          else {
            $entity = $existing ?: $this->createEntity((string) $entityType, is_string($bundle) ? $bundle : NULL);
          }
          $language = $langcodeKey === '__DEFAULT__' ? $this->defaultSiteLanguage : (string) $langcodeKey;
          if (method_exists($entity, 'set') && in_array($language, $this->languagesCodes, TRUE)) {
            try {
              $entity->set('langcode', $language);
            }
            catch (\Throwable $e) {
              // Ignore language assignment if entity doesn't expose langcode.
            }
          }
          foreach ($fields as $name => &$value) {
            if ($name === 'uuid') {
              continue;
            }
            try {
              $fieldInfo = $fieldsInfo[$name] ?? NULL;
              $this->applyFieldValue($entity, (string) $entityType, (string) $name, $value, $fieldInfo, $options);
            }
            catch (\Throwable $e) {
              // Skip invalid nested fields silently.
            }
          }
          unset($value);
          if (method_exists($entity, 'setOwnerId')) {
            $entity->setOwnerId(1);
          }
          $entity->save();
          if (method_exists($entity, 'uuid')) {
            $elements[$elementIndex][$langcodeKey]['uuid'] = $entity->uuid();
          }
          $row = ['target_id' => $entity->id()];
          if ($withRevision && method_exists($entity, 'getRevisionId')) {
            $row['target_revision_id'] = $entity->getRevisionId();
          }
          $refs[] = $row;
        }
        unset($fields);
      }
      $data[$confEntityIndex]['elements'] = $elements;
    }
    return $refs;
  }

  /**
   * Infer menu_name for menu_link_content legacy YAML.
   *
   * @param array $elements
   *   Legacy "elements" array.
   *
   * @return string|null
   *   Menu name if found.
   */
  protected function inferMenuNameFromElements(array $elements): ?string {
    foreach ($elements as $elementBlock) {
      if (!is_array($elementBlock)) {
        continue;
      }
      foreach ($elementBlock as $langcodeKey => $fields) {
        if (!is_array($fields)) {
          continue;
        }
        if (isset($fields['menu_name']) && is_string($fields['menu_name']) && $fields['menu_name'] !== '') {
          return $fields['menu_name'];
        }
      }
    }
    return NULL;
  }

  /**
   * Load entity by UUID if valid.
   */
  protected function loadEntityByUuid(string $entityType, ?string $uuid): ?object {
    if (empty($uuid) || !\Drupal\Component\Uuid\Uuid::isValid($uuid)) {
      return NULL;
    }
    $entity = $this->entityRepository->loadEntityByUuid($entityType, $uuid);
    return $entity ?: NULL;
  }

  /**
   * Create entity instance for supported types.
   */
  protected function createEntity(string $entityType, ?string $bundle): object {
    switch ($entityType) {
      case 'node':
        return Node::create(['type' => $bundle]);

      case 'taxonomy_term':
        return Term::create(['vid' => $bundle]);

      case 'paragraph':
        return Paragraph::create(['type' => $bundle]);

      case 'media':
        return Media::create(['bundle' => $bundle]);

      case 'menu_link_content':
        // Menu link content bundle is "menu_name" context, but the legacy data uses menu_name field.
        return MenuLinkContent::create();

      default:
        throw new \InvalidArgumentException(sprintf('Unsupported entity_type for engine: %s', $entityType));
    }
  }

  /**
   * Get entity field info to generate templates.
   */
  protected function getEntityInfo(string $entityTypeId, ?string $bundle = NULL, bool $recursive = FALSE, array $stack = []): array {
    $currentKey = $entityTypeId . ':' . ($bundle ?? '');
    $stack[$currentKey] = TRUE;
    $entityInfo = ['fields' => []];
    foreach ($this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle) as $fieldName => $definition) {
      $fieldInfo = [
        'label' => $definition->getLabel(),
        'required' => $definition->isRequired() ? 1 : 0,
        'translatable' => $definition->isTranslatable() ? 1 : 0,
        'type' => $definition->getType(),
        'settings' => $definition->getSettings(),
      ];

      if (!empty($definition->getSetting('target_type'))) {
        $fieldInfo['target_type'] = $definition->getSetting('target_type');
        $fieldInfo['target_bundle'] = $definition->getSetting('handler_settings')['target_bundles'] ?? [];
        if ($recursive && !empty($fieldInfo['target_bundle']) && is_array($fieldInfo['target_bundle'])) {
          foreach (array_keys($fieldInfo['target_bundle']) as $targetBundle) {
            $childType = (string) $fieldInfo['target_type'];
            $childBundle = (string) $targetBundle;
            $childKey = $childType . ':' . $childBundle;
            $childRecursive = !isset($stack[$childKey]);
            $nested = $this->getEntityInfo($childType, $childBundle, $childRecursive, $stack);
            if (!empty($nested['fields'])) {
              $fieldInfo['__RECURSIVE__'][$targetBundle] = $nested['fields'];
            }
          }
        }
      }
      $entityInfo['fields'][$fieldName] = $fieldInfo;
    }

    return $entityInfo;
  }

  /**
   * Build template placeholders based on field types.
   *
   * @param array $fieldsInfo
   * @param string $infoKey
   * @param array $seed
   *
   * @return array
   */
  protected function getTemplateFromFields(array $fieldsInfo, string $infoKey, array $seed = []): array {
    $eLangConf = $seed;

    foreach ($fieldsInfo as $fieldName => $fieldInfo) {
      if ($this->shouldSkipTemplateField($fieldName)) {
        continue;
      }

      $fieldType = $fieldInfo['type'];

      // Skip internal parent field if present.
      if ($fieldName === 'parent') {
        continue;
      }

      $settings = $fieldInfo['settings'] ?? [];

      switch ($fieldType) {
        case 'string':
        case 'string_long':
          $eLangConf[$fieldName] = '{{' . $this->cleanString($fieldName) . '}}';
          break;

        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $eLangConf[$fieldName] = [
            'value' => '{{' . $this->cleanString($fieldName) . '}}',
            'format' => 'full_html',
          ];
          break;

        case 'decimal':
        case 'integer':
        case 'float':
          $eLangConf[$fieldName] = 0;
          break;

        case 'created':
        case 'changed':
          $eLangConf[$fieldName] = time();
          break;

        case 'list_integer':
        case 'list_string':
          if (!empty($settings['allowed_values']) && is_array($settings['allowed_values'])) {
            $keys = array_keys($settings['allowed_values']);
            $eLangConf[$fieldName] = '{{' . implode('|', $keys) . '}}';
          }
          else {
            $eLangConf[$fieldName] = '{{' . $this->cleanString($fieldName) . '}}';
          }
          break;

        case 'boolean':
          $eLangConf[$fieldName] = 1;
          break;

        case 'link':
          $host = $this->requestStack->getCurrentRequest()?->getSchemeAndHttpHost() ?? '';
          $eLangConf[$fieldName] = [
            'uri' => $host,
            'title' => '{{' . $this->cleanString($fieldName) . '::title}}',
          ];
          break;

        case 'email':
          $eLangConf[$fieldName] = 'demo@example.com';
          break;

        case 'telephone':
          $eLangConf[$fieldName] = '+34 600 000 000';
          break;

        case 'datetime':
          $eLangConf[$fieldName] = gmdate('Y-m-d\\TH:i:s');
          break;

        case 'timestamp':
          $eLangConf[$fieldName] = time();
          break;

        case 'address':
          $eLangConf[$fieldName] = [
            'country_code' => 'ES',
            'administrative_area' => 'M',
            'locality' => '{{' . $this->cleanString($fieldName) . '::city}}',
            'postal_code' => '28001',
            'address_line1' => '{{' . $this->cleanString($fieldName) . '::line1}}',
          ];
          break;

        case 'entity_reference':
          if (($fieldInfo['target_type'] ?? '') === 'taxonomy_term') {
            $eLangConf[$fieldName] = ['__ENTITY_REFERENCE__'];
          }
          elseif (($fieldInfo['target_type'] ?? '') === 'media') {
            $eLangConf[$fieldName] = [
              [
                '__SOURCE__' => 'files/default_no_image.png',
                'alt' => '{{' . $this->cleanString($fieldName) . '::alt}}',
                'title' => '{{' . $this->cleanString($fieldName) . '::title}}',
              ],
            ];
          }
          elseif (($fieldInfo['target_type'] ?? '') === 'user') {
            $eLangConf[$fieldName] = 1;
          }
          elseif (($fieldInfo['target_type'] ?? '') === 'node') {
            $eLangConf[$fieldName] = ['__UUID__'];
          }
          else {
            $eLangConf[$fieldName] = ['__NOT_SUPPORTED__'];
          }
          break;

        case 'entity_reference_revisions':
          $targetType = (string) ($fieldInfo['target_type'] ?? 'paragraph');
          $targetBundles = array_keys($fieldInfo['target_bundle'] ?? []);
          // Include one example item per allowed bundle so discovery/import is easier.
          if ($targetBundles === []) {
            $targetBundles = [''];
          }
          $eLangConf[$fieldName] = [];
          foreach ($targetBundles as $targetBundle) {
            $targetBundle = (string) $targetBundle;
            $nestedDefault = [];
            if (!empty($fieldInfo['__RECURSIVE__'][$targetBundle]) && is_array($fieldInfo['__RECURSIVE__'][$targetBundle])) {
              $nestedDefault = $this->getTemplateFromFields(
                $fieldInfo['__RECURSIVE__'][$targetBundle],
                $infoKey . '::' . $fieldName . '::' . $targetBundle,
                []
              );
            }
            $eLangConf[$fieldName][] = [
              'entity_type' => $targetType,
              'bundle' => $targetBundle,
              'elements' => [
                [
                  '__DEFAULT__' => $nestedDefault,
                ],
              ],
            ];
          }
          break;

        case 'image':
        case 'file':
          $ext = 'png';
          if (!empty($settings['file_extensions'])) {
            $parts = preg_split('/\s+/', (string) $settings['file_extensions']);
            if (!empty($parts[0])) {
              $ext = $parts[0];
            }
          }
          $eLangConf[$fieldName][0] = [
            '__SOURCE__' => 'files/default_no_image.' . $ext,
            'alt' => '{{' . $this->cleanString($fieldName) . '::alt}}',
            'title' => '{{' . $this->cleanString($fieldName) . '::title}}',
          ];
          break;

        default:
          // Fallback: keep it explicit.
          $eLangConf[$fieldName] = ['__NOT_SUPPORTED__(' . $fieldType . ')__'];
          break;
      }
    }

    return $eLangConf;
  }

  protected function cleanString(string $string): string {
    $string = str_replace(' ', '_', $string);
    return $string;
  }

  /**
   * Skip internal/system fields in generated templates.
   */
  protected function shouldSkipTemplateField(string $fieldName): bool {
    $skip = [
      'id',
      'nid',
      'tid',
      'vid',
      'revision_id',
      'bundle',
      'uuid',
      'langcode',
      'type',
      'revision_timestamp',
      'revision_uid',
      'revision_log',
      'default_langcode',
      'revision_default',
      'revision_translation_affected',
      'metatag',
      'path',
      'menu_link',
      'parent_id',
      'parent_type',
      'parent_field_name',
      'behavior_settings',
    ];
    return in_array($fieldName, $skip, TRUE);
  }

  /**
   * Returns taxonomy terms map by translated name.
   */
  protected function getTaxonomyTerms(array $bundles, string $language): array {
    $termsArray = [];
    foreach ($bundles as $bundle) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => $bundle]);
      foreach ($terms as $term) {
        $translated = $this->entityRepository->getTranslationFromContext($term, $language);
        $hasTranslationCallable = is_callable([$term, 'hasTranslation']);
        $translatedNameCallable = is_callable([$translated, 'getName']);
        if ($hasTranslationCallable && call_user_func([$term, 'hasTranslation'], $language) && $translatedNameCallable) {
          $termsArray[(string) call_user_func([$translated, 'getName'])] = $translated->id();
        }
        else {
          $name = is_callable([$term, 'getName']) ? (string) call_user_func([$term, 'getName']) : (string) $term->id();
          $termsArray[$name] = $term->id();
        }
      }
    }
    return $termsArray;
  }

}

