<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.r3dnextcloudgallery
 *
 * @copyright   (C) 2026 Richard Dvorak / R3D
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.r3dnextcloudgallery
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Filesystem\File;
use Joomla\CMS\Installer\InstallerAdapter;

final class PlgFieldsR3dnextcloudgalleryInstallerScript
{
    public function install(InstallerAdapter $adapter): bool
    {
        $this->cleanupLegacyFieldTypeFiles();

        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        $this->cleanupLegacyFieldTypeFiles();

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        $this->cleanupLegacyFieldTypeFiles();

        return true;
    }

    private function cleanupLegacyFieldTypeFiles(): void
    {
        $base = rtrim(JPATH_ROOT, '/\\') . '/plugins/fields/r3dnextcloudgallery/fields';
        $legacyFiles = [
            $base . '/admin_widget.php',
        ];

        foreach ($legacyFiles as $file) {
            if (is_file($file)) {
                File::delete($file);
            }
        }
    }
}


