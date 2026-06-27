# Changelog

## [2.1.0] - 2026-06-27

- UX: Added more spacing between the toolbar and the selection row in the admin gallery editor.
- UX: `Save Gallery` now shows a visible confirmation while saving.

## [2.0.14] - 2026-06-27

- Fix: `images/nextcloud-galerie` is now additionally allowed in `allowedGalleryBaseDirectories()`.

## [2.0.13] - 2026-06-27

- Fix: The console debug toggle is now shown in the actually rendered field output.
- Debug: The console event buffer persists across reloads via `sessionStorage`.

## [2.0.12] - 2026-06-27

- UX: Added a visible console debug toggle in the backend.
- Debug: AJAX delete now logs request/response/success/failure in a structured way to the browser console.
- Debug: The most recent debug events are available in `window.__r3dnextcloudgalleryDebugEvents`.
- Fix: Increased the asset cachebuster to `1.5.11`.

## [2.0.11] - 2026-06-27

- UX: Added a visible backend debug block for AJAX errors in the gallery editor.
- Fix: Increased the asset cachebuster to `1.5.9`.
- Fix: The error path now returns structured debug data for `Action failed!`.

## [2.0.10] - 2026-06-27

- Fix: Delete buttons in the admin gallery editor are scoped to a stable gallery root again.
- Fix: Single-item delete and `Delete Selection` now use the same instance context.
- Fix: Backend error diagnostics for AJAX actions improved.
- Fix: `updateImageCaptions()` now returns an empty result array on errors instead of an invalid type.
- Fix: Legacy path formats with a leading slash are now normalized correctly for delete/update operations.
- Fix: Delete/AJAX responses are no longer polluted by deprecated warnings from the YOOtheme system plugin.
- Fix: Stale `gallery_json` path references now fall back to the actually populated gallery.
- Asset cachebuster increased to `1.5.7`.

## [2.0.9] - 2026-06-01

- JED checker fix: Changed the package manifest `<name>` from the technical prefix (`pkg_r3dnextcloudgallery`) to the listing name (`R3D Nextcloud Gallery`).
- Package bumped to `2.0.9`.

## [2.0.8] - 2026-06-01

- New: `Append gallery in content prepare` and `Frontend fallback injection` are now configurable per field.
- Both field options support `Use Global` as the default, which inherits the plugin setting.
- Rendering logic now respects the toggles per field:
  - ContentPrepare renders only fields with the ContentPrepare flag enabled.
  - AfterRender renders only fields with the fallback flag enabled.
- Bumped `plg_fields_r3dnextcloudgallery` to `2.0.6` and the package to `2.0.8`.

## [2.0.7] - 2026-06-01

- Fixed the frontend injection position: the gallery is now inserted after the last paragraph (`</p>`) inside the article.
- Fallback remains: if no paragraph is found, insertion happens before `</article>`, then before `</main>`.
- Bumped `plg_fields_r3dnextcloudgallery` to `2.0.5` and the package to `2.0.7`.

## [2.0.6] - 2026-06-01

- Fixed frontend injection for YOOtheme scenarios without bridge integration.
- `onContentPrepare` now uses a `com_content` context check instead of a hard `view=article` abort condition.
- `onAfterRender` now resolves the article ID more robustly (request ID or active menu query) and then injects the gallery.
- Bumped `plg_fields_r3dnextcloudgallery` to `2.0.4` and the package to `2.0.6`.

## [2.0.5] - 2026-06-01

- Fix: The `All` checkbox in the admin caption editor works again.
- The master checkbox now selects/deselects all image checkboxes in the active field widget.
- Scope fix: `Delete selected` now operates only on the active widget instead of globally across the page.
- Asset version increased to `1.5.6` for `field.js` cache busting.
- Bumped `plg_fields_r3dnextcloudgallery` to `2.0.3` and the package to `2.0.5`.

## [2.0.4] - 2026-06-01

- Changed default: `enforce_allowed_share_hosts` is now `No` (`0`).
- Frontend fallback injection is enabled by default so galleries remain visible in more layouts.
- Image import now respects EXIF orientation, including auto-rotation for 90/180/270 degrees and mirrored cases.
- Bumped `plg_fields_r3dnextcloudgallery` to `2.0.2` and the package to `2.0.4`.

## [2.0.3] - 2026-06-01

- Fix: The old file `plugins/fields/r3dnextcloudgallery/fields/admin_widget.php` is removed during install/update.
- This prevents the unwanted field type `R3DNEXTCLOUDGALLERY_admin_widget` from appearing in the field selector.
- Bumped `plg_fields_r3dnextcloudgallery` to `2.0.1` and the package to `2.0.3`.

## [2.0.2] - 2026-06-01

- Package switched to EN/DE i18n (`PKG_R3DNEXTCLOUDGALLERY_*` language keys).
- Package manifest description now uses a language key instead of hardcoded text.
- Post-install message includes a clickable link to the plugin manager filtered by `R3D`.
- Bumped `project.json`, `VERSION`, and the package manifest to `2.0.2`.

## [2.0.1] - 2026-06-01

- Expanded the package install note: activation of both plugins is now explicitly mentioned.
- Postflight message includes a direct link to the plugin manager filtered by `r3dnextcloudgallery`.
- Bumped `project.json`, `VERSION`, and the package manifest to `2.0.1`.

## [2.0.0] - 2026-06-01

- First package uptick to `2.0.0`.
- Bumped `plg_fields_r3dnextcloudgallery` to `2.0.0`.
- Synchronized `pkg_r3dnextcloudgallery` to `2.0.0`.

## [1.5.5] - 2026-06-01

- Initial package project `pkg_r3dnextcloudgallery` created.
- Child plugins integrated from `01_src`.
- Package manifest, installer script, build wrapper, and workspace aligned.
