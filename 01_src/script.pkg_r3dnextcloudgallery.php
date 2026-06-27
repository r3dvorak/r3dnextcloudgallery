<?php
/**
 * @package     pkg_r3dnextcloudgallery
 * @version     2.0.10
 * @date        2026-06-01
 * @author      Richard Dvorak, <dev@r3d.de> - https://www.r3d.de
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Adapter\PackageAdapter;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;

class R3dnextcloudgalleryInstallerScript extends InstallerScript
{
    protected $minimumPhp = '8.2';
    protected $minimumJoomla = '6.0';

    public function preflight($type, $parent)
    {
        if (!parent::preflight($type, $parent)) {
            return false;
        }

        return true;
    }

    public function postflight($type, PackageAdapter $parent): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        $pluginsUrl = 'index.php?option=com_plugins&view=plugins&filter[search]=R3D';
        $message = Text::sprintf(
            'PKG_R3DNEXTCLOUDGALLERY_POSTINSTALL_ENABLE_BOTH',
            $pluginsUrl
        );

        Factory::getApplication()->enqueueMessage(
            $message,
            'success'
        );

        return true;
    }
}
