# Changelog

All notable changes to MomentumLab Media Folder (called MomentumLab Media Folders up to 1.1.6) are documented here.
The project follows [Semantic Versioning](https://semver.org/): MAJOR for breaking changes, MINOR for new features, PATCH for fixes.

## [2.0.0] - 2026-10-17

### Changed
- The plugin is now called "MomentumLab Media Folder". Slug, folder, main file and text domain are `mlab-media-folder` (before: `mlab-media-folders`). The GitHub repository is `momentumlab-ch/mlab-media-folder`.
- The plugin files sit at the root of the repository, and the release archive `mlab-media-folder-<version>.zip` can be uploaded to WordPress as is.
- Breaking for developers: the filters `mlab_media_folders_use_capability`, `mlab_media_folders_manage_capability` and `mlab_media_folders_update_repository` are now `mlab_media_folder_*`. The REST namespace `mlab-media-folders/v1` is now `mlab-media-folder/v1`. The constant `MLAB_MF_KEEP_DATA` is now `MLAB_MEDIA_FOLDER_KEEP_DATA`; the old name is still honoured.

### Added
- Automatic move from the old plugin: sites on 1.1.6 get a small bridge release under the old name, which installs and activates the new plugin and removes the old one. If the new plugin is uploaded by hand instead, it deactivates and removes the old one itself. The old plugin's uninstall routine never runs, so folders and file assignments stay. The auto-update setting carries over.

## [1.1.6] - 2026-10-17

### Fixed
- Folder counts showed files that had been deleted or moved to the trash. Counts are now taken from the files that actually exist, not from the stored term count.
- Deleting files in the grid view now updates the folder counts without a page reload.

### Added
- "Media Folders by MomentumLab" below the folder panel links to momentumlab.ch.

## [1.1.5] - 2026-10-10

### Changed
- Tested with WordPress 7.1.3.

### Fixed
- The update list showed "Compatibility with WordPress: not tested". The update check now passes "Tested up to", "Requires at least" and "Requires PHP" from the release's readme.txt to WordPress.

## [1.1.4] - 2026-10-10

### Added
- Plugin icon (SVG, 128 and 256 px PNG in `assets/images/`), shown under Dashboard > Updates and in the details dialog.

## [1.1.3] - 2026-10-10

### Changed
- The update check keeps the GitHub answer for 1 hour instead of 12, so a new release shows up with WordPress' next update check instead of up to 12 hours later.
- Releases are now published automatically when a new version lands on `main` (no tag needed).

## [1.1.2] - 2026-10-10

### Fixed
- "View details" on the Plugins page no longer says "Plugin not found" when no newer GitHub release exists. The dialog is built from the installed plugin (description, requirements, full changelog) and adds the notes of a newer release when there is one.

## [1.1.1] - 2026-10-10

### Fixed
- The "Enable auto-updates" link now shows on the Plugins page even when no newer release exists or GitHub cannot be reached. Previously WordPress only offered it once a GitHub release had been found.

## [1.1.0] - 2026-10-10

### Added
- Automatic updates from the GitHub releases of `momentumlab-ch/wp-media-folder`: WordPress shows new versions under Dashboard > Updates and on the Plugins page, with release notes under "View details". One-click updates and auto-updates work as for any other plugin.
- Filter `mlab_media_folders_update_repository` to point the update check at another repository.

## [1.0.2] - 2026-10-10

### Added
- Collapse button that shrinks the folder panel to a narrow strip.
- Resizable folder panel: drag the right edge (180 to 480 px), use the arrow keys on the handle, double-click to reset. Width and collapsed state are stored per browser.

### Changed
- Default panel width reduced from 260 px to 220 px.
- The panel stays next to the files down to 782 px (previously it stacked above them below 960 px); stacked, it is limited to 240 px height.

## [1.0.1] - 2026-10-10

### Fixed
- New uploads now appear right away (with progress) while a folder is selected; previously the grid only showed them after a page reload.
- "All files" removes the folder argument instead of sending an empty one.
- Refreshing the grid after moving files no longer uses a cache-busting prop that stopped upload tracking.

## [1.0.0] - 2026-10-10

### Added
- Private hierarchical taxonomy `mlab_media_folder` for attachments.
- Folder sidebar on the media library (grid and list view): nested tree, counts, "All files" and "Unfiled".
- Create, rename, move (drag and drop) and delete folders. Deleting moves files and subfolders up one level.
- Drag and drop of single or multiple files onto folders.
- Uploads go into the open folder.
- Folder filter in all media dialogs and a folder field in the attachment details.
- REST API `mlab-media-folders/v1` with capability checks and input validation.
- German translations (de_DE, de_CH).
- Uninstall routine (opt out with `MLAB_MF_KEEP_DATA`).
