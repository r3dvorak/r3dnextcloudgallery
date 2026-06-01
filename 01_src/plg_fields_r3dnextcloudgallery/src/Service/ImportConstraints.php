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

final class ImportConstraints
{
    public const DEFAULT_MAX_IMAGES = 50;
    public const DEFAULT_MAX_FILE_SIZE_MB = 25;
    public const DEFAULT_LARGE_MAX_EDGE = 1920;
    public const DEFAULT_THUMB_MAX_EDGE = 480;
    public const DEFAULT_JPEG_QUALITY = 75;
    public const DEFAULT_THUMB_QUALITY = 70;
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function fromParams(array $params): array
    {
        $maxImages = self::toInt($params['max_images'] ?? self::DEFAULT_MAX_IMAGES, self::DEFAULT_MAX_IMAGES);
        $maxImages = max(1, min(100, $maxImages));

        return [
            'max_images' => $maxImages,
            'max_file_size_mb' => self::toRangeInt($params['max_file_size_mb'] ?? self::DEFAULT_MAX_FILE_SIZE_MB, 1, 200, self::DEFAULT_MAX_FILE_SIZE_MB),
            'large_max_edge' => self::toRangeInt($params['large_max_edge'] ?? self::DEFAULT_LARGE_MAX_EDGE, 320, 6000, self::DEFAULT_LARGE_MAX_EDGE),
            'thumb_max_edge' => self::toRangeInt($params['thumb_max_edge'] ?? self::DEFAULT_THUMB_MAX_EDGE, 80, 2000, self::DEFAULT_THUMB_MAX_EDGE),
            'jpeg_quality' => self::toRangeInt($params['jpeg_quality'] ?? self::DEFAULT_JPEG_QUALITY, 40, 95, self::DEFAULT_JPEG_QUALITY),
            'thumb_quality' => self::toRangeInt($params['thumb_quality'] ?? self::DEFAULT_THUMB_QUALITY, 40, 95, self::DEFAULT_THUMB_QUALITY),
        ];
    }

    private static function toRangeInt(mixed $value, int $min, int $max, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        if ($intValue < $min || $intValue > $max) {
            return $fallback;
        }

        return $intValue;
    }

    private static function toInt(mixed $value, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }
        return (int) $value;
    }
}

