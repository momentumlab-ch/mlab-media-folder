/**
 * MomentumLab Media Folders: folder sidebar on the media library screen
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
	var STORAGE_KEY = 'mlab-mf-expanded';
	var DRAG_FILES = 'mlab-mf-files:';
	var DRAG_FOLDER = 'mlab-mf-folder:';

	var state = {
		folders: data.folders || [],
		totals: data.totals || { all: 0, unfiled: 0 },
		current: data.current === '' || data.current === undefined ? '' : String( data.current ),
		expanded: loadExpanded(),
		dragging: null,
	};

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
		var $form = $( '<form class="mlab-mf-inline"></form>' );
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
			$target = $tree.find( '[data-folder="' + parent + '"]' ).first().children( '.mlab-mf-children' );
			if ( ! $target.length ) {
				$target = $( '<ul class="mlab-mf-children"></ul>' ).appendTo( $tree.find( '[data-folder="' + parent + '"]' ).first() );
			}
		} else {
			$target = $tree.children( '.mlab-mf-root' );
		}
		var $li = $( '<li class="mlab-mf-item"></li>' ).appendTo( $target );
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
		var $row = $( '<div class="mlab-mf-row"></div>' )
			.attr( 'data-target', options.target )
			.toggleClass( 'is-current', options.current );

		var $toggle = $( '<button type="button" class="mlab-mf-toggle"></button>' ).attr( 'aria-label', i18n.toggle );
		if ( options.hasChildren ) {
			$toggle.attr( 'aria-expanded', options.expanded ? 'true' : 'false' );
		} else {
			$toggle.addClass( 'is-empty' ).attr( { 'aria-hidden': 'true', tabindex: '-1' } );
		}

		var $name = $( '<button type="button" class="mlab-mf-name"></button>' )
			.attr( 'aria-current', options.current ? 'true' : null )
			.append( $( '<span class="dashicons" aria-hidden="true"></span>' ).addClass( options.icon ) )
			.append( $( '<span class="mlab-mf-label"></span>' ).text( options.label ) )
			.append( $( '<span class="mlab-mf-count"></span>' ).text( options.count ) );

		$row.append( $toggle, $name );
		return $row;
	}

	function renderBranch( parent, $ul ) {
		childrenOf( parent ).forEach( function ( folder ) {
			var kids = childrenOf( folder.id );
			var expanded = !! state.expanded[ folder.id ];
			var $li = $( '<li class="mlab-mf-item"></li>' ).attr( 'data-folder', folder.id );
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
				var $more = $( '<button type="button" class="mlab-mf-more" aria-haspopup="true" aria-expanded="false"></button>' )
					.attr( 'aria-label', i18n.actions + ': ' + folder.name )
					.append( '<span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>' );
				var $menu = $( '<div class="mlab-mf-menu" role="menu" hidden></div>' );
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
				var $children = $( '<ul class="mlab-mf-children"></ul>' );
				renderBranch( folder.id, $children );
				$li.append( $children );
			}
			$ul.append( $li );
		} );
	}

	function renderTree() {
		$tree.empty();

		var $fixed = $( '<ul class="mlab-mf-fixed"></ul>' );
		$( '<li class="mlab-mf-item"></li>' )
			.append( makeRow( { target: '', label: i18n.all, count: state.totals.all, icon: 'dashicons-admin-media', current: '' === state.current } ) )
			.appendTo( $fixed );
		$( '<li class="mlab-mf-item"></li>' )
			.append( makeRow( { target: UNFILED, label: i18n.unfiled, count: state.totals.unfiled, icon: 'dashicons-portfolio', current: UNFILED === state.current } ) )
			.appendTo( $fixed );

		var $root = $( '<ul class="mlab-mf-root"></ul>' );
		renderBranch( 0, $root );

		$tree.append( $fixed, $root );
	}

	function buildSidebar() {
		$sidebar = $( '<aside class="mlab-mf-sidebar"></aside>' ).attr( 'aria-label', i18n.title );
		var $head = $( '<div class="mlab-mf-head"></div>' ).append( $( '<h2></h2>' ).text( i18n.title ) );
		if ( data.canManage ) {
			$( '<button type="button" class="button button-small mlab-mf-new"></button>' )
				.append( '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' )
				.append( $( '<span></span>' ).text( i18n.newFolder ) )
				.appendTo( $head );
		}
		$tree = $( '<div class="mlab-mf-tree"></div>' );
		$status = $( '<p class="mlab-mf-status" role="status" aria-live="polite"></p>' );
		var $hint = $( '<p class="mlab-mf-hint"></p>' ).text( i18n.dropHint );
		var $credit = $( '<p class="mlab-mf-credit"></p>' ).text( i18n.credit + ' · v' + data.version );
		$sidebar.append( $head, $tree, $status, $hint, $credit );
		renderTree();
		return $sidebar;
	}

	/* ---------- events ---------- */

	function closeMenus() {
		$sidebar.find( '.mlab-mf-menu' ).attr( 'hidden', true );
		$sidebar.find( '.mlab-mf-more' ).attr( 'aria-expanded', 'false' );
	}

	function bindSidebar() {
		$sidebar.on( 'click', '.mlab-mf-name', function () {
			applyFilter( String( $( this ).closest( '.mlab-mf-row' ).attr( 'data-target' ) ) );
		} );

		$sidebar.on( 'click', '.mlab-mf-toggle:not(.is-empty)', function () {
			var id = $( this ).closest( '.mlab-mf-item' ).attr( 'data-folder' );
			state.expanded[ id ] = ! state.expanded[ id ];
			saveExpanded();
			renderTree();
			$tree.find( '[data-folder="' + id + '"] > .mlab-mf-row .mlab-mf-toggle' ).trigger( 'focus' );
		} );

		$sidebar.on( 'click', '.mlab-mf-new', function () {
			startCreate( selectedFolderId() );
		} );

		$sidebar.on( 'click', '.mlab-mf-more', function ( event ) {
			event.stopPropagation();
			var $menu = $( this ).siblings( '.mlab-mf-menu' );
			var open = $menu.is( '[hidden]' );
			closeMenus();
			if ( open ) {
				$menu.removeAttr( 'hidden' );
				$( this ).attr( 'aria-expanded', 'true' );
				$menu.find( 'button' ).first().trigger( 'focus' );
			}
		} );

		$sidebar.on( 'click', '.mlab-mf-menu button', function () {
			var $row = $( this ).closest( '.mlab-mf-row' );
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
		$sidebar.on( 'keydown', '.mlab-mf-menu', function ( event ) {
			if ( 'Escape' === event.key ) {
				closeMenus();
				$( this ).siblings( '.mlab-mf-more' ).trigger( 'focus' );
			}
		} );

		/* Drop targets: folder rows and "Unfiled". "All files" takes folders only (move to top level). */
		$sidebar.on( 'dragover', '.mlab-mf-row', function ( event ) {
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
		$sidebar.on( 'dragleave drop', '.mlab-mf-row', function () {
			$( this ).removeClass( 'is-drop-target' );
		} );
		$sidebar.on( 'drop', '.mlab-mf-row', function ( event ) {
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
		badge.className = 'mlab-mf-drag-badge';
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

	/* ---------- boot ---------- */

	function mount() {
		var $wrap = $( '#wpbody-content > .wrap' ).first();
		if ( ! $wrap.length ) {
			return;
		}
		var $layout = $( '<div class="mlab-mf-layout"></div>' );
		$wrap.before( $layout );
		$layout.append( buildSidebar(), $wrap );
		$( document.body ).addClass( 'mlab-mf-has-sidebar' );
		bindSidebar();
		bindFileDrag();

		$( document ).on( 'mlab-mf:changed', function () {
			reload();
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
} )( jQuery, window.wp, window.mlabMediaFoldersLibrary );
