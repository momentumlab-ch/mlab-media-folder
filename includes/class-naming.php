<?php
/**
 * Token-based media naming rules.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Builds deterministic, filesystem-safe names without renaming existing files implicitly.
 */
final class Naming {

	/**
	 * Supported placeholders.
	 *
	 * @return string[]
	 */
	public static function tokens(): array {
		return array(
			'%companyname%',
			'%sitename%',
			'%imagename%',
			'%originalname%',
			'%folder%',
			'%author%',
			'%date%',
			'%year%',
			'%month%',
			'%counter%',
		);
	}

	/**
	 * Render one naming pattern.
	 *
	 * @param string              $pattern Pattern with supported tokens.
	 * @param array<string,mixed> $context Token context.
	 * @param int                 $counter Collision counter.
	 */
	public static function render( string $pattern, array $context, int $counter = 1 ): string {
		$date = isset( $context['date'] ) && $context['date'] instanceof \DateTimeInterface
			? $context['date']
			: new \DateTimeImmutable( 'now', wp_timezone() );

		$values = array(
			'%companyname%'  => (string) ( $context['company_name'] ?? Automation_Settings::get()['company_name'] ),
			'%sitename%'     => (string) ( $context['site_name'] ?? get_bloginfo( 'name' ) ),
			'%imagename%'    => (string) ( $context['image_name'] ?? $context['original_name'] ?? 'media' ),
			'%originalname%' => (string) ( $context['original_name'] ?? 'media' ),
			'%folder%'       => (string) ( $context['folder'] ?? 'unfiled' ),
			'%author%'       => (string) ( $context['author'] ?? '' ),
			'%date%'         => $date->format( 'Y-m-d' ),
			'%year%'         => $date->format( 'Y' ),
			'%month%'        => $date->format( 'm' ),
			'%counter%'      => str_pad( (string) max( 1, $counter ), 3, '0', STR_PAD_LEFT ),
		);

		$name = strtr( $pattern, $values );
		$name = remove_accents( $name );
		$name = strtolower( $name );
		$name = preg_replace( '/[^a-z0-9]+/', '-', $name );
		$name = trim( (string) $name, '-' );
		$name = preg_replace( '/-{2,}/', '-', $name );

		return '' === $name ? 'media-' . $values['%counter%'] : substr( $name, 0, 160 );
	}

	/**
	 * Preview for the settings UI.
	 *
	 * @param string $pattern Naming pattern.
	 */
	public static function preview( string $pattern ): string {
		return self::render(
			$pattern,
			array(
				'company_name'  => 'MomentumLab',
				'site_name'     => get_bloginfo( 'name' ),
				'image_name'    => 'startbereich-eigergletscher',
				'original_name' => 'img-8421',
				'folder'        => 'event-2026',
				'author'        => 'redaktion',
				'date'          => new DateTimeImmutable( '2026-11-16', wp_timezone() ),
			)
		) . '.jpg';
	}
}
