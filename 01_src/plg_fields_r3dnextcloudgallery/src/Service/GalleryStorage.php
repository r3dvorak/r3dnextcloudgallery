<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.r3dnextcloudgallery
 *
 * @copyright   (C) 2026 Richard Dvorak / R3D
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace R3d\Plugin\Fields\R3dnextcloudgallery\Service;

defined('_JEXEC') or die;

use RuntimeException;

final class GalleryStorage
{
    public const DEFAULT_BASE_SUBFOLDER = 'nc-gallery';

    public function buildGalleryKey(int $articleId, int $fieldId, string $token): string
    {
        return $articleId . '-' . $fieldId . '-' . substr(sha1($token), 0, 12);
    }

    public function ensureGalleryDirectories(string $baseSubfolder, string $galleryFolderName): array
    {
        $baseSubfolder = $this->sanitizePathSegment($baseSubfolder);
        $galleryFolderName = $this->sanitizePathSegment($galleryFolderName);

        if ($baseSubfolder === '') {
            $baseSubfolder = self::DEFAULT_BASE_SUBFOLDER;
        }
        if ($galleryFolderName === '') {
            $galleryFolderName = 'gallery';
        }

        $baseRelative = 'images/' . $baseSubfolder . '/' . $galleryFolderName;
        $baseAbsolute = rtrim(JPATH_ROOT, '/\\') . '/' . $baseRelative;
        $thumbsAbsolute = $baseAbsolute . '/thumbs';

        $this->ensureDirectory($baseAbsolute);
        $this->ensureDirectory($thumbsAbsolute);

        return [
            'base_relative' => $baseRelative,
            'base_absolute' => $baseAbsolute,
            'thumbs_absolute' => $thumbsAbsolute,
            'gallery_json_absolute' => $baseAbsolute . '/gallery.json',
            'gallery_json_relative' => $baseRelative . '/gallery.json',
            'import_log_absolute' => $baseAbsolute . '/import.log',
            'import_log_relative' => $baseRelative . '/import.log',
        ];
    }

    public function sanitizePathSegment(string $value): string
    {
        $value = trim($value);
        $value = str_replace('\\', '/', $value);
        $value = preg_replace('#/+#', '/', $value) ?: '';
        $value = trim($value, '/');
        $parts = array_filter(explode('/', $value), static fn(string $part): bool => trim($part) !== '' && $part !== '.' && $part !== '..');
        $parts = array_map(fn(string $part): string => $this->sanitizeSingleSegment($part), $parts);
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        return implode('/', $parts);
    }

    public function loadGallery(string $galleryJsonPath): array
    {
        if (!is_file($galleryJsonPath)) {
            return [
                'images' => [],
            ];
        }

        $contents = file_get_contents($galleryJsonPath);

        if (!is_string($contents) || $contents === '') {
            return [
                'images' => [],
            ];
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            return [
                'images' => [],
            ];
        }

        return $decoded;
    }

    public function saveGallery(string $galleryJsonPath, array $gallery): void
    {
        $json = json_encode($gallery, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode gallery.json.');
        }

        if (file_put_contents($galleryJsonPath, $json . PHP_EOL) === false) {
            throw new RuntimeException('Unable to write gallery.json.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory: ' . $path);
        }
    }

    private function sanitizeSingleSegment(string $segment): string
    {
        $segment = trim($segment);
        if ($segment === '') {
            return '';
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $segment);
        if (is_string($ascii) && $ascii !== '') {
            $segment = $ascii;
        }

        $segment = strtolower($segment);
        $segment = preg_replace('/[^a-z0-9._-]+/', '-', $segment) ?: '';
        $segment = trim($segment, '-._');

        return $segment;
    }
}

