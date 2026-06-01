<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.r3dnextcloudgallery_yootheme
 *
 * @copyright   (C) 2026 Richard Dvorak / R3D
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace R3d\Plugin\System\R3dnextcloudgalleryYootheme\Yootheme;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use YOOtheme\Builder\Joomla\Fields\Type\FieldsType;

final class FieldSourceResolver
{
    private const ITEM_TYPE_NAME = 'R3dNcGalleryItem';

    private static ?array $cachedParams = null;

    public static function configureField($config, $field, $source, $context)
    {
        if (!is_array($config) || !is_object($field) || !is_object($source)) {
            return $config;
        }

        if (!self::matchesTargetField($field)) {
            return $config;
        }

        self::registerTypes($source);

        $config['type'] = ['listOf' => self::ITEM_TYPE_NAME];
        $currentLabel = trim((string) (($config['metadata']['label'] ?? '') ?: ($field->title ?? $field->name ?? 'NC Gallery')));
        $config['metadata']['label'] = $currentLabel . ' (R3D NC Gallery)';
        $config['metadata']['group'] = 'R3D Nextcloud Gallery';
        $config['extensions'] = [
            'call' => [
                'func' => self::class . '::resolve',
                'args' => [
                    'context' => $context,
                    'field' => (string) ($field->name ?? ''),
                    'field_id' => (int) ($field->id ?? 0),
                ],
            ],
        ];

        return $config;
    }

    public static function resolve($item, $args = [], $ctx = null, $info = null): array
    {
        $args = is_array($args) ? $args : [];
        $field = self::resolveFieldForItem($item, $args);
        if ($field === null) {
            self::debug('No matching field found for item');
            return [];
        }

        $raw = self::extractRawValue($field);
        if ($raw === '') {
            self::debug('Field value is empty');
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::debug('Field value is not JSON');
            return [];
        }

        $galleryPath = self::resolveGalleryJsonPath((string) ($decoded['gallery_json'] ?? ''));
        if ($galleryPath === null) {
            self::debug('No safe gallery_json path resolved');
            return [];
        }

        $gallery = json_decode((string) file_get_contents($galleryPath), true);
        if (!is_array($gallery)) {
            self::debug('gallery.json decode failed');
            return [];
        }

        $items = self::mapItems((array) ($gallery['images'] ?? []));
        if ($items === []) {
            return [];
        }

        return $items;
    }

    private static function registerTypes(object $source): void
    {
        if (!method_exists($source, 'objectType')) {
            return;
        }

        $source->objectType(self::ITEM_TYPE_NAME, ['fields' => self::itemFieldsConfig()]);
    }

    private static function itemFieldsConfig(): array
    {
        return [
            'id' => ['type' => 'String', 'metadata' => ['label' => 'ID']],
            'image_url' => ['type' => 'String', 'metadata' => ['label' => 'Image URL']],
            'thumb_url' => ['type' => 'String', 'metadata' => ['label' => 'Thumbnail URL']],
            'title' => ['type' => 'String', 'metadata' => ['label' => 'Title']],
            'caption' => ['type' => 'String', 'metadata' => ['label' => 'Caption']],
            'alt' => ['type' => 'String', 'metadata' => ['label' => 'Alt']],
            'width' => ['type' => 'Int', 'metadata' => ['label' => 'Width']],
            'height' => ['type' => 'Int', 'metadata' => ['label' => 'Height']],
            'mime' => ['type' => 'String', 'metadata' => ['label' => 'MIME']],
            'sort' => ['type' => 'Int', 'metadata' => ['label' => 'Sort']],
        ];
    }

    private static function resolveFieldForItem($item, array $args): ?object
    {
        $context = (string) ($args['context'] ?? 'com_content.article');
        $hint = self::params()['field_name_hint'] ?? '';
        $hint = is_string($hint) ? trim($hint) : '';
        $argName = trim((string) ($args['field'] ?? ''));
        $argId = (int) ($args['field_id'] ?? 0);
        $articleId = (int) (($item->id ?? 0));

        if (class_exists(FieldsType::class) && method_exists(FieldsType::class, 'getField')) {
            if ($hint !== '') {
                $field = FieldsType::getField($hint, $item, $context);
                if (is_object($field) && self::matchesTargetField($field, true)) {
                    return $field;
                }
            }

            if ($argName !== '') {
                $field = FieldsType::getField($argName, $item, $context);
                if (is_object($field) && self::matchesTargetField($field)) {
                    return $field;
                }
            }
        }

        if (class_exists(FieldsHelper::class) && $articleId > 0) {
            $fields = FieldsHelper::getFields($context, $item, true);
            foreach ((array) $fields as $field) {
                if (!is_object($field)) {
                    continue;
                }
                if ($hint !== '' && (string) ($field->name ?? '') === $hint && self::matchesTargetField($field, true)) {
                    return $field;
                }
            }
            foreach ((array) $fields as $field) {
                if (!is_object($field)) {
                    continue;
                }
                if ($argId > 0 && (int) ($field->id ?? 0) === $argId && self::matchesTargetField($field)) {
                    return $field;
                }
                if ($argName !== '' && (string) ($field->name ?? '') === $argName && self::matchesTargetField($field)) {
                    return $field;
                }
                if (self::matchesTargetField($field)) {
                    return $field;
                }
            }
        }

        if ($articleId > 0) {
            return self::loadFieldFromDb($articleId, $context, $argName, $argId, $hint);
        }

        return null;
    }

    private static function loadFieldFromDb(int $articleId, string $context, string $argName, int $argId, string $hint): ?object
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select([
                'f.id',
                'f.name',
                'f.type',
                'f.fieldparams',
                'fv.value AS rawvalue',
            ])
            ->from($db->quoteName('#__fields', 'f'))
            ->innerJoin($db->quoteName('#__fields_values', 'fv') . ' ON fv.field_id = f.id')
            ->where('f.context = ' . $db->quote($context))
            ->where('f.state >= 0')
            ->where('fv.item_id = ' . $db->quote((string) $articleId));
        $db->setQuery($query);
        $rows = (array) $db->loadObjectList();

        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            if ($hint !== '' && (string) ($row->name ?? '') === $hint && self::matchesTargetField($row, true)) {
                return $row;
            }
        }
        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            if ($argId > 0 && (int) ($row->id ?? 0) === $argId && self::matchesTargetField($row)) {
                return $row;
            }
            if ($argName !== '' && (string) ($row->name ?? '') === $argName && self::matchesTargetField($row)) {
                return $row;
            }
            if (self::matchesTargetField($row)) {
                return $row;
            }
        }

        return null;
    }

    private static function matchesTargetField(object $field, bool $strictNameOnly = false): bool
    {
        $name = strtolower(trim((string) ($field->name ?? '')));
        $type = strtolower(trim((string) ($field->type ?? '')));
        $hint = self::params()['field_name_hint'] ?? '';
        $hint = strtolower(trim((string) $hint));
        $preferType = (int) (self::params()['prefer_type_match'] ?? 1) === 1;

        if ($hint !== '') {
            return $name === $hint;
        }

        if ($strictNameOnly) {
            return false;
        }

        if ($preferType && $type === 'r3dnextcloudgallery') {
            return true;
        }

        if (str_contains($name, 'nextcloudgallery') || str_contains($name, 'nc-gallery')) {
            return true;
        }

        return $type === 'r3dnextcloudgallery';
    }

    private static function extractRawValue(object $field): string
    {
        if (isset($field->rawvalue) && is_string($field->rawvalue)) {
            return $field->rawvalue;
        }
        if (isset($field->value) && is_string($field->value)) {
            return $field->value;
        }

        return '';
    }

    private static function resolveGalleryJsonPath(string $relative): ?string
    {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', JPATH_ROOT), '/');
        $candidate = $root . '/' . ltrim($relative, '/');
        $real = realpath($candidate);

        if (!is_string($real) || $real === '' || !is_file($real)) {
            return null;
        }

        $realNorm = str_replace('\\', '/', $real);
        if (!str_starts_with($realNorm, $root . '/')) {
            return null;
        }

        return $realNorm;
    }

    private static function mapItems(array $images): array
    {
        $root = rtrim(Factory::getApplication()->get('live_site', ''), '/');
        $base = rtrim(\Joomla\CMS\Uri\Uri::root(), '/');
        $prefix = $root !== '' ? $root : $base;
        $items = [];

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            $imageUrl = self::toUrl($prefix, (string) ($image['file'] ?? ''));
            $thumbUrl = self::toUrl($prefix, (string) ($image['thumb'] ?? ''));
            if ($imageUrl === '' && $thumbUrl === '') {
                continue;
            }

            $items[] = [
                'id' => (string) ($image['id'] ?? ''),
                'image_url' => $imageUrl,
                'thumb_url' => $thumbUrl,
                'title' => (string) ($image['title'] ?? ''),
                'caption' => (string) ($image['caption'] ?? ''),
                'alt' => (string) ($image['alt'] ?? ''),
                'width' => (int) ($image['width'] ?? 0),
                'height' => (int) ($image['height'] ?? 0),
                'mime' => (string) ($image['mime'] ?? ''),
                'sort' => (int) ($image['sort'] ?? 0),
            ];
        }

        return $items;
    }

    private static function toUrl(string $prefix, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($prefix, '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    private static function params(): array
    {
        if (self::$cachedParams !== null) {
            return self::$cachedParams;
        }

        $plugin = PluginHelper::getPlugin('system', 'r3dnextcloudgallery_yootheme');
        $registry = new Registry((string) ($plugin->params ?? '{}'));
        self::$cachedParams = $registry->toArray();

        return self::$cachedParams;
    }

    private static function debug(string $message): void
    {
        if ((int) (self::params()['debug_log'] ?? 0) !== 1) {
            return;
        }

        try {
            Factory::getApplication()->getLogger()->debug('[r3dnextcloudgallery_yootheme] ' . $message);
        } catch (\Throwable $e) {
            // no-op
        }
    }
}
