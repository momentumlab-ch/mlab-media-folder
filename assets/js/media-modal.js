/**
 * MomentumLab Media Folder: folder filter in media dialogs and
 * "upload into the current folder".
 *
 * Loaded wherever WordPress loads its media dialogs (wp_enqueue_media).
 */
( function ( $, _, wp, data ) {
	'use strict';

	if ( ! wp || ! wp.media || ! wp.media.view || ! data ) {
		return;
	}

	var PARAM = data.param;
	var i18n = data.i18n;
	var media = wp.media;

	/**
	 * Indentation for nested folders in a <select>.
	 *
	 * @param {number} depth Nesting depth.
	 * @return {string} Non-breaking spaces.
	 */
	function indent( depth ) {
		return new Array( depth + 1 ).join( '   ' );
	}

	/**
	 * Folder value of the library that is currently shown, if any.
	 *
	 * @return {string} '' (all), 'none' or a folder ID.
	 */
	function currentFolder() {
		var frame = media.frame;
		var state = frame && frame.state && frame.state();
		var library = state && state.get && state.get( 'library' );
		var value = library && library.props ? library.props.get( PARAM ) : '';
		return value ? String( value ) : '';
	}

	/**
	 * Dropdown filter "Folder" next to the type and date filters.
	 */
	var FolderFilter = media.view.AttachmentFilters.extend( {
		id: 'mlab-media-folder-filter',
		className: 'attachment-filters mlab-media-folder-filter',

		createFilters: function () {
			var filters = {};

			filters.all = {
				text: _.escape( i18n.all ),
				props: { mlab_folder: '' },
				priority: 1,
			};
			filters.none = {
				text: _.escape( i18n.unfiled ),
				props: { mlab_folder: data.unfiled },
				priority: 2,
			};
			_.each( data.folders, function ( folder, index ) {
				filters[ 'folder-' + folder.id ] = {
					// AttachmentFilters renders option text as HTML, so escape it.
					text: indent( folder.depth ) + _.escape( folder.name ),
					props: { mlab_folder: String( folder.id ) },
					priority: 10 + index,
				};
			} );

			this.filters = filters;
		},
	} );

	/**
	 * Adds the folder filter to every attachments browser (library grid and
	 * all media dialogs). The media library screen hides it in favour of the
	 * folder sidebar.
	 */
	var AttachmentsBrowser = media.view.AttachmentsBrowser;
	media.view.AttachmentsBrowser = AttachmentsBrowser.extend( {
		createToolbar: function () {
			AttachmentsBrowser.prototype.createToolbar.apply( this, arguments );

			if ( ! this.toolbar || ! this.collection || ! this.collection.props ) {
				return;
			}

			this.toolbar.set(
				'mlabFolderLabel',
				new media.view.Label( {
					value: i18n.filterLabel,
					attributes: { for: 'mlab-media-folder-filter' },
					className: 'media-filter-label mlab-media-folder-filter-label',
					priority: -74,
				} ).render()
			);
			this.toolbar.set(
				'mlabFolderFilter',
				new FolderFilter( {
					controller: this.controller,
					model: this.collection.props,
					priority: -74,
				} ).render()
			);
		},
	} );

	/**
	 * Core only lets a query watch the upload queue when it has no unknown
	 * arguments, so a folder-filtered view would not show new uploads until
	 * the page is reloaded. Uploads go into the open folder, so they always
	 * match: let folder queries watch the queue too.
	 */
	var Query = media.model && media.model.Query;
	if ( Query && Query.prototype && Query.prototype.initialize ) {
		var queryInit = Query.prototype.initialize;
		var observable = [ 's', 'order', 'orderby', 'posts_per_page', 'post_mime_type', 'post_parent', 'author', PARAM ];
		Query.prototype.initialize = function () {
			queryInit.apply( this, arguments );
			var keys = _.keys( this.args || {} );
			if ( wp.Uploader && wp.Uploader.queue && _.has( this.args, PARAM ) && _.isEmpty( _.difference( keys, observable ) ) ) {
				this.observe( wp.Uploader.queue );
			}
		};
	}

	/**
	 * Sends the current folder with every upload made through the media
	 * uploader (plupload), so new files land in the open folder.
	 */
	if ( wp.Uploader && wp.Uploader.prototype ) {
		var init = wp.Uploader.prototype.init;
		wp.Uploader.prototype.init = function () {
			if ( init ) {
				init.apply( this, arguments );
			}
			var uploader = this.uploader;
			if ( ! uploader || ! uploader.bind ) {
				return;
			}
			uploader.bind( 'BeforeUpload', function ( up ) {
				var params = up.settings.multipart_params || {};
				var folder = currentFolder();
				if ( folder && folder !== data.unfiled ) {
					params[ PARAM ] = folder;
				} else {
					delete params[ PARAM ];
				}
				up.settings.multipart_params = params;
			} );
			uploader.bind( 'UploadComplete', function () {
				$( document ).trigger( 'mlab-media-folder:changed' );
			} );
		};
	}

	// Shared with the media library sidebar.
	window.mlabMediaFolderCurrent = currentFolder;
} )( jQuery, _, window.wp, window.mlabMediaFolder );
