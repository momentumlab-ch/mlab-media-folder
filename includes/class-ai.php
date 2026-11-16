<?php
/**
 * AI metadata suggestions using a customer-owned OpenAI API key.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Generates and applies media metadata through a customer-owned AI account.
 */
final class AI {

	const ENDPOINT = 'https://api.openai.com/v1/responses';

	/**
	 * Generate a metadata suggestion for one image.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,mixed>|\\WP_Error
	 */
	public static function suggest( int $attachment_id ) {
		if ( ! Features::available( 'ai_metadata' ) ) {
			return new \WP_Error( 'mlab_media_folder_pro_required', __( 'Media AI requires MomentumLab Media Folder Pro.', 'mlab-media-folder' ), array( 'status' => 403 ) );
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new \WP_Error( 'mlab_media_folder_not_image', __( 'AI metadata is currently available for images.', 'mlab-media-folder' ), array( 'status' => 400 ) );
		}

		$key = Automation_Settings::api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'mlab_media_folder_ai_key_missing', __( 'Add your OpenAI API key under Media Folder settings first.', 'mlab-media-folder' ), array( 'status' => 400 ) );
		}

		$file = get_attached_file( $attachment_id );
		if ( ! is_string( $file ) || ! is_readable( $file ) ) {
			return new \WP_Error( 'mlab_media_folder_ai_file_unavailable', __( 'The image file cannot be read from this server.', 'mlab-media-folder' ), array( 'status' => 400 ) );
		}
		$size = filesize( $file );
		if ( false === $size || 8 * MB_IN_BYTES < $size ) {
			return new \WP_Error( 'mlab_media_folder_ai_file_too_large', __( 'The image is too large for the current AI workflow. Use an image up to 8 MB.', 'mlab-media-folder' ), array( 'status' => 400 ) );
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! is_string( $mime ) || ! str_starts_with( $mime, 'image/' ) ) {
			return new \WP_Error( 'mlab_media_folder_ai_mime', __( 'The attachment is not a supported image.', 'mlab-media-folder' ), array( 'status' => 400 ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local attachment file, not a URL.
		$bytes = file_get_contents( $file );
		if ( false === $bytes ) {
			return new \WP_Error( 'mlab_media_folder_ai_read_failed', __( 'The image could not be prepared for AI analysis.', 'mlab-media-folder' ), array( 'status' => 500 ) );
		}

		$settings = Automation_Settings::get();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for an image data URL sent to the selected AI provider.
		$image_data = base64_encode( $bytes );
		$body       = array(
			'model' => (string) $settings['model'],
			'input' => array(
				array(
					'role' => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => self::prompt( $attachment_id, $settings ),
						),
						array(
							'type'      => 'input_image',
							'image_url' => 'data:' . $mime . ';base64,' . $image_data,
						),
					),
				),
			),
		);

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'mlab_media_folder_ai_unreachable', __( 'The AI provider could not be reached. Please try again.', 'mlab-media-folder' ), array( 'status' => 502 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $data ) ) {
			$message = is_array( $data ) ? sanitize_text_field( (string) ( $data['error']['message'] ?? '' ) ) : '';
			return new \WP_Error( 'mlab_media_folder_ai_error', '' !== $message ? $message : __( 'The AI provider returned an invalid response.', 'mlab-media-folder' ), array( 'status' => 502 ) );
		}

		$json = self::decode_json( self::response_text( $data ) );
		if ( ! is_array( $json ) ) {
			return new \WP_Error( 'mlab_media_folder_ai_invalid_json', __( 'The AI response could not be understood. Generate a new suggestion.', 'mlab-media-folder' ), array( 'status' => 502 ) );
		}
		return self::normalize( $json );
	}

	/**
	 * Apply approved suggestion fields.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param array<string,mixed> $suggestion    Sanitized suggestion.
	 * @param bool                $alt            Write alt text.
	 * @param bool                $tags           Write media tags.
	 * @param bool                $title          Write title/description.
	 * @return true|\WP_Error
	 */
	public static function apply( int $attachment_id, array $suggestion, bool $alt = true, bool $tags = true, bool $title = true ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new \WP_Error( 'mlab_media_folder_attachment_missing', __( 'Attachment not found.', 'mlab-media-folder' ) );
		}

		if ( $alt && array_key_exists( 'alt_text', $suggestion ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $suggestion['alt_text'] ) );
		}

		if ( $title ) {
			$post = array( 'ID' => $attachment_id );
			if ( isset( $suggestion['title'] ) ) {
				$post['post_title'] = sanitize_text_field( (string) $suggestion['title'] );
			}
			if ( isset( $suggestion['description'] ) ) {
				$post['post_content'] = sanitize_textarea_field( (string) $suggestion['description'] );
			}
			if ( 1 < count( $post ) ) {
				$result = wp_update_post( $post, true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		if ( $tags && isset( $suggestion['tags'] ) && is_array( $suggestion['tags'] ) ) {
			$terms = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', $suggestion['tags'] ) ) ), 0, 20 );
			$result = wp_set_object_terms( $attachment_id, $terms, Tags::NAME, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Build an instruction prompt with local context and glossary rules.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param array<string,mixed> $settings Automation settings.
	 */
	private static function prompt( int $attachment_id, array $settings ): string {
		$filename = wp_basename( (string) get_attached_file( $attachment_id ) );
		$folder = Folders::path( Folders::of_attachment( $attachment_id ) );
		$lines = array(
			'Create concise WordPress media metadata.',
			'Language/locale: ' . (string) $settings['language'],
			'Filename: ' . $filename,
			'Current title: ' . get_the_title( $attachment_id ),
			'Folder: ' . ( $folder ? implode( ' > ', $folder ) : 'Unfiled' ),
			'Return JSON only with keys alt_text, title, description, tags, image_name.',
			'Never invent identities or names. Alt text must describe useful visual content and avoid keyword stuffing.',
			'tags must contain 3 to 8 short reusable keywords. image_name must be a short slug concept without extension.',
		);
		$glossary = Automation_Settings::glossary_map();
		if ( $glossary ) {
			$rules = array();
			foreach ( $glossary as $from => $to ) {
				$rules[] = $from . ' => ' . $to;
			}
			$lines[] = 'Preferred terminology/replacements: ' . implode( '; ', $rules );
		}
		$blocked = Automation_Settings::blocked_terms();
		if ( $blocked ) {
			$lines[] = 'Never use these terms: ' . implode( ', ', $blocked );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Extract text output from the Responses API payload.
	 *
	 * @param array<string,mixed> $data Provider response.
	 */
	private static function response_text( array $data ): string {
		foreach ( (array) ( $data['output'] ?? array() ) as $item ) {
			foreach ( (array) ( $item['content'] ?? array() ) as $content ) {
				if ( 'output_text' === ( $content['type'] ?? '' ) && isset( $content['text'] ) ) {
					return (string) $content['text'];
				}
			}
		}
		return '';
	}

	/**
	 * Decode JSON output, allowing an optional Markdown code fence.
	 *
	 * @param string $text Provider output text.
	 * @return array<string,mixed>|null
	 */
	private static function decode_json( string $text ): ?array {
		$text = trim( $text );
		$text = preg_replace( '/^```(?:json)?\\s*|\\s*```$/i', '', $text );
		$data = json_decode( (string) $text, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Sanitize provider output before exposing or applying it.
	 *
	 * @param array<string,mixed> $data Provider output.
	 * @return array<string,mixed>
	 */
	private static function normalize( array $data ): array {
		$tags = is_array( $data['tags'] ?? null ) ? $data['tags'] : array();
		$tags = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', $tags ) ) ), 0, 12 );
		return array(
			'alt_text' => sanitize_text_field( (string) ( $data['alt_text'] ?? '' ) ),
			'title' => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'tags' => $tags,
			'image_name' => sanitize_title( (string) ( $data['image_name'] ?? '' ) ),
		);
	}
}
