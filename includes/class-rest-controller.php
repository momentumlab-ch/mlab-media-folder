<?php
/**
 * REST API for the folder tree.
 *
 * Every route requires a logged-in user with the right capability; requests
 * from the admin are authenticated with the core "wp_rest" nonce (wp.apiFetch).
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /wp-json/mlab-media-folder/v1.
 */
final class Rest_Controller {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'mlab-media-folder/v1';

	/**
	 * Registers all routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/folders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_folders' ),
					'permission_callback' => array( $this, 'can_use' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_folder' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'name'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'parent' => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/folders/(?P<id>\d+)',
			array(
				'args' => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_folder' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'name'   => array( 'type' => 'string' ),
						'parent' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_folder' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/assign',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'assign' ),
				'permission_callback' => array( $this, 'can_use' ),
				'args'                => array(
					'ids'    => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'maxItems' => Folders::MAX_ASSIGN,
					),
					'folder' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'can_use' ),
				'args'                => array(
					'q' => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 2,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/attachment/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'attachment' ),
				'permission_callback' => array( $this, 'can_edit_attachment' ),
				'args'                => array(
					'id' => array( 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ai/suggest/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_suggest' ),
				'permission_callback' => array( $this, 'can_edit_attachment' ),
				'args'                => array(
					'id' => array( 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ai/apply/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_apply' ),
				'permission_callback' => array( $this, 'can_edit_attachment' ),
				'args'                => array(
					'id'          => array( 'type' => 'integer', 'minimum' => 1 ),
					'alt_text'    => array( 'type' => 'string' ),
					'title'       => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'tags'        => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * Permission: use folders (read the tree, move files).
	 *
	 * @return bool
	 */
	public function can_use() {
		return current_user_can( Plugin::use_capability() );
	}

	/**
	 * Permission: manage folders (create, rename, move, delete).
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( Plugin::use_capability() ) && current_user_can( Plugin::manage_capability() );
	}

	/**
	 * GET /folders
	 *
	 * @return WP_REST_Response
	 */
	public function list_folders() {
		return rest_ensure_response(
			array(
				'folders' => Folders::all(),
				'totals'  => Folders::totals(),
			)
		);
	}

	/**
	 * Search files and folders together.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function search( WP_REST_Request $request ) {
		global $wpdb;

		$query = trim( sanitize_text_field( (string) $request['q'] ) );
		if ( mb_strlen( $query ) < 2 ) {
			return rest_ensure_response( array( 'folders' => array(), 'media' => array() ) );
		}

		$folders = get_terms(
			array(
				'taxonomy'   => Taxonomy::NAME,
				'hide_empty' => false,
				'search'     => $query,
				'number'     => 12,
			)
		);
		$folder_rows = array();
		if ( ! is_wp_error( $folders ) ) {
			foreach ( $folders as $term ) {
				$folder_rows[] = array(
					'id'   => (int) $term->term_id,
					'name' => Folders::name( $term ),
					'path' => implode( ' › ', Folders::path( (int) $term->term_id ) ),
				);
			}
		}

		$like = '%' . $wpdb->esc_like( $query ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Bounded cross-field media search for the admin workspace.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
				LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_wp_attachment_image_alt'
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash'
				AND (p.post_title LIKE %s OR p.post_excerpt LIKE %s OR f.meta_value LIKE %s OR a.meta_value LIKE %s)
				ORDER BY p.post_date DESC
				LIMIT 20",
				$like,
				$like,
				$like,
				$like
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$media = array();
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$media[] = array(
				'id'        => $id,
				'title'     => get_the_title( $id ),
				'filename'  => wp_basename( (string) get_attached_file( $id ) ),
				'alt_text'  => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'thumbnail' => wp_get_attachment_image_url( $id, 'thumbnail' ),
				'folder'    => implode( ' › ', Folders::path( Folders::of_attachment( $id ) ) ),
			);
		}

		return rest_ensure_response( array( 'folders' => $folder_rows, 'media' => $media ) );
	}

	/**
	 * Inspector payload for one attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function attachment( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$post   = get_post( $id );
		$file   = get_attached_file( $id );
		$health = Health::scan();
		$author = $post ? get_the_author_meta( 'display_name', (int) $post->post_author ) : '';

		return rest_ensure_response(
			array(
				'id'          => $id,
				'title'       => get_the_title( $id ),
				'filename'    => is_string( $file ) ? wp_basename( $file ) : '',
				'mime'        => (string) get_post_mime_type( $id ),
				'size'        => is_string( $file ) && is_file( $file ) ? (int) filesize( $file ) : null,
				'alt_text'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'description' => $post ? (string) $post->post_content : '',
				'author'      => (string) $author,
				'date'        => $post ? get_the_date( DATE_ATOM, $post ) : '',
				'folder_id'   => Folders::of_attachment( $id ),
				'folder'      => implode( ' › ', Folders::path( Folders::of_attachment( $id ) ) ),
				'tags'        => wp_get_object_terms( $id, Tags::NAME, array( 'fields' => 'names' ) ),
				'health'      => array(
					'duplicate' => in_array( $id, $health['duplicate'], true ),
					'missing'   => in_array( $id, $health['missing'], true ),
					'no_alt'    => wp_attachment_is_image( $id ) && '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
				),
				'ai'          => array(
					'available' => Features::available( 'ai_metadata' ),
					'key'       => 'none' !== Automation_Settings::api_key_source(),
				),
			)
		);
	}

	/**
	 * Generate an AI suggestion without writing it.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function ai_suggest( WP_REST_Request $request ) {
		$result = AI::suggest( (int) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Apply an explicitly approved AI suggestion.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function ai_apply( WP_REST_Request $request ) {
		if ( ! Features::available( 'ai_metadata' ) ) {
			return new \WP_Error( 'mlab_media_folder_pro_required', __( 'Media AI requires MomentumLab Media Folder Pro.', 'mlab-media-folder' ), array( 'status' => 403 ) );
		}

		$id = (int) $request['id'];
		if ( $request->has_param( 'alt_text' ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $request['alt_text'] ) );
		}

		$post_update = array( 'ID' => $id );
		if ( $request->has_param( 'title' ) ) {
			$post_update['post_title'] = sanitize_text_field( (string) $request['title'] );
		}
		if ( $request->has_param( 'description' ) ) {
			$post_update['post_content'] = sanitize_textarea_field( (string) $request['description'] );
		}
		if ( 1 < count( $post_update ) ) {
			wp_update_post( $post_update );
		}

		if ( $request->has_param( 'tags' ) ) {
			$tags = array_slice(
				array_values( array_filter( array_map( 'sanitize_text_field', (array) $request['tags'] ) ) ),
				0,
				20
			);
			wp_set_object_terms( $id, $tags, Tags::NAME, false );
		}

		return $this->attachment( $request );
	}

	/**
	 * POST /folders
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function create_folder( WP_REST_Request $request ) {
		$result = Folders::create( $request['name'], (int) $request['parent'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * PATCH /folders/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function update_folder( WP_REST_Request $request ) {
		$name   = $request->has_param( 'name' ) ? $request['name'] : null;
		$parent = $request->has_param( 'parent' ) ? (int) $request['parent'] : null;
		$result = Folders::update( (int) $request['id'], $name, $parent );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * DELETE /folders/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function delete_folder( WP_REST_Request $request ) {
		$result = Folders::delete( (int) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /assign
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function assign( WP_REST_Request $request ) {
		$result = Folders::assign( (array) $request['ids'], (int) $request['folder'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
