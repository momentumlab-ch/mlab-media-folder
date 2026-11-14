# Changelog

All notable changes to MomentumLab Media Folder (called MomentumLab Media Folders up to 1.1.6) are documented here.
The project follows [Semantic Versioning](https://semver.org/): MAJOR for breaking changes, MINOR for new features, PATCH for fixes.

## [2.3.1] - 2026-11-14

### Changed
- The media library screen (grid and list) shows each file once. Entries whose file is missing on the server are hidden, and when several entries point to the same file only the entry with the lowest ID is listed. The folder counters, "All files" and the "To do" views "Unfiled", "No alt text" and "Not in a page" count exactly these images, so a counter always equals the images shown. The views "Duplicates" and "File missing" still list the hidden entries so they can be cleaned up. The tooltip of "All files" shows both numbers.
- The media dialogs of the editor are not affected: they keep listing every entry, so a post can still open any entry it uses. Only requests with the new query key `mlab_clean` (sent by the library screen) hide entries.
- `Folders::totals()` also returns `entries` (the number of library entries).

### Fixed
- Moving the kept entry of a duplicate group to the trash (or restoring it) updated the hidden entries only after up to an hour. The scan is now cleared on trash and restore.

## [2.3.0] - 2026-11-14

### Added
- "Duplicates" view under "To do": entries (attachments) that point to the same file in the uploads folder. The media library lists database entries, not files, so the same image appears once per entry. The "Origin" field names the other entries that share the file.
- "File missing" view under "To do": entries whose file is no longer in the uploads folder. Only the newest 5000 entries are checked (filter `mlab_media_folder_missing_scan_limit`); files stored outside the server are skipped (filter `mlab_media_folder_check_missing_files` turns the check off). The scan is cached for one hour and cleared when a file is added or deleted.
- Query values `duplicate` and `missing`; the media dialogs offer both in the folder filter.

### Fixed
- Deleting an entry also deleted the file on disk, even when another entry still used it, which left the other entry with a broken image. The file is now kept while another entry points to it.

## [2.2.0] - 2026-11-14

### Added
- A workflow in the folder panel. "Next step" points to the first task that still has files. The "To do" list has three views with counts: "Unfiled", "No alt text" (images with an empty alt text) and "Not in a page" (files that were not uploaded to a page or post). A note above the files says where the files were found and what to do, with a way back to all files.
- "Origin" field in the attachment details (grid, dialogs, edit screen): who uploaded the file and when, the page or post it was uploaded to, the posts it is the featured image of, its folder path and open tasks (no folder, no alternative text).
- "Move to…" in the selection bar. It moves the selected files without dragging, so it works on touch screens.
- "Undo" after moving files. The REST response of `POST /assign` now also contains `previous` (file ID => folder before the move).
- Search field above the folders (shown from 8 folders), arrow-key navigation in the folder list, an empty state with a hint when there are no folders.
- The folder filter of the media dialogs offers the views "No alt text" and "Not in a page". Query values `noalt` and `unattached` next to `none`.

### Changed
- New look following Apple's Human Interface Guidelines: sections like a Finder sidebar, filled selection in the accent colour, soft surfaces, tap targets of 44 points on touch screens, "reduce motion" respected.
- Deleting a folder is confirmed next to the folder instead of in a browser dialog.
- Error message now says what to do ("Check your connection and try again.").

### Fixed
- Moving more than 500 files at once moved only the first 500 without saying so. The move now runs in blocks and reports files that were skipped for lack of permission.
- The counts did not refresh after alternative text or other details were saved in the grid.
- "Not in a page" and "No alt text" cannot be used as drop targets; uploads made while such a view is open no longer send it as a folder.

## [2.1.1] - 2026-10-17

### Fixed
- The bar at the bottom of the grid could stay on screen after "Bulk select" was switched off. It now follows the select mode itself and no longer relies on one event of the media library.
- "Select all" switches on bulk select if it is off, so the selection is visible.
- Selecting by dragging no longer depends on the exact markup around the grid, and the browser's own image drag is switched off while a selection is dragged.
- Dragging files onto a folder no longer shows the "Drop files to upload" notice of the media library.

### Changed
- Documented which drags select and which move: with "Bulk select" on, a drag that starts on a file selects files; with it off, dragging a file moves it to a folder, and a drag that starts between files switches bulk select on.

## [2.1.0] - 2026-10-17

### Added
- Select several files in the grid by dragging across them with the mouse button held down. The drag can start on a file or in the space between files and switches on "Bulk select". The page scrolls along when the pointer reaches the top or bottom edge, and Esc cancels the selection made by that drag.
- A bar at the bottom of the grid while "Bulk select" is on: number of selected files, "Select all" (every file of the open folder, loading the rest first), "Delete permanently" or "Move to Trash" for the whole selection, and "Cancel". It runs core's own bulk action, so core's confirmation, the trash setting and user permissions apply.

### Changed
- Dragging a selected file onto a folder still moves the whole selection; outside "Bulk select" dragging a file works as before.

### Removed
- The move from the old plugin name (`mlab-media-folders`, up to 1.1.6): the code that removed the old plugin, the bridge archive that releases carried for it, and the old version option. All sites run 2.0.0 or newer. The old constant `MLAB_MF_KEEP_DATA` is still honoured on uninstall.

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
