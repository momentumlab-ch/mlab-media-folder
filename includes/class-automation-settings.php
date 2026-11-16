<?php
/**
 * Media Automation settings.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized settings for AI, glossary and naming automation.
 */
final class Automation_Settings {

	const OPTION = 'mlab_media_folder_automation';

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'provider'        => 'openai',
			'model'           => 'gpt-6-luna',
			'language'        => get_locale(),
			'company_name'    => get_bloginfo( 'name' ),
			'naming_pattern'  => '%companyname%-%imagename%-%counter%',
			'naming_enabled'  => false,
			'auto_alt'        => false,
			'auto_tags'       => false,
			'glossary'        => '',
			'blocked_terms'   => '',
			'api_key'         => '',
		);
	}

	/**
	 * Public, normalized settings. The API key remains encrypted.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$value = get_option( self::OPTION, array() );
		$value = is_array( $value ) ? $value : array();
		return array_merge( self::defaults(), $value );
	}

	/**
	 * API key from wp-config.php or encrypted settings.
	 */
	public static function api_key(): string {
		if ( defined( 'MLAB_MEDIA_FOLDER_OPENAI_API_KEY' ) && is_string( MLAB_MEDIA_FOLDER_OPENAI_API_KEY ) ) {
			return trim( MLAB_MEDIA_FOLDER_OPENAI_API_KEY );
		}
		$settings = self::get();
		return Secrets::decrypt( (string) $settings['api_key'] );
	}

	/**
	 * Where the currently active API key comes from.
	 */
	public static function api_key_source(): string {
		if ( defined( 'MLAB_MEDIA_FOLDER_OPENAI_API_KEY' ) && '' !== self::api_key() ) {
			return 'constant';
		}
		return '' === self::api_key() ? 'none' : 'stored';
	}

	/**
	 * Save settings from an admin form.
	 *
	 * @param array<string,mixed> $input Raw form input.
	 */
	public static function save( array $input ): void {
		$current = self::get();
		$next    = $current;

		$next['provider']     = 'openai';
		$next['model']        = sanitize_text_field( (string) ( $input['model'] ?? $current['model'] ) );
		$next['language']     = sanitize_text_field( (string) ( $input['language'] ?? $current['language'] ) );
		$next['company_name'] = sanitize_text_field( (string) ( $input['company_name'] ?? $current['company_name'] ) );

		if ( Features::available( 'naming_rules' ) ) {
			$next['naming_pattern'] = sanitize_text_field( (string) ( $input['naming_pattern'] ?? $current['naming_pattern'] ) );
			$next['naming_enabled'] = ! empty( $input['naming_enabled'] );
		}
		if ( Features::available( 'advanced_automation' ) ) {
			$next['auto_alt']  = ! empty( $input['auto_alt'] );
			$next['auto_tags'] = ! empty( $input['auto_tags'] );
		}
		if ( Features::available( 'glossary' ) ) {
			$next['glossary']      = sanitize_textarea_field( (string) ( $input['glossary'] ?? $current['glossary'] ) );
			$next['blocked_terms'] = sanitize_textarea_field( (string) ( $input['blocked_terms'] ?? $current['blocked_terms'] ) );
		}

		if ( Features::available( 'ai_metadata' ) && 'constant' !== self::api_key_source() ) {
			if ( ! empty( $input['clear_api_key'] ) ) {
				$next['api_key'] = '';
			} elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
				$next['api_key'] = Secrets::encrypt( trim( (string) $input['api_key'] ) );
			}
		}

		update_option( self::OPTION, $next, false );
	}

	/**
	 * Glossary lines in the form "preferred = replacement".
	 *
	 * @return array<string,string>
	 */
	public static function glossary_map(): array {
		$map = array();
		foreach ( preg_split( '/\R/u', (string) self::get()['glossary'] ) ?: array() as $line ) {
			$parts = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$map[ $parts[0] ] = $parts[1];
			}
		}
		return $map;
	}

	/**
	 * Terms the AI must not produce.
	 *
	 * @return string[]
	 */
	public static function blocked_terms(): array {
		$terms = preg_split( '/[\r\n,]+/u', (string) self::get()['blocked_terms'] ) ?: array();
		return array_values( array_filter( array_map( 'trim', $terms ) ) );
	}
}
