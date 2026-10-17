/**
 * MomentumLab Media Folder: folder sidebar on the media library screen
 * (grid and list mode) with create, rename, delete and drag and drop.
 *
 * All folder names are inserted with textContent / jQuery .text(), never as HTML.
 */
( function ( $, wp, data ) {
	'use strict';

	if ( ! data || ! wp || ! wp.apiFetch ) {
		return;
	}

	var PARAM = data.param;
	var UNFILED = data.unfiled;
	var i18n = data.i18n;
	var STORAGE_KEY = 'mlab-media-folder-expanded';
	var LAYOUT_KEY = 'mlab-media-folder-layout';
	var WIDTH_MIN = 180;
	var WIDTH_MAX = 480;
	var WIDTH_DEFAULT = 220;
	var DRAG_FILES = 'mlab-media-folder-files:';
	var DRAG_FOLDER = 'mlab-media-folder-folder:';

	var state = {
		folders: data.folders || [],
		totals: data.totals || { all: 0, unfiled: 0 },
		current: data.current === '' || data.current === undefined ? '' : String( data.current ),
		expanded: loadExpanded(),
		layout: loadLayout(),
		dragging: null,
	};

	var $panel;
	var $sidebar;
	var $tree;
	var $status;

	/* ---------- helpers ---------- */

	function loadExpanded() {
		try {
			return JSON.parse( window.localStorage.getItem( STORAGE_KEY ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function saveExpanded() {
		try {
			window.localStorage.setItem( STORAGE_KEY, JSON.stringify( state.expanded ) );
		} catch ( e ) {}
	}

	function loadLayout() {
		var layout = { width: WIDTH_DEFAULT, collapsed: false };
		try {
			var saved = JSON.parse( window.localStorage.getItem( LAYOUT_KEY ) || '{}' ) || {};
			if ( typeof saved.width === 'number' ) {
				layout.width = clampWidth( saved.width );
			}
			layout.collapsed = true === saved.collapsed;
		} catch ( e ) {}
		return layout;
	}

	function saveLayout() {
		try {
			window.localStorage.setItem( LAYOUT_KEY, JSON.stringify( state.layout ) );
		} catch ( e ) {}
	}

	function clampWidth( width ) {
		return Math.max( WIDTH_MIN, Math.min( WIDTH_MAX, Math.round( width ) ) );
	}

	/**
	 * The media grid computes its columns on window resize, so tell it
	 * whenever the space next to the sidebar changes.
	 */
	function relayout() {
		window.clearTimeout( relayout.timer );
		relayout.timer = window.setTimeout( function () {
			$( window ).trigger( 'resize' );
		}, 60 );
	}

	function applyLayout() {
		$panel.css( '--mlab-media-folder-width', state.layout.width + 'px' );
		$panel.toggleClass( 'is-collapsed', state.layout.collapsed );
		$panel
			.find( '.mlab-media-folder-collapse' )
			.attr( 'aria-expanded', state.layout.collapsed ? 'false' : 'true' )
			.attr( 'aria-label', state.layout.collapsed ? i18n.expand : i18n.collapse )
			.attr( 'title', state.layout.collapsed ? i18n.expand : i18n.collapse );
		$panel.find( '.mlab-media-folder-resizer' ).attr( 'aria-valuenow', state.layout.width );
		relayout();
	}

	function api( path, options ) {
		return wp.apiFetch( $.extend( { path: data.restPath + path }, options || {} ) );
	}

	function announce( message, isError ) {
		$status.text( message ).toggleClass( 'is-error', !! isError );
		window.clearTimeout( announce.timer );
		announce.timer = window.setTimeout( function () {
			$status.text( '' ).removeClass( 'is-error' );
		}, 4000 );
	}

	function errorMessage( error ) {
		return ( error && error.message ) || i18n.error;
	}

	function sprintf( template, value ) {
		return String( template ).replace( /%[sd]/, value );
	}

	function childrenOf( parent ) {
		return state.folders
			.filter( function ( folder ) {
				return folder.parent === parent;
			} )
			.sort( function ( a, b ) {
				return a.name.localeCompare( b.name, undefined, { numeric: true, sensitivity: 'base' } );
			} );
	}

	function findFolder( id ) {
		id = parseInt( id, 10 );
		for ( var i = 0; i < state.folders.length; i++ ) {
			if ( state.folders[ i ].id === id ) {
				return state.folders[ i ];
			}
		}
		return null;
	}

	function isDescendant( id, ancestorId ) {
		var folder = findFolder( id );
		var guard = 0;
		while ( folder && folder.parent && guard++ < 50 ) {
			if ( folder.parent === ancestorId ) {
				return true;
			}
			folder = findFolder( folder.parent );
		}
		return false;
	}

	/* ---------- media library integration ---------- */

	function gridLibrary() {
		var frame = wp.media && wp.media.frame;
		var s = frame && frame.state && frame.state();
		return s && s.get ? s.get( 'library' ) : null;
	}

	function refreshGrid() {
		var library = gridLibrary();
		if ( ! library ) {
			return;
		}
		// Drop cached queries and fetch the current view again.
		if ( wp.media.model && wp.media.model.Query && wp.media.model.Query.cleanQueries ) {
			wp.media.model.Query.cleanQueries();
		}
		if ( library._requery ) {
			library._requery();
		}
	}

	/**
	 * Sets the folder on the grid library. "All files" removes the argument
	 * entirely, so core's own upload handling stays untouched there.
	 *
	 * @param {Object} library Attachments collection.
	 * @param {string} value   Folder value.
	 */
	function setLibraryFolder( library, value ) {
		if ( value ) {
			library.props.set( PARAM, value );
		} else {
			library.props.unset( PARAM );
		}
	}

	function applyFilter( value ) {
		state.current = value;
		renderTree();

		if ( 'list' === data.mode ) {
			var url = new URL( window.location.href );
			url.searchParams.delete( 'paged' );
			if ( value ) {
				url.searchParams.set( PARAM, value );
			} else {
				url.searchParams.delete( PARAM );
			}
			window.location.assign( url.toString() );
			return;
		}

		var library = gridLibrary();
		if ( library ) {
			setLibraryFolder( library, value );
		}
		try {
			var current = new URL( window.location.href );
			if ( value ) {
				current.searchParams.set( PARAM, value );
			} else {
				current.searchParams.delete( PARAM );
			}
			window.history.replaceState( null, '', current.toString() );
		} catch ( e ) {}
	}

	function reload() {
		return api( '/folders' ).then( function ( response ) {
			state.folders = ( response.folders || [] ).map( function ( folder ) {
				return {
					id: parseInt( folder.id, 10 ),
					name: String( folder.name ),
					parent: parseInt( folder.parent, 10 ) || 0,
					count: parseInt( folder.count, 10 ) || 0,
				};
			} );
			state.totals = response.totals || state.totals;
			if ( state.current && state.current !== UNFILED && ! findFolder( state.current ) ) {
				applyFilter( '' );
			}
			renderTree();
		} );
	}

	/* ---------- folder actions ---------- */

	function moveFiles( ids, folder ) {
		return api( '/assign', { method: 'POST', data: { ids: ids, folder: folder } } )
			.then( function ( result ) {
				announce( sprintf( i18n.moved, ( result.moved || [] ).length ) );
				if ( 'list' === data.mode ) {
					window.location.reload();
					return;
				}
				refreshGrid();
				return reload();
			} )
			.catch( function ( error ) {
				announce( errorMessage( error ), true );
			} );
	}

	function moveFolder( id, parent ) {
		return api( '/folders/' + id, { method: 'PATCH', data: { parent: parent } } )
			.then( function () {
				if ( parent ) {
					state.expanded[ parent ] = true;
					saveExpanded();
				}
				return reload();
			} )
			.catch( function ( error ) {
				announce( errorMessage( error ), true );
			} );
	}

	function deleteFolder( folder ) {
		if ( ! window.confirm( sprintf( i18n.confirmDelete, folder.name ) ) ) {
			return;
		}
		api( '/folders/' + folder.id, { method: 'DELETE' } )
			.then( function () {
				if ( String( folder.id ) === state.current ) {
					applyFilter( folder.parent ? String( folder.parent ) : '' );
				}
				refreshGrid();
				return reload();
			} )
			.catch( function ( error ) {
				announce( errorMessage( error ), true );
			} );
	}

	/**
	 * Inline text field for creating or renaming a folder.
	 *
	 * @param {jQuery}   $container Where to show the field.
	 * @param {string}   value      Initial value.
	 * @param {Function} onSave     Called with the trimmed name; returns a promise.
	 */
	function inlineInput( $container, value, onSave ) {
		var $form = $( '<form class="mlab-media-folder-inline"></form>' );
		var $input = $( '<input type="text" maxlength="100" required>' )
			.attr( 'placeholder', i18n.namePlaceholder )
			.attr( 'aria-label', i18n.namePlaceholder )
			.val( value || '' );
		var $save = $( '<button type="submit" class="button button-small button-primary"></button>' ).text( i18n.save );
		var $cancel = $( '<button type="button" class="button button-small"></button>' ).text( i18n.cancel );
		var busy = false;

		function close() {
			$form.remove();
			renderTree();
		}

		$form.append( $input, $save, $cancel ).on( 'submit', function ( event ) {
			event.preventDefault();
			var name = $.trim( $input.val() );
			if ( ! name || busy ) {
				return;
			}
			busy = true;
			onSave( name )
				.then( function () {
					return reload();
				} )
				.catch( function ( error ) {
					busy = false;
					announce( errorMessage( error ), true );
					$input.trigger( 'focus' );
				} );
		} );
		$cancel.on( 'click', close );
		$input.on( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				close();
			}
		} );

		$container.append( $form );
		$input.trigger( 'focus' ).trigger( 'select' );
	}

	function startCreate( parent ) {
		var $target;
		if ( parent ) {
			state.expanded[ parent ] = true;
			saveExpanded();
			renderTree();
			$target = $tree.find( '[data-folder="' + parent + '"]' ).first().children( '.mlab-media-folder-children' );
			if ( ! $target.length ) {
				$target = $( '<ul class="mlab-media-folder-children"></ul>' ).appendTo( $tree.find( '[data-folder="' + parent + '"]' ).first() );
			}
		} else {
			$target = $tree.children( '.mlab-media-folder-root' );
		}
		var $li = $( '<li class="mlab-media-folder-item"></li>' ).appendTo( $target );
		inlineInput( $li, '', function ( name ) {
			return api( '/folders', { method: 'POST', data: { name: name, parent: parent || 0 } } );
		} );
	}

	function startRename( folder, $row ) {
		$row.hide();
		inlineInput( $row.parent(), folder.name, function ( name ) {
			return api( '/folders/' + folder.id, { method: 'PATCH', data: { name: name } } );
		} );
	}

	/* ---------- rendering ---------- */

	function selectedFolderId() {
		var id = parseInt( state.current, 10 );
		return id > 0 && findFolder( id ) ? id : 0;
	}

	function makeRow( options ) {
		var $row = $( '<div class="mlab-media-folder-row"></div>' )
			.attr( 'data-target', options.target )
			.toggleClass( 'is-current', options.current );

		var $toggle = $( '<button type="button" class="mlab-media-folder-toggle"></button>' ).attr( 'aria-label', i18n.toggle );
		if ( options.hasChildren ) {
			$toggle.attr( 'aria-expanded', options.expanded ? 'true' : 'false' );
		} else {
			$toggle.addClass( 'is-empty' ).attr( { 'aria-hidden': 'true', tabindex: '-1' } );
		}

		var $name = $( '<button type="button" class="mlab-media-folder-name"></button>' )
			.attr( 'aria-current', options.current ? 'true' : null )
			.append( $( '<span class="dashicons" aria-hidden="true"></span>' ).addClass( options.icon ) )
			.append( $( '<span class="mlab-media-folder-label"></span>' ).text( options.label ) )
			.append( $( '<span class="mlab-media-folder-count"></span>' ).text( options.count ) );

		$row.append( $toggle, $name );
		return $row;
	}

	function renderBranch( parent, $ul ) {
		childrenOf( parent ).forEach( function ( folder ) {
			var kids = childrenOf( folder.id );
			var expanded = !! state.expanded[ folder.id ];
			var $li = $( '<li class="mlab-media-folder-item"></li>' ).attr( 'data-folder', folder.id );
			var $row = makeRow( {
				target: String( folder.id ),
				label: folder.name,
				count: folder.count,
				icon: expanded && kids.length ? 'dashicons-open-folder' : 'dashicons-category',
				current: String( folder.id ) === state.current,
				hasChildren: kids.length > 0,
				expanded: expanded,
			} );

			if ( data.canManage ) {
				$row.attr( 'draggable', 'true' ).attr( 'data-drag-folder', folder.id );
				var $more = $( '<button type="button" class="mlab-media-folder-more" aria-haspopup="true" aria-expanded="false"></button>' )
					.attr( 'aria-label', i18n.actions + ': ' + folder.name )
					.append( '<span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>' );
				var $menu = $( '<div class="mlab-media-folder-menu" role="menu" hidden></div>' );
				[
					[ i18n.newSubfolder, 'sub' ],
					[ i18n.rename, 'rename' ],
					[ i18n.delete, 'delete' ],
				].forEach( function ( item ) {
					$( '<button type="button" role="menuitem"></button>' ).text( item[ 0 ] ).attr( 'data-action', item[ 1 ] ).appendTo( $menu );
				} );
				$row.append( $more, $menu );
			}

			$li.append( $row );
			if ( kids.length && expanded ) {
				var $children = $( '<ul class="mlab-media-folder-children"></ul>' );
				renderBranch( folder.id, $children );
				$li.append( $children );
			}
			$ul.append( $li );
		} );
	}

	function renderTree() {
		$tree.empty();

		var $fixed = $( '<ul class="mlab-media-folder-fixed"></ul>' );
		$( '<li class="mlab-media-folder-item"></li>' )
			.append( makeRow( { target: '', label: i18n.all, count: state.totals.all, icon: 'dashicons-admin-media', current: '' === state.current } ) )
			.appendTo( $fixed );
		$( '<li class="mlab-media-folder-item"></li>' )
			.append( makeRow( { target: UNFILED, label: i18n.unfiled, count: state.totals.unfiled, icon: 'dashicons-portfolio', current: UNFILED === state.current } ) )
			.appendTo( $fixed );

		var $root = $( '<ul class="mlab-media-folder-root"></ul>' );
		renderBranch( 0, $root );

		$tree.append( $fixed, $root );
	}

	function buildSidebar() {
		$sidebar = $( '<aside class="mlab-media-folder-sidebar"></aside>' ).attr( 'aria-label', i18n.title );
		var $head = $( '<div class="mlab-media-folder-head"></div>' )
			.append(
				$( '<button type="button" class="mlab-media-folder-collapse"></button>' ).append(
					'<span class="dashicons dashicons-category" aria-hidden="true"></span>'
				)
			)
			.append( $( '<h2></h2>' ).text( i18n.title ) );
		if ( data.canManage ) {
			$( '<button type="button" class="button button-small mlab-media-folder-new"></button>' )
				.append( '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' )
				.attr( 'title', i18n.newFolder )
				.append( $( '<span class="mlab-media-folder-new-label"></span>' ).text( i18n.newFolder ) )
				.appendTo( $head );
		}
		$tree = $( '<div class="mlab-media-folder-tree"></div>' );
		$status = $( '<p class="mlab-media-folder-status" role="status" aria-live="polite"></p>' );
		var $hint = $( '<p class="mlab-media-folder-hint"></p>' ).text( i18n.dropHint );
		var $credit = $( '<p class="mlab-media-folder-credit"></p>' ).append(
			$( '<a target="_blank" rel="noopener noreferrer"></a>' ).attr( 'href', data.creditUrl ).text( i18n.credit ),
			document.createTextNode( ' · v' + data.version )
		);
		$sidebar.append( $head, $tree, $status, $hint, $credit );
		renderTree();

		var $resizer = $( '<div class="mlab-media-folder-resizer" role="separator" tabindex="0" aria-orientation="vertical"></div>' )
			.attr( 'aria-label', i18n.resize )
			.attr( 'title', i18n.resize )
			.attr( 'aria-valuemin', WIDTH_MIN )
			.attr( 'aria-valuemax', WIDTH_MAX );
		$panel = $( '<div class="mlab-media-folder-panel"></div>' ).append( $sidebar, $resizer );
		applyLayout();
		return $panel;
	}

	/* ---------- collapse and resize ---------- */

	function bindPanel() {
		$panel.on( 'click', '.mlab-media-folder-collapse', function () {
			state.layout.collapsed = ! state.layout.collapsed;
			closeMenus();
			applyLayout();
			saveLayout();
		} );

		$panel.on( 'pointerdown', '.mlab-media-folder-resizer', function ( event ) {
			if ( state.layout.collapsed || event.button > 0 ) {
				return;
			}
			event.preventDefault();
			var original = event.originalEvent || event;
			var startX = original.clientX;
			var startWidth = state.layout.width;
			var handle = this;
			if ( handle.setPointerCapture && original.pointerId !== undefined ) {
				handle.setPointerCapture( original.pointerId );
			}
			$panel.addClass( 'is-resizing' );

			$( handle )
				.on( 'pointermove.mlabmediafolder', function ( e ) {
					var moved = e.originalEvent || e;
					state.layout.width = clampWidth( startWidth + moved.clientX - startX );
					$panel.css( '--mlab-media-folder-width', state.layout.width + 'px' );
				} )
				.on( 'pointerup.mlabmediafolder pointercancel.mlabmediafolder', function () {
					$( handle ).off( '.mlabmediafolder' );
					$panel.removeClass( 'is-resizing' );
					applyLayout();
					saveLayout();
				} );
		} );

		$panel.on( 'keydown', '.mlab-media-folder-resizer', function ( event ) {
			var step = event.shiftKey ? 60 : 20;
			var width = state.layout.width;
			if ( 'ArrowLeft' === event.key ) {
				width -= step;
			} else if ( 'ArrowRight' === event.key ) {
				width += step;
			} else if ( 'Home' === event.key ) {
				width = WIDTH_MIN;
			} else if ( 'End' === event.key ) {
				width = WIDTH_MAX;
			} else {
				return;
			}
			event.preventDefault();
			state.layout.width = clampWidth( width );
			applyLayout();
			saveLayout();
		} );

		$panel.on( 'dblclick', '.mlab-media-folder-resizer', function () {
			state.layout.width = WIDTH_DEFAULT;
			applyLayout();
			saveLayout();
		} );
	}

	/* ---------- events ---------- */

	function closeMenus() {
		$sidebar.find( '.mlab-media-folder-menu' ).attr( 'hidden', true );
		$sidebar.find( '.mlab-media-folder-more' ).attr( 'aria-expanded', 'false' );
	}

	function bindSidebar() {
		$sidebar.on( 'click', '.mlab-media-folder-name', function () {
			applyFilter( String( $( this ).closest( '.mlab-media-folder-row' ).attr( 'data-target' ) ) );
		} );

		$sidebar.on( 'click', '.mlab-media-folder-toggle:not(.is-empty)', function () {
			var id = $( this ).closest( '.mlab-media-folder-item' ).attr( 'data-folder' );
			state.expanded[ id ] = ! state.expanded[ id ];
			saveExpanded();
			renderTree();
			$tree.find( '[data-folder="' + id + '"] > .mlab-media-folder-row .mlab-media-folder-toggle' ).trigger( 'focus' );
		} );

		$sidebar.on( 'click', '.mlab-media-folder-new', function () {
			startCreate( selectedFolderId() );
		} );

		$sidebar.on( 'click', '.mlab-media-folder-more', function ( event ) {
			event.stopPropagation();
			var $menu = $( this ).siblings( '.mlab-media-folder-menu' );
			var open = $menu.is( '[hidden]' );
			closeMenus();
			if ( open ) {
				$menu.removeAttr( 'hidden' );
				$( this ).attr( 'aria-expanded', 'true' );
				$menu.find( 'button' ).first().trigger( 'focus' );
			}
		} );

		$sidebar.on( 'click', '.mlab-media-folder-menu button', function () {
			var $row = $( this ).closest( '.mlab-media-folder-row' );
			var folder = findFolder( $row.attr( 'data-target' ) );
			var action = $( this ).attr( 'data-action' );
			closeMenus();
			if ( ! folder ) {
				return;
			}
			if ( 'sub' === action ) {
				startCreate( folder.id );
			} else if ( 'rename' === action ) {
				startRename( folder, $row );
			} else if ( 'delete' === action ) {
				deleteFolder( folder );
			}
		} );

		$( document ).on( 'click', closeMenus );
		$sidebar.on( 'keydown', '.mlab-media-folder-menu', function ( event ) {
			if ( 'Escape' === event.key ) {
				closeMenus();
				$( this ).siblings( '.mlab-media-folder-more' ).trigger( 'focus' );
			}
		} );

		/* Drop targets: folder rows and "Unfiled". "All files" takes folders only (move to top level). */
		$sidebar.on( 'dragover', '.mlab-media-folder-row', function ( event ) {
			var target = $( this ).attr( 'data-target' );
			var drag = state.dragging;
			if ( ! drag ) {
				return;
			}
			var ok = false;
			if ( 'files' === drag.type ) {
				ok = '' !== target;
			} else if ( 'folder' === drag.type ) {
				var to = target === '' ? 0 : parseInt( target, 10 );
				ok = target !== UNFILED && to !== drag.id && ! ( to && isDescendant( to, drag.id ) );
			}
			if ( ok ) {
				event.preventDefault();
				event.originalEvent.dataTransfer.dropEffect = 'move';
				$( this ).addClass( 'is-drop-target' );
			}
		} );
		$sidebar.on( 'dragleave drop', '.mlab-media-folder-row', function () {
			$( this ).removeClass( 'is-drop-target' );
		} );
		$sidebar.on( 'drop', '.mlab-media-folder-row', function ( event ) {
			event.preventDefault();
			var drag = state.dragging;
			var target = $( this ).attr( 'data-target' );
			state.dragging = null;
			if ( ! drag ) {
				return;
			}
			if ( 'files' === drag.type ) {
				moveFiles( drag.ids, target === UNFILED ? 0 : parseInt( target, 10 ) );
			} else if ( 'folder' === drag.type ) {
				moveFolder( drag.id, target === '' ? 0 : parseInt( target, 10 ) );
			}
		} );

		/* Dragging folders inside the tree. */
		$sidebar.on( 'dragstart', '[data-drag-folder]', function ( event ) {
			var id = parseInt( $( this ).attr( 'data-drag-folder' ), 10 );
			state.dragging = { type: 'folder', id: id };
			event.originalEvent.dataTransfer.effectAllowed = 'move';
			event.originalEvent.dataTransfer.setData( 'text/plain', DRAG_FOLDER + id );
		} );
		$sidebar.on( 'dragend', function () {
			state.dragging = null;
			$sidebar.find( '.is-drop-target' ).removeClass( 'is-drop-target' );
		} );
	}

	/**
	 * Small badge used as drag image, e.g. "3".
	 *
	 * @param {number} count Number of files.
	 * @return {HTMLElement} Element attached to the body.
	 */
	function dragBadge( count ) {
		var badge = document.createElement( 'div' );
		badge.className = 'mlab-media-folder-drag-badge';
		badge.textContent = String( count );
		document.body.appendChild( badge );
		window.setTimeout( function () {
			badge.remove();
		}, 0 );
		return badge;
	}

	function startFileDrag( event, ids ) {
		if ( ! ids.length ) {
			return;
		}
		state.dragging = { type: 'files', ids: ids };
		var transfer = event.originalEvent.dataTransfer;
		transfer.effectAllowed = 'move';
		transfer.setData( 'text/plain', DRAG_FILES + ids.join( ',' ) );
		if ( transfer.setDragImage ) {
			transfer.setDragImage( dragBadge( ids.length ), 16, 16 );
		}
		$sidebar.addClass( 'is-dragging' );
	}

	function bindFileDrag() {
		var $doc = $( document );

		$doc.on( 'dragend', function () {
			state.dragging = null;
			if ( $sidebar ) {
				$sidebar.removeClass( 'is-dragging' ).find( '.is-drop-target' ).removeClass( 'is-drop-target' );
			}
		} );

		if ( 'list' === data.mode ) {
			$doc.on( 'mousedown', '#the-list tr[id^="post-"]', function () {
				this.setAttribute( 'draggable', 'true' );
			} );
			$doc.on( 'dragstart', '#the-list tr[id^="post-"]', function ( event ) {
				var id = parseInt( this.id.replace( 'post-', '' ), 10 );
				var checked = $( '#the-list input[name="media[]"]:checked' )
					.map( function () {
						return parseInt( this.value, 10 );
					} )
					.get();
				startFileDrag( event, checked.indexOf( id ) !== -1 ? checked : [ id ] );
			} );
			return;
		}

		$doc.on( 'mousedown', '.attachments-browser li.attachment', function () {
			this.setAttribute( 'draggable', 'true' );
		} );
		$doc.on( 'dragstart', '.attachments-browser li.attachment', function ( event ) {
			var id = parseInt( $( this ).attr( 'data-id' ), 10 );
			var frame = wp.media && wp.media.frame;
			var selection = frame && frame.state && frame.state().get( 'selection' );
			var selected = selection ? selection.pluck( 'id' ) : [];
			startFileDrag( event, selected.indexOf( id ) !== -1 ? selected : [ id ] );
		} );
	}

	/* ---------- selecting several files (grid mode) ---------- */

	var SWEEP_THRESHOLD = 6;
	var SCROLL_EDGE = 48;

	function gridFrame() {
		return wp.media && wp.media.frame && wp.media.frame.isModeActive ? wp.media.frame : null;
	}

	function gridSelection() {
		var frame = gridFrame();
		var s = frame && frame.state && frame.state();
		return s && s.get ? s.get( 'selection' ) : null;
	}

	function inSelectMode() {
		var frame = gridFrame();
		return !! ( frame && frame.isModeActive( 'select' ) );
	}

	/**
	 * Core enables its toolbar buttons on this event, which only its own clicks send.
	 */
	function selectionChanged() {
		var frame = gridFrame();
		if ( frame ) {
			frame.trigger( 'selection:toggle' );
		}
	}

	/**
	 * Switches the grid into core's "Bulk select" mode, as its own button does.
	 */
	function enterSelectMode() {
		if ( ! inSelectMode() ) {
			$( '.attachments-browser .select-mode-toggle-button' ).first().trigger( 'click' );
		}
	}

	/**
	 * Nearest scrolling ancestor of the grid, or null for the window.
	 *
	 * @param {HTMLElement} el Start element.
	 * @return {HTMLElement|null} Scroll container.
	 */
	function scrollParent( el ) {
		for ( el = el && el.parentElement; el && el !== document.body; el = el.parentElement ) {
			var overflow = window.getComputedStyle( el ).overflowY;
			if ( ( 'auto' === overflow || 'scroll' === overflow ) && el.scrollHeight > el.clientHeight ) {
				return el;
			}
		}
		return null;
	}

	/**
	 * Hold the mouse button and drag across the grid to select files.
	 *
	 * In "Bulk select" mode the drag can start on a file that is not selected
	 * yet; dragging a selected file still moves the selection to a folder.
	 * Starting in the gaps between files works in every mode and switches to
	 * "Bulk select". Files touched by the frame are added to the selection.
	 */
	function bindSweep() {
		var sweep = null;
		var suppressClick = false;

		// A sweep ends with a click on the last file; it must not toggle that file.
		document.addEventListener(
			'click',
			function ( event ) {
				if ( suppressClick ) {
					suppressClick = false;
					event.preventDefault();
					event.stopPropagation();
				}
			},
			true
		);

		// No native image drag or text selection while sweeping.
		document.addEventListener(
			'dragstart',
			function ( event ) {
				if ( sweep ) {
					event.preventDefault();
					event.stopPropagation();
				}
			},
			true
		);

		function toList( clientX, clientY ) {
			var rect = sweep.list.getBoundingClientRect();
			return { x: clientX - rect.left, y: clientY - rect.top };
		}

		function update() {
			var list = sweep.list.getBoundingClientRect();
			var current = toList( sweep.clientX, sweep.clientY );
			var left = Math.min( sweep.start.x, current.x );
			var top = Math.min( sweep.start.y, current.y );
			var right = Math.max( sweep.start.x, current.x );
			var bottom = Math.max( sweep.start.y, current.y );

			sweep.$box.css( {
				left: list.left + left,
				top: list.top + top,
				width: right - left,
				height: bottom - top,
			} );

			var selection = gridSelection();
			if ( ! selection ) {
				return;
			}
			$( sweep.list )
				.children( 'li.attachment' )
				.each( function () {
					var r = this.getBoundingClientRect();
					var id = parseInt( this.getAttribute( 'data-id' ), 10 );
					var model = id ? wp.media.attachment( id ) : null;
					if ( ! model ) {
						return;
					}
					var hit = r.right - list.left > left && r.left - list.left < right && r.bottom - list.top > top && r.top - list.top < bottom;
					if ( hit && ! selection.get( id ) ) {
						selection.add( model );
						sweep.added[ id ] = true;
					} else if ( ! hit && sweep.added[ id ] ) {
						selection.remove( model );
						delete sweep.added[ id ];
					}
				} );
		}

		function autoScroll() {
			if ( ! sweep || ! sweep.started ) {
				return;
			}
			var box = sweep.scroller ? sweep.scroller.getBoundingClientRect() : { top: 0, bottom: window.innerHeight };
			var step = 0;
			if ( sweep.clientY < box.top + SCROLL_EDGE ) {
				step = -Math.ceil( ( box.top + SCROLL_EDGE - sweep.clientY ) / 3 );
			} else if ( sweep.clientY > box.bottom - SCROLL_EDGE ) {
				step = Math.ceil( ( sweep.clientY - box.bottom + SCROLL_EDGE ) / 3 );
			}
			if ( step ) {
				var pos = sweep.scroller ? sweep.scroller.scrollTop : window.pageYOffset;
				// Re-rendering the grid can throw the page back to the top; carry on from where we were.
				if ( null !== sweep.expected && Math.abs( pos - sweep.expected ) > 1 ) {
					pos = sweep.expected;
				}
				pos = Math.max( 0, pos + step );
				if ( sweep.scroller ) {
					sweep.scroller.scrollTop = pos;
				} else {
					window.scrollTo( window.pageXOffset, pos );
				}
				sweep.expected = pos;
				update();
			} else {
				sweep.expected = null;
			}
			sweep.frame = window.requestAnimationFrame( autoScroll );
		}

		function finish( cancel ) {
			if ( ! sweep ) {
				return;
			}
			window.removeEventListener( 'pointermove', onMove );
			window.removeEventListener( 'pointerup', onUp );
			window.removeEventListener( 'pointercancel', onCancel );
			window.removeEventListener( 'keydown', onKey );
			window.removeEventListener( 'wheel', onWheel );
			window.cancelAnimationFrame( sweep.frame );
			if ( sweep.started ) {
				sweep.$box.remove();
				$( document.body ).removeClass( 'mlab-media-folder-sweeping' );
				suppressClick = true;
				// Only the click that ends this sweep is swallowed.
				window.setTimeout( function () {
					suppressClick = false;
				}, 0 );
				if ( cancel ) {
					var selection = gridSelection();
					Object.keys( sweep.added ).forEach( function ( id ) {
						if ( selection ) {
							selection.remove( selection.get( parseInt( id, 10 ) ) );
						}
					} );
				}
				selectionChanged();
			}
			sweep = null;
		}

		function onMove( event ) {
			if ( ! sweep || event.pointerId !== sweep.pointerId ) {
				return;
			}
			sweep.clientX = event.clientX;
			sweep.clientY = event.clientY;
			if ( ! sweep.started ) {
				if ( Math.abs( event.clientX - sweep.downX ) < SWEEP_THRESHOLD && Math.abs( event.clientY - sweep.downY ) < SWEEP_THRESHOLD ) {
					return;
				}
				sweep.started = true;
				enterSelectMode();
				sweep.$box = $( '<div class="mlab-media-folder-sweep" aria-hidden="true"></div>' ).appendTo( document.body );
				$( document.body ).addClass( 'mlab-media-folder-sweeping' );
				if ( window.getSelection ) {
					window.getSelection().removeAllRanges();
				}
				sweep.frame = window.requestAnimationFrame( autoScroll );
			}
			event.preventDefault();
			update();
		}

		function onUp( event ) {
			if ( sweep && event.pointerId === sweep.pointerId ) {
				finish( false );
			}
		}

		function onCancel( event ) {
			if ( sweep && event.pointerId === sweep.pointerId ) {
				finish( true );
			}
		}

		function onWheel() {
			if ( sweep ) {
				sweep.expected = null;
			}
		}

		function onKey( event ) {
			if ( 'Escape' === event.key ) {
				finish( true );
			}
		}

		$( document ).on( 'pointerdown', '.attachments-browser .attachments-wrapper', function ( event ) {
			var e = event.originalEvent;
			if ( sweep || 0 !== e.button || 'touch' === e.pointerType || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey ) {
				return;
			}
			var $target = $( e.target );
			var list = $( this ).find( 'ul.attachments' )[ 0 ];
			if ( ! list || $target.closest( 'button, a, input, select, textarea, .load-more-wrapper' ).length || ! gridSelection() ) {
				return;
			}
			// The thumbnail itself; the rest of the tile is the gap around it.
			var $tile = $target.closest( '.attachment-preview' ).closest( 'li.attachment' );
			if ( $tile.length ) {
				// Outside "Bulk select" a file drag moves it to a folder.
				if ( ! inSelectMode() || gridSelection().get( parseInt( $tile.attr( 'data-id' ), 10 ) ) ) {
					return;
				}
				$tile.removeAttr( 'draggable' );
			}

			sweep = {
				pointerId: e.pointerId,
				list: list,
				scroller: scrollParent( list ),
				downX: e.clientX,
				downY: e.clientY,
				clientX: e.clientX,
				clientY: e.clientY,
				started: false,
				expected: null,
				added: {},
			};
			sweep.start = toList( e.clientX, e.clientY );
			window.addEventListener( 'pointermove', onMove );
			window.addEventListener( 'pointerup', onUp );
			window.addEventListener( 'pointercancel', onCancel );
			window.addEventListener( 'keydown', onKey );
			window.addEventListener( 'wheel', onWheel, { passive: true } );
		} );

		// While a sweep may start, the file under the pointer must not become draggable.
		$( document ).on( 'mousedown', '.attachments-browser li.attachment', function () {
			if ( sweep ) {
				this.removeAttribute( 'draggable' );
			}
		} );
	}

	/**
	 * Bar at the bottom of the screen while "Bulk select" is on: number of
	 * selected files, select all, delete and cancel. The delete buttons run
	 * core's own bulk actions (with core's confirmation), so trash settings
	 * and permissions behave exactly as in the toolbar at the top.
	 *
	 * @param {jQuery} $wrap Content area of the media library.
	 */
	function bindSelectionBar( $wrap ) {
		var $bar = $( '<div class="mlab-media-folder-bulkbar" role="region" hidden></div>' ).attr( 'aria-label', i18n.selectionBar );
		var $count = $( '<strong class="mlab-media-folder-bulkbar-count" aria-live="polite"></strong>' );
		var $hint = $( '<span class="mlab-media-folder-bulkbar-hint"></span>' ).text( i18n.sweepHint );
		var $all = $( '<button type="button" class="button"></button>' );
		var $actions = $( '<span class="mlab-media-folder-bulkbar-actions"></span>' );
		var $cancel = $( '<button type="button" class="button"></button>' ).text( i18n.cancel );
		var loadingAll = false;

		$bar.append( $count, $hint, $all, $actions, $cancel ).appendTo( $wrap );

		function coreButtons() {
			return $( '.attachments-browser .media-toolbar' )
				.find( '.delete-selected-button, .delete-selected-permanently-button' )
				.filter( function () {
					return ! $( this ).hasClass( 'hidden' );
				} );
		}

		function allSelected() {
			var library = gridLibrary();
			var selection = gridSelection();
			if ( ! library || ! selection || ! library.length || ( library.hasMore && library.hasMore() ) ) {
				return false;
			}
			return library.every( function ( model ) {
				return !! selection.get( model.id );
			} );
		}

		function update() {
			var selection = gridSelection();
			var count = selection ? selection.length : 0;
			$bar.prop( 'hidden', ! inSelectMode() );
			$( document.body ).toggleClass( 'mlab-media-folder-has-bulkbar', inSelectMode() );
			$count.text( sprintf( i18n.selected, count ) );
			$hint.prop( 'hidden', count > 0 );
			$all.text( loadingAll ? i18n.loadingAll : allSelected() ? i18n.selectNone : i18n.selectAll ).prop( 'disabled', loadingAll );

			$actions.empty();
			coreButtons().each( function () {
				var core = this;
				$( '<button type="button" class="button"></button>' )
					.addClass( $( core ).hasClass( 'button-primary' ) ? 'button-primary' : 'mlab-media-folder-bulkbar-delete' )
					.text( $.trim( $( core ).text() ) )
					.prop( 'disabled', ! count )
					.on( 'click', function () {
						$( core ).trigger( 'click' );
					} )
					.appendTo( $actions );
			} );
		}

		function loadAll( library, guard ) {
			if ( library.hasMore && library.hasMore() && guard < 500 ) {
				return $.when( library.more() ).then( function () {
					return loadAll( library, guard + 1 );
				} );
			}
			return $.Deferred().resolve().promise();
		}

		$all.on( 'click', function () {
			var library = gridLibrary();
			var selection = gridSelection();
			if ( ! library || ! selection ) {
				return;
			}
			if ( allSelected() ) {
				selection.reset();
				selectionChanged();
				return;
			}
			loadingAll = true;
			update();
			loadAll( library, 0 ).always( function () {
				loadingAll = false;
				selection.add( library.models );
				selectionChanged();
				update();
			} );
		} );

		$cancel.on( 'click', function () {
			$( '.attachments-browser .select-mode-toggle-button' ).first().trigger( 'click' );
		} );

		var tries = 0;
		( function waitForFrame() {
			var frame = gridFrame();
			var selection = gridSelection();
			if ( ! frame || ! selection ) {
				if ( tries++ < 100 ) {
					window.setTimeout( waitForFrame, 50 );
				}
				return;
			}
			frame.on( 'select:activate select:deactivate', function () {
				// Core updates its toolbar on the same event; read it afterwards.
				window.setTimeout( update, 0 );
			} );
			var debounce = window._ && window._.debounce;
			selection.on( 'add remove reset', debounce ? debounce( update, 30 ) : update );
			gridLibrary().on( 'add remove reset', debounce ? debounce( update, 60 ) : update );
			update();
		} )();
	}

	/* ---------- boot ---------- */

	function mount() {
		var $wrap = $( '#wpbody-content > .wrap' ).first();
		if ( ! $wrap.length ) {
			return;
		}
		var $layout = $( '<div class="mlab-media-folder-layout"></div>' );
		$wrap.before( $layout );
		$layout.append( buildSidebar(), $wrap );
		$( document.body ).addClass( 'mlab-media-folder-has-sidebar' );
		bindSidebar();
		bindPanel();
		bindFileDrag();
		if ( 'grid' === data.mode ) {
			bindSweep();
			bindSelectionBar( $wrap );
		}

		$( document ).on( 'mlab-media-folder:changed', function () {
			reload();
		} );

		// Grid mode deletes files over admin-ajax without a page reload:
		// refresh the counts once the last request of a batch has finished.
		var deleteTimer = 0;
		$( document ).ajaxComplete( function ( event, xhr, settings ) {
			var body = settings && 'string' === typeof settings.data ? settings.data : '';
			var removed = /(^|&)action=(delete|trash|untrash)-post(&|$)/.test( body ) ||
				( /(^|&)action=save-attachment(&|$)/.test( body ) && /changes%5Bstatus%5D=/.test( body ) );
			if ( removed ) {
				window.clearTimeout( deleteTimer );
				deleteTimer = window.setTimeout( reload, 300 );
			}
		} );

		// Grid mode: apply a folder from the URL once the media frame exists.
		if ( 'grid' === data.mode && state.current ) {
			var tries = 0;
			( function waitForFrame() {
				var library = gridLibrary();
				if ( library ) {
					setLibraryFolder( library, state.current );
				} else if ( tries++ < 100 ) {
					window.setTimeout( waitForFrame, 50 );
				}
			} )();
		}

		// Keep the sidebar in sync when the folder is changed elsewhere.
		if ( 'grid' === data.mode ) {
			var bound = 0;
			( function bindProps() {
				var library = gridLibrary();
				if ( library ) {
					library.props.on( 'change:' + PARAM, function ( model, value ) {
						value = value ? String( value ) : '';
						if ( value !== state.current ) {
							state.current = value;
							renderTree();
						}
					} );
				} else if ( bound++ < 100 ) {
					window.setTimeout( bindProps, 50 );
				}
			} )();
		}
	}

	$( mount );
} )( jQuery, window.wp, window.mlabMediaFolderLibrary );
