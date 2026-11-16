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
	}

	/**
	 * Save both automation and optimisation settings.
	 */
	public function save(): void {
		if ( ! current_user_can( Plugin::settings_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to change Media Folder settings.', 'mlab-media-folder' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mlab_media_folder_save_settings' );

		$automation = isset( $_POST['automation'] ) && is_array( $_POST['automation'] )
			? wp_unslash( $_POST['automation'] )
			: array();
		$optimization = isset( $_POST['optimization'] ) && is_array( $_POST['optimization'] )
			? wp_unslash( $_POST['optimization'] )
			: array();

		Automation_Settings::save( $automation );
		Optimization_Settings::save( $optimization );

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
	 * Render the settings page. WordPress and third-party notices remain above this wrapper.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::settings_capability() ) ) {
			return;
		}

		$automation = Automation_Settings::get();
		$optimization = Optimization_Settings::get();
		$capabilities = Image_Optimization::capabilities();
		$is_pro = Features::is_pro();
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

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presentation only. ?>
				<div class="notice notice-success is-dismissible mlab-mf-own-notice"><p><?php esc_html_e( 'Media Folder settings saved.', 'mlab-media-folder' ); ?></p></div>
			<?php endif; ?>

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
									<option value="avif" <?php selected( $optimization['format'], 'avif' ); ?> <?php disabled( ! $capabilities['avif'] ); ?>>AVIF<?php echo $capabilities['avif'] ? '' : ' — ' . esc_html__( 'not supported', 'mlab-media-folder' ); ?></option>
								</select>
							</label>
						</div>
						<div class="mlab-mf-three">
							<label class="mlab-mf-field"><span>JPEG</span><input type="number" min="50" max="100" name="optimization[jpeg_quality]" value="<?php echo esc_attr( (string) $optimization['jpeg_quality'] ); ?>"></label>
							<label class="mlab-mf-field"><span>WebP</span><input type="number" min="50" max="100" name="optimization[webp_quality]" value="<?php echo esc_attr( (string) $optimization['webp_quality'] ); ?>"></label>
							<label class="mlab-mf-field"><span>AVIF</span><input type="number" min="40" max="95" name="optimization[avif_quality]" value="<?php echo esc_attr( (string) $optimization['avif_quality'] ); ?>"></label>
						</div>
						<label class="mlab-mf-check"><input type="checkbox" name="optimization[convert_png]" value="1" <?php checked( ! empty( $optimization['convert_png'] ) ); ?>> <?php esc_html_e( 'Also convert PNG derivatives when transparency is supported', 'mlab-media-folder' ); ?></label>
						<div class="mlab-mf-safe"><span class="dashicons dashicons-shield"></span><div><strong><?php esc_html_e( 'Safe by default', 'mlab-media-folder' ); ?></strong><p><?php esc_html_e( 'Version 3.0 never replaces or deletes uploaded originals. Existing media and URLs are not changed.', 'mlab-media-folder' ); ?></p></div></div>
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
