# Changelog

All notable changes to MomentumLab Media Folders are documented here.
The project follows [Semantic Versioning](https://semver.org/): MAJOR for breaking changes, MINOR for new features, PATCH for fixes.

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
