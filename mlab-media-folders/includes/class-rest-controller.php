<?php
/**
 * REST API for the folder tree.
 *
 * Every route requires a logged-in user with the right capability; requests
 * from the admin are authenticated with the core "wp_rest" nonce (wp.apiFetch).
 *
 * @package MomentumLab\MediaFolders
 */

namespace MomentumLab\MediaFolders;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /wp-json/mlab-media-folders/v1.
 */
final class Rest_Controller {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'mlab-media-folders/v1';

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
