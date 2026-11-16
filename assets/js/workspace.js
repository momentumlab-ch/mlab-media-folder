( function ( $, wp, data ) {
	'use strict';

	if ( ! data || ! wp || ! wp.apiFetch ) {
		return;
	}

	var $workspace;
	var $search;
	var $results;
	var $inspector;
	var timer;
	var inspectorMode = false;

	function escapeHtml( value ) {
		return $( '<div>' ).text( value == null ? '' : String( value ) ).html();
	}

	function closeResults() {
		if ( $results ) {
			$results.attr( 'hidden', true ).empty();
		}
	}

	function search() {
		var q = $.trim( $search.val() );
		if ( q.length < 2 ) {
			closeResults();
			return;
		}

		wp.apiFetch( { path: data.restPath + '/search?q=' + encodeURIComponent( q ) } )
			.then( function ( response ) {
				$results.empty().removeAttr( 'hidden' );

				if ( response.folders && response.folders.length ) {
					$results.append( '<div class="mlab-mf-search-group">' + escapeHtml( data.workspaceI18n.folders ) + '</div>' );
					response.folders.forEach( function ( folder ) {
						$( '<button type="button" class="mlab-mf-search-result is-folder"></button>' )
							.attr( 'data-folder', folder.id )
							.append( '<span class="dashicons dashicons-category" aria-hidden="true"></span>' )
							.append( $( '<span class="mlab-mf-search-result-copy"></span>' ).append( $( '<strong></strong>' ).text( folder.name ), $( '<small></small>' ).text( folder.path ) ) )
							.appendTo( $results );
					} );
				}

				if ( response.media && response.media.length ) {
					$results.append( '<div class="mlab-mf-search-group">' + escapeHtml( data.workspaceI18n.media ) + '</div>' );
					response.media.forEach( function ( item ) {
						var $button = $( '<button type="button" class="mlab-mf-search-result is-media"></button>' ).attr( 'data-attachment', item.id );
						if ( item.thumbnail ) {
							$button.append( $( '<img alt="">' ).attr( 'src', item.thumbnail ) );
						} else {
							$button.append( '<span class="mlab-mf-search-placeholder dashicons dashicons-format-image" aria-hidden="true"></span>' );
						}
						$button.append(
							$( '<span class="mlab-mf-search-result-copy"></span>' )
								.append( $( '<strong></strong>' ).text( item.title || item.filename ) )
								.append( $( '<small></small>' ).text( [ item.filename, item.folder ].filter( Boolean ).join( ' · ' ) ) )
						);
						$button.appendTo( $results );
					} );
				}

				if ( ! $results.children().length ) {
					$results.append( $( '<p class="mlab-mf-search-empty"></p>' ).text( data.workspaceI18n.noResults ) );
				}
			} )
			.catch( function () {
				closeResults();
			} );
	}

	function openFolder( id ) {
		var url = new URL( window.location.href );
		url.searchParams.set( data.param, String( id ) );
		url.searchParams.delete( 'paged' );
		window.location.href = url.toString();
	}

	function healthPill( label, active, tone ) {
		if ( ! active ) {
			return '';
		}
		return '<span class="mlab-mf-health-pill ' + ( tone || '' ) + '">' + escapeHtml( label ) + '</span>';
	}

	function renderInspector( item ) {
		var size = item.size ? Math.round( item.size / 1024 ) + ' KB' : '—';
		var tags = item.tags && item.tags.length ? item.tags.map( escapeHtml ).join( ' · ' ) : '—';

		$inspector.html(
			'<div class="mlab-mf-inspector-head"><div><span class="mlab-mf-kicker">' + escapeHtml( data.workspaceI18n.inspector ) + '</span><h2>' + escapeHtml( item.title || item.filename ) + '</h2></div><button type="button" class="mlab-mf-inspector-close" aria-label="' + escapeHtml( data.workspaceI18n.close ) + '">×</button></div>' +
			'<dl class="mlab-mf-inspector-meta">' +
				'<div><dt>' + escapeHtml( data.workspaceI18n.filename ) + '</dt><dd>' + escapeHtml( item.filename ) + '</dd></div>' +
				'<div><dt>' + escapeHtml( data.workspaceI18n.folder ) + '</dt><dd>' + escapeHtml( item.folder || data.workspaceI18n.unfiled ) + '</dd></div>' +
				'<div><dt>' + escapeHtml( data.workspaceI18n.author ) + '</dt><dd>' + escapeHtml( item.author || '—' ) + '</dd></div>' +
				'<div><dt>' + escapeHtml( data.workspaceI18n.size ) + '</dt><dd>' + escapeHtml( size ) + '</dd></div>' +
				'<div><dt>' + escapeHtml( data.workspaceI18n.altText ) + '</dt><dd>' + escapeHtml( item.alt_text || '—' ) + '</dd></div>' +
				'<div><dt>' + escapeHtml( data.workspaceI18n.tags ) + '</dt><dd>' + tags + '</dd></div>' +
			'</dl>' +
			'<div class="mlab-mf-inspector-health">' +
				healthPill( data.workspaceI18n.duplicate, item.health.duplicate, 'is-warn' ) +
				healthPill( data.workspaceI18n.missing, item.health.missing, 'is-bad' ) +
				healthPill( data.workspaceI18n.noAlt, item.health.no_alt, 'is-warn' ) +
			'</div>' +
			( item.ai.available ? '<div class="mlab-mf-ai-box"><strong>' + escapeHtml( data.workspaceI18n.aiMetadata ) + '</strong><p>' + escapeHtml( item.ai.key ? data.workspaceI18n.aiReady : data.workspaceI18n.aiNeedsKey ) + '</p><button type="button" class="button mlab-mf-ai-suggest" data-id="' + item.id + '"' + ( item.ai.key ? '' : ' disabled' ) + '>' + escapeHtml( data.workspaceI18n.generate ) + '</button><div class="mlab-mf-ai-output"></div></div>' : '' )
		).addClass( 'is-open' ).removeAttr( 'hidden' );
		$( 'body' ).addClass( 'mlab-mf-inspector-open' );
	}

	function inspect( id ) {
		inspectorMode = true;
		closeResults();
		$inspector.removeAttr( 'hidden' ).addClass( 'is-loading' ).html( '<span class="spinner is-active"></span>' );
		wp.apiFetch( { path: data.restPath + '/attachment/' + id } )
			.then( function ( response ) {
				$inspector.removeClass( 'is-loading' );
				renderInspector( response );
			} )
			.catch( function () {
				$inspector.removeClass( 'is-loading' ).attr( 'hidden', true );
			} );
	}

	function generateAi( id ) {
		var $button = $inspector.find( '.mlab-mf-ai-suggest' );
		var $out = $inspector.find( '.mlab-mf-ai-output' );
		$button.prop( 'disabled', true ).addClass( 'is-busy' );
		$out.text( data.workspaceI18n.generating );

		wp.apiFetch( { path: data.restPath + '/ai/suggest/' + id, method: 'POST' } )
			.then( function ( suggestion ) {
				$button.prop( 'disabled', false ).removeClass( 'is-busy' );
				var tags = suggestion.tags || [];
				$out.empty().append(
					$( '<label></label>' ).text( data.workspaceI18n.altText ).append( $( '<textarea class="mlab-mf-ai-alt" rows="3"></textarea>' ).val( suggestion.alt_text || '' ) ),
					$( '<label></label>' ).text( data.workspaceI18n.title ).append( $( '<input class="mlab-mf-ai-title" type="text">' ).val( suggestion.title || '' ) ),
					$( '<label></label>' ).text( data.workspaceI18n.tags ).append( $( '<input class="mlab-mf-ai-tags" type="text">' ).val( tags.join( ', ' ) ) ),
					$( '<button type="button" class="button button-primary mlab-mf-ai-apply"></button>' ).attr( 'data-id', id ).text( data.workspaceI18n.apply )
				);
			} )
			.catch( function ( error ) {
				$button.prop( 'disabled', false ).removeClass( 'is-busy' );
				$out.text( error && error.message ? error.message : data.workspaceI18n.error );
			} );
	}

	function applyAi( id ) {
		var tags = $inspector.find( '.mlab-mf-ai-tags' ).val().split( ',' ).map( function ( value ) { return $.trim( value ); } ).filter( Boolean );
		wp.apiFetch( {
			path: data.restPath + '/ai/apply/' + id,
			method: 'POST',
			data: {
				alt_text: $inspector.find( '.mlab-mf-ai-alt' ).val(),
				title: $inspector.find( '.mlab-mf-ai-title' ).val(),
				tags: tags,
			},
		} ).then( renderInspector );
	}

	function build() {
		var $wrap = $( '.wrap' ).first();
		if ( ! $wrap.length || $( '.mlab-mf-workspace' ).length ) {
			return;
		}

		$workspace = $( '<div class="mlab-mf-workspace"></div>' );
		var $brand = $( '<div class="mlab-mf-workspace-brand"></div>' )
			.append( $( '<img alt="" width="36" height="36">' ).attr( 'src', data.brandMark ) )
			.append( '<div><span>MomentumLab</span><strong>Media Folder</strong></div>' );
		var $searchWrap = $( '<div class="mlab-mf-global-search"></div>' );
		$search = $( '<input type="search" autocomplete="off">' ).attr( 'placeholder', data.workspaceI18n.search );
		$results = $( '<div class="mlab-mf-search-results" hidden></div>' );
		$searchWrap.append( '<span class="dashicons dashicons-search" aria-hidden="true"></span>', $search, $results );

		var $actions = $( '<div class="mlab-mf-workspace-actions"></div>' );
		$actions.append(
			$( '<a class="button"></a>' ).attr( 'href', data.settingsUrl ).text( data.workspaceI18n.settings ),
			$( '<button type="button" class="button mlab-mf-inspector-toggle"></button>' ).text( data.workspaceI18n.inspector )
		);
		$workspace.append( $brand, $searchWrap, $actions );
		$wrap.before( $workspace );

		$inspector = $( '<aside class="mlab-mf-inspector" hidden aria-live="polite"></aside>' ).appendTo( 'body' );
	}

	function captureAttachmentClick( event ) {
		if ( ! inspectorMode ) {
			return;
		}
		var attachment = event.target.closest && event.target.closest( '.attachment[data-id]' );
		if ( ! attachment || event.target.closest( '.mlab-mf-workspace, .mlab-mf-inspector' ) ) {
			return;
		}
		var id = parseInt( attachment.getAttribute( 'data-id' ), 10 );
		if ( ! id ) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		if ( event.stopImmediatePropagation ) {
			event.stopImmediatePropagation();
		}
		inspect( id );
	}

	$( function () {
		build();
		document.addEventListener( 'click', captureAttachmentClick, true );

		$( document )
			.on( 'input', '.mlab-mf-global-search input', function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( search, 180 );
			} )
			.on( 'click', '.mlab-mf-search-result.is-folder', function () {
				openFolder( $( this ).data( 'folder' ) );
			} )
			.on( 'click', '.mlab-mf-search-result.is-media', function () {
				inspect( $( this ).data( 'attachment' ) );
			} )
			.on( 'click', '.mlab-mf-inspector-close', function () {
				inspectorMode = false;
				$inspector.attr( 'hidden', true ).removeClass( 'is-open' );
				$( 'body' ).removeClass( 'mlab-mf-inspector-open' );
			} )
			.on( 'click', '.mlab-mf-inspector-toggle', function () {
				if ( $inspector.hasClass( 'is-open' ) ) {
					$inspector.find( '.mlab-mf-inspector-close' ).trigger( 'click' );
				} else {
					inspectorMode = true;
					$inspector.removeAttr( 'hidden' ).addClass( 'is-open' ).html( '<p class="mlab-mf-inspector-empty">' + escapeHtml( data.workspaceI18n.inspectHint ) + '</p>' );
					$( 'body' ).addClass( 'mlab-mf-inspector-open' );
				}
			} )
			.on( 'click', '.mlab-mf-ai-suggest', function () {
				generateAi( $( this ).data( 'id' ) );
			} )
			.on( 'click', '.mlab-mf-ai-apply', function () {
				applyAi( $( this ).data( 'id' ) );
			} )
			.on( 'click', function ( event ) {
				if ( $results && ! $( event.target ).closest( '.mlab-mf-global-search' ).length ) {
					closeResults();
				}
			} );
	} );
} )( jQuery, window.wp, window.mlabMediaFolderWorkspace );
