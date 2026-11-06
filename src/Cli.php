<?php
/**
 * WP-CLI command.
 *
 * @package Shakewell\RelationshipSync
 */

namespace Shakewell\RelationshipSync;

defined( 'ABSPATH' ) || exit;

/**
 * Two-way relationship sync tools.
 */
final class Cli {

	private const BATCH = 200;

	/**
	 * Adds every missing back-link between paired fields. Never removes anything. Safe to run again.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shakewell-relationship-sync repair --dry-run
	 *     wp shakewell-relationship-sync repair
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Flags.
	 */
	public function repair( $args, $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			\WP_CLI::error( 'Advanced Custom Fields is not active.' );
		}
		if ( ! Sync::pairs() ) {
			\WP_CLI::error( 'No pairs configured. Add them with the shakewell_relationship_sync_pairs filter.' );
		}
		$dry    = ! empty( $assoc['dry-run'] );
		$counts = self::run( $dry );
		\WP_CLI::success(
			sprintf(
				'%s %d posts, %d links. %s %d back-links. Left alone: %d where the other side was full, %d pointing at deleted posts.',
				$dry ? 'Dry run, checked' : 'Checked',
				$counts['posts'],
				$counts['links'],
				$dry ? 'Would add' : 'Added',
				$counts['added'],
				$counts['full'],
				$counts['dangling']
			)
		);
	}

	/**
	 * Walks every post that stores a paired field, in batches, and adds the missing back-links.
	 *
	 * @param bool $dry True to count without writing.
	 * @return array{posts: int, links: int, added: int, full: int, dangling: int}
	 */
	public static function run( bool $dry ): array {
		$names = array();
		foreach ( Sync::pairs() as $pair ) {
			foreach ( $pair as $wanted ) {
				$field   = 0 === strpos( $wanted, 'field_' ) ? acf_get_field( $wanted ) : false;
				$names[] = $field ? $field['name'] : $wanted;
			}
		}
		$names      = array_values( array_unique( $names ) );
		$meta_query = array( 'relation' => 'OR' );
		foreach ( $names as $name ) {
			$meta_query[] = array(
				'key'     => $name,
				'compare' => 'EXISTS',
			);
		}
		$types  = array_values( array_diff( get_post_types(), array( 'revision', 'nav_menu_item', 'customize_changeset' ) ) );
		$counts = array(
			'posts'    => 0,
			'links'    => 0,
			'added'    => 0,
			'full'     => 0,
			'dangling' => 0,
		);
		$page   = 1;
		do {
			$ids = get_posts(
				array(
					'post_type'              => $types,
					'post_status'            => array_merge( array_values( get_post_stati() ), array( 'trash' ) ),
					'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'posts_per_page'         => self::BATCH,
					'paged'                  => $page++,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $ids as $post_id ) {
				++$counts['posts'];
				foreach ( $names as $name ) {
					$field = Sync::field_for( $post_id, $name );
					if ( ! $field ) {
						continue;
					}
					foreach ( Sync::ids( get_post_meta( $post_id, $name, true ) ) as $related ) {
						++$counts['links'];
						if ( ! get_post( $related ) ) {
							++$counts['dangling'];
							continue;
						}
						foreach ( Sync::partners( $field ) as $partner ) {
							$status = Sync::write( $related, $partner, $post_id, true, $dry );
							if ( isset( $counts[ $status ] ) ) {
								++$counts[ $status ];
							}
						}
					}
				}
			}
			$full_batch = count( $ids ) === self::BATCH;
		} while ( $full_batch );

		return $counts;
	}
}
