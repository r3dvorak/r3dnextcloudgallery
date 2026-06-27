<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.r3dnextcloudgallery
 *
 * @copyright   (C) 2026 Richard Dvorak / R3D
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$fieldId = (int) ($data['fieldId'] ?? 0);
$articleId = (int) ($data['articleId'] ?? 0);
$shareUrl = (string) ($data['shareUrl'] ?? '');
$galleryTitle = (string) ($data['galleryTitle'] ?? '');
$tokenKey = (string) ($data['tokenKey'] ?? '');
?>
<div class="r3d-nextcloud-gallery-actions"
     data-r3dncg-actions="1"
     data-field-id="<?php echo $fieldId; ?>"
     data-article-id="<?php echo $articleId; ?>"
     data-share-url="<?php echo htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8'); ?>"
     data-token-key="<?php echo htmlspecialchars($tokenKey, ENT_QUOTES, 'UTF-8'); ?>">
  <div class="r3d-nextcloud-gallery-actions__row">
    <label for="r3dncg-share-url-<?php echo $fieldId; ?>" class="form-label"><?php echo Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_SHARE_URL'); ?></label>
    <input
      id="r3dncg-share-url-<?php echo $fieldId; ?>"
      type="url"
      class="form-control form-control-sm"
      data-r3dncg-share-url-input="1"
      placeholder="<?php echo htmlspecialchars(Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_SHARE_URL_PLACEHOLDER'), ENT_QUOTES, 'UTF-8'); ?>"
      value="<?php echo htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8'); ?>">
  </div>
  <div class="r3d-nextcloud-gallery-actions__row">
    <label for="r3dncg-gallery-title-<?php echo $fieldId; ?>" class="form-label"><?php echo Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_GALLERY_TITLE'); ?></label>
    <input
      id="r3dncg-gallery-title-<?php echo $fieldId; ?>"
      type="text"
      class="form-control form-control-sm"
      data-r3dncg-gallery-title-input="1"
      placeholder="<?php echo htmlspecialchars(Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_GALLERY_TITLE_PLACEHOLDER'), ENT_QUOTES, 'UTF-8'); ?>"
      value="<?php echo htmlspecialchars($galleryTitle, ENT_QUOTES, 'UTF-8'); ?>">
  </div>
  <div class="r3d-nextcloud-gallery-actions__row">
  <button type="button" class="btn btn-sm btn-secondary" data-r3dncg-action="import"><?php echo Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_IMPORT'); ?></button>
  <button type="button" class="btn btn-sm btn-outline-secondary" data-r3dncg-action="reimport"><?php echo Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_REIMPORT'); ?></button>
  <button type="button" class="btn btn-sm btn-outline-primary" data-r3dncg-action="save_meta"><?php echo Text::_('PLG_FIELDS_R3DNEXTCLOUDGALLERY_UI_SAVE'); ?></button>
  </div>
</div>
