<?php
/**
 * Admin settings for Media Folder 3.0.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * One coherent settings surface for workspace, automation, optimisation and licensing.
 */
final class Settings_Page {

	const SLUG = 'mlab-media-folder-settings';

	/**
	 * Register admin hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_mlab_media_folder_save_settings', array( $this, 'save' ) );
		add_action( 'admin_post_mlab_media_folder_migrate', array( $this, 'migrate' ) );
		add_action( 'admin_post_mlab_media_folder_bulk_optimize', array( $this, 'bulk_optimize' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Add settings below Media without introducing another top-level menu.
	 */
	public function menu(): void {
		add_media_page(
			__( 'Media Folder Settings', 'mlab-media-folder' ),
			__( 'Media Folder Settings', 'mlab-media-folder' ),
			Plugin::settings_capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue only on our own page.
	 *
	 * @param string $hook_suffix Current admin hook.
	 */
	public function assets( string $hook_suffix ): void {
		if ( 'media_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'mlab-media-folder-settings',
			MLAB_MEDIA_FOLDER_URL . 'assets/css/settings.css',
			array(),
			MLAB_MEDIA_FOLDER_VERSION
		);
		wp_enqueue_script(
			'mlab-media-folder-settings',
			MLAB_MEDIA_FOLDER_URL . 'assets/js/settings.js',
			array( 'jquery' ),
			MLAB_MEDIA_FOLDER_VERSION,
			true
		);
	}

	/**
	 * Save both automation and optimisation settings.
	 */
	public function save(): void {
		if ( ! current_user_can( Plugin::settings_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to change Media Folder settings.', 'mlab-media-folder' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mlab_media_folder_save_settings' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Automation_Settings sanitizes every supported field.
		$automation = isset( $_POST['automation'] ) && is_array( $_POST['automation'] ) ? wp_unslash( $_POST['automation'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Optimization_Settings sanitizes every supported field.
		$optimization = isset( $_POST['optimization'] ) && is_array( $_POST['optimization'] ) ? wp_unslash( $_POST['optimization'] ) : array();

		Automation_Settings::save( $automation );
		Optimization_Settings::save( $optimization );

		if ( Features::available( 'dynamic_views' ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Dynamic_Views::replace normalizes every row.
			$views = isset( $_POST['dynamic_views'] ) && is_array( $_POST['dynamic_views'] ) ? wp_unslash( $_POST['dynamic_views'] ) : array();
			Dynamic_Views::replace( $views );
		}

		if ( Features::available( 'folder_permissions' ) && isset( $_POST['permissions'] ) && is_array( $_POST['permissions'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each nested value is sanitized below before persistence.
			$permissions = wp_unslash( $_POST['permissions'] );
			foreach ( $permissions as $folder_id => $rules ) {
				$folder_id = absint( $folder_id );
				if ( ! $folder_id || ! is_array( $rules ) || ! Folders::exists( $folder_id ) ) {
					continue;
				}
				$roles = isset( $rules['roles'] ) && is_array( $rules['roles'] ) ? $rules['roles'] : array();
				$users = isset( $rules['users'] )
					? preg_split( '/[\s,]+/', sanitize_text_field( (string) $rules['users'] ) )
					: array();
				Folder_Permissions::save( $folder_id, $roles, is_array( $users ) ? $users : array(), (string) ( $rules['color'] ?? '' ) );
			}
		}

		$url = add_query_arg(
			array(
				'page'    => self::SLUG,
				'updated' => '1',
			),
			admin_url( 'upload.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Import one detected folder taxonomy without touching the source plugin data.
	 */
	public function migrate(): void {
		if ( ! current_user_can( Plugin::settings_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to migrate media folders.', 'mlab-media-folder' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mlab_media_folder_migrate' );

		$taxonomy = isset( $_REQUEST['taxonomy'] ) ? sanitize_key( wp_unslash( $_REQUEST['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$result   = Migration::import( $taxonomy );

		$args = array( 'page' => self::SLUG );
		if ( is_wp_error( $result ) ) {
			$args['migration_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['migration_folders'] = (int) $result['folders'];
			$args['migration_files']   = (int) $result['assignments'];
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'upload.php' ) ) );
		exit;
	}

	/**
	 * Start the explicit Pro rebuild of existing image derivatives.
	 */
	public function bulk_optimize(): void {
		if ( ! current_user_can( Plugin::settings_capability() ) || ! Features::is_pro() ) {
			wp_die( esc_html__( 'You are not allowed to run bulk image optimization.', 'mlab-media-folder' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mlab_media_folder_bulk_optimize' );
		Bulk_Optimize::start();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::SLUG,
					'bulk_start' => '1',
				),
				admin_url( 'upload.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings page. WordPress and third-party notices remain above this wrapper.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::settings_capability() ) ) {
			return;
		}

		$automation   = Automation_Settings::get();
		$optimization = Optimization_Settings::get();
		$capabilities = Image_Optimization::capabilities();
		$is_pro       = Features::is_pro();
		$views        = Dynamic_Views::all();
		$folders      = Folders::flattened_tree();
		$roles        = wp_roles()->roles;
		$authors      = get_users(
			array(
				'capability' => 'upload_files',
				'number'     => 200,
				'orderby'    => 'display_name',
				'order'      => 'ASC',
			)
		);
		$migrations = Migration::detect();
		$bulk_status = Bulk_Optimize::status();
		/* translators: 1: checked images, 2: rebuilt images, 3: failed images. */
		$bulk_status_text = sprintf(
			__( '%1$d checked · %2$d rebuilt · %3$d failed', 'mlab-media-folder' ),
			(int) $bulk_status['checked'],
			(int) $bulk_status['rebuilt'],
			(int) $bulk_status['failed']
		);
		?>
		<div class="wrap mlab-mf-settings">
			<div class="mlab-mf-brandbar">
				<img src="<?php echo esc_url( MLAB_MEDIA_FOLDER_URL . 'assets/images/media-folder-mark.svg' ); ?>" alt="" width="54" height="54">
				<div class="mlab-mf-brandcopy">
					<span class="mlab-mf-brandname">MomentumLab</span>
					<h1><?php esc_html_e( 'Media Folder', 'mlab-media-folder' ); ?></h1>
					<p><?php esc_html_e( 'Organise, find, optimise and automate your WordPress media library.', 'mlab-media-folder' ); ?></p>
				</div>
				<div class="mlab-mf-license-state <?php echo $is_pro ? 'is-pro' : 'is-free'; ?>">
					<span class="mlab-mf-license-dot" aria-hidden="true"></span>
					<?php echo esc_html( $is_pro ? __( 'Pro active', 'mlab-media-folder' ) : __( 'Free', 'mlab-media-folder' ) ); ?>
				</div>
			</div>

			<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only status flags after nonce-protected redirects. ?>
			<?php if ( isset( $_GET['bulk_start'] ) ) : ?>
				<div class="notice notice-success is-dismissible mlab-mf-own-notice"><p><?php esc_html_e( 'Bulk image optimization was queued and will run in small background batches.', 'mlab-media-folder' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['migration_folders'], $_GET['migration_files'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presentation only. ?>
				<div class="notice notice-success is-dismissible mlab-mf-own-notice"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: folders, 2: assignments. */
							__( 'Migration complete: %1$d folders created, %2$d media assignments copied.', 'mlab-media-folder' ),
							absint( $_GET['migration_folders'] ),
							absint( $_GET['migration_files'] )
						)
					);
					?>
				</p></div>
			<?php elseif ( isset( $_GET['migration_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presentation only. ?>
				<div class="notice notice-error is-dismissible mlab-mf-own-notice"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['migration_error'] ) ) ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presentation only. ?>
				<div class="notice notice-success is-dismissible mlab-mf-own-notice"><p><?php esc_html_e( 'Media Folder settings saved.', 'mlab-media-folder' ); ?></p></div>
			<?php endif; ?>

			<?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mlab_media_folder_save_settings">
				<?php wp_nonce_field( 'mlab_media_folder_save_settings' ); ?>

				<div class="mlab-mf-settings-grid">
					<section class="mlab-mf-card">
						<div class="mlab-mf-card-heading">
							<div>
								<span class="mlab-mf-kicker"><?php esc_html_e( 'Automation', 'mlab-media-folder' ); ?></span>
								<h2><?php esc_html_e( 'AI metadata', 'mlab-media-folder' ); ?> <?php self::pro_badge(); ?></h2>
							</div>
						</div>
						<p><?php esc_html_e( 'Generate suggestions for alt text, titles, descriptions, tags and a short image name. Suggestions never overwrite media automatically.', 'mlab-media-folder' ); ?></p>
						<?php self::locked_note( 'ai_metadata' ); ?>
						<div class="<?php echo Features::available( 'ai_metadata' ) ? '' : 'mlab-mf-disabled'; ?>">
							<label class="mlab-mf-field">
								<span><?php esc_html_e( 'OpenAI API key', 'mlab-media-folder' ); ?></span>
								<?php if ( 'constant' === Automation_Settings::api_key_source() ) : ?>
									<input type="text" value="<?php esc_attr_e( 'Configured in wp-config.php', 'mlab-media-folder' ); ?>" disabled>
									<small><?php esc_html_e( 'The MLAB_MEDIA_FOLDER_OPENAI_API_KEY constant takes precedence.', 'mlab-media-folder' ); ?></small>
								<?php else : ?>
									<input type="password" name="automation[api_key]" value="" autocomplete="new-password" placeholder="<?php echo 'stored' === Automation_Settings::api_key_source() ? esc_attr__( 'Key stored — enter a new key to replace it', 'mlab-media-folder' ) : 'sk-…'; ?>">
									<?php if ( 'stored' === Automation_Settings::api_key_source() ) : ?>
										<label class="mlab-mf-check"><input type="checkbox" name="automation[clear_api_key]" value="1"> <?php esc_html_e( 'Remove stored key', 'mlab-media-folder' ); ?></label>
									<?php endif; ?>
								<?php endif; ?>
							</label>
							<div class="mlab-mf-two">
								<label class="mlab-mf-field">
									<span><?php esc_html_e( 'Model', 'mlab-media-folder' ); ?></span>
									<input type="text" name="automation[model]" value="<?php echo esc_attr( (string) $automation['model'] ); ?>" placeholder="gpt-6-luna">
								</label>
								<label class="mlab-mf-field">
									<span><?php esc_html_e( 'Language / locale', 'mlab-media-folder' ); ?></span>
									<input type="text" name="automation[language]" value="<?php echo esc_attr( (string) $automation['language'] ); ?>">
								</label>
							</div>
							<?php if ( Features::available( 'advanced_automation' ) ) : ?>
								<div class="mlab-mf-safe">
									<span class="dashicons dashicons-clock" aria-hidden="true"></span>
									<div>
										<strong><?php esc_html_e( 'Optional background automation', 'mlab-media-folder' ); ?></strong>
										<p><?php esc_html_e( 'Runs after upload in WP-Cron so uploads stay responsive. Disabled by default.', 'mlab-media-folder' ); ?></p>
										<label class="mlab-mf-check"><input type="checkbox" name="automation[auto_alt]" value="1" <?php checked( ! empty( $automation['auto_alt'] ) ); ?>> <?php esc_html_e( 'Automatically create alt text for new images', 'mlab-media-folder' ); ?></label>
										<label class="mlab-mf-check"><input type="checkbox" name="automation[auto_tags]" value="1" <?php checked( ! empty( $automation['auto_tags'] ) ); ?>> <?php esc_html_e( 'Automatically create media tags for new images', 'mlab-media-folder' ); ?></label>
									</div>
								</div>
							<?php endif; ?>
						</div>
					</section>

					<section class="mlab-mf-card">
						<div class="mlab-mf-card-heading"><div><span class="mlab-mf-kicker"><?php esc_html_e( 'Rules', 'mlab-media-folder' ); ?></span><h2><?php esc_html_e( 'Glossary', 'mlab-media-folder' ); ?> <?php self::pro_badge(); ?></h2></div></div>
						<p><?php esc_html_e( 'Keep terminology consistent and explicitly exclude terms AI must never produce.', 'mlab-media-folder' ); ?></p>
						<?php self::locked_note( 'glossary' ); ?>
						<div class="<?php echo Features::available( 'glossary' ) ? '' : 'mlab-mf-disabled'; ?>">
							<label class="mlab-mf-field">
								<span><?php esc_html_e( 'Preferred terms / replacements', 'mlab-media-folder' ); ?></span>
								<textarea name="automation[glossary]" rows="6" placeholder="Ski Mountaineering = Skimo"><?php echo esc_textarea( (string) $automation['glossary'] ); ?></textarea>
								<small><?php esc_html_e( 'One rule per line: source = preferred term.', 'mlab-media-folder' ); ?></small>
							</label>
							<label class="mlab-mf-field">
								<span><?php esc_html_e( 'Never use', 'mlab-media-folder' ); ?></span>
								<textarea name="automation[blocked_terms]" rows="4" placeholder="internal project code&#10;confidential name"><?php echo esc_textarea( (string) $automation['blocked_terms'] ); ?></textarea>
							</label>
						</div>
					</section>

					<section class="mlab-mf-card">
						<div class="mlab-mf-card-heading"><div><span class="mlab-mf-kicker"><?php esc_html_e( 'Naming', 'mlab-media-folder' ); ?></span><h2><?php esc_html_e( 'Naming rules', 'mlab-media-folder' ); ?> <?php self::pro_badge(); ?></h2></div></div>
						<p><?php esc_html_e( 'Build predictable, web-safe names from tokens. Existing files are never renamed automatically.', 'mlab-media-folder' ); ?></p>
						<?php self::locked_note( 'naming_rules' ); ?>
						<div class="<?php echo Features::available( 'naming_rules' ) ? '' : 'mlab-mf-disabled'; ?>">
							<label class="mlab-mf-field">
								<span><?php esc_html_e( 'Company name', 'mlab-media-folder' ); ?></span>
								<input type="text" name="automation[company_name]" value="<?php echo esc_attr( (string) $automation['company_name'] ); ?>">
							</label>
							<label class="mlab-mf-field">
								<span><?php esc_html_e( 'Pattern', 'mlab-media-folder' ); ?></span>
								<input type="text" name="automation[naming_pattern]" value="<?php echo esc_attr( (string) $automation['naming_pattern'] ); ?>">
								<small><?php echo esc_html( implode( ' · ', Naming::tokens() ) ); ?></small>
							</label>
							<div class="mlab-mf-preview"><span><?php esc_html_e( 'Preview', 'mlab-media-folder' ); ?></span><code><?php echo esc_html( Naming::preview( (string) $automation['naming_pattern'] ) ); ?></code></div>
							<label class="mlab-mf-check"><input type="checkbox" name="automation[naming_enabled]" value="1" <?php checked( ! empty( $automation['naming_enabled'] ) ); ?>> <?php esc_html_e( 'Use naming rule for new uploads after the upload-safe workflow is enabled', 'mlab-media-folder' ); ?></label>
						</div>
					</section>

					<section class="mlab-mf-card">
						<div class="mlab-mf-card-heading"><div><span class="mlab-mf-kicker"><?php esc_html_e( 'Performance', 'mlab-media-folder' ); ?></span><h2><?php esc_html_e( 'Image optimization', 'mlab-media-folder' ); ?></h2></div></div>
						<p><?php esc_html_e( 'Optimize newly generated WordPress image sizes. Originals, existing URLs and existing media remain untouched.', 'mlab-media-folder' ); ?></p>
						<label class="mlab-mf-switch"><input type="checkbox" name="optimization[enabled]" value="1" <?php checked( ! empty( $optimization['enabled'] ) ); ?>><span></span><?php esc_html_e( 'Optimize new image derivatives', 'mlab-media-folder' ); ?></label>
						<div class="mlab-mf-two">
							<label class="mlab-mf-field"><span><?php esc_html_e( 'Max. dimension', 'mlab-media-folder' ); ?></span><input type="number" min="1280" max="7680" step="1" name="optimization[max_dimension]" value="<?php echo esc_attr( (string) $optimization['max_dimension'] ); ?>"></label>
							<label class="mlab-mf-field"><span><?php esc_html_e( 'Output format', 'mlab-media-folder' ); ?></span>
								<select name="optimization[format]">
									<option value="original" <?php selected( $optimization['format'], 'original' ); ?>><?php esc_html_e( 'Keep original format', 'mlab-media-folder' ); ?></option>
									<option value="webp" <?php selected( $optimization['format'], 'webp' ); ?> <?php disabled( ! $capabilities['webp'] ); ?>>WebP<?php echo $capabilities['webp'] ? '' : ' — ' . esc_html__( 'not supported', 'mlab-media-folder' ); ?></option>
									<option value="avif" <?php selected( $optimization['format'], 'avif' ); ?> <?php disabled( ! $capabilities['avif'] || ! $is_pro ); ?>>AVIF · PRO<?php echo $capabilities['avif'] ? '' : ' — ' . esc_html__( 'not supported', 'mlab-media-folder' ); ?></option>
								</select>
							</label>
						</div>
						<div class="mlab-mf-three">
							<label class="mlab-mf-field"><span>JPEG</span><input type="number" min="50" max="100" name="optimization[jpeg_quality]" value="<?php echo esc_attr( (string) $optimization['jpeg_quality'] ); ?>"></label>
							<label class="mlab-mf-field"><span>WebP</span><input type="number" min="50" max="100" name="optimization[webp_quality]" value="<?php echo esc_attr( (string) $optimization['webp_quality'] ); ?>"></label>
							<label class="mlab-mf-field"><span>AVIF</span><input type="number" min="40" max="95" name="optimization[avif_quality]" value="<?php echo esc_attr( (string) $optimization['avif_quality'] ); ?>"></label>
						</div>
						<label class="mlab-mf-check"><input type="checkbox" name="optimization[convert_png]" value="1" <?php checked( ! empty( $optimization['convert_png'] ) ); ?>> <?php esc_html_e( 'Also convert PNG derivatives when transparency is supported', 'mlab-media-folder' ); ?></label>

						<div class="<?php echo $is_pro ? '' : 'mlab-mf-disabled'; ?>">
							<label class="mlab-mf-field">
								<span><?php esc_html_e( 'Metadata privacy', 'mlab-media-folder' ); ?> <?php self::pro_badge(); ?></span>
								<select name="optimization[exif_policy]">
									<option value="keep" <?php selected( $optimization['exif_policy'], 'keep' ); ?>><?php esc_html_e( 'Keep metadata', 'mlab-media-folder' ); ?></option>
									<option value="strip_gps" <?php selected( $optimization['exif_policy'], 'strip_gps' ); ?>><?php esc_html_e( 'Remove GPS metadata from generated derivatives', 'mlab-media-folder' ); ?></option>
									<option value="strip_all" <?php selected( $optimization['exif_policy'], 'strip_all' ); ?>><?php esc_html_e( 'Remove all metadata from generated derivatives', 'mlab-media-folder' ); ?></option>
								</select>
								<small><?php esc_html_e( 'Requires Imagick. Uploaded originals remain unchanged.', 'mlab-media-folder' ); ?></small>
							</label>
						</div>

						<?php if ( $is_pro ) : ?>
							<div class="mlab-mf-bulk-state">
								<div>
									<strong><?php esc_html_e( 'Existing library', 'mlab-media-folder' ); ?></strong>
									<p><?php echo esc_html( $bulk_status_text ); ?></p>
								</div>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mlab_media_folder_bulk_optimize' ), 'mlab_media_folder_bulk_optimize' ) ); ?>"><?php esc_html_e( 'Rebuild optimized derivatives', 'mlab-media-folder' ); ?></a>
							</div>
						<?php endif; ?>

						<div class="mlab-mf-safe"><span class="dashicons dashicons-shield"></span><div><strong><?php esc_html_e( 'Safe by default', 'mlab-media-folder' ); ?></strong><p><?php esc_html_e( 'Version 3.0 never replaces or deletes uploaded originals. Existing URLs remain valid; bulk rebuild leaves older derivatives in place.', 'mlab-media-folder' ); ?></p></div></div>
					</section>

					<section class="mlab-mf-card mlab-mf-card-wide">
						<div class="mlab-mf-card-heading"><div><span class="mlab-mf-kicker"><?php esc_html_e( 'Smart Views', 'mlab-media-folder' ); ?></span><h2><?php esc_html_e( 'Saved Dynamic Views', 'mlab-media-folder' ); ?> <?php self::pro_badge(); ?></h2></div></div>
						<p><?php esc_html_e( 'Create reusable views by media type, uploader and age. They appear directly below the core Smart Views in the Media Library.', 'mlab-media-folder' ); ?></p>
						<?php self::locked_note( 'dynamic_views' ); ?>
						<div class="<?php echo Features::available( 'dynamic_views' ) ? '' : 'mlab-mf-disabled'; ?>">
							<div class="mlab-mf-dynamic-views">
							<?php
							$view_rows   = $views;
							$view_rows[] = array(
								'id'     => '',
								'name'   => '',
								'mime'   => '',
								'author' => 0,
								'days'   => 0,
								'min_mb' => 0,
							);
							foreach ( $view_rows as $index => $view ) :
								?>
								<div class="mlab-mf-rule-row">
									<input type="hidden" name="dynamic_views[<?php echo (int) $index; ?>][id]" value="<?php echo esc_attr( (string) $view['id'] ); ?>">
									<label class="mlab-mf-field"><span><?php esc_html_e( 'Name', 'mlab-media-folder' ); ?></span><input type="text" name="dynamic_views[<?php echo (int) $index; ?>][name]" value="<?php echo esc_attr( (string) $view['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Recent event images', 'mlab-media-folder' ); ?>"></label>
									<label class="mlab-mf-field"><span><?php esc_html_e( 'Type', 'mlab-media-folder' ); ?></span><select name="dynamic_views[<?php echo (int) $index; ?>][mime]"><option value="" <?php selected( $view['mime'], '' ); ?>><?php esc_html_e( 'All media', 'mlab-media-folder' ); ?></option><option value="image" <?php selected( $view['mime'], 'image' ); ?>><?php esc_html_e( 'Images', 'mlab-media-folder' ); ?></option><option value="video" <?php selected( $view['mime'], 'video' ); ?>><?php esc_html_e( 'Video', 'mlab-media-folder' ); ?></option><option value="audio" <?php selected( $view['mime'], 'audio' ); ?>><?php esc_html_e( 'Audio', 'mlab-media-folder' ); ?></option><option value="application/pdf" <?php selected( $view['mime'], 'application/pdf' ); ?>>PDF</option></select></label>
									<label class="mlab-mf-field"><span><?php esc_html_e( 'Uploader', 'mlab-media-folder' ); ?></span><select name="dynamic_views[<?php echo (int) $index; ?>][author]"><option value="0"><?php esc_html_e( 'Any uploader', 'mlab-media-folder' ); ?></option><?php foreach ( $authors as $author ) : ?><option value="<?php echo (int) $author->ID; ?>" <?php selected( (int) $view['author'], (int) $author->ID ); ?>><?php echo esc_html( $author->display_name ); ?></option><?php endforeach; ?></select></label>
									<label class="mlab-mf-field"><span><?php esc_html_e( 'Last days', 'mlab-media-folder' ); ?></span><input type="number" min="0" max="3650" name="dynamic_views[<?php echo (int) $index; ?>][days]" value="<?php echo esc_attr( (string) $view['days'] ); ?>" placeholder="0"></label>
									<label class="mlab-mf-field"><span><?php esc_html_e( 'Minimum MB', 'mlab-media-folder' ); ?></span><input type="number" min="0" max="102400" name="dynamic_views[<?php echo (int) $index; ?>][min_mb]" value="<?php echo esc_attr( (string) $view['min_mb'] ); ?>" placeholder="0"></label>
									<button type="button" class="button-link-delete mlab-mf-remove-view"><?php esc_html_e( 'Remove', 'mlab-media-folder' ); ?></button>
								</div>
							<?php endforeach; ?>
							</div>
							<p><button type="button" class="button mlab-mf-add-view"><?php esc_html_e( 'Add view', 'mlab-media-folder' ); ?></button></p>
						</div>
					</section>

					<section class="mlab-mf-card mlab-mf-card-wide">
						<div class="mlab-mf-card-heading"><div><span class="mlab-mf-kicker"><?php esc_html_e( 'Access', 'mlab-media-folder' ); ?></span><h2><?php esc_html_e( 'Folder permissions', 'mlab-media-folder' ); ?> <?php self::pro_badge(); ?></h2></div></div>
						<p><?php esc_html_e( 'Empty rules mean everyone who can use the Media Library can access the folder. Administrators always retain access.', 'mlab-media-folder' ); ?></p>
						<?php self::locked_note( 'folder_permissions' ); ?>
						<div class="<?php echo Features::available( 'folder_permissions' ) ? '' : 'mlab-mf-disabled'; ?>">
							<?php foreach ( $folders as $folder ) : ?>
								<?php $rules = Folder_Permissions::get( (int) $folder['id'] ); ?>
								<div class="mlab-mf-permission-row">
									<div class="mlab-mf-folder-path"><?php echo esc_html( str_repeat( '— ', (int) $folder['depth'] ) . $folder['name'] ); ?></div>
									<label class="mlab-mf-field"><span><?php esc_html_e( 'Roles', 'mlab-media-folder' ); ?></span><select multiple size="3" name="permissions[<?php echo (int) $folder['id']; ?>][roles][]"><?php foreach ( $roles as $role_key => $role ) : ?><option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( in_array( $role_key, $rules['roles'], true ) ); ?>><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></option><?php endforeach; ?></select></label>
									<label class="mlab-mf-field"><span><?php esc_html_e( 'Specific user IDs', 'mlab-media-folder' ); ?></span><input type="text" name="permissions[<?php echo (int) $folder['id']; ?>][users]" value="<?php echo esc_attr( implode( ', ', $rules['users'] ) ); ?>" placeholder="12, 44"></label>
									<label class="mlab-mf-field"><span><?php esc_html_e( 'Folder color', 'mlab-media-folder' ); ?></span><input type="color" name="permissions[<?php echo (int) $folder['id']; ?>][color]" value="<?php echo esc_attr( '' !== $rules['color'] ? $rules['color'] : '#f2aa00' ); ?>"></label>
								</div>
							<?php endforeach; ?>
						</div>
					</section>

					<section class="mlab-mf-card mlab-mf-card-wide">
						<div class="mlab-mf-card-heading"><div><span class="mlab-mf-kicker"><?php esc_html_e( 'Tools', 'mlab-media-folder' ); ?></span><h2><?php esc_html_e( 'Import / Migration', 'mlab-media-folder' ); ?></h2></div></div>
						<p><?php esc_html_e( 'Copy folder structures into Media Folder without deleting or modifying the source plugin data.', 'mlab-media-folder' ); ?></p>
						<?php if ( ! $migrations ) : ?>
							<div class="mlab-mf-safe"><span class="dashicons dashicons-yes-alt"></span><div><strong><?php esc_html_e( 'No supported source detected', 'mlab-media-folder' ); ?></strong><p><?php esc_html_e( 'When a supported folder taxonomy is present it will appear here automatically.', 'mlab-media-folder' ); ?></p></div></div>
						<?php else : ?>
							<?php foreach ( $migrations as $source ) : ?>
							<div class="mlab-mf-migration-row">
								<div>
									<strong><?php echo esc_html( $source['label'] ); ?></strong>
									<p>
										<?php
										/* translators: 1: detected folders, 2: detected attachment relationships. */
										echo esc_html(
											sprintf(
												__( '%1$d folders · %2$d media relationships detected', 'mlab-media-folder' ),
												(int) $source['folders'],
												(int) $source['attachments']
											)
										);
										?>
									</p>
								</div>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'mlab_media_folder_migrate', 'taxonomy' => $source['taxonomy'] ), admin_url( 'admin-post.php' ) ), 'mlab_media_folder_migrate' ) ); ?>"><?php esc_html_e( 'Copy into Media Folder', 'mlab-media-folder' ); ?></a>
							</div>
						<?php endforeach; ?>
						<?php endif; ?>
					</section>

					<section class="mlab-mf-card mlab-mf-card-wide">
						<div class="mlab-mf-card-heading"><div><span class="mlab-mf-kicker"><?php esc_html_e( 'License', 'mlab-media-folder' ); ?></span><h2><?php esc_html_e( 'Free & Pro', 'mlab-media-folder' ); ?></h2></div></div>
						<div class="mlab-mf-plan-grid">
							<div><h3><?php esc_html_e( 'Free', 'mlab-media-folder' ); ?></h3><p><?php esc_html_e( 'Folders, drag & drop, bulk workflow, core Smart Views, global search, inspector, Media Health, migration and mobile workspace.', 'mlab-media-folder' ); ?></p></div>
							<div class="is-pro"><h3>Pro</h3><p><?php esc_html_e( 'AI metadata, glossary, naming rules, folder permissions, dynamic saved views and advanced automation.', 'mlab-media-folder' ); ?></p><code><?php echo esc_html( Features::PRO_PRODUCT ); ?></code></div>
						</div>
					</section>
				</div>

				<p class="submit"><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save settings', 'mlab-media-folder' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a Pro badge.
	 */
	private static function pro_badge(): void {
		echo '<span class="mlab-mf-pro">PRO</span>';
	}

	/**
	 * Render a visible, non-blocking license explanation.
	 *
	 * @param string $feature Feature key.
	 */
	private static function locked_note( string $feature ): void {
		if ( Features::available( $feature ) ) {
			return;
		}
		echo '<div class="mlab-mf-lock"><span class="dashicons dashicons-lock" aria-hidden="true"></span><span>'
			. esc_html__( 'Requires the mlab-media-folder-pro unlock from MomentumLab Core / License Server.', 'mlab-media-folder' )
			. '</span></div>';
	}
}
