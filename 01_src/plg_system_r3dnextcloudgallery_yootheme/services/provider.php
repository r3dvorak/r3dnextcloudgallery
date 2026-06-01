<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.r3dnextcloudgallery_yootheme
 *
 * @copyright   (C) 2026 Richard Dvorak / R3D
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use R3d\Plugin\System\R3dnextcloudgalleryYootheme\Extension\R3dnextcloudgalleryYootheme;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new R3dnextcloudgalleryYootheme(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'r3dnextcloudgallery_yootheme')
                );
                $plugin->setApplication(Factory::getApplication());
                return $plugin;
            }
        );
    }
};

