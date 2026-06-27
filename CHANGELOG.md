# Changelog

## [2.0.10] - 2026-06-27

- Fix: Lösch-Buttons im Admin-Galerie-Editor wieder auf stabilen Galerie-Root scopen.
- Fix: Einzel-Papierkorb und "Auswahl löschen" nutzen jetzt denselben Instanz-Context.
- Fix: Backend-Fehlerdiagnose für AJAX-Aktionen verbessert.
- Fix: `updateImageCaptions()` liefert bei Fehlern jetzt ein leeres Result-Array statt eines falschen Typs.
- Asset-Cachebuster auf `1.5.7` erhöht.

## [2.0.9] - 2026-06-01

- JED-Checker-Fix: Package-Manifest-`<name>` von technischem Prefix (`pkg_r3dnextcloudgallery`) auf Listing-Namen (`R3D Nextcloud Gallery`) umgestellt.
- Package auf `2.0.9` angehoben.

## [2.0.8] - 2026-06-01

- Neu: `Galerie in Content Prepare anhängen` und `Frontend-Fallback-Injektion` jetzt pro Feld konfigurierbar.
- Beide Feldoptionen unterstützen `Use Global` als Standard (übernimmt Plugin-Einstellung).
- Render-Logik respektiert die Schalter jetzt pro Feld:
  - ContentPrepare rendert nur Felder mit aktivem ContentPrepare-Flag.
  - AfterRender rendert nur Felder mit aktivem Fallback-Flag.
- `plg_fields_r3dnextcloudgallery` auf `2.0.6` und Package auf `2.0.8` erhöht.

## [2.0.7] - 2026-06-01

- Frontend-Inject-Position korrigiert: Galerie wird jetzt nach dem letzten Absatz (`</p>`) innerhalb des Artikels eingefügt.
- Fallback bleibt: wenn kein Absatz gefunden wird, Einfügen vor `</article>`, danach vor `</main>`.
- `plg_fields_r3dnextcloudgallery` auf `2.0.5` und Package auf `2.0.7` erhöht.

## [2.0.6] - 2026-06-01

- Frontend-Inject repariert für YOOtheme-Szenarien ohne Bridge-Integration.
- `onContentPrepare` nutzt jetzt `com_content`-Kontextprüfung statt harter `view=article`-Abbruchbedingung.
- `onAfterRender` ermittelt Artikel-ID robuster (Request-ID oder aktive Menü-Query) und injiziert dann die Galerie.
- `plg_fields_r3dnextcloudgallery` auf `2.0.4` und Package auf `2.0.6` erhöht.

## [2.0.5] - 2026-06-01

- Fix: Checkbox `Alle` im Admin-Caption-Editor funktioniert wieder.
- Master-Checkbox setzt/entfernt jetzt alle Bild-Checkboxen im jeweiligen Feld-Widget.
- Scope-Fix: `Delete selected` arbeitet nur noch auf dem aktiven Widget statt global über die Seite.
- Asset-Version auf `1.5.6` erhöht (Cache-Busting für `field.js`).
- `plg_fields_r3dnextcloudgallery` auf `2.0.3` und Package auf `2.0.5` erhöht.

## [2.0.4] - 2026-06-01

- Default geändert: `enforce_allowed_share_hosts` ist jetzt `Nein` (`0`).
- Frontend-Fallback-Injection standardmäßig aktiviert, damit Galerien in mehr Layouts zuverlässig sichtbar sind.
- Bildimport: EXIF-Orientation wird berücksichtigt (Auto-Rotation für 90/180/270 Grad inkl. Spiegelungsfälle).
- `plg_fields_r3dnextcloudgallery` auf `2.0.2` erhöht, Package auf `2.0.4`.

## [2.0.3] - 2026-06-01

- Fix: Entfernt beim Install/Update die Altdatei `plugins/fields/r3dnextcloudgallery/fields/admin_widget.php`.
- Dadurch verschwindet der unerwünschte Feldtyp `R3DNEXTCLOUDGALLERY_admin_widget` aus der Feldauswahl.
- `plg_fields_r3dnextcloudgallery` auf `2.0.1` erhöht, Package auf `2.0.3`.

## [2.0.2] - 2026-06-01

- Package auf EN/DE i18n umgestellt (`PKG_R3DNEXTCLOUDGALLERY_*` Sprachkeys).
- Paket-Manifest-Beschreibung nutzt jetzt Sprachkey statt festem Text.
- Post-Install-Meldung enthält klickbaren Link zur Plugin-Verwaltung mit Suchfilter `R3D`.
- `project.json`, `VERSION` und Paket-Manifest auf `2.0.2` angehoben.

## [2.0.1] - 2026-06-01

- Paket-Installationshinweis erweitert: Aktivierung beider Plugins ist jetzt explizit genannt.
- Postflight-Meldung enthält direkten Link zur Plugin-Verwaltung mit Suchfilter `r3dnextcloudgallery`.
- `project.json`, `VERSION` und Paket-Manifest auf `2.0.1` angehoben.

## [2.0.0] - 2026-06-01

- Erstes Uptick im Package-Projekt auf `2.0.0`.
- `plg_fields_r3dnextcloudgallery` auf `2.0.0` angehoben.
- `pkg_r3dnextcloudgallery` auf `2.0.0` synchronisiert.

## [1.5.5] - 2026-06-01

- Package-Projekt `pkg_r3dnextcloudgallery` initial aufgebaut.
- Child-Plugins aus `01_src` integriert.
- Package-Manifest, Installer-Script, Build-Wrapper und Workspace ausgerichtet.
