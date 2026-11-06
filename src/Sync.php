<?php
/**
 * The two-way sync itself.
 *
 * @package Shakewell\RelationshipSync
 */

namespace Shakewell\RelationshipSync;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors a relationship or post object field onto the paired field of every post it points to.
 *
 * It writes with update_post_meta(), never update_field(), so the ACF filter that triggers it
 * cannot be re-entered from its own writes (that is what prevents an infinite loop).
 * There is no capability check on the other post: whoever may save the first post may link it.
 */
final class Sync {

	public const TYPES = array( 'relationship', 'post_object' );

	/**
	 * The configured pairs, with anything malformed dropped.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function pairs(): array {
		$pairs = array();
		foreach ( (array) apply_filters( 'shakewell_relationship_sync_pairs', array() ) as $pair ) {
			$pair = is_array( $pair ) ? array_values( $pair ) : array();
			if ( 2 === count( $pair ) && is_string( $pair[0] ) && is_string( $pair[1] ) && '' !== $pair[0] && '' !== $pair[1] ) {
				$pairs[] = array( $pair[0], $pair[1] );
			}
		}
		return $pairs;
	}

	/**
	 * Names or keys of the fields paired with $field.
	 *
	 * @param array $field ACF field array.
	 * @return string[]
	 */
	public static function partners( array $field ): array {
		$partners = array();
		foreach ( self::pairs() as list( $a, $b ) ) {
			foreach ( array( array( $a, $b ), array( $b, $a ) ) as list( $mine, $other ) ) {
				if ( $mine === $field['name'] || $mine === $field['key'] ) {
					$partners[] = $other;
				}
			}
		}
		return array_values( array_unique( $partners ) );
	}

	/**
	 * Post ids held by a stored or submitted value.
	 *
	 * @param mixed $value Stored or submitted value.
	 * @return int[]
	 */
	public static function ids( $value ): array {
		if ( '' === $value || null === $value || false === $value ) {
			return array();
		}
		return array_values( array_map( 'intval', (array) $value ) );
	}

	/**
	 * The relationship or post object field named or keyed $wanted that applies to the post, or false.
	 *
	 * @param int    $post_id Post.
	 * @param string $wanted  Field name or key.
	 * @return array|false
	 */
	public static function field_for( int $post_id, string $wanted ) {
		$found = false;
		foreach ( acf_get_field_groups( array( 'post_id' => $post_id ) ) as $group ) {
			$fields = acf_get_fields( $group['key'] );
			foreach ( is_array( $fields ) ? $fields : array() as $candidate ) {
				if ( ( $candidate['name'] === $wanted || $candidate['key'] === $wanted ) && in_array( $candidate['type'], self::TYPES, true ) ) {
					$found = $candidate;
				}
			}
		}
		return $found;
	}

	/**
	 * Filter on acf/update_value: the post is being saved with $value, so mirror the change.
	 *
	 * @param mixed $value   Submitted value.
	 * @param mixed $post_id Post id, or an ACF location string such as "options" or "user_3".
	 * @param array $field   ACF field array.
	 * @return mixed The value, untouched.
	 */
	public static function on_update( $value, $post_id, $field ) {
		// Stay silent while Post 2 Post for ACF is loaded, so both can be active during the switch.
		if ( class_exists( 'acf_post2post', false ) || ! is_numeric( $post_id ) || (int) $post_id < 1 || wp_is_post_revision( (int) $post_id ) ) {
			return $value;
		}
		$post_id  = (int) $post_id;
		$partners = self::partners( $field );
		if ( ! $partners ) {
			return $value;
		}
		$previous = self::ids( get_post_meta( $post_id, $field['name'], true ) );
		$new      = self::ids( $value );

		foreach ( $partners as $partner ) {
			foreach ( array_diff( $previous, $new ) as $related ) {
				self::write( $related, $partner, $post_id, false );
			}
			foreach ( $new as $related ) {
				self::write( $related, $partner, $post_id, true );
			}
		}
		return $value;
	}

	/**
	 * Adds or removes the link to $other in the field $wanted on $post_id, in ACF's own stored format.
	 *
	 * @param int    $post_id Post to change.
	 * @param string $wanted  Paired field name or key on that post.
	 * @param int    $other   Post to link to or unlink from.
	 * @param bool   $add     True to add, false to remove.
	 * @param bool   $dry_run True to report without writing.
	 * @return string added, removed, full (side already holds a different post), present, absent or nofield.
	 */
	public static function write( int $post_id, string $wanted, int $other, bool $add, bool $dry_run = false ): string {
		$field = $post_id > 0 ? self::field_for( $post_id, $wanted ) : false;
		if ( ! $field ) {
			return 'nofield';
		}
		$name   = $field['name'];
		$single = 'post_object' === $field['type'] && empty( $field['multiple'] );
		$max    = $single ? 1 : (int) ( $field['max'] ?? 0 );
		$ids    = self::ids( get_post_meta( $post_id, $name, true ) );
		$status = 'present';

		if ( $add ) {
			if ( in_array( $other, $ids, true ) ) {
				$status = 'present';
			} elseif ( 0 === $max || count( $ids ) < $max ) {
				$ids[]  = $other;
				$status = 'added';
			} else {
				$status = 'full'; // A full side is left as it is.
			}
		} else {
			if ( ! $ids ) {
				return 'absent';
			}
			$status = in_array( $other, $ids, true ) ? 'removed' : 'absent';
			$ids    = array_values( array_diff( $ids, array( $other ) ) );
		}

		if ( ! $dry_run ) {
			update_post_meta( $post_id, $name, $single ? ( $ids ? (string) $ids[0] : '' ) : array_map( 'strval', $ids ) );
			update_post_meta( $post_id, '_' . $name, $field['key'] );
		}
		return $status;
	}
}
