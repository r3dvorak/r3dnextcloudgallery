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

final class FieldValueMapper
{
    public const DEFAULT_ALT_FALLBACK = 'Bild';
    private ?string $activeGalleryJsonRealPath = null;
    private ?string $activeAlbumDirRealPath = null;
    private ?string $activeThumbsDirRealPath = null;
    public function decode(string $fieldValueJson): array
    {
        $decoded = json_decode($fieldValueJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode field value JSON.');
        }

        return $json;
    }

    public function applyCaptionsToGallery(string $galleryJsonAbsolutePath, array $captionUpdates): array
    {
        return $this->applyMetaToGallery($galleryJsonAbsolutePath, $captionUpdates);
    }

    public function applyMetaToGallery(string $galleryJsonAbsolutePath, array $metaUpdates): array
    {
        $this->beginSafeDeleteContext($galleryJsonAbsolutePath);
        try {
            if (!is_file($galleryJsonAbsolutePath)) {
                return ['updated' => 0, 'total' => 0];
            }

            $gallery = json_decode((string) file_get_contents($galleryJsonAbsolutePath), true);

            if (!is_array($gallery)) {
                return ['updated' => 0, 'total' => 0];
            }

            $gallery = $this->normalizeGalleryArray($gallery);
            $images = (array) ($gallery['images'] ?? []);
            $updatedCount = 0;

            foreach ($images as &$image) {
                $sourceName = (string) ($image['source_name'] ?? '');

                if ($sourceName === '' || !array_key_exists($sourceName, $metaUpdates)) {
                    continue;
                }

                $payload = (array) $metaUpdates[$sourceName];
                $newCaption = trim((string) ($payload['caption'] ?? ''));
                $newTitle = trim((string) ($payload['title'] ?? ''));
                if ($newTitle === '' && $newCaption !== '') {
                    $newTitle = $newCaption;
                }
                $newSort = (int) ($payload['sort'] ?? (int) ($image['sort'] ?? 0));
                $delete = !empty($payload['delete']);

                if ($delete) {
                    $image['__delete'] = true;
                    $updatedCount++;
                    continue;
                }

                if ($newTitle !== (string) ($image['title'] ?? '')) {
                    $image['title'] = $newTitle;
                    $updatedCount++;
                }

                if ($newCaption !== (string) ($image['caption'] ?? '')) {
                    $image['caption'] = $newCaption;
                    $updatedCount++;
                }

                $effectiveTitle = $newTitle !== '' ? $newTitle : trim((string) ($image['title'] ?? ''));
                $autoAlt = $this->resolveAltFallback('', '', $effectiveTitle, (string) ($image['source_name'] ?? ''));
                if ($autoAlt !== (string) ($image['alt'] ?? '')) {
                    $image['alt'] = $autoAlt;
                    $updatedCount++;
                }

                if ($newSort > 0 && $newSort !== (int) ($image['sort'] ?? 0)) {
                    $image['sort'] = $newSort;
                    $updatedCount++;
                }
            }
            unset($image);

            $gallery['images'] = $this->finalizeImages($images);
            $json = json_encode($gallery, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if (!is_string($json)) {
                throw new RuntimeException('Unable to encode gallery JSON.');
            }

            file_put_contents($galleryJsonAbsolutePath, $json . PHP_EOL);

            return ['updated' => $updatedCount, 'total' => count($gallery['images'])];
        } finally {
            $this->endSafeDeleteContext();
        }
    }

    private function finalizeImages(array $images): array
    {
        $filtered = [];

        foreach ($images as $image) {
            if (!empty($image['__delete'])) {
                $this->deleteImageFiles($image);
                continue;
            }
            $filtered[] = $image;
        }

        usort($filtered, static function (array $a, array $b): int {
            return ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
        });

        $index = 1;
        foreach ($filtered as &$image) {
            $image['sort'] = $index++;
        }
        unset($image);

        return $filtered;
    }

    public function normalizeGalleryArray(array $gallery): array
    {
        $images = (array) ($gallery['images'] ?? []);
        $normalized = [];
        $index = 1;

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            $item = $this->normalizeImageItem($image, $index);
            if ($item !== null) {
                $normalized[] = $item;
                $index++;
            }
        }

        $gallery['images'] = $normalized;

        return $gallery;
    }

    private function normalizeImageItem(array $image, int $fallbackSort): ?array
    {
        $file = trim((string) ($image['file'] ?? $image['image'] ?? $image['original'] ?? ''));
        $thumb = trim((string) ($image['thumb'] ?? $image['thumbnail'] ?? ''));
        $sourceTemp = trim((string) ($image['source_temp'] ?? $image['source'] ?? ''));
        $sourceName = trim((string) ($image['source_name'] ?? ''));

        if ($file === '' && $sourceTemp !== '') {
            $file = $sourceTemp;
        }

        if ($file === '' || $thumb === '') {
            return null;
        }

        if ($sourceName === '') {
            $sourceName = basename($file);
        }

        $id = trim((string) ($image['id'] ?? ''));
        if ($id === '') {
            $id = sha1($sourceName);
        }

        $sort = (int) ($image['sort'] ?? $fallbackSort);
        if ($sort <= 0) {
            $sort = $fallbackSort;
        }

        $sourceTitle = trim((string) ($image['title'] ?? ''));
        $caption = trim((string) ($image['caption'] ?? ''));
        if ($caption === '') {
            $caption = $this->buildReadableFromFilename($sourceName);
        }

        $alt = trim((string) ($image['alt'] ?? ''));
        $derivedAlt = $this->resolveAltFallback('', '', $sourceTitle, $sourceName);
        if ($alt === '' || $alt !== $derivedAlt) {
            $alt = $derivedAlt;
        }

        return [
            'id' => $id,
            'source_name' => $sourceName,
            'file' => $file,
            'thumb' => $thumb,
            'title' => $sourceTitle,
            'caption' => $caption,
            'alt' => $alt,
            'sort' => $sort,
            'status' => (string) ($image['status'] ?? 'processed'),
            'source_temp' => $sourceTemp,
            'size' => (int) ($image['size'] ?? 0),
            'mime' => (string) ($image['mime'] ?? ''),
            'width' => (int) ($image['width'] ?? 0),
            'height' => (int) ($image['height'] ?? 0),
            'thumb_width' => (int) ($image['thumb_width'] ?? 0),
            'thumb_height' => (int) ($image['thumb_height'] ?? 0),
        ];
    }

    public function resolveAltFallback(string $alt, string $caption, string $title, string $filename = ''): string
    {
        $alt = trim($alt);
        if ($alt !== '') {
            return $alt;
        }

        $caption = trim($caption);
        if ($caption !== '') {
            return $caption;
        }

        $title = trim($title);
        if ($title !== '') {
            return $title;
        }

        $cleaned = $this->buildReadableFromFilename($filename);
        if ($cleaned !== '') {
            return $cleaned;
        }

        return self::DEFAULT_ALT_FALLBACK;
    }

    public function buildReadableFromFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = trim($base);
        if ($base === '') {
            return '';
        }

        $base = str_replace(['_', '-'], ' ', $base);
        $base = preg_replace('/\s+/', ' ', $base) ?: '';
        $base = trim($base);
        if ($base === '') {
            return '';
        }

        $base = mb_convert_case($base, MB_CASE_TITLE, 'UTF-8');
        $base = str_replace(['Ae', 'Oe', 'Ue', 'Ss'], ['Ä', 'Ö', 'Ü', 'ß'], $base);

        return $base;
    }

    private function deleteImageFiles(array $image): void
    {
        if ($this->activeAlbumDirRealPath === null || $this->activeGalleryJsonRealPath === null) {
            return;
        }

        // Security: only delete files belonging to the currently edited gallery album.
        // This prevents arbitrary deletion via manipulated image paths in gallery metadata.
        foreach (['file', 'thumb', 'source_temp'] as $key) {
            $relativePath = trim((string) ($image[$key] ?? ''));
            if ($relativePath === '') {
                continue;
            }

            $realFile = $this->resolveSafeImagePath($relativePath);
            if ($realFile === null) {
                continue;
            }

            if ($realFile === $this->activeGalleryJsonRealPath || is_dir($realFile)) {
                continue;
            }

            @unlink($realFile);
        }
    }

    private function beginSafeDeleteContext(string $galleryJsonAbsolutePath): void
    {
        $this->activeGalleryJsonRealPath = null;
        $this->activeAlbumDirRealPath = null;
        $this->activeThumbsDirRealPath = null;

        $galleryReal = realpath($galleryJsonAbsolutePath);
        if ($galleryReal === false || !is_file($galleryReal)) {
            return;
        }

        $galleryReal = str_replace('\\', '/', $galleryReal);
        $albumDir = dirname($galleryReal);
        $albumReal = realpath($albumDir);
        if ($albumReal === false || !is_dir($albumReal)) {
            return;
        }

        $albumReal = str_replace('\\', '/', $albumReal);
        $thumbsReal = realpath($albumReal . '/thumbs');
        if ($thumbsReal !== false && is_dir($thumbsReal)) {
            $thumbsReal = str_replace('\\', '/', $thumbsReal);
        } else {
            $thumbsReal = null;
        }

        $this->activeGalleryJsonRealPath = $galleryReal;
        $this->activeAlbumDirRealPath = $albumReal;
        $this->activeThumbsDirRealPath = $thumbsReal;
    }

    private function endSafeDeleteContext(): void
    {
        $this->activeGalleryJsonRealPath = null;
        $this->activeAlbumDirRealPath = null;
        $this->activeThumbsDirRealPath = null;
    }

    private function resolveSafeImagePath(string $relativePath): ?string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === '') {
            return null;
        }
        if (preg_match('#^[A-Za-z]:/#', $relativePath)) {
            return null;
        }
        if (str_contains($relativePath, '../') || str_contains($relativePath, '/..') || str_contains($relativePath, '..\\')) {
            return null;
        }

        $absolute = rtrim(JPATH_ROOT, '/\\') . '/' . ltrim($relativePath, '/');
        $real = realpath($absolute);
        if ($real === false || !is_file($real)) {
            return null;
        }

        $real = str_replace('\\', '/', $real);
        $album = $this->activeAlbumDirRealPath;
        $thumbs = $this->activeThumbsDirRealPath;
        if ($album === null) {
            return null;
        }

        $insideAlbum = str_starts_with($real . '/', rtrim($album, '/') . '/');
        $insideThumbs = $thumbs !== null && str_starts_with($real . '/', rtrim($thumbs, '/') . '/');

        // Keep deletion strictly inside active album (incl. thumbs) and below /images.
        $imagesRoot = realpath(rtrim(JPATH_ROOT, '/\\') . '/images');
        $imagesRoot = $imagesRoot !== false ? str_replace('\\', '/', $imagesRoot) : null;
        if ($imagesRoot === null || !str_starts_with($real . '/', rtrim($imagesRoot, '/') . '/')) {
            return null;
        }

        if (!$insideAlbum && !$insideThumbs) {
            return null;
        }

        return $real;
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        // Legacy gallery data sometimes stored web-root-relative paths with a leading slash.
        // Normalizing here keeps delete/update operations compatible without widening access
        // outside the Joomla site root.
        return ltrim($path, '/');
    }
}

