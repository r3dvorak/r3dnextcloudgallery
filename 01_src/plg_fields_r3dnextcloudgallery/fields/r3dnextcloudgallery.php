<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.r3dnextcloudgallery
 *
 * @copyright   (C) 2026 Richard Dvorak / R3D
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use R3d\Plugin\Fields\R3dnextcloudgallery\Service\FieldValueMapper;

class JFormFieldR3dnextcloudgallery extends FormField
{
    protected $type = 'r3dnextcloudgallery';

    protected function getInput()
    {
        $directAssets = $this->ensureDirectAssetLoading();
        $rawValue = is_string($this->value) ? $this->value : '';
        $decoded = json_decode($rawValue, true);
        $decoded = is_array($decoded) ? $decoded : [];

        $shareUrl = trim((string) ($decoded['share_url'] ?? (is_string($this->value) ? $this->value : '')));
        $galleryTitle = trim((string) ($decoded['gallery_title'] ?? ''));
        $fieldAlias = trim((string) ($this->fieldname ?? ''));
        $resolvedFieldId = $this->resolveFieldIdByAlias($fieldAlias);
        $tokenKey = Session::getFormToken();
        $articleId = (int) $this->form->getValue('id');

        $galleryJson = trim((string) ($decoded['gallery_json'] ?? ''));
        if ($galleryJson === '' && $shareUrl !== '' && $articleId > 0) {
            $galleryJson = $this->resolveGalleryJsonFromShare($shareUrl, $articleId, $fieldAlias);
            if ($galleryJson !== '') {
                $decoded['gallery_json'] = $galleryJson;
            }
        }
        if ($galleryJson === '' && $articleId > 0 && $resolvedFieldId > 0) {
            $galleryJson = $this->resolveGalleryJsonFromStorage($articleId, $resolvedFieldId);
            if ($galleryJson !== '') {
                $decoded['gallery_json'] = $galleryJson;
            }
        }
        if ($galleryJson === '' && $articleId > 0) {
            $galleryJson = $this->resolveLatestGalleryJsonByArticle($articleId);
            if ($galleryJson !== '') {
                $decoded['gallery_json'] = $galleryJson;
            }
        }

        $statusHtml = '';
        $editorHtml = '';
        if ($galleryJson !== '') {
            $galleryPath = rtrim(JPATH_ROOT, '/\\') . '/' . ltrim($galleryJson, '/');
            if (is_file($galleryPath)) {
                $gallery = json_decode((string) file_get_contents($galleryPath), true);
                $gallery = is_array($gallery) ? (new FieldValueMapper())->normalizeGalleryArray($gallery) : [];
                $editorHtml = $this->buildCardEditorHtml($gallery);
            }
        }

        $html =
            '<div class="r3d-nextcloud-gallery-field" data-r3dncg-root="1">'
            . '<input type="hidden" name="' . htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8') . '" data-r3dncg-field-value="1" value="' . htmlspecialchars($rawValue, ENT_QUOTES, 'UTF-8') . '">'
            . '<div class="r3d-nextcloud-gallery-actions"'
            . ' data-r3dncg-actions="1"'
            . ' data-field-id="' . (int) $resolvedFieldId . '"'
            . ' data-field-name="' . htmlspecialchars($fieldAlias, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-article-id="' . $articleId . '"'
            . ' data-share-url="' . htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-gallery-title="' . htmlspecialchars($galleryTitle, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-token-key="' . htmlspecialchars($tokenKey, ENT_QUOTES, 'UTF-8') . '">'
            . '<div class="r3d-nextcloud-gallery-actions__row">'
            . '<label class="form-label" for="r3dncg-share-url-' . (int) $resolvedFieldId . '">' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_SHARE_URL') . '</label>'
            . '<input id="r3dncg-share-url-' . (int) $resolvedFieldId . '" type="url" class="form-control form-control-sm" data-r3dncg-share-url-input="1" placeholder="' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_SHARE_URL_PLACEHOLDER') . '" value="' . htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') . '">'
            . '</div>'
            . '<div class="r3d-nextcloud-gallery-actions__row">'
            . '<label class="form-label" for="r3dncg-gallery-title-' . (int) $resolvedFieldId . '">' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_GALLERY_TITLE') . '</label>'
            . '<input id="r3dncg-gallery-title-' . (int) $resolvedFieldId . '" type="text" class="form-control form-control-sm" data-r3dncg-gallery-title-input="1" placeholder="' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_GALLERY_TITLE_PLACEHOLDER') . '" value="' . htmlspecialchars($galleryTitle, ENT_QUOTES, 'UTF-8') . '">'
            . '</div>'
            . '<div class="r3d-nextcloud-gallery-actions__toolbar">'
            . '<button type="button" class="btn btn-sm btn-secondary" data-r3dncg-action="import">' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_IMPORT') . '</button>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary" data-r3dncg-action="reimport">' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_REIMPORT') . '</button>'
            . '<button type="button" class="btn btn-sm btn-outline-primary" data-r3dncg-action="save_meta">' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_SAVE') . '</button>'
            . '<button type="button" class="btn btn-sm btn-outline-dark" data-r3dncg-debug-toggle="1" data-r3dncg-debug-state="off">Console Debug: OFF</button>'
            . '<div class="r3dncg-upload-queue" data-r3dncg-upload-queue="1">'
            . '<small class="text-muted">' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_UPLOAD_QUEUE') . ': </small>'
            . '<span class="badge text-bg-light" data-r3dncg-upload-state="1">' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_QUEUE_IDLE') . '</span>'
            . '</div>'
            . '</div>'
            . $statusHtml
            . '<div class="alert alert-danger d-none mt-2 r3dncg-debug" data-r3dncg-debug="1" role="alert" aria-live="polite">'
            . '<div class="r3dncg-debug__body" data-r3dncg-debug-body="1" style="white-space: pre-wrap; font-family: var(--bs-font-monospace, monospace); font-size: .875rem;"></div>'
            . '<button type="button" class="btn btn-sm btn-outline-light mt-2" data-r3dncg-debug-dismiss="1">Debug schließen</button>'
            . '</div>'
            . $editorHtml
            . '</div>';

        return $directAssets . $html;
    }

    private function ensureDirectAssetLoading(): string
    {
        $base = rtrim(Uri::root(), '/') . '/plugins/fields/r3dnextcloudgallery/media/plg_fields_r3dnextcloudgallery';
        $version = '1.5.12';

        return '<link rel="stylesheet" href="' . htmlspecialchars($base . '/css/field.css?v=' . $version, ENT_QUOTES, 'UTF-8') . '">'
            . '<script src="' . htmlspecialchars($base . '/js/field.js?v=' . $version, ENT_QUOTES, 'UTF-8') . '" defer></script>';
    }

    private function buildStatusHtml(array $gallery): string
    {
        $rawStatus = strtolower(trim((string) ($gallery['status'] ?? '')));
        $key = match ($rawStatus) {
            'successful', 'processed' => 'synced',
            'partially_failed' => 'pending',
            'failed' => 'conflict',
            'offline' => 'offline',
            'remote_missing' => 'remote_missing',
            default => 'local',
        };

        $map = [
            'local' => 'PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_STATUS_LOCAL',
            'synced' => 'PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_STATUS_SYNCED',
            'pending' => 'PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_STATUS_PENDING',
            'conflict' => 'PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_STATUS_CONFLICT',
            'offline' => 'PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_STATUS_OFFLINE',
            'remote_missing' => 'PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_STATUS_REMOTE_MISSING',
        ];

        return '<div class="r3dncg-status-wrap">'
            . '<small class="text-muted">' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_STATUS') . '</small> '
            . '<span class="badge r3dncg-badge r3dncg-badge--' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">' . Text::_($map[$key]) . '</span>'
            . '</div>';
    }

    private function buildCardEditorHtml(array $gallery): string
    {
        $images = (array) ($gallery['images'] ?? []);
        if ($images === []) {
            return '';
        }

        $mapper = new FieldValueMapper();
        $cards = [];

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            $sourceName = (string) ($image['source_name'] ?? '');
            if ($sourceName === '') {
                continue;
            }

            $caption = (string) ($image['caption'] ?? '');
            $title = (string) ($image['title'] ?? '');
            $titleSuggestion = $mapper->buildReadableFromFilename($sourceName);
            $sort = (int) ($image['sort'] ?? 0);
            $thumb = (string) ($image['thumb'] ?? '');
            $thumbEsc = htmlspecialchars('/' . ltrim($thumb, '/'), ENT_QUOTES, 'UTF-8');
            $keyEsc = htmlspecialchars($sourceName, ENT_QUOTES, 'UTF-8');

            $cards[] = '<article class="r3dncg-card" draggable="true" data-r3dncg-card="' . $keyEsc . '">'
                . '<header class="r3dncg-card__head">'
                . '<label class="r3dncg-check"><input type="checkbox" data-r3dncg-delete="' . $keyEsc . '"></label>'
                . '<span class="r3dncg-file" title="' . $keyEsc . '">' . $keyEsc . '</span>'
                . '</header>'
                . '<div class="r3dncg-card__thumb dz-thumb" data-r3dncg-drag-handle="1"><img src="' . $thumbEsc . '" alt="" loading="lazy">'
                . '<span class="r3dncg-thumb-overlay">'
                . '<button type="button" class="btn btn-sm r3dncg-delete r3dncg-delete-icon" data-r3dncg-delete-item="' . $keyEsc . '" title="' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_DELETE') . '" aria-label="' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_DELETE') . '"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>'
                . '<span class="badge rounded-pill r3dncg-badge r3dncg-badge--synced" title="' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_STATUS_SYNCED') . '" aria-label="' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_STATUS_SYNCED') . '"><i class="fa-solid fa-check" aria-hidden="true"></i></span>'
                . '</span></div>'
                . '<div class="r3dncg-card__fields">'
                . '<input type="hidden" data-r3dncg-sort="' . $keyEsc . '" value="' . $sort . '">'
                . '<input type="text" class="form-control form-control-sm" data-r3dncg-caption="' . $keyEsc . '" value="' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '" placeholder="' . Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_CAPTION') . '">'
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

    private function resolveGalleryJsonFromShare(string $shareUrl, int $articleId, string $fieldAlias): string
    {
        if (!preg_match('#/s/([A-Za-z0-9]+)#', $shareUrl, $matches)) {
            return '';
        }

        $token = $matches[1];
        $fieldId = $this->resolveFieldIdByAlias($fieldAlias);

        if ($fieldId <= 0) {
            return '';
        }

        $galleryKey = $articleId . '-' . $fieldId . '-' . substr(sha1($token), 0, 12);
        $relative = 'images/galleries/nextcloud/' . $galleryKey . '/gallery.json';
        $absolute = rtrim(JPATH_ROOT, '/\\') . '/' . $relative;

        return is_file($absolute) ? $relative : '';
    }

    private function resolveGalleryJsonFromStorage(int $articleId, int $fieldId): string
    {
        $base = rtrim(JPATH_ROOT, '/\\') . '/images/galleries/nextcloud';
        if (!is_dir($base)) {
            return '';
        }

        $pattern = $base . '/' . $articleId . '-' . $fieldId . '-*/gallery.json';
        $matches = glob($pattern) ?: [];
        if ($matches === []) {
            return '';
        }

        usort($matches, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $relative = str_replace('\\', '/', str_replace(rtrim(JPATH_ROOT, '/\\') . '/', '', $matches[0]));

        return $relative;
    }

    private function resolveLatestGalleryJsonByArticle(int $articleId): string
    {
        $base = rtrim(JPATH_ROOT, '/\\') . '/images/galleries/nextcloud';
        if (!is_dir($base)) {
            return '';
        }

        $pattern = $base . '/' . $articleId . '-*-*/gallery.json';
        $matches = glob($pattern) ?: [];
        if ($matches === []) {
            return '';
        }

        usort($matches, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return str_replace('\\', '/', str_replace(rtrim(JPATH_ROOT, '/\\') . '/', '', $matches[0]));
    }

    private function resolveFieldIdByAlias(string $fieldAlias): int
    {
        $alias = trim(strtolower($fieldAlias));
        if ($alias === '') {
            return 0;
        }

        $candidates = array_values(array_unique([
            $alias,
            str_replace('-', '_', $alias),
            str_replace('_', '-', $alias),
        ]));

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__fields'))
                ->where($db->quoteName('context') . ' = ' . $db->quote('com_content.article'))
                ->where($db->quoteName('state') . ' >= 0')
                ->where($db->quoteName('type') . ' = ' . $db->quote('r3dnextcloudgallery'));

            $or = [];
            foreach ($candidates as $candidate) {
                $or[] = $db->quoteName('name') . ' = ' . $db->quote($candidate);
            }

            $query->where('(' . implode(' OR ', $or) . ')')
                ->order($db->quoteName('id') . ' DESC');

            $db->setQuery($query, 0, 1);
            return (int) $db->loadResult();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}







