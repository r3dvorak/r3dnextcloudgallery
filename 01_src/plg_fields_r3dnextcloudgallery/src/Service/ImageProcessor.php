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

final class ImageProcessor
{
    private const MAX_WIDTH = 12000;
    private const MAX_HEIGHT = 12000;
    private const MAX_PIXELS = 40000000;
    private const MAX_MEMORY_BYTES = 268435456; // 256 MB safety ceiling

    public function createVariants(
        string $sourceAbsolute,
        string $largeAbsolute,
        string $thumbAbsolute,
        int $largeMaxEdge,
        int $thumbMaxEdge,
        int $jpegQuality,
        int $thumbQuality
    ): array {
        $imageInfo = @getimagesize($sourceAbsolute);

        if (!is_array($imageInfo)) {
            throw new RuntimeException('Unable to read image metadata.');
        }

        $sourceWidth = (int) $imageInfo[0];
        $sourceHeight = (int) $imageInfo[1];
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            throw new RuntimeException('Invalid source image dimensions.');
        }
        if ($sourceWidth > self::MAX_WIDTH || $sourceHeight > self::MAX_HEIGHT) {
            throw new RuntimeException('Image dimensions exceed allowed limits.');
        }
        if (($sourceWidth * $sourceHeight) > self::MAX_PIXELS) {
            throw new RuntimeException('Image pixel count exceeds allowed limits.');
        }

        $mime = strtolower((string) $imageInfo['mime']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Unsupported image MIME type.');
        }

        $estimatedMemory = ($sourceWidth * $sourceHeight) * 12;
        if ($estimatedMemory > self::MAX_MEMORY_BYTES) {
            throw new RuntimeException('Image requires too much memory for safe processing.');
        }

        $sourceImage = $this->createImageFromFile($sourceAbsolute, $mime);
        [$sourceImage, $sourceWidth, $sourceHeight] = $this->applyOrientationFromExif($sourceImage, $sourceAbsolute, $mime, $sourceWidth, $sourceHeight);

        [$largeWidth, $largeHeight] = $this->fitWithin($sourceWidth, $sourceHeight, $largeMaxEdge);
        [$thumbWidth, $thumbHeight] = $this->fitWithin($sourceWidth, $sourceHeight, $thumbMaxEdge);

        $largeImage = imagecreatetruecolor($largeWidth, $largeHeight);
        $thumbImage = imagecreatetruecolor($thumbWidth, $thumbHeight);

        if (!$largeImage || !$thumbImage) {
            imagedestroy($sourceImage);
            throw new RuntimeException('Unable to allocate image buffers.');
        }

        imagecopyresampled($largeImage, $sourceImage, 0, 0, 0, 0, $largeWidth, $largeHeight, $sourceWidth, $sourceHeight);
        imagecopyresampled($thumbImage, $sourceImage, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $sourceWidth, $sourceHeight);

        $this->saveImageByMime($largeImage, $largeAbsolute, $mime, $jpegQuality);
        $this->saveImageByMime($thumbImage, $thumbAbsolute, $mime, $thumbQuality);

        imagedestroy($largeImage);
        imagedestroy($thumbImage);
        imagedestroy($sourceImage);

        return [
            'width' => $largeWidth,
            'height' => $largeHeight,
            'thumb_width' => $thumbWidth,
            'thumb_height' => $thumbHeight,
            'mime' => $mime,
        ];
    }

    private function createImageFromFile(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        } ?: throw new RuntimeException('Unsupported image MIME type for processing: ' . $mime);
    }

    private function saveImageByMime($image, string $path, string $mime, int $quality): void
    {
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, $path, $quality),
            'image/png' => imagepng($image, $path, $this->jpegQualityToPngCompression($quality)),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, $path, $quality) : false,
            default => false,
        };

        if ($ok !== true) {
            throw new RuntimeException('Unable to write image variant: ' . $path);
        }
    }

    private function fitWithin(int $width, int $height, int $maxEdge): array
    {
        if ($width <= $maxEdge && $height <= $maxEdge) {
            return [$width, $height];
        }

        $ratio = min(((float) $maxEdge) / ((float) $width), ((float) $maxEdge) / ((float) $height));

        return [
            max(1, (int) round(((float) $width) * $ratio)),
            max(1, (int) round(((float) $height) * $ratio)),
        ];
    }

    private function jpegQualityToPngCompression(int $jpegQuality): int
    {
        $normalized = max(0, min(100, $jpegQuality));
        $compression = 9 - (int) round((((float) $normalized) / 100.0) * 9.0);

        return max(0, min(9, $compression));
    }

    private function applyOrientationFromExif($image, string $path, string $mime, int $width, int $height): array
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return [$image, $width, $height];
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        if ($orientation < 2 || $orientation > 8) {
            return [$image, $width, $height];
        }

        $rotated = false;

        switch ($orientation) {
            case 2:
                if (function_exists('imageflip')) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                break;
            case 3:
                $image = imagerotate($image, 180, 0);
                $rotated = true;
                break;
            case 4:
                if (function_exists('imageflip')) {
                    imageflip($image, IMG_FLIP_VERTICAL);
                }
                break;
            case 5:
                if (function_exists('imageflip')) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                $image = imagerotate($image, -90, 0);
                $rotated = true;
                break;
            case 6:
                $image = imagerotate($image, -90, 0);
                $rotated = true;
                break;
            case 7:
                if (function_exists('imageflip')) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                $image = imagerotate($image, 90, 0);
                $rotated = true;
                break;
            case 8:
                $image = imagerotate($image, 90, 0);
                $rotated = true;
                break;
        }

        if ($rotated && (($orientation >= 5 && $orientation <= 8) || $orientation === 6 || $orientation === 8)) {
            [$width, $height] = [$height, $width];
        }

        return [$image, $width, $height];
    }
}

