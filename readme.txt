=== MomentumLab Media Folder ===
Contributors: momentumlab
Tags: media, folders, media library, organize, drag and drop
Requires at least: 6.5
Tested up to: 7.1.3
Requires PHP: 8.0
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Folders for the WordPress media library: folder tree, drag and drop, upload into the open folder and a folder filter in every media dialog.

== Description ==

* Folder tree next to the media library (grid and list view), with nested folders.
* Create, rename, move (drag a folder onto another) and delete folders.
* Move files by dragging them onto a folder. In bulk-select mode all selected files move together.
* "All files" and "Unfiled" views with counts.
* Media Health runs resumable full-library checks for missing files and SHA-256 content duplicates, with a protected manual rescan action.
* "Duplicates" finds both repeated attachment paths and copied files with identical content while keeping independent physical files visible until you decide what to remove.
* New uploads land in the folder that is open.
* "Folder" filter in every media dialog (block editor, gallery, featured image).
* "Folder" field in the attachment details, as a keyboard-friendly alternative to drag and drop.
* Deleting a folder never deletes files: files and subfolders move up one level.
* Folders only organise the admin. File URLs on the website do not change.

Security

* Every REST route checks capabilities (`upload_files` by default) and uses the core REST nonce.
* Each moved file is checked against `edit_post` for the current user.
* Input is validated (types, ranges, existing folders, no cycles) and folder names are sanitised and escaped on output.
* No tracking and no custom database tables (uses a private taxonomy). Standalone installations only contact the public GitHub releases during WordPress update checks. If MomentumLab Core manages this plugin, the standalone GitHub updater does not register and Core/License Server becomes the single update path.

Developer hooks

* `mlab_media_folder_use_capability` (default `upload_files`): who can see folders and move files.
* `mlab_media_folder_manage_capability` (default `upload_files`): who can create, rename, move and delete folders.
* `mlab_media_folder_audit_capability` (default `manage_options`): who can manually restart full Media Health audits.
* REST namespace `mlab-media-folder/v1`: `GET/POST /folders`, `PATCH/DELETE /folders/{id}`, `POST /assign`.
* `mlab_media_folder_update_repository` (default `momentumlab-ch/mlab-media-folder`): GitHub repository that is checked for updates.
* Define `MLAB_MEDIA_FOLDER_KEEP_DATA` as `true` to keep the folders when the plugin is deleted.

== Changelog ==

= 3.0.0 =
* New workspace: unified MomentumLab header, global search across media and folders, right-side Inspector, mobile folder drawer and Inspector bottom sheet.
* Smart Views: Recent uploads and a conservative Potentially unused view backed by a resumable background usage audit; existing Unfiled, No alt text, Duplicates and File missing views remain.
* Pro: customer-owned OpenAI metadata suggestions, glossary rules, blocked terms, upload naming rules, role/user folder permissions, folder colors/duplication, saved Dynamic Views and bounded bulk AI.
* Image optimization: opt-in WebP optimization for new WordPress derivatives in Free; Pro adds AVIF, derivative metadata privacy and explicit background rebuild of existing derivatives. Uploaded originals are never automatically replaced or deleted.
* Migration: non-destructive copy from HappyFiles, current FileBird tables and the legacy FileBird taxonomy. Source data is never modified.
* Licensing: Pro features unlock through MomentumLab Core / License Server entitlement `mlab-media-folder-pro`. Existing 2.x functionality remains available in Free.
* Performance: permission-aware folder and Smart View counters are cached with targeted generation invalidation; audits and bulk work run in bounded background batches.
* Security: AI API keys are customer-owned and encrypted at rest; manual AI is suggestion-first; folder permissions are enforced in queries, search, Inspector, AI and upload assignment.
* Compatibility: existing folder URLs, media URLs and uploaded originals are not changed by the upgrade.

= 2.4.0 =
* New: full-library missing-file audit in small resumable background batches with progress and a protected “Scan again” action.
* New: SHA-256 content duplicate audit finds copied files even when their names and paths differ.
* Change: “Duplicates” covers same-path and same-content entries; only entries that truly share one physical file are hidden from the normal library.
* Change: attachment details link related same-file or same-content entries.
* Integration: MomentumLab Core can take over updates through the License Server; the GitHub updater automatically stands down to avoid two competing update sources.
* Security: attachment paths are constrained to uploads; unavailable/offloaded storage fails safely and disabled audits do not reschedule forever.
* Reliability: audit cron is started on activation/upgrade, cleared on deactivation and audit data is removed on uninstall.
* Design: scoped MomentumLab styling with Montserrat fallback, improved focus states, tap targets and reduced-motion support.
* Quality: CI checks PHP 8.0–8.4, JavaScript, executable path-safety regressions, WPCS/PHP compatibility and release version consistency.

= 2.3.1 =
* Change: the media library shows each file once. Entries without a file are hidden, and of several entries for one file only the first is listed. The counters equal the images shown. "Duplicates" and "File missing" list the hidden entries. The editor's media dialogs still list every entry.

= 2.3.0 =
* New: "Duplicates" under "To do": entries that point to the same file. The media library lists entries, not files, so such a file shows up more than once. "Origin" names the other entries that share the file.
* New: "File missing" under "To do": entries whose file is no longer in the uploads folder.
* New: deleting an entry no longer removes a file that another entry still uses.

= 2.2.0 =
* New: a workflow in the folder panel. "Next step" points to the first thing that needs doing; the "To do" list shows unfiled files, images without alternative text and files not uploaded to a page, each with a count.
* New: when a "To do" view is open, a note above the files says where the files were found and what to do.
* New: "Origin" in the file details: who uploaded the file and when, which page or post it belongs to or is the featured image of, its folder, and what is still open.
* New: "Move to…" in the selection bar moves selected files without dragging (touch screens).
* New: "Undo" after moving files; search field for many folders; arrow keys move through the folder list.
* Change: new look in the style of Apple's Human Interface Guidelines: quiet surfaces, filled selection, larger tap targets on touch screens, respects "reduce motion". Deleting a folder is confirmed next to the folder instead of in a browser dialog.
* Fix: moving more than 500 files at once no longer drops the rest. Counts also refresh after alternative text is saved. Dragging files onto a "To do" view is no longer offered.
* The media dialogs get the same two views in the folder filter.

= 2.1.1 =
* Fix: the bar at the bottom of the grid could stay on screen after "Bulk select" was switched off. It now follows the select mode itself.
* Fix: "Select all" switches on bulk select if it is off.
* Fix: selecting by dragging no longer depends on the exact markup of the grid, and the browser's own image drag is switched off while you drag a selection.
* Fix: dragging files onto a folder no longer shows "Drop files to upload".
* Note: a drag that starts on a file selects files while "Bulk select" is on. With it off, dragging a file moves it to a folder, and a drag that starts between files switches bulk select on.

= 2.1.0 =
* New: select several files in the grid by dragging across them with the mouse button held down. Starting on a file or between files switches on bulk select; the page scrolls along at the edges and Esc cancels.
* New: a bar at the bottom shows how many files are selected, with "Select all" (all files of the open folder, also those not loaded yet), "Delete permanently" or "Move to Trash" and "Cancel". Core's own confirmation and permissions apply.
* Selected files can still be dragged onto a folder together.
* Removed: the code that moved sites from the old plugin name (`mlab-media-folders`, up to 1.1.6). Releases no longer carry the extra archive for it.

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
