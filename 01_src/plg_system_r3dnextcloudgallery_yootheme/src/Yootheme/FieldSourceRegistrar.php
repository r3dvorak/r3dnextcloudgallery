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

use YOOtheme\Application as YooApplication;
use YOOtheme\Event;

final class FieldSourceRegistrar
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        if (!class_exists(YooApplication::class, false) || !class_exists(Event::class)) {
            return;
        }

        Event::on('source.com_fields.field', [FieldSourceResolver::class, 'configureField']);
        Event::on('source.com_fields.field|filter', [FieldSourceResolver::class, 'configureField']);
        self::$registered = true;
    }
}
