=== MomentumLab Media Folder ===
Contributors: momentumlab
Tags: media, folders, media library, organize, drag and drop
Requires at least: 6.5
Tested up to: 7.1.3
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Folders for the WordPress media library: folder tree, drag and drop, upload into the open folder and a folder filter in every media dialog.

== Description ==

* Folder tree next to the media library (grid and list view), with nested folders.
* Create, rename, move (drag a folder onto another) and delete folders.
* Move files by dragging them onto a folder. In bulk-select mode all selected files move together.
* "All files" and "Unfiled" views with counts.
* New uploads land in the folder that is open.
* "Folder" filter in every media dialog (block editor, gallery, featured image).
* "Folder" field in the attachment details, as a keyboard-friendly alternative to drag and drop.
* Deleting a folder never deletes files: files and subfolders move up one level.
* Folders only organise the admin. File URLs on the website do not change.

Security

* Every REST route checks capabilities (`upload_files` by default) and uses the core REST nonce.
* Each moved file is checked against `edit_post` for the current user.
* Input is validated (types, ranges, existing folders, no cycles) and folder names are sanitised and escaped on output.
* No tracking and no custom database tables (uses a private taxonomy). The only external request is the update check against the public GitHub releases of the plugin (when WordPress checks for updates, no site data is sent).

Developer hooks

* `mlab_media_folder_use_capability` (default `upload_files`): who can see folders and move files.
* `mlab_media_folder_manage_capability` (default `upload_files`): who can create, rename, move and delete folders.
* REST namespace `mlab-media-folder/v1`: `GET/POST /folders`, `PATCH/DELETE /folders/{id}`, `POST /assign`.
* `mlab_media_folder_update_repository` (default `momentumlab-ch/mlab-media-folder`): GitHub repository that is checked for updates.
* Define `MLAB_MEDIA_FOLDER_KEEP_DATA` as `true` to keep the folders when the plugin is deleted.

== Changelog ==

= 2.0.0 =
* Change: the plugin is now called "MomentumLab Media Folder" with the slug, folder and text domain `mlab-media-folder` (before: `mlab-media-folders`). Sites on 1.1.6 update automatically: the new plugin is installed and activated, the old one is removed. Folders, file assignments and the auto-update setting are kept.
* Breaking for developers: filters `mlab_media_folders_*` are now `mlab_media_folder_*`, the REST namespace `mlab-media-folders/v1` is now `mlab-media-folder/v1`, the constant `MLAB_MF_KEEP_DATA` is now `MLAB_MEDIA_FOLDER_KEEP_DATA` (the old one is still honoured).
* The plugin files now sit at the root of the GitHub repository.

= 1.1.6 =
* Fix: folder counts no longer include deleted or trashed files, and they update right after files are deleted in the grid view.
* New: "Media Folder by MomentumLab" below the folder panel links to momentumlab.ch.

= 1.1.5 =
* Tested with WordPress 7.1.3.
* Fix: the update list now shows the WordPress and PHP compatibility of the new version instead of "not tested".

= 1.1.4 =
* New: plugin icon, shown under Dashboard > Updates and in the details dialog.

= 1.1.3 =
* Change: the update check keeps the GitHub answer for 1 hour instead of 12, so new releases show up with WordPress' next update check.

= 1.1.2 =
* Fix: "View details" on the Plugins page no longer says "Plugin not found". It shows the description, requirements and the full changelog, plus the notes of a newer release when one exists.

= 1.1.1 =
* Fix: the "Enable auto-updates" link now shows on the Plugins page even when no newer release exists or GitHub cannot be reached.

= 1.1.0 =
* New: WordPress checks the GitHub releases of the plugin for updates. New versions appear under Dashboard > Updates and on the Plugins page and can be installed with one click or by auto-update.

= 1.0.2 =
* New: the folder panel can be collapsed to a narrow strip and resized by dragging its right edge (or with the arrow keys). Both settings are remembered per browser.
* Change: narrower default width (220 px); the panel stays beside the files down to tablet width.

= 1.0.1 =
* Fix: new uploads now appear right away (with progress) while a folder is selected, and folder counts update after the upload.
* Fix: "All files" no longer adds an empty folder argument to the media query.

= 1.0.0 =
* First release.
