# Changelog

All notable changes to MomentumLab Media Folders are documented here.
The project follows [Semantic Versioning](https://semver.org/): MAJOR for breaking changes, MINOR for new features, PATCH for fixes.

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
