=== MomentumLab Media Folders ===
Contributors: momentumlab
Tags: media, folders, media library, organize, drag and drop
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.1
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
* No external requests, no tracking, no custom database tables (uses a private taxonomy).

Developer hooks

* `mlab_media_folders_use_capability` (default `upload_files`): who can see folders and move files.
* `mlab_media_folders_manage_capability` (default `upload_files`): who can create, rename, move and delete folders.
* REST namespace `mlab-media-folders/v1`: `GET/POST /folders`, `PATCH/DELETE /folders/{id}`, `POST /assign`.
* Define `MLAB_MF_KEEP_DATA` as `true` to keep the folders when the plugin is deleted.

== Changelog ==

= 1.0.1 =
* Fix: new uploads now appear right away (with progress) while a folder is selected, and folder counts update after the upload.
* Fix: "All files" no longer adds an empty folder argument to the media query.

= 1.0.0 =
* First release.
