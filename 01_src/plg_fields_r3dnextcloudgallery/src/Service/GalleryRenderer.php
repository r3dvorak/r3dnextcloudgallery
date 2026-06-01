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

final class GalleryRenderer
{
    public function renderFromGalleryJsonPath(string $galleryJsonPath, array $options = []): string
    {
        if (!is_file($galleryJsonPath)) {
            return '';
        }

        $decoded = json_decode((string) file_get_contents($galleryJsonPath), true);

        if (!is_array($decoded)) {
            return '';
        }

        $decoded = (new FieldValueMapper())->normalizeGalleryArray($decoded);
        $images = (array) ($decoded['images'] ?? []);

        if ($images === []) {
            return '';
        }

        return $this->renderImages($images, $options);
    }

    public function renderLegacyItems(array $items, array $options = []): string
    {
        if ($items === []) {
            return '';
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $file = trim((string) ($item['image'] ?? $item['original'] ?? ''));
            $thumb = trim((string) ($item['thumbnail'] ?? $file));
            if ($file === '' || $thumb === '') {
                continue;
            }

            $normalized[] = [
                'file' => ltrim($file, '/'),
                'thumb' => ltrim($thumb, '/'),
                'alt' => (string) ($item['alt'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
                'caption' => (string) ($item['caption'] ?? ''),
                'source_name' => basename($file),
                'width' => (int) ($item['width'] ?? 0),
                'height' => (int) ($item['height'] ?? 0),
                'thumb_width' => (int) ($item['thumb_width'] ?? 0),
                'thumb_height' => (int) ($item['thumb_height'] ?? 0),
                'large_width' => (int) ($item['large_width'] ?? 0),
                'large_height' => (int) ($item['large_height'] ?? 0),
            ];
        }

        if ($normalized === []) {
            return '';
        }

        return $this->renderImages($normalized, $options);
    }

    private function renderImages(array $images, array $options): string
    {
        $layout = (string) ($options['frontend_layout'] ?? 'masonry');
        $useMasonry = $layout === 'masonry';
        $lightboxMode = (string) ($options['frontend_lightbox'] ?? 'builtin');
        $thumbMinWidth = max(120, (int) ($options['thumb_min_width'] ?? 240));
        $thumbMaxWidth = max($thumbMinWidth, (int) ($options['thumb_max_width'] ?? 480));
        $gap = max(0, (int) ($options['gap'] ?? 15));
        $mobileColumns = max(1, min(4, (int) ($options['columns_mobile'] ?? 2)));
        $tabletColumns = max(1, min(6, (int) ($options['columns_tablet'] ?? 3)));
        $desktopColumns = max(1, min(6, (int) ($options['columns_desktop'] ?? 4)));
        $layoutClass = $useMasonry ? 'r3d-nextcloud-gallery--masonry r3d-nextcloud-gallery--js-masonry' : 'r3d-nextcloud-gallery--grid';
        $galleryTitle = trim((string) ($options['gallery_title'] ?? ''));

        $html = [];
        $mapper = new FieldValueMapper();
        $html[] = '<section class="r3d-nextcloud-gallery-wrap">';
        if ($galleryTitle !== '') {
            $html[] = '<h2 class="r3d-nextcloud-gallery__title">' . htmlspecialchars($galleryTitle, ENT_QUOTES, 'UTF-8') . '</h2>';
        }

        $containerAttrs = [
            'class="r3d-nextcloud-gallery r3d-nextcloud-gallery--dynamic-cols ' . $layoutClass . '"',
            'data-r3dncg-lightbox="' . htmlspecialchars($lightboxMode, ENT_QUOTES, 'UTF-8') . '"',
            'data-r3dncg-lg-zoom="' . (int) ($options['lightgallery_zoom'] ?? 1) . '"',
            'data-r3dncg-lg-fullscreen="' . (int) ($options['lightgallery_fullscreen'] ?? 1) . '"',
            'data-r3dncg-lg-autoplay="' . (int) ($options['lightgallery_autoplay'] ?? 1) . '"',
            'data-r3dncg-lg-thumbnails="' . (int) ($options['lightgallery_thumbnails'] ?? 0) . '"',
            'data-r3dncg-lg-share="' . (int) ($options['lightgallery_share'] ?? 0) . '"',
            'data-r3dncg-lg-rotate="' . (int) ($options['lightgallery_rotate'] ?? 0) . '"',
            'data-r3dncg-lg-hash="' . (int) ($options['lightgallery_hash'] ?? 1) . '"',
            'data-r3dncg-lg-download="' . (int) ($options['lightgallery_download'] ?? 0) . '"',
            'data-r3dncg-lg-autoplay-interval="' . max(1000, (int) ($options['lightgallery_autoplay_interval'] ?? 5000)) . '"',
            'data-r3dncg-built-in-fullscreen="' . (int) ($options['built_in_fullscreen'] ?? 1) . '"',
            'data-r3dncg-built-in-slideshow="' . (int) ($options['built_in_slideshow'] ?? 0) . '"',
            'style="--r3d-nextcloud-gallery-gap:' . $gap . 'px;--r3d-nextcloud-gallery-thumb-min:' . $thumbMinWidth . 'px;--r3d-nextcloud-gallery-thumb-max:' . $thumbMaxWidth . 'px;--r3d-nextcloud-gallery-cols-mobile:' . $mobileColumns . ';--r3d-nextcloud-gallery-cols-tablet:' . $tabletColumns . ';--r3d-nextcloud-gallery-cols-desktop:' . $desktopColumns . ';"',
        ];
        $html[] = '<div ' . implode(' ', $containerAttrs) . '>';

        foreach ($images as $image) {
            $file = (string) ($image['file'] ?? '');
            $thumb = (string) ($image['thumb'] ?? '');
            $alt = trim((string) ($image['alt'] ?? ''));
            $title = trim((string) ($image['title'] ?? ''));
            $caption = trim((string) ($image['caption'] ?? ''));
            $sourceName = (string) ($image['source_name'] ?? basename($file));

            if ($file === '' || $thumb === '') {
                continue;
            }

            $fileUrl = '/' . ltrim($file, '/');
            $thumbUrl = '/' . ltrim($thumb, '/');
            $altText = $mapper->resolveAltFallback($alt, '', '', $sourceName);
            $altEsc = htmlspecialchars($altText, ENT_QUOTES, 'UTF-8');
            $hoverText = $title !== '' ? $title : $caption;
            $hoverEsc = htmlspecialchars($hoverText, ENT_QUOTES, 'UTF-8');

            $thumbWidth = (int) ($image['thumb_width'] ?? 0);
            $thumbHeight = (int) ($image['thumb_height'] ?? 0);
            $imgWhAttr = ($thumbWidth > 0 && $thumbHeight > 0)
                ? ' width="' . $thumbWidth . '" height="' . $thumbHeight . '"'
                : '';

            $largeWidth = (int) ($image['large_width'] ?? 0);
            $largeHeight = (int) ($image['large_height'] ?? 0);
            if ($largeWidth <= 0 || $largeHeight <= 0) {
                $largeWidth = (int) ($image['width'] ?? 0);
                $largeHeight = (int) ($image['height'] ?? 0);
            }

            $linkAttrs = [
                'href="' . htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') . '"',
                'rel="noopener"',
                'data-r3dncg-item="1"',
                'data-r3dncg-src="' . htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') . '"',
                'data-r3dncg-thumb="' . htmlspecialchars($thumbUrl, ENT_QUOTES, 'UTF-8') . '"',
                'data-r3dncg-caption="' . $hoverEsc . '"',
                'data-r3dncg-lightbox="' . htmlspecialchars($lightboxMode, ENT_QUOTES, 'UTF-8') . '"',
            ];
            if ($largeWidth > 0 && $largeHeight > 0) {
                $linkAttrs[] = 'data-r3dncg-width="' . $largeWidth . '"';
                $linkAttrs[] = 'data-r3dncg-height="' . $largeHeight . '"';
            }

            $html[] = '<figure class="r3d-nextcloud-gallery__item">';
            $html[] = '<a ' . implode(' ', $linkAttrs) . '>';
            $html[] = '<img src="' . htmlspecialchars($thumbUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $altEsc . '" loading="lazy" decoding="async" fetchpriority="low"' . $imgWhAttr . '>';
            if ($hoverText !== '') {
                $html[] = '<span class="r3d-nextcloud-gallery__hover-title">' . $hoverEsc . '</span>';
            }
            $html[] = '</a>';
            $html[] = '</figure>';
        }

        $html[] = '</div>';
        $html[] = '</section>';

        return implode('', $html);
    }
}

