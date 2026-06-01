<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.r3dnextcloudgallery_yootheme
 *
 * @copyright   (C) 2026 Richard Dvorak / R3D
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Version;

class PlgSystemR3dnextcloudgallery_yoothemeInstallerScript
{
    public function preflight($type, $parent): bool
    {
        $joomlaOk = version_compare((new Version())->getShortVersion(), '4.4.0', '>=');
        $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');

        if (!$joomlaOk || !$phpOk) {
            if (!$joomlaOk) {
                echo Text::_('PLG_SYSTEM_R3DNEXTCLOUDGALLERY_YOOTHEME_ERR_JOOMLA_VERSION');
            }
            if (!$phpOk) {
                echo Text::_('PLG_SYSTEM_R3DNEXTCLOUDGALLERY_YOOTHEME_ERR_PHP_VERSION');
            }
            return false;
        }

        return true;
    }
}

