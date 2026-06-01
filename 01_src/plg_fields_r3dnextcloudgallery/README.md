# plg_fields_r3dnextcloudgallery (Plugin Source)

Joomla Fields Plugin (`group=fields`, `element=r3dnextcloudgallery`) fuer Nextcloud-Import und Frontend-Galerie.

## Inhalt

- Manifest: `r3dnextcloudgallery.xml`
- Einstieg: `r3dnextcloudgallery.php`
- Plugin-Klasse: `src/Extension/R3dnextcloudgallery.php`
- Service Provider: `services/provider.php`
- Renderer: `src/Service/GalleryRenderer.php`
- Sprachen: `language/en-GB`, `language/de-DE`
- Assets: `media/plg_fields_r3dnextcloudgallery/*`
- lightGallery (optional, lokal): `media/plg_fields_r3dnextcloudgallery/vendor/lightgallery/*`

## Lightbox-Architektur

- Neutrales HTML mit `data-r3dncg-*` Attributen
- Engines:
  - `none`
  - `builtin`
  - `lightgallery`
- Built-in bleibt als Fallback und Standard erhalten.

## Lizenzhinweis lightGallery

Die lokal gebuendelte lightGallery-Lizenz liegt in:

- `media/plg_fields_r3dnextcloudgallery/vendor/lightgallery/LICENSE.txt`
