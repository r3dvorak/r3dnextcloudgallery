<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.r3dnextcloudgallery
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
use R3d\Plugin\Fields\R3dnextcloudgallery\Extension\R3dnextcloudgallery;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new R3dnextcloudgallery(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('fields', 'r3dnextcloudgallery')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};

