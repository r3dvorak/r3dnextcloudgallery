<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.r3dnextcloudgallery_yootheme
 *
 * @copyright   (C) 2026 Richard Dvorak / R3D
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace R3d\Plugin\System\R3dnextcloudgalleryYootheme\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use R3d\Plugin\System\R3dnextcloudgalleryYootheme\Yootheme\FieldSourceRegistrar;

final class R3dnextcloudgalleryYootheme extends CMSPlugin
{
    protected $autoloadLanguage = true;

    public function onAfterInitialise(): void
    {
        if (!$this->isSupportedRuntime()) {
            return;
        }

        if ((int) $this->params->get('enabled', 1) !== 1) {
            return;
        }

        FieldSourceRegistrar::register();
    }

    public function onAfterRoute(): void
    {
        if (!$this->isSupportedRuntime()) {
            return;
        }

        if ((int) $this->params->get('enabled', 1) !== 1) {
            return;
        }

        FieldSourceRegistrar::register();
    }

    private function isSupportedRuntime(): bool
    {
        $app = $this->getApplication();

        if ($app->isClient('site')) {
            return true;
        }

        if (!$app->isClient('administrator')) {
            return false;
        }

        $input = $app->input;
        if ($input->getCmd('option') !== 'com_ajax') {
            return false;
        }

        $p = $input->getCmd('p');

        if ($p === 'page' || $p === 'customizer') {
            return true;
        }

        $p = (string) $p;
        if (str_starts_with($p, 'builder') || str_starts_with($p, 'theme')) {
            return true;
        }

        return false;
    }
}
