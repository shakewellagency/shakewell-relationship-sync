<?php
/**
 * Two-way relationship sync, against real ACF with local field groups.
 *
 * Uses book, author, review and series post types, with paired relationship and post object fields.
 *
 * @package Shakewell\RelationshipSync
 */

namespace Shakewell\RelationshipSync\Tests;

use Shakewell\RelationshipSync\Cli;
use Shakewell\RelationshipSync\Plugin;
use Shakewell\RelationshipSync\Sync;
use WP_UnitTestCase;

final class SyncTest extends WP_UnitTestCase {

	private int $book;
	private int $book2;
	private int $book3;
	private int $author;
	private int $author2;
	private int $review;
	private int $review2;
	private int $series;

	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'book', 'author', 'review', 'series' ) as $type ) {
			register_post_type( $type );
		}
		add_filter(
			'shakewell_relationship_sync_pairs',
			static fn() => array(
				array( 'book_author_relationship', 'book_author_relationship' ),
				array( 'review_book', 'review_book' ),
				array( 'book_parent_series', 'book_parent_series' ),
				array( 'field_book_featured', 'field_review_featured' ),
			)
		);
		$this->group( 'book', array(
			$this->field( 'field_book_rv', 'review_book', 'relationship' ),
			$this->field( 'field_book_au', 'book_author_relationship', 'relationship' ),
			$this->field( 'field_book_pp', 'book_parent_series', 'post_object', array( 'multiple' => 0 ) ),
			$this->field( 'field_book_featured', 'featured_reviews', 'relationship', array( 'max' => 2 ) ),
		) );
		$this->group( 'series', array( $this->field( 'field_series_pp', 'book_parent_series', 'relationship' ) ) );
		$this->group( 'review', array(
			$this->field( 'field_review_rv', 'review_book', 'post_object', array( 'multiple' => 1 ) ),
			$this->field( 'field_review_featured', 'featured_book', 'post_object', array( 'multiple' => 0 ) ),
		) );
		$this->group( 'author', array( $this->field( 'field_author_au', 'book_author_relationship', 'relationship' ) ) );

		$this->book    = self::factory()->post->create( array( 'post_type' => 'book' ) );
		$this->book2   = self::factory()->post->create( array( 'post_type' => 'book' ) );
		$this->book3   = self::factory()->post->create( array( 'post_type' => 'book' ) );
		$this->author    = self::factory()->post->create( array( 'post_type' => 'author' ) );
		$this->author2   = self::factory()->post->create( array( 'post_type' => 'author' ) );
		$this->review    = self::factory()->post->create( array( 'post_type' => 'review' ) );
		$this->review2   = self::factory()->post->create( array( 'post_type' => 'review' ) );
		$this->series = self::factory()->post->create( array( 'post_type' => 'series' ) );
	}

	private function field( string $key, string $name, string $type, array $extra = array() ): array {
		return array_merge( array( 'key' => $key, 'label' => $name, 'name' => $name, 'type' => $type ), $extra );
	}

	private function group( string $type, array $fields ): void {
		acf_add_local_field_group( array(
			'key'      => 'group_' . $type,
			'title'    => $type,
			'fields'   => $fields,
			'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => $type ) ) ),
		) );
	}

	/** What an editor's save does: ACF runs the update_value filters, then stores. */
	private function save( string $key, $value, int $post ): void {
		update_field( $key, $value, $post );
	}

	/** Stored ids, as strings, exactly as the plugin leaves them. */
	private function stored( int $post, string $name ) {
		return get_post_meta( $post, $name, true );
	}

	private function strings( int ...$ids ): array {
		return array_map( 'strval', $ids );
	}

	public function test_linking_adds_the_back_link_in_acf_format(): void {
		$this->save( 'field_review_rv', array( $this->book, $this->book2 ), $this->review );
		$this->assertSame( $this->strings( $this->review ), $this->stored( $this->book, 'review_book' ) );
		$this->assertSame( $this->strings( $this->review ), $this->stored( $this->book2, 'review_book' ) );
		$this->assertSame( 'field_book_rv', get_post_meta( $this->book, '_review_book', true ) );
	}

	public function test_both_directions_work(): void {
		$this->save( 'field_author_au', array( $this->book ), $this->author );
		$this->assertSame( $this->strings( $this->author ), $this->stored( $this->book, 'book_author_relationship' ) );
		$this->save( 'field_book_au', array( $this->author, $this->author2 ), $this->book );
		$this->assertSame( $this->strings( $this->book ), $this->stored( $this->author2, 'book_author_relationship' ) );
	}

	public function test_back_link_is_appended_and_order_is_kept(): void {
		$this->save( 'field_review_rv', array( $this->book ), $this->review );
		$this->save( 'field_review_rv', array( $this->book ), $this->review2 );
		$this->assertSame( $this->strings( $this->review, $this->review2 ), $this->stored( $this->book, 'review_book' ) );
	}

	public function test_unlinking_removes_the_back_link_and_keeps_the_others(): void {
		$this->save( 'field_review_rv', array( $this->book, $this->book2 ), $this->review );
		$this->save( 'field_review_rv', array( $this->book ), $this->review2 );
		$this->save( 'field_review_rv', array( $this->book2 ), $this->review );
		$this->assertSame( $this->strings( $this->review2 ), $this->stored( $this->book, 'review_book' ) );
		$this->assertSame( $this->strings( $this->review ), $this->stored( $this->book2, 'review_book' ) );
		$this->save( 'field_review_rv', array(), $this->review );
		$this->assertSame( array(), $this->stored( $this->book2, 'review_book' ) );
	}

	public function test_replacing_moves_the_link(): void {
		$this->save( 'field_book_au', array( $this->author ), $this->book );
		$this->save( 'field_book_au', array( $this->author2 ), $this->book );
		$this->assertSame( array(), $this->stored( $this->author, 'book_author_relationship' ) );
		$this->assertSame( $this->strings( $this->book ), $this->stored( $this->author2, 'book_author_relationship' ) );
	}

	public function test_relinking_does_not_duplicate(): void {
		$this->save( 'field_review_rv', array( $this->book ), $this->review );
		$before = get_post_meta( $this->book );
		$this->save( 'field_review_rv', array( $this->book ), $this->review );
		$this->save( 'field_review_rv', array( $this->book ), $this->review );
		$this->assertSame( $this->strings( $this->review ), $this->stored( $this->book, 'review_book' ) );
		$this->assertSame( $before, get_post_meta( $this->book ) );
	}

	public function test_saving_both_sides_does_not_loop_and_is_stable(): void {
		$runs = 0;
		add_filter(
			'acf/update_value/type=relationship',
			static function ( $value ) use ( &$runs ) {
				++$runs;
				return $value;
			},
			12
		);
		$this->save( 'field_book_au', array( $this->author ), $this->book );
		$this->assertSame( 1, $runs, 'One save runs the filter once: the back-link write does not re-enter it.' );
		$this->save( 'field_author_au', array( $this->book ), $this->author );
		$this->assertSame( $this->strings( $this->author ), $this->stored( $this->book, 'book_author_relationship' ) );
		$this->assertSame( $this->strings( $this->book ), $this->stored( $this->author, 'book_author_relationship' ) );
	}

	public function test_post_object_single_side_stores_a_string_and_clears_to_empty(): void {
		$this->save( 'field_series_pp', array( $this->book, $this->book2 ), $this->series );
		$this->assertSame( (string) $this->series, $this->stored( $this->book, 'book_parent_series' ) );
		$this->save( 'field_book_pp', $this->series, $this->book3 );
		$this->assertSame( $this->strings( $this->book, $this->book2, $this->book3 ), $this->stored( $this->series, 'book_parent_series' ) );
		$this->save( 'field_book_pp', '', $this->book3 );
		$this->assertSame( $this->strings( $this->book, $this->book2 ), $this->stored( $this->series, 'book_parent_series' ) );
		$this->save( 'field_series_pp', array( $this->book2 ), $this->series );
		$this->assertSame( '', $this->stored( $this->book, 'book_parent_series' ) );
	}

	public function test_post_object_multiple_is_an_array_of_strings(): void {
		$this->save( 'field_book_rv', array( $this->review ), $this->book );
		$this->assertSame( $this->strings( $this->book ), $this->stored( $this->review, 'review_book' ) );
	}

	public function test_a_single_side_that_already_has_another_post_is_left_alone(): void {
		$this->save( 'field_series_pp', array( $this->book ), $this->series );
		$other = self::factory()->post->create( array( 'post_type' => 'series' ) );
		$this->save( 'field_series_pp', array( $this->book ), $other );
		$this->assertSame( (string) $this->series, $this->stored( $this->book, 'book_parent_series' ) );
		$this->assertSame( $this->strings( $this->book ), $this->stored( $other, 'book_parent_series' ) );
	}

	public function test_a_relationship_side_at_its_max_is_left_alone(): void {
		// Different field names, paired by key, with a max of 2 on the book side.
		$r3 = self::factory()->post->create( array( 'post_type' => 'review' ) );
		foreach ( array( $this->review, $this->review2, $r3 ) as $review ) {
			$this->save( 'field_review_featured', $this->book, $review );
		}
		$this->assertSame( $this->strings( $this->review, $this->review2 ), $this->stored( $this->book, 'featured_reviews' ) );
	}

	public function test_pairs_may_use_different_field_keys(): void {
		$this->save( 'field_review_featured', $this->book, $this->review );
		$this->assertSame( $this->strings( $this->review ), $this->stored( $this->book, 'featured_reviews' ) );
		$this->save( 'field_book_featured', array( $this->review2 ), $this->book );
		$this->assertSame( '', $this->stored( $this->review, 'featured_book' ) );
		$this->assertSame( (string) $this->book, $this->stored( $this->review2, 'featured_book' ) );
	}

	public function test_unpaired_fields_are_ignored(): void {
		remove_all_filters( 'shakewell_relationship_sync_pairs' );
		$this->save( 'field_review_rv', array( $this->book ), $this->review );
		$this->assertSame( '', $this->stored( $this->book, 'review_book' ) );
	}

	public function test_malformed_pairs_are_dropped(): void {
		add_filter( 'shakewell_relationship_sync_pairs', static fn() => array( 'x', array( 'a' ), array( 'a', '' ), array( 'a', 1 ), array( 'a', 'b' ) ), 20 );
		$this->assertSame( array( array( 'a', 'b' ) ), Sync::pairs() );
	}

	public function test_unknown_targets_revisions_and_non_post_locations_are_ignored(): void {
		$this->save( 'field_review_rv', array( $this->book, 999999 ), $this->review );
		$this->assertSame( array(), get_post_meta( 999999 ) );
		$revision = wp_insert_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $this->review ) );
		$this->assertTrue( (bool) wp_is_post_revision( $revision ) );
		$field    = acf_get_field( 'field_review_rv' );
		$this->assertSame( array( $this->book2 ), Sync::on_update( array( $this->book2 ), (int) $revision, $field ) );
		Sync::on_update( array( $this->book2 ), 'options', $field );
		Sync::on_update( array( $this->book2 ), 0, $field );
		Sync::on_update( array( $this->book2 ), 'user_1', $field );
		$this->assertSame( '', $this->stored( $this->book2, 'review_book' ) );
	}

	public function test_nothing_is_hooked_on_trash_restore_or_delete(): void {
		foreach ( array( 'wp_trash_post', 'trashed_post', 'untrashed_post', 'before_delete_post', 'save_post', 'transition_post_status' ) as $hook ) {
			global $wp_filter;
			foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$this->assertFalse( is_array( $callback['function'] ) && is_string( $callback['function'][0] ) && 0 === strpos( $callback['function'][0], 'Shakewell\\RelationshipSync' ) );
				}
			}
		}
		$this->save( 'field_review_rv', array( $this->book ), $this->review );
		wp_trash_post( $this->review );
		wp_untrash_post( $this->review );
		$this->assertSame( $this->strings( $this->review ), $this->stored( $this->book, 'review_book' ) );
	}

	public function test_trashed_posts_are_linked_and_unlinked_like_any_other(): void {
		wp_trash_post( $this->book );
		$this->save( 'field_review_rv', array( $this->book ), $this->review );
		$this->assertSame( $this->strings( $this->review ), $this->stored( $this->book, 'review_book' ) );
		$this->save( 'field_review_rv', array(), $this->review );
		$this->assertSame( array(), $this->stored( $this->book, 'review_book' ) );
	}

	public function test_a_user_who_cannot_edit_the_other_post_still_gets_the_back_link(): void {
		// Same as the original mu-plugin: no capability check on the other post.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( current_user_can( 'edit_post', $this->book ) );
		$this->save( 'field_review_rv', array( $this->book ), $this->review );
		$this->assertSame( $this->strings( $this->review ), $this->stored( $this->book, 'review_book' ) );
	}

	public function test_random_saves_leave_relationship_pairs_consistent(): void {
		mt_srand( 42 );
		$books = array( $this->book, $this->book2, $this->book3 );
		$authors = array( $this->author, $this->author2 );
		for ( $i = 0; $i < 60; $i++ ) {
			if ( mt_rand( 0, 1 ) ) {
				shuffle( $authors );
				$this->save( 'field_book_au', array_slice( $authors, 0, mt_rand( 0, 2 ) ), $books[ mt_rand( 0, 2 ) ] );
			} else {
				shuffle( $books );
				$this->save( 'field_author_au', array_slice( $books, 0, mt_rand( 0, 3 ) ), $authors[ mt_rand( 0, 1 ) ] );
			}
		}
		foreach ( $books as $book ) {
			foreach ( Sync::ids( $this->stored( $book, 'book_author_relationship' ) ) as $author ) {
				$this->assertContains( $book, Sync::ids( $this->stored( $author, 'book_author_relationship' ) ) );
			}
		}
		foreach ( $authors as $author ) {
			foreach ( Sync::ids( $this->stored( $author, 'book_author_relationship' ) ) as $book ) {
				$this->assertContains( $author, Sync::ids( $this->stored( $book, 'book_author_relationship' ) ) );
			}
		}
	}

	/** Stored one-sided, as after an import that bypassed ACF. */
	private function one_sided(): void {
		update_post_meta( $this->review, 'review_book', $this->strings( $this->book, $this->book2 ) );
		update_post_meta( $this->review2, 'review_book', $this->strings( $this->book ) );
		update_post_meta( $this->author, 'book_author_relationship', $this->strings( 999999 ) );
	}

	public function test_repair_dry_run_reports_and_writes_nothing(): void {
		$this->one_sided();
		$counts = Cli::run( true );
		$this->assertSame( 3, $counts['added'] );
		$this->assertSame( 1, $counts['dangling'] );
		$this->assertSame( '', $this->stored( $this->book, 'review_book' ) );
	}

	public function test_repair_adds_back_links_in_order_and_is_idempotent(): void {
		$this->one_sided();
		$counts = Cli::run( false );
		$this->assertSame( 3, $counts['added'] );
		$this->assertSame( $this->strings( $this->review, $this->review2 ), $this->stored( $this->book, 'review_book' ) );
		$this->assertSame( $this->strings( $this->review ), $this->stored( $this->book2, 'review_book' ) );
		$again = Cli::run( false );
		$this->assertSame( 0, $again['added'] );
		$this->assertSame( $this->strings( $this->review, $this->review2 ), $this->stored( $this->book, 'review_book' ) );
	}

	public function test_repair_leaves_a_full_single_side_alone_and_counts_it(): void {
		update_post_meta( $this->book, 'book_parent_series', (string) $this->series );
		$other = self::factory()->post->create( array( 'post_type' => 'series' ) );
		update_post_meta( $other, 'book_parent_series', $this->strings( $this->book ) );
		$counts = Cli::run( false );
		$this->assertSame( 1, $counts['full'] );
		$this->assertSame( (string) $this->series, $this->stored( $this->book, 'book_parent_series' ) );
	}

	public function test_repair_batches_past_one_page(): void {
		$reviews = self::factory()->post->create_many( 205, array( 'post_type' => 'review' ) );
		foreach ( $reviews as $review ) {
			update_post_meta( $review, 'review_book', $this->strings( $this->book ) );
		}
		$this->assertSame( 205, Cli::run( false )['added'] );
		$this->assertCount( 205, Sync::ids( $this->stored( $this->book, 'review_book' ) ) );
	}

	public function test_notice_shows_only_when_acf_is_missing_and_user_can_activate_plugins(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Plugin::missing_acf_notice( false );
		$this->assertStringContainsString( 'needs Advanced Custom Fields', ob_get_clean() );
		ob_start();
		Plugin::missing_acf_notice( true );
		$this->assertSame( '', ob_get_clean() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		Plugin::missing_acf_notice( false );
		$this->assertSame( '', ob_get_clean() );
		$this->assertNotFalse( has_action( 'admin_notices', array( Plugin::class, 'missing_acf_notice' ) ) );
	}
}
