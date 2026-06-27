<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.r3dnextcloudgallery
 *
 * @copyright   (C) 2026 Richard Dvorak / R3D
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace R3d\Plugin\Fields\R3dnextcloudgallery\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Response\JsonResponse;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Path;
use R3d\Plugin\Fields\R3dnextcloudgallery\Service\FieldValueMapper;
use R3d\Plugin\Fields\R3dnextcloudgallery\Service\GalleryImportService;
use R3d\Plugin\Fields\R3dnextcloudgallery\Service\GalleryRenderer;
use R3d\Plugin\Fields\R3dnextcloudgallery\Service\GalleryStorage;
use R3d\Plugin\Fields\R3dnextcloudgallery\Service\ImageProcessor;
use R3d\Plugin\Fields\R3dnextcloudgallery\Service\ImportConstraints;
use R3d\Plugin\Fields\R3dnextcloudgallery\Service\PublicWebDavClient;
use R3d\Plugin\Fields\R3dnextcloudgallery\Service\ShareLinkParser;

final class R3dnextcloudgallery extends \Joomla\Component\Fields\Administrator\Plugin\FieldsPlugin
{
    private const ASSET_VERSION = '1.5.12';

    private array $preSaveFieldValues = [];
    private bool $frontendNeedsLightGallery = false;
    /**
     * Tracks which optional lightGallery plugins are actually needed by rendered galleries.
     *
     * @var array<string, bool>
     */
    private array $frontendLightGalleryPlugins = [
        'zoom' => false,
        'fullscreen' => false,
        'autoplay' => true, // keep true to render play control consistently across environments
        'thumbnail' => false,
        'share' => false,
        'rotate' => false,
        'hash' => false,
    ];

    public function onContentPrepareForm(Form $form, $data): bool
    {
        return (bool) parent::onContentPrepareForm($form, $data);
    }

    public function onAfterInitialise(): void
    {
        // Hard safety cleanup: remove obsolete legacy field type file if it still exists.
        $legacy = rtrim(JPATH_ROOT, '/\\') . '/plugins/fields/r3dnextcloudgallery/fields/admin_widget.php';
        if (is_file($legacy)) {
            File::delete($legacy);
        }
    }

    public function onCustomFieldsPrepareDom($field, \DOMElement $parent, Form $form)
    {
        $fieldNode = parent::onCustomFieldsPrepareDom($field, $parent, $form);
        if (!$fieldNode) {
            return $fieldNode;
        }

        $name = strtolower(trim((string) ($field->name ?? '')));
        $type = strtolower(trim((string) ($field->type ?? '')));

        // ACF-style: enforce the custom input type when field naming indicates this gallery,
        // even if a legacy/incorrect type was stored in DB.
        if ($type === 'r3dnextcloudgallery' || str_contains($name, 'nextcloudgallery') || str_contains($name, 'nc_gallery') || str_contains($name, 'nc-gallery')) {
            $fieldNode->setAttribute('type', 'r3dnextcloudgallery');
        }

        return $fieldNode;
    }

    public function onAjaxR3dnextcloudgallery(): array
    {
        $app = Factory::getApplication();
        $input = $app->input;

        if (!Session::checkToken('post')) {
            return ['ok' => false, 'message' => 'Invalid security token.'];
        }

        $action = strtolower(trim((string) $input->post->getString('r3dncg_action', '')));
        $articleId = (int) $input->post->getInt('r3dncg_article_id', 0);
        $fieldId = (int) $input->post->getInt('r3dncg_field_id', 0);
        $fieldName = trim((string) $input->post->getString('r3dncg_field_name', ''));
        $debugEnabled = $input->post->getInt('r3dncg_debug', 0) === 1;

        if ($action === '' || $articleId <= 0) {
            return ['ok' => false, 'message' => 'Missing action/article id.'];
        }

        $field = $this->resolveFieldRecordForAjax($fieldId, $fieldName);
        if ($field === null) {
            return ['ok' => false, 'message' => 'Field not found.'];
        }

        if (!$this->canEditArticleId($articleId)) {
            return ['ok' => false, 'message' => 'Missing edit permission.'];
        }

        $rawValue = $this->loadFieldValueForItem((int) $field->id, $articleId);
        $shareUrl = trim((string) $input->post->getString('r3dncg_share_url', ''));
        $galleryTitle = trim((string) $input->post->getString('r3dncg_gallery_title', ''));
        if ($shareUrl === '') {
            $shareUrl = $this->resolveShareUrlForAction($rawValue);
        }
        if ($galleryTitle === '') {
            $galleryTitle = $this->resolveGalleryTitleForAction($rawValue);
        }

        try {
            if (in_array($action, ['save_meta', 'update_captions', 'delete_item'], true)) {
                $captionRaw = $input->post->getRaw('r3dncg_captions', '{}');
                $captionUpdates = json_decode(is_string($captionRaw) ? $captionRaw : '{}', true);
                $captionUpdates = is_array($captionUpdates) ? $captionUpdates : [];

                if ($action === 'delete_item') {
                    $deleteKey = trim((string) $input->post->getString('r3dncg_delete_key', ''));
                    if ($deleteKey !== '') {
                        $captionUpdates[$deleteKey] = ['delete' => 1];
                    }
                }

                $rawValue = $this->ensureGalleryJsonInFieldValue($rawValue, $shareUrl, $articleId, (int) $field->id);
                $this->persistFieldValueForItem((int) $field->id, $articleId, $rawValue);
                $updateResult = $this->updateImageCaptions($rawValue, $captionUpdates);

                $debug = null;
                if ($debugEnabled) {
                    $debug = $this->buildAjaxDebugData(
                        $action,
                        $rawValue,
                        $shareUrl,
                        $galleryTitle,
                        $articleId,
                        (int) $field->id,
                        $fieldName,
                        $captionUpdates,
                        $updateResult,
                        $deleteKey ?? ''
                    );
                }

                $response = [
                    'ok' => true,
                    'message' => 'Gallery metadata updated.',
                    'updated' => (int) ($updateResult['updated'] ?? 0),
                    'total' => (int) ($updateResult['total'] ?? 0),
                ];
                if (is_array($debug)) {
                    $response['debug'] = $debug;
                }
                return $response;
            }

            if (in_array($action, ['import', 'reimport'], true)) {
                $action = $action . '_init';
            }

            if ($action === 'import_init' || $action === 'reimport_init') {
                if ($shareUrl === '') {
                    return ['ok' => false, 'message' => 'Import requires share URL.'];
                }
                if ($galleryTitle === '') {
                    return ['ok' => false, 'message' => 'Import requires gallery title.'];
                }
                $importParams = $this->resolveImportParams($field, $articleId);
                $importParams['gallery_title'] = $galleryTitle;
                $state = $this->startStepImport($shareUrl, $articleId, (int) $field->id, $importParams);
                return [
                    'ok' => true,
                    'message' => 'Import initialized.',
                    'step_state' => $state['state_id'],
                    'total' => $state['total'],
                    'processed' => 0,
                ];
            }

            if ($action === 'import_next') {
                $stateId = trim((string) $input->post->getString('r3dncg_state_id', ''));
                if ($stateId === '') {
                    return ['ok' => false, 'message' => 'Missing import state id.'];
                }
                $userId = (int) ($app->getIdentity()->id ?? 0);
                $step = $this->processStepImport($stateId, $articleId, (int) $field->id, $userId);
                return [
                    'ok' => true,
                    'message' => 'Import step completed.',
                    'step_state' => $stateId,
                    'processed' => (int) $step['processed'],
                    'total' => (int) $step['total'],
                    'done' => (bool) $step['done'],
                ];
            }

            if ($action === 'import_finalize') {
                $stateId = trim((string) $input->post->getString('r3dncg_state_id', ''));
                if ($stateId === '') {
                    return ['ok' => false, 'message' => 'Missing import state id.'];
                }
                $userId = (int) ($app->getIdentity()->id ?? 0);
                $result = $this->finalizeStepImport($stateId, $articleId, (int) $field->id, $userId);
                $newValue = $this->buildFieldValueJson($shareUrl, (string) ($result['gallery_key'] ?? ''), (string) ($result['gallery_json'] ?? ''), $galleryTitle);
                $this->persistFieldValueForItem((int) $field->id, $articleId, $newValue);

                return [
                    'ok' => true,
                    'message' => 'Import completed.',
                    'status' => (string) ($result['status'] ?? 'failed'),
                    'gallery_json' => (string) ($result['gallery_json'] ?? ''),
                    'imported_images' => (int) ($result['imported_images'] ?? 0),
                    'errors' => (array) ($result['errors'] ?? []),
                ];
            }

            if (!in_array($action, ['import_init', 'reimport_init', 'import_next', 'import_finalize'], true)) {
                return ['ok' => false, 'message' => 'Unsupported action.'];
            }
        } catch (\Throwable $e) {
            $debug = [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'action' => $action,
                'article_id' => $articleId,
                'field_id' => (int) $field->id,
                'field_name' => $fieldName,
            ];

            try {
                error_log('[r3dnextcloudgallery] AJAX failure: ' . json_encode($debug, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $logError) {
                // Ignore logging failures so the original error still reaches the client.
            }

            return ['ok' => false, 'message' => 'Action failed.', 'debug' => $debug];
        }
    }

    public function importGallery(string $shareUrl, int $articleId, int $fieldId, array $params = []): array
    {
        $allowedShareHosts = $this->parseAllowedShareHosts((string) ($params['allowed_share_hosts'] ?? ''));
        $enforceAllowedHosts = array_key_exists('enforce_allowed_share_hosts', $params) && (int) $params['enforce_allowed_share_hosts'] === 1;
        if ($enforceAllowedHosts && $allowedShareHosts === []) {
            throw new \RuntimeException('No allowed share hosts configured. Please add trusted Nextcloud hosts in the plugin settings.');
        }

        $service = new GalleryImportService(
            new ShareLinkParser(),
            new PublicWebDavClient($allowedShareHosts),
            new GalleryStorage(),
            new ImageProcessor()
        );

        return $service->import($shareUrl, $articleId, $fieldId, $params);
    }

    public function reimportGallery(string $shareUrl, int $articleId, int $fieldId, array $params = []): array
    {
        return $this->importGallery($shareUrl, $articleId, $fieldId, $params);
    }

    public function renderGallery(string $fieldValueJson, array $renderOptions = []): string
    {
        $decoded = json_decode($fieldValueJson, true);
        $galleryJson = '';
        $galleryTitle = '';

        if (is_array($decoded)) {
            $galleryJson = (string) ($decoded['gallery_json'] ?? '');
            $galleryTitle = trim((string) ($decoded['gallery_title'] ?? ''));
        } elseif (is_string($fieldValueJson) && trim($fieldValueJson) !== '') {
            $decoded = ['share_url' => trim($fieldValueJson)];
        }

        if ($galleryJson === '' && is_array($decoded)) {
            $shareUrl = trim((string) ($decoded['share_url'] ?? ''));
            $articleId = (int) ($renderOptions['article_id'] ?? 0);
            $fieldId = (int) ($renderOptions['field_id'] ?? 0);
            if ($shareUrl !== '' && $articleId > 0 && $fieldId > 0) {
                $galleryJson = $this->resolveGalleryJsonByShare($shareUrl, $articleId, $fieldId);
            }
            if ($galleryJson === '' && $articleId > 0 && $fieldId > 0) {
                $galleryJson = $this->resolveGalleryJsonByArticleField($articleId, $fieldId);
            }
            if ($galleryJson === '' && $articleId > 0) {
                $galleryJson = $this->resolveLatestGalleryJsonByArticle($articleId);
            }
        }

        if ($galleryJson === '') {
            if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
                return $this->renderLegacyItemsGallery($decoded['items'], $renderOptions);
            }
            return '';
        }

        $path = rtrim(JPATH_ROOT, '/\\') . '/' . ltrim($galleryJson, '/');
        $renderer = new GalleryRenderer();
        $renderOptions['gallery_title'] = $galleryTitle;
        if ((string) ($renderOptions['frontend_lightbox'] ?? 'builtin') === 'lightgallery') {
            $this->frontendNeedsLightGallery = true;
            $this->frontendLightGalleryPlugins['zoom'] = ((int) ($renderOptions['lightgallery_zoom'] ?? 1)) === 1;
            $this->frontendLightGalleryPlugins['fullscreen'] = ((int) ($renderOptions['lightgallery_fullscreen'] ?? 1)) === 1;
            $this->frontendLightGalleryPlugins['thumbnail'] = ((int) ($renderOptions['lightgallery_thumbnails'] ?? 0)) === 1;
            $this->frontendLightGalleryPlugins['share'] = ((int) ($renderOptions['lightgallery_share'] ?? 0)) === 1;
            $this->frontendLightGalleryPlugins['rotate'] = ((int) ($renderOptions['lightgallery_rotate'] ?? 0)) === 1;
            $this->frontendLightGalleryPlugins['hash'] = ((int) ($renderOptions['lightgallery_hash'] ?? 1)) === 1;
        }

        return $renderer->renderFromGalleryJsonPath($path, $renderOptions);
    }

    private function renderLegacyItemsGallery(array $items, array $options = []): string
    {
        if ($items === []) {
            return '';
        }

        $renderer = new GalleryRenderer();
        return $renderer->renderLegacyItems($items, $options);
    }

    public function resolveBackendImportStatus(string $fieldValueJson): string
    {
        $decoded = json_decode($fieldValueJson, true);

        if (!is_array($decoded) || empty($decoded['gallery_json'])) {
            return 'never_imported';
        }

        try {
            $galleryJsonPath = $this->resolveSafeGalleryJsonPath((string) $decoded['gallery_json']);
        } catch (\Throwable $e) {
            return 'never_imported';
        }

        if (!is_file($galleryJsonPath)) {
            return 'never_imported';
        }

        $gallery = json_decode((string) file_get_contents($galleryJsonPath), true);
        $status = (string) ($gallery['status'] ?? '');

        return match ($status) {
            'successful' => 'successful',
            'partially_failed' => 'partially_failed',
            'failed' => 'failed',
            default => 'never_imported',
        };
    }

    public function onCustomFieldsPrepareField($context, $item, $field)
    {
        if (!$this->isTargetField($field)) {
            return '';
        }

        $this->loadAssets();

        if (Factory::getApplication()->isClient('site')) {
            // Frontend output is appended by onContentPrepare/onAfterRender at article end.
            return '';
        }

        $this->handleBackendActions($item, $field);

        $rawValue = is_string($field->rawvalue ?? null) ? (string) $field->rawvalue : (string) ($field->value ?? '');
        $status = $this->resolveBackendImportStatus($rawValue);
        $meta = $this->resolveBackendImportMeta($rawValue);

        $badgeClass = match ($status) {
            'successful' => 'success',
            'partially_failed' => 'warning',
            'failed' => 'danger',
            default => 'secondary',
        };

        $statusLabel = $this->humanStatusLabel($status);
        $statusHtml = '<span class="badge bg-' . $badgeClass . '">Nextcloud Import: ' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span>';

        if ($meta['log_url'] !== '') {
            $statusHtml .= ' <a href="' . htmlspecialchars($meta['log_url'], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Import-Log</a>';
        }

        $widgetHtml = $this->buildBackendActionLinks($item, $field, $rawValue);
        $editorHtml = $this->buildCaptionEditorHtml($rawValue);

        $field->description = trim(
            (string) ($field->description ?? '')
            . ' '
            . $statusHtml
            . ' '
            . $widgetHtml
            . ' '
            . $editorHtml
        );

        return '';
    }

    public function updateImageCaptions(string $fieldValueJson, array $captionUpdates): array
    {
        $mapper = new FieldValueMapper();
        $decoded = $mapper->decode($fieldValueJson);
        if (!is_array($decoded)) {
            return ['updated' => 0, 'total' => 0, 'reason' => 'invalid_field_value'];
        }
        $galleryJson = (string) ($decoded['gallery_json'] ?? '');

        if ($galleryJson === '') {
            return ['updated' => 0, 'total' => 0, 'reason' => 'missing_gallery_json'];
        }

        try {
            $galleryPath = $this->resolveSafeGalleryJsonPath($galleryJson);
        } catch (\Throwable $e) {
            return [
                'updated' => 0,
                'total' => 0,
                'reason' => 'invalid_gallery_path',
                'error' => $e->getMessage(),
            ];
        }

        return $mapper->applyCaptionsToGallery($galleryPath, $captionUpdates);
    }

    private function ensureGalleryJsonInFieldValue(string $fieldValueJson, string $shareUrl, int $articleId, int $fieldId): string
    {
        $mapper = new FieldValueMapper();
        $decoded = $mapper->decode($fieldValueJson);

        if (!is_array($decoded)) {
            $decoded = [];
        }

        $galleryJson = trim((string) ($decoded['gallery_json'] ?? ''));
        if ($galleryJson !== '' && $this->galleryJsonHasImages($galleryJson)) {
            return $fieldValueJson;
        }

        $resolved = $this->resolveGalleryJsonByShare($shareUrl, $articleId, $fieldId);
        if ($resolved === '') {
            $resolved = $this->resolveGalleryJsonByArticleField($articleId, $fieldId);
        }
        if ($resolved === '') {
            $resolved = $this->resolveLatestGalleryJsonByArticle($articleId);
        }
        if ($resolved === '') {
            return $fieldValueJson;
        }

        $decoded['share_url'] = $shareUrl;
        $decoded['gallery_key'] = $decoded['gallery_key'] ?? ($articleId . '-' . $fieldId . '-' . substr(sha1((string) preg_replace('#^.*?/s/([A-Za-z0-9]+).*$#', '$1', $shareUrl)), 0, 12));
        $decoded['gallery_json'] = $resolved;
        $decoded['imported_at'] = $decoded['imported_at'] ?? gmdate('c');

        return $mapper->encode($decoded);
    }

    private function galleryJsonHasImages(string $galleryJsonRelativePath): bool
    {
        if ($galleryJsonRelativePath === '') {
            return false;
        }

        try {
            $galleryJsonAbsolutePath = $this->resolveSafeGalleryJsonPath($galleryJsonRelativePath);
        } catch (\Throwable $e) {
            return false;
        }

        if (!is_file($galleryJsonAbsolutePath)) {
            return false;
        }

        $gallery = json_decode((string) file_get_contents($galleryJsonAbsolutePath), true);
        if (!is_array($gallery)) {
            return false;
        }

        $images = $gallery['images'] ?? [];
        return is_array($images) && count($images) > 0;
    }

    private function countGalleryImages(string $galleryJsonRelativePath): int
    {
        if ($galleryJsonRelativePath === '') {
            return 0;
        }

        try {
            $galleryJsonAbsolutePath = $this->resolveSafeGalleryJsonPath($galleryJsonRelativePath);
        } catch (\Throwable $e) {
            return 0;
        }

        if (!is_file($galleryJsonAbsolutePath)) {
            return 0;
        }

        $gallery = json_decode((string) file_get_contents($galleryJsonAbsolutePath), true);
        if (!is_array($gallery)) {
            return 0;
        }

        $images = $gallery['images'] ?? [];
        return is_array($images) ? count($images) : 0;
    }

    private function buildAjaxDebugData(string $action, string $fieldValueJson, string $shareUrl, string $galleryTitle, int $articleId, int $fieldId, string $fieldName, array $captionUpdates = [], ?array $updateResult = null, string $deleteKey = ''): array
    {
        $decoded = json_decode($fieldValueJson, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $fieldGalleryJson = trim((string) ($decoded['gallery_json'] ?? ''));
        $fieldValueKeys = array_keys($decoded);
        $fieldPathDebug = $this->describeGalleryJsonPath($fieldGalleryJson);

        $resolvedByShare = $this->resolveGalleryJsonByShare($shareUrl, $articleId, $fieldId);
        $resolvedByArticleField = $this->resolveGalleryJsonByArticleField($articleId, $fieldId);
        $resolvedByLatest = $this->resolveLatestGalleryJsonByArticle($articleId);

        $resolvedSource = 'field_value';
        $effectiveGalleryJson = $fieldGalleryJson;
        if ($effectiveGalleryJson === '' || !$this->galleryJsonHasImages($effectiveGalleryJson)) {
            if ($resolvedByShare !== '') {
                $effectiveGalleryJson = $resolvedByShare;
                $resolvedSource = 'share';
            } elseif ($resolvedByArticleField !== '') {
                $effectiveGalleryJson = $resolvedByArticleField;
                $resolvedSource = 'article_field';
            } elseif ($resolvedByLatest !== '') {
                $effectiveGalleryJson = $resolvedByLatest;
                $resolvedSource = 'latest_article';
            } else {
                $resolvedSource = 'none';
            }
        }
        $effectivePathDebug = $this->describeGalleryJsonPath($effectiveGalleryJson);

        return [
            'action' => $action,
            'delete_key' => $deleteKey,
            'article_id' => $articleId,
            'field_id' => $fieldId,
            'field_name' => $fieldName,
            'share_url' => $shareUrl,
            'gallery_title' => $galleryTitle,
            'field_value_raw_length' => strlen($fieldValueJson),
            'field_value_json_keys' => $fieldValueKeys,
            'field_value_gallery_json' => $fieldGalleryJson,
            'field_value_gallery_json_exists' => $this->galleryJsonExists($fieldGalleryJson),
            'field_value_gallery_images' => $this->countGalleryImages($fieldGalleryJson),
            'field_value_gallery_path_debug' => $fieldPathDebug,
            'resolved_gallery_json' => $effectiveGalleryJson,
            'resolved_gallery_json_exists' => $this->galleryJsonExists($effectiveGalleryJson),
            'resolved_gallery_images' => $this->countGalleryImages($effectiveGalleryJson),
            'resolved_gallery_path_debug' => $effectivePathDebug,
            'resolved_source' => $resolvedSource,
            'resolved_by_share' => $resolvedByShare,
            'resolved_by_article_field' => $resolvedByArticleField,
            'resolved_by_latest' => $resolvedByLatest,
            'caption_updates_count' => count($captionUpdates),
            'caption_updates_keys' => array_keys($captionUpdates),
            'update_result' => $updateResult,
        ];
    }

    private function galleryJsonExists(string $galleryJsonRelativePath): bool
    {
        if ($galleryJsonRelativePath === '') {
            return false;
        }

        try {
            $galleryJsonAbsolutePath = $this->resolveSafeGalleryJsonPath($galleryJsonRelativePath);
        } catch (\Throwable $e) {
            return false;
        }

        return is_file($galleryJsonAbsolutePath);
    }

    private function describeGalleryJsonPath(string $galleryJsonRelativePath): array
    {
        $normalized = $this->normalizeRelativePath($galleryJsonRelativePath);
        $candidate = $normalized !== '' ? Path::clean(rtrim(JPATH_ROOT, '/\\') . '/' . $normalized) : '';
        $real = $candidate !== '' ? realpath($candidate) : false;
        $realNormalized = is_string($real) && $real !== '' ? str_replace('\\', '/', $real) : '';
        $allowedBases = $this->allowedGalleryBaseDirectories();
        $matches = [];

        if ($realNormalized !== '') {
            foreach ($allowedBases as $allowedBase) {
                if (str_starts_with($realNormalized . '/', $allowedBase . '/')) {
                    $matches[] = $allowedBase;
                }
            }
        }

        return [
            'relative' => $normalized,
            'candidate_absolute' => $candidate,
            'realpath' => $realNormalized,
            'exists' => $candidate !== '' && is_file($candidate),
            'allowed_bases' => $allowedBases,
            'allowed_base_match' => $matches,
            'is_allowed' => $matches !== [],
        ];
    }

    private function resolveBackendImportMeta(string $fieldValueJson): array
    {
        $decoded = json_decode($fieldValueJson, true);

        if (!is_array($decoded) || empty($decoded['gallery_json'])) {
            return ['log_url' => ''];
        }

        try {
            $galleryJsonPath = $this->resolveSafeGalleryJsonPath((string) $decoded['gallery_json']);
        } catch (\Throwable $e) {
            return ['log_url' => ''];
        }

        if (!is_file($galleryJsonPath)) {
            return ['log_url' => ''];
        }

        $gallery = json_decode((string) file_get_contents($galleryJsonPath), true);
        if (!is_array($gallery)) {
            return ['log_url' => ''];
        }

        $logFile = (string) ($gallery['log_file'] ?? '');
        if ($logFile === '') {
            return ['log_url' => ''];
        }

        return ['log_url' => Uri::root() . ltrim($logFile, '/')];
    }

    private function isTargetField(object $field): bool
    {
        $type = strtolower((string) ($field->type ?? ''));
        $name = strtolower((string) ($field->name ?? ''));
        $raw = is_string($field->rawvalue ?? null) ? (string) $field->rawvalue : (string) ($field->value ?? '');

        if ($type === 'r3dnextcloudgallery' || str_contains($type, 'nextcloudgallery')) {
            return true;
        }

        if (str_contains($name, 'r3dnextcloudgallery') || str_contains($name, 'nextcloudgallery') || str_contains($name, 'nc-gallery') || str_contains($name, 'nc_gallery')) {
            return true;
        }

        return $this->looksLikeGalleryValue($raw);
    }

    private function handleBackendActions(object $item, object $field): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('administrator')) {
            return;
        }

        $input = $app->input;
        $isAjax = $input->getInt('r3dncg_ajax', 0) === 1;
        $action = strtolower($input->getCmd('r3dncg_action', ''));
        $targetFieldId = $input->getInt('r3dncg_field_id', 0);
        $targetFieldName = strtolower(trim((string) $input->getString('r3dncg_field_name', '')));
        $currentFieldId = (int) ($field->id ?? 0);
        $currentFieldName = strtolower(trim((string) ($field->name ?? '')));

        if ($action === '') {
            return;
        }

        $idMatches = $targetFieldId > 0 && $currentFieldId === $targetFieldId;
        $normalize = static function (string $value): string {
            return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
        };

        $nameMatches = $targetFieldName !== ''
            && $currentFieldName !== ''
            && $normalize($targetFieldName) === $normalize($currentFieldName);
        $typeMatches = $this->isTargetField($field);

        if (!$idMatches && !$nameMatches && !$typeMatches) {
            return;
        }

        if ($isAjax) {
            if (!Session::checkToken('post')) {
                $this->closeAjax(['ok' => false, 'message' => 'Invalid security token.']);
            }
        } elseif (!Session::checkToken('get')) {
            $app->enqueueMessage('Invalid security token for Nextcloud gallery action.', 'error');
            return;
        }

        if (!in_array($action, ['import', 'reimport', 'update_captions', 'save_meta', 'delete_item'], true)) {
            return;
        }

        if (!$this->canEditItem($item)) {
            if ($isAjax) {
                $this->closeAjax(['ok' => false, 'message' => 'Missing edit permission.']);
            }
            $app->enqueueMessage('Missing edit permission for this article.', 'error');
            return;
        }

        $rawValue = is_string($field->rawvalue ?? null) ? (string) $field->rawvalue : (string) ($field->value ?? '');

        try {
            if ($action === 'update_captions' || $action === 'save_meta' || $action === 'delete_item') {
                $captionJson = $this->getRawCaptionsPayload();
                $captionUpdates = json_decode($captionJson, true);
                $captionUpdates = is_array($captionUpdates) ? $captionUpdates : [];

                if ($action === 'delete_item') {
                    $deleteKey = trim((string) $input->getString('r3dncg_delete_key', ''));
                    if ($deleteKey !== '') {
                        $captionUpdates[$deleteKey] = ['delete' => 1];
                    }
                }

                $articleIdForMeta = (int) ($item->id ?? 0);
                $shareUrlForMeta = trim((string) $input->getString('r3dncg_share_url', ''));
                if ($shareUrlForMeta === '') {
                    $shareUrlForMeta = $this->resolveShareUrlForAction($rawValue);
                }
                $rawValue = $this->ensureGalleryJsonInFieldValue($rawValue, $shareUrlForMeta, $articleIdForMeta, $currentFieldId);
                $updateResult = $this->updateImageCaptions($rawValue, $captionUpdates);

                if ($isAjax) {
                    $this->closeAjax([
                        'ok' => true,
                        'message' => 'Gallery metadata updated.',
                        'updated' => (int) ($updateResult['updated'] ?? 0),
                        'total' => (int) ($updateResult['total'] ?? 0),
                    ]);
                }

                $app->enqueueMessage('Gallery metadata updated: ' . (int) $updateResult['updated'], 'message');
                return;
            }

            $articleId = (int) (($item->id ?? 0) ?: $input->getInt('r3dncg_article_id', 0));
            $shareUrl = trim((string) $input->getString('r3dncg_share_url', ''));
            $galleryTitle = trim((string) $input->getString('r3dncg_gallery_title', ''));
            if ($shareUrl === '') {
                $shareUrl = $this->resolveShareUrlForAction($rawValue);
            }
            if ($galleryTitle === '') {
                $galleryTitle = $this->resolveGalleryTitleForAction($rawValue);
            }

            if ($articleId <= 0 || $shareUrl === '') {
                $app->enqueueMessage('Import requires article id and share URL.', 'warning');
                return;
            }

            $importParams = $this->resolveImportParams($field, $articleId);
            $importParams['gallery_title'] = $galleryTitle;
            $result = $this->importGallery($shareUrl, $articleId, $currentFieldId, $importParams);
            $newValue = $this->buildFieldValueJson($shareUrl, $result['gallery_key'] ?? '', $result['gallery_json'] ?? '', $galleryTitle);
            $field->value = $newValue;
            $field->rawvalue = $newValue;

            $status = (string) ($result['status'] ?? 'failed');

            if ($isAjax) {
                $this->closeAjax([
                    'ok' => true,
                    'message' => 'Import completed.',
                    'status' => $status,
                    'imported_images' => (int) ($result['imported_images'] ?? 0),
                    'errors' => (array) ($result['errors'] ?? []),
                ]);
            }

            $app->enqueueMessage('Nextcloud import status: ' . $status, $status === 'successful' ? 'message' : 'warning');
        } catch (\Throwable $e) {
            if ($isAjax) {
                $this->closeAjax([
                    'ok' => false,
                    'message' => 'Nextcloud action failed.',
                ]);
            }
            $app->enqueueMessage('Nextcloud import failed.', 'error');
        }
    }

    private function humanStatusLabel(string $status): string
    {
        return match ($status) {
            'successful' => 'erfolgreich',
            'partially_failed' => 'teilweise fehlerhaft',
            'failed' => 'fehlgeschlagen',
            default => 'nie importiert',
        };
    }

    private function resolveShareUrlForAction(string $fieldValueJson): string
    {
        $decoded = json_decode($fieldValueJson, true);
        if (!is_array($decoded)) {
            return '';
        }

        return trim((string) ($decoded['share_url'] ?? ''));
    }

    private function resolveGalleryTitleForAction(string $fieldValueJson): string
    {
        $decoded = json_decode($fieldValueJson, true);
        if (!is_array($decoded)) {
            return '';
        }

        return trim((string) ($decoded['gallery_title'] ?? ''));
    }

    /**
     * Render fallback for templates not outputting custom fields.
     */
    public function onContentPrepare($context, &$article, &$params, $page = 0): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $context = (string) $context;
        if ($context !== '' && !str_starts_with($context, 'com_content')) {
            return;
        }

        $input = $app->input;
        $articleId = (int) ($article->id ?? 0);
        if ($articleId <= 0) {
            return;
        }

        $requestedId = $input->getInt('id', 0);
        if ($requestedId > 0 && $requestedId !== $articleId) {
            return;
        }

        $fields = (array) ($article->jcfields ?? []);

        $append = [];

        if ($fields !== []) {
            foreach ($fields as $field) {
                if (!is_object($field) || !$this->isTargetField($field)) {
                    continue;
                }

                $raw = is_string($field->rawvalue ?? null) ? (string) $field->rawvalue : (string) ($field->value ?? '');
                $options = $this->extractRenderOptions($field);
                if (($options['frontend_contentprepare_append'] ?? 1) !== 1) {
                    continue;
                }
                $options['article_id'] = $articleId;
                $options['field_id'] = (int) ($field->id ?? 0);
                $html = $this->renderGallery($raw, $options);

                if ($html !== '') {
                    $append[] = $html;
                }
            }
        }

        // Fallback: if template did not provide jcfields, load field values directly.
        if ($append === []) {
            $append = $this->loadRenderedGalleriesForArticle($articleId, 'contentprepare');
        }

        if ($append === []) {
            return;
        }

        $marker = '<!-- r3dnextcloudgallery-rendered -->';

        if (str_contains((string) ($article->text ?? ''), 'r3d-nextcloud-gallery')
            || str_contains((string) ($article->introtext ?? ''), 'r3d-nextcloud-gallery')
            || str_contains((string) ($article->fulltext ?? ''), 'r3d-nextcloud-gallery')) {
            return;
        }

        $this->loadAssets();
        $chunk = $marker . implode('', $append);
        $article->text = (string) ($article->text ?? '') . $chunk;
        if (property_exists($article, 'fulltext')) {
            $article->fulltext = (string) ($article->fulltext ?? '') . $chunk;
        }
    }

    public function onAfterRender(): void
    {
        $app = Factory::getApplication();
        if (!$app->isClient('site')) {
            return;
        }

        $input = $app->input;
        $articleId = $this->resolveCurrentArticleId();
        if ($articleId <= 0) {
            return;
        }

        $body = (string) $app->getBody();
        if ($body === '' || str_contains($body, 'r3d-nextcloud-gallery')
            || str_contains($body, 'r3dnextcloudgallery-rendered')
            || str_contains($body, 'r3dnextcloudgallery-afterrender')) {
            return;
        }

        $append = $this->loadRenderedGalleriesForArticle($articleId, 'afterrender');
        if ($append === []) {
            return;
        }

        $assets = $this->directAssetHtml();
        if (!str_contains($body, '/plugins/fields/r3dnextcloudgallery/media/plg_fields_r3dnextcloudgallery/css/field.css')) {
            $body = $this->injectAssetsIntoHead($body, $assets);
        }

        $chunk = '<!-- r3dnextcloudgallery-afterrender -->' . implode('', $append);
        if (str_contains($body, '</body>')) {
            $body = $this->injectGalleryIntoBody($body, $chunk);
        } else {
            $body .= $chunk;
        }

        $app->setBody($body);
    }

    private function resolveCurrentArticleId(): int
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $articleId = (int) $input->getInt('id', 0);
        if ($articleId > 0) {
            return $articleId;
        }

        $menu = $app->getMenu();
        $active = $menu ? $menu->getActive() : null;
        if ($active && isset($active->query) && is_array($active->query)) {
            $query = $active->query;
            if (($query['option'] ?? '') === 'com_content' && ($query['view'] ?? '') === 'article') {
                $menuArticleId = (int) ($query['id'] ?? 0);
                if ($menuArticleId > 0) {
                    return $menuArticleId;
                }
            }
        }

        return 0;
    }

    public function onContentBeforeSave($context, $table, $isNew, $data = []): bool
    {
        if ((string) $context !== 'com_content.article') {
            return true;
        }

        $articleId = (int) ($table->id ?? 0);
        if ($articleId <= 0) {
            return true;
        }

        $this->preSaveFieldValues[$articleId] = $this->loadAllTargetFieldValuesForItem($articleId);

        return true;
    }

    public function onContentAfterSave($context, $table, $isNew, $data = []): bool
    {
        if ((string) $context !== 'com_content.article') {
            return true;
        }

        $articleId = (int) ($table->id ?? 0);
        if ($articleId <= 0) {
            return true;
        }

        $before = $this->preSaveFieldValues[$articleId] ?? [];
        unset($this->preSaveFieldValues[$articleId]);
        if ($before === []) {
            return true;
        }

        $after = $this->loadAllTargetFieldValuesForItem($articleId);
        foreach ($before as $fieldId => $oldRaw) {
            $old = (new FieldValueMapper())->decode((string) $oldRaw);
            $oldGalleryJson = trim((string) ($old['gallery_json'] ?? ''));
            if ($oldGalleryJson === '') {
                continue;
            }

            $new = (new FieldValueMapper())->decode((string) ($after[$fieldId] ?? ''));
            $hasCurrentGallery = trim((string) ($new['gallery_json'] ?? '')) !== '' || trim((string) ($new['share_url'] ?? '')) !== '';
            if ($hasCurrentGallery) {
                continue;
            }

            $this->deleteGalleryAlbumByJson($oldGalleryJson);
        }

        return true;
    }

    private function buildFieldValueJson(string $shareUrl, string $galleryKey, string $galleryJson, string $galleryTitle = ''): string
    {
        $mapper = new FieldValueMapper();

        return $mapper->encode([
            'share_url' => $shareUrl,
            'gallery_title' => $galleryTitle,
            'gallery_key' => $galleryKey,
            'imported_at' => gmdate('c'),
            'gallery_json' => $galleryJson,
        ]);
    }

    private function buildBackendActionLinks(object $item, object $field, string $rawValue): string
    {
        $fieldId = (int) ($field->id ?? 0);
        $articleId = (int) ($item->id ?? 0);
        $shareUrl = $this->resolveShareUrlForAction($rawValue);
        $galleryTitle = $this->resolveGalleryTitleForAction($rawValue);

        if ($fieldId <= 0 || $articleId <= 0) {
            return '';
        }

        $tokenKey = Session::getFormToken();

        $data = [
            'fieldId' => $fieldId,
            'articleId' => $articleId,
            'shareUrl' => $shareUrl,
            'galleryTitle' => $galleryTitle,
            'tokenKey' => $tokenKey,
        ];

        return $this->renderAdminWidget($data);
    }

    private function buildCaptionEditorHtml(string $fieldValueJson): string
    {
        $decoded = json_decode($fieldValueJson, true);

        if (!is_array($decoded) || empty($decoded['gallery_json'])) {
            return '';
        }

        try {
            $galleryJsonPath = $this->resolveSafeGalleryJsonPath((string) $decoded['gallery_json']);
        } catch (\Throwable $e) {
            return '';
        }

        if (!is_file($galleryJsonPath)) {
            return '';
        }

        $gallery = json_decode((string) file_get_contents($galleryJsonPath), true);
        if (!is_array($gallery)) {
            return '';
        }
        $gallery = (new FieldValueMapper())->normalizeGalleryArray($gallery);

        $images = (array) ($gallery['images'] ?? []);
        if ($images === []) {
            return '';
        }

        $cards = [];
        $mapper = new FieldValueMapper();
        foreach ($images as $image) {
            $sourceName = (string) ($image['source_name'] ?? '');
            if ($sourceName === '') {
                continue;
            }

            $caption = (string) ($image['caption'] ?? '');
            $title = (string) ($image['title'] ?? '');
            $titleSuggestion = $mapper->buildReadableFromFilename($sourceName);
            $sort = (int) ($image['sort'] ?? 0);
            $thumb = (string) ($image['thumb'] ?? '');
            $keyEsc = htmlspecialchars($sourceName, ENT_QUOTES, 'UTF-8');
            $captionEsc = htmlspecialchars($caption, ENT_QUOTES, 'UTF-8');
            $thumbEsc = htmlspecialchars('/' . ltrim($thumb, '/'), ENT_QUOTES, 'UTF-8');

            $cards[] = '<article class="r3dncg-card" draggable="true" data-r3dncg-card="' . $keyEsc . '">'
                . '<header class="r3dncg-card__head">'
                . '<label class="r3dncg-check"><input type="checkbox" data-r3dncg-delete="' . $keyEsc . '"></label>'
                . '<span class="r3dncg-file" title="' . $keyEsc . '">' . $keyEsc . '</span>'
                . '</header>'
                . '<div class="r3dncg-card__thumb dz-thumb" data-r3dncg-drag-handle="1"><img src="' . $thumbEsc . '" alt="">'
                . '<span class="r3dncg-thumb-overlay">'
                . '<button type="button" class="btn btn-sm r3dncg-delete r3dncg-delete-icon" data-r3dncg-delete-item="' . $keyEsc . '" title="' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_DELETE') . '" aria-label="' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_DELETE') . '"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>'
                . '<span class="badge rounded-pill r3dncg-badge r3dncg-badge--synced" title="' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_STATUS_SYNCED') . '" aria-label="' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_STATUS_SYNCED') . '"><i class="fa-solid fa-check" aria-hidden="true"></i></span>'
                . '</span></div>'
                . '<div class="r3dncg-card__fields">'
                . '<input type="hidden" data-r3dncg-sort="' . $keyEsc . '" value="' . $sort . '">'
                . '<input type="text" class="form-control form-control-sm" data-r3dncg-caption="' . $keyEsc . '" value="' . $captionEsc . '" placeholder="Caption">'
                . '</div>'
                . '</article>';
        }

        if ($cards === []) {
            return '';
        }

        return '<section class="r3d-nextcloud-gallery-caption-editor controls">'
            . '<div class="r3d-nextcloud-gallery-controls">'
            . '<label><input type="checkbox" data-r3dncg-master-delete="1"> ' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_SELECT_ALL') . '</label>'
            . '<button type="button" class="btn btn-sm btn-outline-danger" data-r3dncg-delete-selected="1">' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_DELETE_SELECTED') . '</button>'
            . '</div>'
            . '<div class="r3dncg-grid" data-r3dncg-grid="1">' . implode('', $cards) . '</div>'
            . '</section>';
    }

    private function loadRenderedGalleriesForArticle(int $articleId, string $mode = 'any'): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select([
                'f.id AS field_id',
                'f.name AS field_name',
                'f.type AS field_type',
                'f.fieldparams AS fieldparams',
                'fv.value AS rawvalue',
            ])
            ->from($db->quoteName('#__fields', 'f'))
            ->innerJoin($db->quoteName('#__fields_values', 'fv') . ' ON fv.field_id = f.id')
            ->where('f.context = ' . $db->quote('com_content.article'))
            ->where('f.state >= 0')
            ->where('fv.item_id = ' . $db->quote((string) $articleId));

        $db->setQuery($query);
        $rows = (array) $db->loadObjectList();

        $result = [];
        foreach ($rows as $row) {
            if (!is_object($row) || !$this->isTargetField($row)) {
                continue;
            }
            $raw = is_string($row->rawvalue ?? null) ? (string) $row->rawvalue : '';
            $options = $this->extractRenderOptions($row);
            if ($mode === 'contentprepare' && ($options['frontend_contentprepare_append'] ?? 1) !== 1) {
                continue;
            }
            if ($mode === 'afterrender' && ($options['frontend_fallback_injection'] ?? 0) !== 1) {
                continue;
            }
            $options['article_id'] = $articleId;
            $options['field_id'] = (int) ($row->field_id ?? 0);
            if ($raw === '') {
                $galleryJson = $this->resolveGalleryJsonByArticleField($articleId, (int) ($row->field_id ?? 0));
                if ($galleryJson !== '') {
                    $raw = json_encode(['gallery_json' => $galleryJson], JSON_UNESCAPED_SLASHES) ?: '';
                }
            }
            if ($raw === '') {
                continue;
            }
            $html = $this->renderGallery($raw, $options);
            if ($html !== '') {
                $result[] = $html;
            }
        }

        // Hard fallback: detect gallery-like values independent from type/name.
        if ($result === []) {
            foreach ($rows as $row) {
                if (!is_object($row)) {
                    continue;
                }
                $raw = is_string($row->rawvalue ?? null) ? (string) $row->rawvalue : '';
                if (!$this->looksLikeGalleryValue($raw)) {
                    continue;
                }
                $options = [
                    'layout' => 'masonry',
                    'columns' => 4,
                    'article_id' => $articleId,
                    'field_id' => (int) ($row->field_id ?? 0),
                    'frontend_contentprepare_append' => (int) $this->params->get('frontend_contentprepare_append', 1),
                    'frontend_fallback_injection' => (int) $this->params->get('frontend_fallback_injection', 1),
                ];
                if ($mode === 'contentprepare' && ($options['frontend_contentprepare_append'] ?? 1) !== 1) {
                    continue;
                }
                if ($mode === 'afterrender' && ($options['frontend_fallback_injection'] ?? 0) !== 1) {
                    continue;
                }
                $html = $this->renderGallery($raw, $options);
                if ($html !== '') {
                    $result[] = $html;
                }
            }
        }

        return $result;
    }

    private function looksLikeGalleryValue(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '') {
            return false;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return false;
        }

        if (!empty($decoded['gallery_json']) || !empty($decoded['share_url'])) {
            return true;
        }

        if (isset($decoded['items']) && is_array($decoded['items'])) {
            return true;
        }

        return false;
    }

    private function resolveGalleryJsonByShare(string $shareUrl, int $articleId, int $fieldId): string
    {
        if (!preg_match('#/s/([A-Za-z0-9]+)#', $shareUrl, $matches)) {
            return '';
        }

        $token = $matches[1];
        $legacyGalleryKey = $articleId . '-' . $fieldId . '-' . substr(sha1($token), 0, 12);
        $legacyRelative = 'images/galleries/nextcloud/' . $legacyGalleryKey . '/gallery.json';
        $legacyAbsolute = rtrim(JPATH_ROOT, '/\\') . '/' . $legacyRelative;

        if (is_file($legacyAbsolute)) {
            return $legacyRelative;
        }

        $matches = $this->findGalleryJsonFilesUnderImages();
        foreach ($matches as $file) {
            $gallery = json_decode((string) file_get_contents($file), true);
            if (!is_array($gallery)) {
                continue;
            }
            if ((string) ($gallery['share_url'] ?? '') !== $shareUrl) {
                continue;
            }

            return str_replace('\\', '/', str_replace(rtrim(JPATH_ROOT, '/\\') . '/', '', $file));
        }

        return '';
    }

    private function resolveGalleryJsonByArticleField(int $articleId, int $fieldId): string
    {
        if ($articleId <= 0 || $fieldId <= 0) {
            return '';
        }

        $legacyBase = rtrim(JPATH_ROOT, '/\\') . '/images/galleries/nextcloud';
        $matches = [];
        if (is_dir($legacyBase)) {
            $pattern = $legacyBase . '/' . $articleId . '-' . $fieldId . '-*/gallery.json';
            $matches = glob($pattern) ?: [];
        }
        if ($matches === []) {
            $files = $this->findGalleryJsonFilesUnderImages();
            foreach ($files as $file) {
                $gallery = json_decode((string) file_get_contents($file), true);
                if (!is_array($gallery)) {
                    continue;
                }
                if ((int) ($gallery['article_id'] ?? 0) !== $articleId || (int) ($gallery['field_id'] ?? 0) !== $fieldId) {
                    continue;
                }
                $matches[] = $file;
            }
            if ($matches === []) {
                return '';
            }
        }

        usort($matches, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return str_replace('\\', '/', str_replace(rtrim(JPATH_ROOT, '/\\') . '/', '', $matches[0]));
    }

    private function resolveLatestGalleryJsonByArticle(int $articleId): string
    {
        if ($articleId <= 0) {
            return '';
        }

        $legacyBase = rtrim(JPATH_ROOT, '/\\') . '/images/galleries/nextcloud';
        $matches = [];
        if (is_dir($legacyBase)) {
            $pattern = $legacyBase . '/' . $articleId . '-*-*/gallery.json';
            $matches = glob($pattern) ?: [];
        }
        if ($matches === []) {
            $files = $this->findGalleryJsonFilesUnderImages();
            foreach ($files as $file) {
                $gallery = json_decode((string) file_get_contents($file), true);
                if (!is_array($gallery)) {
                    continue;
                }
                if ((int) ($gallery['article_id'] ?? 0) !== $articleId) {
                    continue;
                }
                $matches[] = $file;
            }
            if ($matches === []) {
                return '';
            }
        }

        usort($matches, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return str_replace('\\', '/', str_replace(rtrim(JPATH_ROOT, '/\\') . '/', '', $matches[0]));
    }

    private function renderAdminWidget(array $data): string
    {
        $layout = rtrim(__DIR__, '/\\') . '/../../tmpl/admin_widget.php';

        if (!is_file($layout)) {
            return '';
        }

        ob_start();
        include $layout;

        return (string) ob_get_clean();
    }

    private function canEditItem(object $item): bool
    {
        $user = Factory::getApplication()->getIdentity();
        $itemId = (int) ($item->id ?? 0);

        if ($itemId > 0 && $user->authorise('core.edit', 'com_content.article.' . $itemId)) {
            return true;
        }

        return $user->authorise('core.edit', 'com_content');
    }

    private function canEditArticleId(int $articleId): bool
    {
        $user = Factory::getApplication()->getIdentity();

        if ($articleId > 0 && $user->authorise('core.edit', 'com_content.article.' . $articleId)) {
            return true;
        }

        return $user->authorise('core.edit', 'com_content');
    }

    private function resolveFieldRecordForAjax(int $fieldId, string $fieldName): ?object
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('name'),
                $db->quoteName('fieldparams'),
                $db->quoteName('type'),
                $db->quoteName('context'),
            ])
            ->from($db->quoteName('#__fields'))
            ->where($db->quoteName('context') . ' = ' . $db->quote('com_content.article'))
            ->where($db->quoteName('state') . ' >= 0');

        if ($fieldId > 0) {
            $query->where($db->quoteName('id') . ' = ' . (int) $fieldId);
            $db->setQuery($query, 0, 1);
            $row = $db->loadObject();
            if (is_object($row) && strtolower((string) ($row->type ?? '')) === 'r3dnextcloudgallery') {
                return $row;
            }
        }

        $db->setQuery($query);
        $rows = $db->loadObjectList() ?: [];
        if ($rows === []) {
            return null;
        }

        $normalize = static function (string $value): string {
            return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
        };

        $target = $normalize($fieldName);

        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            if (strtolower((string) ($row->type ?? '')) !== 'r3dnextcloudgallery') {
                continue;
            }
            if ($target !== '' && $normalize((string) ($row->name ?? '')) === $target) {
                return $row;
            }
        }

        foreach ($rows as $row) {
            if (is_object($row) && strtolower((string) ($row->type ?? '')) === 'r3dnextcloudgallery') {
                return $row;
            }
        }

        return null;
    }

    private function loadFieldValueForItem(int $fieldId, int $itemId): string
    {
        if ($fieldId <= 0 || $itemId <= 0) {
            return '';
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('value'))
            ->from($db->quoteName('#__fields_values'))
            ->where($db->quoteName('field_id') . ' = ' . (int) $fieldId)
            ->where($db->quoteName('item_id') . ' = ' . $db->quote((string) $itemId));
        $db->setQuery($query, 0, 1);
        $value = $db->loadResult();

        return is_string($value) ? $value : '';
    }

    private function persistFieldValueForItem(int $fieldId, int $itemId, string $value): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__fields_values'))
            ->where($db->quoteName('field_id') . ' = ' . (int) $fieldId)
            ->where($db->quoteName('item_id') . ' = ' . $db->quote((string) $itemId));
        $db->setQuery($query, 0, 1);
        $exists = (bool) $db->loadResult();

        if ($exists) {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__fields_values'))
                ->set($db->quoteName('value') . ' = ' . $db->quote($value))
                ->where($db->quoteName('field_id') . ' = ' . (int) $fieldId)
                ->where($db->quoteName('item_id') . ' = ' . $db->quote((string) $itemId));
            $db->setQuery($query)->execute();
            return;
        }

        $columns = ['field_id', 'item_id', 'value'];
        $values = [
            (int) $fieldId,
            $db->quote((string) $itemId),
            $db->quote($value),
        ];
        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__fields_values'))
            ->columns(array_map([$db, 'quoteName'], $columns))
            ->values(implode(',', $values));
        $db->setQuery($query)->execute();
    }

    private function getRawCaptionsPayload(): string
    {
        $app = Factory::getApplication();
        $raw = $app->input->post->getRaw('r3dncg_captions', '');

        if (!is_string($raw) || $raw === '') {
            $raw = (string) $app->input->getString('r3dncg_captions', '{}');
        }

        return $raw === '' ? '{}' : $raw;
    }

    private function closeAjax(array $payload): void
    {
        $app = Factory::getApplication();
        echo new JsonResponse($payload);
        $app->close();
    }

    private function resolveImportParams(object $field, int $articleId = 0): array
    {
        $pluginParams = is_object($this->params) ? $this->params->toArray() : [];
        $fieldParamsRaw = $field->fieldparams ?? null;
        $fieldParams = [];

        if ($fieldParamsRaw instanceof Registry) {
            $fieldParams = $fieldParamsRaw->toArray();
        } elseif (is_string($fieldParamsRaw) && $fieldParamsRaw !== '') {
            $fieldParams = (new Registry($fieldParamsRaw))->toArray();
        } elseif (is_array($fieldParamsRaw)) {
            $fieldParams = $fieldParamsRaw;
        }

        if (array_key_exists('field_keep_source_for_debug', $fieldParams) && !array_key_exists('keep_source_for_debug', $fieldParams)) {
            $fieldParams['keep_source_for_debug'] = $fieldParams['field_keep_source_for_debug'];
        }

        $keys = [
            'max_images',
            'max_file_size_mb',
            'large_max_edge',
            'thumb_max_edge',
            'jpeg_quality',
            'thumb_quality',
            'keep_source_for_debug',
            'storage_subfolder',
            'use_filename_for_caption',
            'allowed_share_hosts',
            'enforce_allowed_share_hosts',
        ];

        foreach ($keys as $key) {
            if (array_key_exists($key, $fieldParams)) {
                $pluginParams[$key] = $fieldParams[$key];
            }
        }

        $categoryAlias = $this->resolveArticleCategoryAlias($articleId);
        if ($categoryAlias !== '') {
            $base = trim((string) ($pluginParams['storage_subfolder'] ?? 'nc-gallery'), '/');
            $pluginParams['storage_subfolder'] = $base . '/' . $categoryAlias;
        }

        return $pluginParams;
    }

    private function loadAllTargetFieldValuesForItem(int $itemId): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select([
                'f.id AS field_id',
                'f.type AS field_type',
                'fv.value AS value',
            ])
            ->from($db->quoteName('#__fields', 'f'))
            ->leftJoin($db->quoteName('#__fields_values', 'fv') . ' ON fv.field_id = f.id AND fv.item_id = ' . $db->quote((string) $itemId))
            ->where('f.context = ' . $db->quote('com_content.article'))
            ->where('f.state >= 0');
        $db->setQuery($query);
        $rows = (array) $db->loadObjectList();
        $result = [];
        foreach ($rows as $row) {
            if (!is_object($row) || strtolower((string) ($row->field_type ?? '')) !== 'r3dnextcloudgallery') {
                continue;
            }
            $result[(int) ($row->field_id ?? 0)] = (string) ($row->value ?? '');
        }

        return $result;
    }

    private function deleteGalleryAlbumByJson(string $galleryJsonRelative): void
    {
        try {
            $galleryJsonAbsolute = $this->resolveSafeGalleryJsonPath($galleryJsonRelative);
        } catch (\Throwable $e) {
            return;
        }
        $albumDir = dirname($galleryJsonAbsolute);
        if (!is_dir($albumDir)) {
            return;
        }

        $albumReal = realpath($albumDir);
        if (!is_string($albumReal) || $albumReal === '') {
            return;
        }
        $albumReal = str_replace('\\', '/', $albumReal);
        $rootImages = str_replace('\\', '/', realpath(rtrim(JPATH_ROOT, '/\\') . '/images') ?: '');
        if ($rootImages === '' || $albumReal === $rootImages) {
            return;
        }
        $allowedBases = $this->allowedGalleryBaseDirectories();
        $insideAllowed = false;
        foreach ($allowedBases as $allowedBase) {
            if (str_starts_with($albumReal . '/', $allowedBase . '/')) {
                $insideAllowed = true;
                break;
            }
        }
        if (!$insideAllowed) {
            return;
        }

        $this->deleteDirectoryRecursive($albumDir);
    }

    private function deleteDirectoryRecursive(string $path): void
    {
        $path = Path::clean($path);
        if (!file_exists($path)) {
            return;
        }

        // Never traverse into symlinks. Delete link itself only when it is inside allowed base.
        if (is_link($path)) {
            $normalized = str_replace('\\', '/', $path);
            foreach ($this->allowedGalleryBaseDirectories() as $allowedBase) {
                if (str_starts_with($normalized, $allowedBase . '/')) {
                    @unlink($path);
                    return;
                }
            }
            return;
        }

        $real = realpath($path);
        if (!is_string($real) || $real === '') {
            return;
        }
        $real = str_replace('\\', '/', $real);
        $insideAllowedBase = false;
        foreach ($this->allowedGalleryBaseDirectories() as $allowedBase) {
            if (str_starts_with($real, $allowedBase . '/')) {
                $insideAllowedBase = true;
                break;
            }
        }
        if (!$insideAllowedBase) {
            return;
        }

        $items = @scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $target = $path . '/' . $item;
            if (is_link($target)) {
                @unlink($target);
                continue;
            }
            if (is_dir($target)) {
                $this->deleteDirectoryRecursive($target);
            } else {
                @unlink($target);
            }
        }
        @rmdir($path);
    }

    private function startStepImport(string $shareUrl, int $articleId, int $fieldId, array $params): array
    {
        $constraints = ImportConstraints::fromParams($params);
        $shareLinkParser = new ShareLinkParser();
        $allowedShareHosts = $this->parseAllowedShareHosts((string) ($params['allowed_share_hosts'] ?? ''));
        $enforceAllowedHosts = array_key_exists('enforce_allowed_share_hosts', $params) && (int) $params['enforce_allowed_share_hosts'] === 1;
        if ($enforceAllowedHosts && $allowedShareHosts === []) {
            throw new \RuntimeException('No allowed share hosts configured. Please add trusted Nextcloud hosts in the plugin settings.');
        }
        $webDavClient = new PublicWebDavClient($allowedShareHosts);
        $galleryStorage = new GalleryStorage();
        $parsed = $shareLinkParser->parse($shareUrl, $allowedShareHosts);
        $baseSubfolder = (string) ($params['storage_subfolder'] ?? GalleryStorage::DEFAULT_BASE_SUBFOLDER);
        $shareTitle = $webDavClient->fetchShareTitle($parsed['share_url']);
        $manualGalleryTitle = trim((string) ($params['gallery_title'] ?? ''));
        $shareFolder = $manualGalleryTitle !== ''
            ? $galleryStorage->sanitizePathSegment($manualGalleryTitle)
            : $this->resolveShareFolderNameFromValues($parsed['token'], $shareUrl, $shareTitle);
        $paths = $galleryStorage->ensureGalleryDirectories($baseSubfolder, $shareFolder);

        $webDavClient->testAccess($parsed['base_url'], $parsed['token']);
        $davItems = $webDavClient->listFiles($parsed['base_url'], $parsed['token']);
        $files = $this->filterImportableFiles($davItems, $constraints);

        $state = [
            'version' => 1,
            'state_id' => bin2hex(random_bytes(16)),
            'share_url' => $parsed['share_url'],
            'base_url' => $parsed['base_url'],
            'token' => $parsed['token'],
            'gallery_key' => $galleryStorage->buildGalleryKey($articleId, $fieldId, $parsed['token']),
            'gallery_folder' => $shareFolder,
            'storage_subfolder' => $baseSubfolder,
            'paths' => $paths,
            'article_id' => $articleId,
            'field_id' => $fieldId,
            'user_id' => (int) (Factory::getApplication()->getIdentity()->id ?? 0),
            'constraints' => $constraints,
            'params' => $params,
            'files' => array_values(array_map(fn(array $f): array => [
                'href' => (string) ($f['href'] ?? ''),
                'content_length' => (int) ($f['content_length'] ?? 0),
                'content_type' => (string) ($f['content_type'] ?? ''),
            ], $files)),
            'index' => 0,
            'images' => [],
            'errors' => [],
        ];

        $this->saveImportState($state);
        return ['state_id' => $state['state_id'], 'total' => count($state['files'])];
    }

    private function processStepImport(string $stateId, int $articleId, int $fieldId, int $userId): array
    {
        $state = $this->loadImportState($stateId);
        if ((int) ($state['article_id'] ?? 0) !== $articleId || (int) ($state['field_id'] ?? 0) !== $fieldId || (int) ($state['user_id'] ?? 0) !== $userId) {
            throw new \RuntimeException('Import state validation failed.');
        }
        $index = (int) ($state['index'] ?? 0);
        $files = (array) ($state['files'] ?? []);
        $total = count($files);
        if ($index >= $total) {
            return ['processed' => $total, 'total' => $total, 'done' => true];
        }

        $file = (array) $files[$index];
        $allowedShareHosts = $this->parseAllowedShareHosts((string) (($state['params']['allowed_share_hosts'] ?? '')));
        $webDavClient = new PublicWebDavClient($allowedShareHosts);
        $imageProcessor = new ImageProcessor();
        $paths = (array) ($state['paths'] ?? []);
        $constraints = (array) ($state['constraints'] ?? []);
        $params = (array) ($state['params'] ?? []);
        $useFilenameForCaption = !array_key_exists('use_filename_for_caption', $params) || (string) $params['use_filename_for_caption'] === '1';
        $mapper = new FieldValueMapper();
        $fileName = $this->filenameFromHref((string) ($file['href'] ?? ''));

        if ($fileName !== '') {
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $safeName = $this->sanitizeImportedFilename($fileName);
            $largeAbsolute = $this->resolveUniquePath((string) ($paths['base_absolute'] ?? ''), $safeName);
            $largeFileName = basename($largeAbsolute);
            $thumbFileName = $this->buildThumbFilename($largeFileName);
            $thumbAbsolute = $this->resolveUniquePath((string) ($paths['thumbs_absolute'] ?? ''), $thumbFileName);
            $thumbFileName = basename($thumbAbsolute);
            $largeRelative = (string) ($paths['base_relative'] ?? '') . '/' . $largeFileName;
            $thumbRelative = (string) ($paths['base_relative'] ?? '') . '/thumbs/' . $thumbFileName;
            $sourceAbsolute = (string) ($paths['base_absolute'] ?? '') . '/.tmp-' . sha1($fileName) . '.' . $extension;

            try {
                $maxBytes = ((int) ($constraints['max_file_size_mb'] ?? 10)) * 1024 * 1024;
                $webDavClient->download((string) ($state['base_url'] ?? ''), (string) ($state['token'] ?? ''), '', $fileName, $sourceAbsolute, $maxBytes);
                $variant = $imageProcessor->createVariants(
                    $sourceAbsolute,
                    $largeAbsolute,
                    $thumbAbsolute,
                    (int) ($constraints['large_max_edge'] ?? 1920),
                    (int) ($constraints['thumb_max_edge'] ?? 512),
                    (int) ($constraints['jpeg_quality'] ?? 82),
                    (int) ($constraints['thumb_quality'] ?? 76)
                );
                if (is_file($sourceAbsolute)) {
                    @unlink($sourceAbsolute);
                }

                $caption = $useFilenameForCaption ? $mapper->buildReadableFromFilename($fileName) : '';
                $state['images'][] = [
                    'id' => sha1($fileName),
                    'source_name' => $fileName,
                    'file' => $largeRelative,
                    'thumb' => $thumbRelative,
                    'title' => '',
                    'caption' => $caption,
                    'alt' => $mapper->resolveAltFallback('', '', '', $fileName),
                    'sort' => $index + 1,
                    'status' => 'processed',
                    'size' => (int) ($file['content_length'] ?? 0),
                    'mime' => (string) ($variant['mime'] ?? ''),
                    'width' => (int) ($variant['width'] ?? 0),
                    'height' => (int) ($variant['height'] ?? 0),
                    'thumb_width' => (int) ($variant['thumb_width'] ?? 0),
                    'thumb_height' => (int) ($variant['thumb_height'] ?? 0),
                ];
            } catch (\Throwable $e) {
                if (is_file($sourceAbsolute)) {
                    @unlink($sourceAbsolute);
                }
                $state['errors'][] = $fileName . ': ' . $e->getMessage();
            }
        }

        $state['index'] = $index + 1;
        $this->saveImportState($state);

        return ['processed' => (int) $state['index'], 'total' => $total, 'done' => ((int) $state['index']) >= $total];
    }

    private function finalizeStepImport(string $stateId, int $articleId, int $fieldId, int $userId): array
    {
        $state = $this->loadImportState($stateId);
        if ((int) ($state['article_id'] ?? 0) !== $articleId || (int) ($state['field_id'] ?? 0) !== $fieldId || (int) ($state['user_id'] ?? 0) !== $userId) {
            throw new \RuntimeException('Import state validation failed.');
        }
        $totalImported = count((array) ($state['images'] ?? []));
        $totalErrors = count((array) ($state['errors'] ?? []));
        $status = $this->resolveStatus($totalImported, $totalErrors);
        $now = gmdate('c');
        $paths = (array) ($state['paths'] ?? []);

        $gallery = [
            'version' => 1,
            'share_url' => (string) ($state['share_url'] ?? ''),
            'token_hash' => sha1((string) ($state['token'] ?? '')),
            'gallery_key' => (string) ($state['gallery_key'] ?? ''),
            'gallery_folder' => (string) ($state['gallery_folder'] ?? ''),
            'storage_subfolder' => (string) ($state['storage_subfolder'] ?? ''),
            'article_id' => (int) ($state['article_id'] ?? 0),
            'field_id' => (int) ($state['field_id'] ?? 0),
            'status' => $status,
            'imported_at' => $now,
            'cache_refreshed_at' => $now,
            'images' => (array) ($state['images'] ?? []),
            'errors' => (array) ($state['errors'] ?? []),
            'constraints' => (array) ($state['constraints'] ?? []),
            'log_file' => (string) ($paths['import_log_relative'] ?? ''),
            'source_storage' => 'none',
        ];
        (new GalleryStorage())->saveGallery((string) ($paths['gallery_json_absolute'] ?? ''), $gallery);
        @unlink($this->importStatePath($stateId));

        return [
            'status' => $status,
            'gallery_key' => (string) ($state['gallery_key'] ?? ''),
            'gallery_json' => (string) ($paths['gallery_json_relative'] ?? ''),
            'imported_images' => $totalImported,
            'errors' => (array) ($state['errors'] ?? []),
        ];
    }

    private function importStatePath(string $stateId): string
    {
        $tmp = rtrim((string) Factory::getConfig()->get('tmp_path', JPATH_ROOT . '/tmp'), '/\\');
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0755, true);
        }
        return $tmp . '/r3dncg-import-' . preg_replace('/[^a-zA-Z0-9_-]+/', '', $stateId) . '.json';
    }

    private function saveImportState(array $state): void
    {
        $path = $this->importStatePath((string) ($state['state_id'] ?? ''));
        $json = json_encode($state, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($path, $json) === false) {
            throw new \RuntimeException('Unable to persist import state.');
        }
        @chmod($path, 0600);
    }

    private function loadImportState(string $stateId): array
    {
        $path = $this->importStatePath($stateId);
        if (!is_file($path)) {
            throw new \RuntimeException('Import state not found.');
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid import state.');
        }
        return $decoded;
    }

    private function filterImportableFiles(array $davItems, array $constraints): array
    {
        $maxFileSizeBytes = ((int) ($constraints['max_file_size_mb'] ?? 10)) * 1024 * 1024;
        $filtered = [];
        foreach ($davItems as $item) {
            if (($item['is_collection'] ?? false) === true) {
                continue;
            }
            $fileName = $this->filenameFromHref((string) ($item['href'] ?? ''));
            if ($fileName === '') {
                continue;
            }
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (!in_array($extension, ImportConstraints::ALLOWED_EXTENSIONS, true)) {
                continue;
            }
            $mime = strtolower((string) ($item['content_type'] ?? ''));
            if ($mime !== '' && !in_array($mime, ImportConstraints::ALLOWED_MIME_TYPES, true)) {
                continue;
            }
            $contentLength = (int) ($item['content_length'] ?? 0);
            if ($contentLength > 0 && $contentLength > $maxFileSizeBytes) {
                continue;
            }
            $filtered[] = $item;
            if (count($filtered) >= (int) ($constraints['max_images'] ?? 100)) {
                break;
            }
        }
        return $filtered;
    }

    private function filenameFromHref(string $href): string
    {
        $decoded = urldecode($href);
        $trimmed = trim($decoded, '/');
        if ($trimmed === '') {
            return '';
        }
        $parts = explode('/', $trimmed);
        return end($parts) ?: '';
    }

    private function resolveShareFolderNameFromValues(string $token, string $shareUrl, string $shareTitle = ''): string
    {
        $candidate = trim($shareTitle);
        if ($candidate === '') {
            $path = (string) (parse_url($shareUrl, PHP_URL_PATH) ?? '');
            $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
            $maybe = end($parts);
            if (is_string($maybe) && $maybe !== '' && !preg_match('/^[A-Za-z0-9]{12,}$/', $maybe)) {
                $candidate = $maybe;
            }
        }
        if ($candidate === '') {
            $candidate = $token;
        }
        $sanitized = (new GalleryStorage())->sanitizePathSegment($candidate);
        if ($sanitized === '') {
            $sanitized = (new GalleryStorage())->sanitizePathSegment($token);
        }
        return $sanitized . '-' . gmdate('Y-m-d');
    }

    private function sanitizeImportedFilename(string $filename): string
    {
        $filename = trim($filename);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
        if (is_string($ascii) && $ascii !== '') {
            $base = $ascii;
        }
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?: '';
        $base = trim($base, '-._');
        if ($base === '') {
            $base = 'image';
        }
        if ($ext === '') {
            $ext = 'jpg';
        }
        return $base . '.' . $ext;
    }

    private function buildThumbFilename(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $base = pathinfo($filename, PATHINFO_FILENAME);
        if ($ext === '') {
            $ext = 'jpg';
        }
        return $base . '-thumb.' . $ext;
    }

    private function resolveUniquePath(string $directory, string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $candidate = rtrim($directory, '/\\') . '/' . $filename;
        $counter = 2;
        while (is_file($candidate)) {
            $suffix = '-' . $counter++;
            $candidate = rtrim($directory, '/\\') . '/' . $base . $suffix . ($ext !== '' ? '.' . $ext : '');
        }
        return $candidate;
    }

    private function resolveStatus(int $importedCount, int $errorCount): string
    {
        if ($importedCount === 0 && $errorCount === 0) {
            return 'failed';
        }
        if ($importedCount > 0 && $errorCount === 0) {
            return 'successful';
        }
        if ($importedCount > 0 && $errorCount > 0) {
            return 'partially_failed';
        }
        return 'failed';
    }

    private function resolveArticleCategoryAlias(int $articleId): string
    {
        if ($articleId <= 0) {
            return '';
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('c.alias'))
                ->from($db->quoteName('#__content', 'a'))
                ->innerJoin($db->quoteName('#__categories', 'c') . ' ON c.id = a.catid')
                ->where('a.id = ' . (int) $articleId);
            $db->setQuery($query, 0, 1);
            $alias = (string) $db->loadResult();
            $alias = trim($alias);
            if ($alias === '') {
                return '';
            }

            return preg_replace('/[^a-z0-9._-]+/i', '-', strtolower($alias)) ?: '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function extractRenderOptions(object $field): array
    {
        $options = [
            'frontend_layout' => 'masonry',
            'frontend_lightbox' => 'builtin',
            'thumb_min_width' => 240,
            'thumb_max_width' => 480,
            'gap' => 15,
            'columns_mobile' => 2,
            'columns_tablet' => 3,
            'columns_desktop' => 4,
            'built_in_fullscreen' => 1,
            'built_in_slideshow' => 0,
            'lightgallery_zoom' => 1,
            'lightgallery_fullscreen' => 1,
            'lightgallery_autoplay' => 1,
            'lightgallery_thumbnails' => 0,
            'lightgallery_share' => 0,
            'lightgallery_rotate' => 0,
            'lightgallery_hash' => 1,
            'lightgallery_download' => 0,
            'lightgallery_autoplay_interval' => 5000,
            'frontend_contentprepare_append' => (int) $this->params->get('frontend_contentprepare_append', 1),
            'frontend_fallback_injection' => (int) $this->params->get('frontend_fallback_injection', 1),
        ];

        $fieldParamsRaw = $field->fieldparams ?? null;
        $fieldParams = [];

        if ($fieldParamsRaw instanceof Registry) {
            $fieldParams = $fieldParamsRaw->toArray();
        } elseif (is_string($fieldParamsRaw) && $fieldParamsRaw !== '') {
            $fieldParams = (new Registry($fieldParamsRaw))->toArray();
        } elseif (is_array($fieldParamsRaw)) {
            $fieldParams = $fieldParamsRaw;
        }

        $layout = strtolower((string) ($fieldParams['frontend_layout'] ?? 'masonry'));
        if (in_array($layout, ['grid', 'masonry'], true)) {
            $options['frontend_layout'] = $layout;
        }

        $thumbMinWidth = (int) ($fieldParams['frontend_thumb_min_width'] ?? 240);
        if ($thumbMinWidth >= 120 && $thumbMinWidth <= 800) {
            $options['thumb_min_width'] = $thumbMinWidth;
        }

        $thumbMaxWidth = (int) ($fieldParams['frontend_thumb_max_width'] ?? 480);
        if ($thumbMaxWidth >= 180 && $thumbMaxWidth <= 1200) {
            $options['thumb_max_width'] = max($options['thumb_min_width'], $thumbMaxWidth);
        }

        $gap = (int) ($fieldParams['frontend_gap'] ?? 15);
        if ($gap >= 0 && $gap <= 80) {
            $options['gap'] = $gap;
        }

        $mobileColumns = (int) ($fieldParams['frontend_columns_mobile'] ?? 2);
        if ($mobileColumns >= 1 && $mobileColumns <= 4) {
            $options['columns_mobile'] = $mobileColumns;
        }

        $tabletColumns = (int) ($fieldParams['frontend_columns_tablet'] ?? 3);
        if ($tabletColumns >= 1 && $tabletColumns <= 6) {
            $options['columns_tablet'] = $tabletColumns;
        }

        $desktopColumns = (int) ($fieldParams['frontend_columns_desktop'] ?? 4);
        if ($desktopColumns >= 1 && $desktopColumns <= 6) {
            $options['columns_desktop'] = $desktopColumns;
        }

        $lightbox = strtolower((string) ($fieldParams['frontend_lightbox'] ?? 'builtin'));
        if ($lightbox === 'native') {
            $lightbox = 'builtin';
        }
        if (in_array($lightbox, ['none', 'builtin', 'lightgallery'], true)) {
            $options['frontend_lightbox'] = $lightbox;
        }

        $options['built_in_fullscreen'] = ((int) ($fieldParams['built_in_fullscreen'] ?? 1)) === 1 ? 1 : 0;
        $options['built_in_slideshow'] = ((int) ($fieldParams['built_in_slideshow'] ?? 0)) === 1 ? 1 : 0;
        $options['lightgallery_zoom'] = ((int) ($fieldParams['lightgallery_zoom'] ?? 1)) === 1 ? 1 : 0;
        $options['lightgallery_fullscreen'] = ((int) ($fieldParams['lightgallery_fullscreen'] ?? 1)) === 1 ? 1 : 0;
        $options['lightgallery_autoplay'] = ((int) ($fieldParams['lightgallery_autoplay'] ?? 1)) === 1 ? 1 : 0;
        $options['lightgallery_thumbnails'] = ((int) ($fieldParams['lightgallery_thumbnails'] ?? 0)) === 1 ? 1 : 0;
        $options['lightgallery_share'] = ((int) ($fieldParams['lightgallery_share'] ?? 0)) === 1 ? 1 : 0;
        $options['lightgallery_rotate'] = ((int) ($fieldParams['lightgallery_rotate'] ?? 0)) === 1 ? 1 : 0;
        $options['lightgallery_hash'] = ((int) ($fieldParams['lightgallery_hash'] ?? 1)) === 1 ? 1 : 0;
        $options['lightgallery_download'] = ((int) ($fieldParams['lightgallery_download'] ?? 0)) === 1 ? 1 : 0;
        $autoplayInterval = (int) ($fieldParams['lightgallery_autoplay_interval'] ?? 5000);
        if ($autoplayInterval >= 1000 && $autoplayInterval <= 30000) {
            $options['lightgallery_autoplay_interval'] = $autoplayInterval;
        }

        $legacyMobile = (int) ($fieldParams['frontend_mobile_columns'] ?? 0);
        if ($legacyMobile >= 1 && $legacyMobile <= 2) {
            $options['columns_mobile'] = $legacyMobile;
        }

        if (array_key_exists('frontend_contentprepare_append', $fieldParams) && (string) $fieldParams['frontend_contentprepare_append'] !== '') {
            $options['frontend_contentprepare_append'] = ((int) $fieldParams['frontend_contentprepare_append'] === 1) ? 1 : 0;
        }

        if (array_key_exists('frontend_fallback_injection', $fieldParams) && (string) $fieldParams['frontend_fallback_injection'] !== '') {
            $options['frontend_fallback_injection'] = ((int) $fieldParams['frontend_fallback_injection'] === 1) ? 1 : 0;
        }

        return $options;
    }

    private function injectGalleryIntoBody(string $body, string $chunk): string
    {
        $insertBeforeClosing = static function (string $html, string $closingTag, string $payload): string {
            $closePos = stripos($html, $closingTag);
            if ($closePos === false) {
                return $html;
            }

            $scope = substr($html, 0, $closePos);
            $lastParagraphPos = strripos($scope, '</p>');

            if ($lastParagraphPos !== false) {
                $insertPos = $lastParagraphPos + 4;
                return substr($html, 0, $insertPos) . $payload . substr($html, $insertPos);
            }

            return substr($html, 0, $closePos) . $payload . substr($html, $closePos);
        };

        // Prefer article-local insertion (after last paragraph inside article).
        if (str_contains(strtolower($body), '</article>')) {
            $updated = $insertBeforeClosing($body, '</article>', $chunk);
            if ($updated !== $body) {
                return $updated;
            }
        }

        // Fallback for templates without <article>, but with <main>.
        if (str_contains(strtolower($body), '</main>')) {
            $updated = $insertBeforeClosing($body, '</main>', $chunk);
            if ($updated !== $body) {
                return $updated;
            }
        }

        if (str_contains($body, '</body>')) {
            return str_replace('</body>', $chunk . '</body>', $body);
        }

        return $body . $chunk;
    }

    private function injectAssetsIntoHead(string $body, string $assets): string
    {
        if (str_contains($body, '</head>')) {
            return preg_replace('/<\/head>/i', $assets . '</head>', $body, 1) ?: $body;
        }

        return $assets . $body;
    }

    /**
     * Resolve and validate gallery.json path under approved image folders only.
     */
    private function resolveSafeGalleryJsonPath(string $relative): string
    {
        $relative = $this->normalizeRelativePath($relative);
        if ($relative === '') {
            throw new \RuntimeException('Empty gallery path not allowed.');
        }
        if (preg_match('#^[A-Za-z]:/#', $relative)) {
            throw new \RuntimeException('Absolute gallery path not allowed.');
        }
        if (str_contains($relative, '../') || str_contains($relative, '..\\')) {
            throw new \RuntimeException('Path traversal detected.');
        }
        $absolute = Path::clean(rtrim(JPATH_ROOT, '/\\') . '/' . ltrim($relative, '/'));
        if (!str_ends_with(strtolower($absolute), '/gallery.json') && !str_ends_with(strtolower($absolute), '\\gallery.json')) {
            throw new \RuntimeException('Only gallery.json paths are allowed.');
        }
        $real = realpath($absolute);
        if (!is_string($real) || $real === '') {
            $real = $absolute;
        }
        $real = str_replace('\\', '/', $real);
        foreach ($this->allowedGalleryBaseDirectories() as $allowedBase) {
            if (str_starts_with($real . '/', $allowedBase . '/')) {
                return $real;
            }
        }
        throw new \RuntimeException('Gallery path outside allowed directories.');
    }

    private function normalizeRelativePath(string $path): string
    {
        return ltrim(trim(str_replace('\\', '/', $path)), '/');
    }

    /**
     * @return list<string> real normalized absolute base directories
     */
    private function allowedGalleryBaseDirectories(): array
    {
        $root = rtrim(JPATH_ROOT, '/\\');
        $candidates = [
            $root . '/images/nc-gallery',
            $root . '/images/nextcloud-galerie',
            $root . '/images/galleries/nextcloud',
        ];

        $configured = trim((string) ($this->params->get('storage_subfolder', 'nc-gallery')));
        $configured = trim(str_replace('\\', '/', $configured), '/');
        if ($configured !== '' && !str_contains($configured, '..')) {
            $candidates[] = $root . '/images/' . $configured;
        }

        $bases = [];
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            $normalized = str_replace('\\', '/', $real !== false ? $real : Path::clean($candidate));
            if ($normalized !== '' && !in_array($normalized, $bases, true)) {
                $bases[] = $normalized;
            }
        }
        return $bases;
    }

    /**
     * @return string[]
     */
    private function parseAllowedShareHosts(string $raw): array
    {
        $parts = preg_split('/[\r\n,;]+/', trim($raw)) ?: [];
        $hosts = [];
        foreach ($parts as $part) {
            $host = strtolower(trim($part));
            $host = rtrim($host, '.');
            if ($host === '' || str_contains($host, '*') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
                continue;
            }
            if (function_exists('idn_to_ascii') && preg_match('/[^[:ascii:]]/', $host)) {
                $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if (is_string($ascii) && $ascii !== '') {
                    $host = strtolower(trim($ascii));
                    $host = rtrim($host, '.');
                }
            }
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
        return array_values(array_unique($hosts));
    }

    private function loadAssets(): void
    {
        try {
            Text::script('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_ERR_SHARE_REQUIRED');
            Text::script('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_NONE_SELECTED');
            Text::script('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_CONFIRM_DELETE');
            Text::script('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_CONFIRM_DELETE_SELECTED');
            Text::script('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_ERR_ACTION_FAILED');
            Text::script('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_IMPORT_RUNNING');
            Text::script('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_REIMPORT_RUNNING');
            Text::script('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_IMPORT_COMPLETED');
            Text::script('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_ERR_GALLERY_TITLE_REQUIRED');

            $doc = Factory::getApplication()->getDocument();
            $wa = $doc->getWebAssetManager();
            $assetFile = Path::clean(JPATH_ROOT . '/plugins/fields/r3dnextcloudgallery/media/plg_fields_r3dnextcloudgallery/joomla.asset.json');

            if (is_file($assetFile)) {
                $wa->getRegistry()->addRegistryFile($assetFile);
            }

            if ($wa->assetExists('style', 'plg_fields_r3dnextcloudgallery.style')) {
                $wa->useStyle('plg_fields_r3dnextcloudgallery.style');
            }

            if ($wa->assetExists('script', 'plg_fields_r3dnextcloudgallery.script')) {
                $wa->useScript('plg_fields_r3dnextcloudgallery.script');
            }

            if ($this->frontendNeedsLightGallery) {
                if ($wa->assetExists('style', 'plg_fields_r3dnextcloudgallery.lightgallery.style')) {
                    $wa->useStyle('plg_fields_r3dnextcloudgallery.lightgallery.style');
                }
                $lgScripts = ['plg_fields_r3dnextcloudgallery.lightgallery.core.script'];
                if (!empty($this->frontendLightGalleryPlugins['zoom'])) {
                    $lgScripts[] = 'plg_fields_r3dnextcloudgallery.lightgallery.zoom.script';
                }
                if (!empty($this->frontendLightGalleryPlugins['fullscreen'])) {
                    $lgScripts[] = 'plg_fields_r3dnextcloudgallery.lightgallery.fullscreen.script';
                }
                if (!empty($this->frontendLightGalleryPlugins['autoplay'])) {
                    $lgScripts[] = 'plg_fields_r3dnextcloudgallery.lightgallery.autoplay.script';
                }
                if (!empty($this->frontendLightGalleryPlugins['thumbnail'])) {
                    $lgScripts[] = 'plg_fields_r3dnextcloudgallery.lightgallery.thumbnail.script';
                }
                if (!empty($this->frontendLightGalleryPlugins['share'])) {
                    $lgScripts[] = 'plg_fields_r3dnextcloudgallery.lightgallery.share.script';
                }
                if (!empty($this->frontendLightGalleryPlugins['rotate'])) {
                    $lgScripts[] = 'plg_fields_r3dnextcloudgallery.lightgallery.rotate.script';
                }
                if (!empty($this->frontendLightGalleryPlugins['hash'])) {
                    $lgScripts[] = 'plg_fields_r3dnextcloudgallery.lightgallery.hash.script';
                }
                foreach ($lgScripts as $assetName) {
                    if ($wa->assetExists('script', $assetName)) {
                        $wa->useScript($assetName);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Late rendering phases can lock the WebAssetManager. In that case
            // we fall back to direct asset tags in onAfterRender.
        }
    }

    private function directAssetHtml(): string
    {
        $base = rtrim(Uri::root(), '/') . '/plugins/fields/r3dnextcloudgallery/media/plg_fields_r3dnextcloudgallery';
        $v = self::ASSET_VERSION;
        $html = '<link rel="stylesheet" href="' . htmlspecialchars($base . '/css/field.css?v=' . $v, ENT_QUOTES, 'UTF-8') . '">';
        if ($this->frontendNeedsLightGallery) {
            $html .= '<link rel="stylesheet" href="' . htmlspecialchars($base . '/vendor/lightgallery/css/lightgallery-bundle.min.css?v=' . $v, ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<script src="' . htmlspecialchars($base . '/vendor/lightgallery/js/lightgallery.min.js?v=' . $v, ENT_QUOTES, 'UTF-8') . '" defer></script>';
            if (!empty($this->frontendLightGalleryPlugins['zoom'])) {
                $html .= '<script src="' . htmlspecialchars($base . '/vendor/lightgallery/js/lg-zoom.min.js?v=' . $v, ENT_QUOTES, 'UTF-8') . '" defer></script>';
            }
            if (!empty($this->frontendLightGalleryPlugins['fullscreen'])) {
                $html .= '<script src="' . htmlspecialchars($base . '/vendor/lightgallery/js/lg-fullscreen.min.js?v=' . $v, ENT_QUOTES, 'UTF-8') . '" defer></script>';
            }
            if (!empty($this->frontendLightGalleryPlugins['autoplay'])) {
                $html .= '<script src="' . htmlspecialchars($base . '/vendor/lightgallery/js/lg-autoplay.min.js?v=' . $v, ENT_QUOTES, 'UTF-8') . '" defer></script>';
            }
            if (!empty($this->frontendLightGalleryPlugins['thumbnail'])) {
                $html .= '<script src="' . htmlspecialchars($base . '/vendor/lightgallery/js/lg-thumbnail.min.js?v=' . $v, ENT_QUOTES, 'UTF-8') . '" defer></script>';
            }
            if (!empty($this->frontendLightGalleryPlugins['share'])) {
                $html .= '<script src="' . htmlspecialchars($base . '/vendor/lightgallery/js/lg-share.min.js?v=' . $v, ENT_QUOTES, 'UTF-8') . '" defer></script>';
            }
            if (!empty($this->frontendLightGalleryPlugins['rotate'])) {
                $html .= '<script src="' . htmlspecialchars($base . '/vendor/lightgallery/js/lg-rotate.min.js?v=' . $v, ENT_QUOTES, 'UTF-8') . '" defer></script>';
            }
            if (!empty($this->frontendLightGalleryPlugins['hash'])) {
                $html .= '<script src="' . htmlspecialchars($base . '/vendor/lightgallery/js/lg-hash.min.js?v=' . $v, ENT_QUOTES, 'UTF-8') . '" defer></script>';
            }
        }
        $html .= '<script src="' . htmlspecialchars($base . '/js/field.js?v=' . $v, ENT_QUOTES, 'UTF-8') . '" defer></script>';
        return $html;
    }

    /**
     * @return list<string>
     */
    private function findGalleryJsonFilesUnderImages(): array
    {
        $imagesBase = rtrim(JPATH_ROOT, '/\\') . '/images';
        if (!is_dir($imagesBase)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($imagesBase, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            if (strtolower($fileInfo->getFilename()) !== 'gallery.json') {
                continue;
            }
            $files[] = str_replace('\\', '/', $fileInfo->getPathname());
        }

        return $files;
    }
}




