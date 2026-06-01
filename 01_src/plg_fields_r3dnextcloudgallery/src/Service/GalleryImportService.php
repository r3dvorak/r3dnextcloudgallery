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

use DateTimeImmutable;
use RuntimeException;

final class GalleryImportService
{
    /** @var list<string> */
    private array $deleteAllowedBaseDirs = [];

    public function __construct(
        private readonly ShareLinkParser $shareLinkParser,
        private readonly PublicWebDavClient $webDavClient,
        private readonly GalleryStorage $galleryStorage,
        private readonly ImageProcessor $imageProcessor
    ) {
    }

    public function import(string $shareUrl, int $articleId, int $fieldId, array $params = []): array
    {
        $constraints = ImportConstraints::fromParams($params);
        $allowedShareHosts = $this->parseAllowedShareHosts((string) ($params['allowed_share_hosts'] ?? ''));
        $parsed = $this->shareLinkParser->parse($shareUrl, $allowedShareHosts);
        $galleryKey = $this->galleryStorage->buildGalleryKey($articleId, $fieldId, $parsed['token']);
        $baseSubfolder = (string) ($params['storage_subfolder'] ?? GalleryStorage::DEFAULT_BASE_SUBFOLDER);
        $useFilenameForCaption = !array_key_exists('use_filename_for_caption', $params) || (string) $params['use_filename_for_caption'] === '1';
        $shareTitle = $this->webDavClient->fetchShareTitle($parsed['share_url']);
        $shareFolder = $this->resolveShareFolderName($parsed['token'], $shareUrl, $shareTitle);
        $galleryFolderName = $shareFolder !== '' ? $shareFolder : $galleryKey;
        $paths = $this->galleryStorage->ensureGalleryDirectories($baseSubfolder, $galleryFolderName);
        $this->deleteAllowedBaseDirs = [
            str_replace('\\', '/', (string) realpath($paths['base_absolute'])),
            str_replace('\\', '/', (string) realpath($paths['thumbs_absolute'])),
        ];
        $keepSourceForDebug = !empty($params['keep_source_for_debug']) && (string) $params['keep_source_for_debug'] === '1';
        if (!$keepSourceForDebug) {
            $legacySourceDir = $paths['base_absolute'] . '/source';
            if (is_dir($legacySourceDir)) {
                $this->deleteDirectoryRecursive($legacySourceDir);
            }
        }
        $previous = (new FieldValueMapper())->normalizeGalleryArray(
            $this->galleryStorage->loadGallery($paths['gallery_json_absolute'])
        );
        $mapper = new FieldValueMapper();
        $existingImagesByFileName = $this->mapExistingByFilename((array) ($previous['images'] ?? []));
        $logLines = [];

        $this->webDavClient->testAccess($parsed['base_url'], $parsed['token']);
        $davItems = $this->webDavClient->listFiles($parsed['base_url'], $parsed['token']);
        $resolvedShareFolder = $this->resolveShareFolderFromDav($davItems, $parsed['token']);
        if ($resolvedShareFolder !== '') {
            $galleryFolderName = $resolvedShareFolder;
            $paths = $this->galleryStorage->ensureGalleryDirectories($baseSubfolder, $galleryFolderName);
        }
        $files = $this->filterImportableFiles($davItems, $constraints);

        $errors = [];
        $images = [];

        foreach ($files as $index => $file) {
            if ($index >= $constraints['max_images']) {
                break;
            }

            $fileName = $this->filenameFromHref($file['href']);
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $id = sha1($fileName);
            $safeName = $this->sanitizeImportedFilename($fileName);
            $largeAbsolute = $this->resolveUniquePath($paths['base_absolute'], $safeName);
            $largeFileName = basename($largeAbsolute);
            $thumbFileName = $this->buildThumbFilename($largeFileName);
            $thumbAbsolute = $this->resolveUniquePath($paths['thumbs_absolute'], $thumbFileName);
            $thumbFileName = basename($thumbAbsolute);
            $largeRelative = $paths['base_relative'] . '/' . $largeFileName;
            $thumbRelative = $paths['base_relative'] . '/thumbs/' . $thumbFileName;
            $sourceAbsolute = $paths['base_absolute'] . '/.tmp-' . $id . '.' . $extension;
            $sourceRelative = $paths['base_relative'] . '/.tmp-' . $id . '.' . $extension;

            try {
                $this->webDavClient->download($parsed['base_url'], $parsed['token'], '', $fileName, $sourceAbsolute);
                $variant = $this->imageProcessor->createVariants(
                    $sourceAbsolute,
                    $largeAbsolute,
                    $thumbAbsolute,
                    $constraints['large_max_edge'],
                    $constraints['thumb_max_edge'],
                    $constraints['jpeg_quality'],
                    $constraints['thumb_quality']
                );
                if (!$keepSourceForDebug && is_file($sourceAbsolute)) {
                    @unlink($sourceAbsolute);
                }
                $logLines[] = '[OK] ' . $fileName;
            } catch (RuntimeException $exception) {
                $errors[] = $fileName . ': ' . $exception->getMessage();
                $logLines[] = '[ERR] ' . $fileName . ' :: ' . $exception->getMessage();
                if (is_file($sourceAbsolute)) {
                    @unlink($sourceAbsolute);
                }
                continue;
            }

            $previousItem = $existingImagesByFileName[$fileName] ?? [];

            $caption = (string) ($previousItem['caption'] ?? '');
            $title = trim((string) ($previousItem['title'] ?? ''));
            $alt = (string) ($previousItem['alt'] ?? '');
            $filenameCaption = $mapper->buildReadableFromFilename($fileName);
            if ($useFilenameForCaption && trim($caption) === '') {
                $caption = $filenameCaption;
            }
            $alt = $mapper->resolveAltFallback('', '', $title, $fileName);

            $images[] = [
                'id' => $id,
                'source_name' => $fileName,
                'file' => $largeRelative,
                'thumb' => $thumbRelative,
                'title' => $title,
                'caption' => $caption,
                'alt' => $alt,
                'sort' => (int) ($previousItem['sort'] ?? (((int) $index) + 1)),
                'status' => 'processed',
                'source_temp' => $keepSourceForDebug ? $sourceRelative : '',
                'size' => (int) $file['content_length'],
                'mime' => (string) $variant['mime'],
                'width' => (int) $variant['width'],
                'height' => (int) $variant['height'],
                'thumb_width' => (int) $variant['thumb_width'],
                'thumb_height' => (int) $variant['thumb_height'],
            ];
        }

        $status = $this->resolveStatus(count($images), count($errors));
        $now = (new DateTimeImmutable('now'))->format(DATE_ATOM);

        $gallery = [
            'version' => 1,
            'share_url' => $parsed['share_url'],
            'token_hash' => sha1($parsed['token']),
            'gallery_key' => $galleryKey,
            'gallery_folder' => $galleryFolderName,
            'storage_subfolder' => $baseSubfolder,
            'article_id' => $articleId,
            'field_id' => $fieldId,
            'status' => $status,
            'imported_at' => $now,
            'cache_refreshed_at' => $now,
            'images' => $images,
            'errors' => $errors,
            'constraints' => $constraints,
            'log_file' => $paths['import_log_relative'],
            'source_storage' => $keepSourceForDebug ? 'debug_temp_kept' : 'none',
        ];

        $this->galleryStorage->saveGallery($paths['gallery_json_absolute'], $gallery);
        $this->writeImportLog($paths['import_log_absolute'], $status, $logLines, $errors);

        return [
            'status' => $status,
            'gallery_key' => $galleryKey,
            'gallery_json' => $paths['gallery_json_relative'],
            'import_log' => $paths['import_log_relative'],
            'imported_images' => count($images),
            'errors' => $errors,
        ];
    }

    private function filterImportableFiles(array $davItems, array $constraints): array
    {
        $maxFileSizeBytes = $constraints['max_file_size_mb'] * 1024 * 1024;
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

    private function mapExistingByFilename(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $name = (string) ($item['source_name'] ?? '');
            if ($name !== '') {
                $result[$name] = $item;
            }
        }

        return $result;
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

    private function writeImportLog(string $logPath, string $status, array $lines, array $errors): void
    {
        $content = [];
        $content[] = '[' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') . '] status=' . $status;
        $content = array_merge($content, $lines);

        if ($errors !== []) {
            $content[] = '-- errors --';
            $content = array_merge($content, $errors);
        }

        file_put_contents($logPath, implode(PHP_EOL, $content) . PHP_EOL);
    }

    private function resolveShareFolderName(string $token, string $shareUrl, string $shareTitle = ''): string
    {
        $candidate = trim($shareTitle);
        if ($candidate === '') {
            $path = (string) (parse_url($shareUrl, PHP_URL_PATH) ?? '');
            $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
            $maybe = end($parts);
            if (is_string($maybe) && $maybe !== '' && !$this->isLikelyShareTokenName($maybe, $token)) {
                $candidate = $maybe;
            }
        }

        if ($candidate === '') {
            $candidate = $token;
        }

        $sanitized = $this->galleryStorage->sanitizePathSegment($candidate);
        if ($sanitized === '') {
            $sanitized = $this->galleryStorage->sanitizePathSegment($token);
        }

        $date = (new DateTimeImmutable('now'))->format('Y-m-d');

        return $sanitized . '-' . $date;
    }

    private function resolveShareFolderFromDav(array $davItems, string $token): string
    {
        foreach ($davItems as $item) {
            if (empty($item['is_collection'])) {
                continue;
            }
            $name = $this->filenameFromHref((string) ($item['href'] ?? ''));
            if ($this->isLikelyShareTokenName($name, $token)) {
                continue;
            }
            $name = $this->galleryStorage->sanitizePathSegment($name);
            if ($name !== '') {
                $date = (new DateTimeImmutable('now'))->format('Y-m-d');
                return $name . '-' . $date;
            }
        }

        return '';
    }

    private function isLikelyShareTokenName(string $name, string $token): bool
    {
        $name = trim($name);
        if ($name === '') {
            return true;
        }

        if (strtolower($name) === strtolower($token)) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z0-9]{12,}$/', $name);
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
        $candidate = $directory . '/' . $filename;
        $counter = 2;

        while (is_file($candidate)) {
            $suffix = '-' . $counter++;
            $candidate = $directory . '/' . $base . $suffix . ($ext !== '' ? '.' . $ext : '');
        }

        return $candidate;
    }

    private function deleteDirectoryRecursive(string $path): void
    {
        $path = str_replace('\\', '/', $path);
        if (is_link($path)) {
            if ($this->isAllowedDeletePath($path)) {
                @unlink($path);
            }
            return;
        }

        $realPath = realpath($path);
        if (!is_string($realPath) || $realPath === '') {
            return;
        }
        $realPath = str_replace('\\', '/', $realPath);
        if (!$this->isAllowedDeletePath($realPath)) {
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
                if ($this->isAllowedDeletePath($target)) {
                    @unlink($target);
                }
                continue;
            }
            if (is_dir($target)) {
                $this->deleteDirectoryRecursive($target);
            } else {
                $realTarget = realpath($target);
                if (is_string($realTarget) && $realTarget !== '' && $this->isAllowedDeletePath($realTarget)) {
                    @unlink($target);
                }
            }
        }

        @rmdir($path);
    }

    private function isAllowedDeletePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        foreach ($this->deleteAllowedBaseDirs as $baseDir) {
            if ($baseDir !== '' && str_starts_with($normalized . '/', rtrim($baseDir, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
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
            if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7f]/', $host)) {
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
}

