<?php
/**
 * Two-way relationship sync, against real ACF with local field groups.
 *
 * Mirrors the Merivale venue / event space / event / precinct set-up the code was first written for.
 *
 * @package Shakewell\RelationshipSync
 */

namespace Shakewell\RelationshipSync\Tests;

use Shakewell\RelationshipSync\Cli;
use Shakewell\RelationshipSync\Plugin;
use Shakewell\RelationshipSync\Sync;
use WP_UnitTestCase;

final class SyncTest extends WP_UnitTestCase {

	private int $venue;
	private int $venue2;
	private int $venue3;
	private int $space;
	private int $space2;
	private int $event;
	private int $event2;
	private int $precinct;

	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'venue', 'event_space', 'event', 'precinct' ) as $type ) {
			register_post_type( $type );
		}
		add_filter(
			'shakewell_relationship_sync_pairs',
			static fn() => array(
				array( 'venue_event_space_relationship', 'venue_event_space_relationship' ),
				array( 'event_venue', 'event_venue' ),
				array( 'venue_parent_precinct', 'venue_parent_precinct' ),
				array( 'field_venue_featured', 'field_event_featured' ),
			)
		);
		$this->group( 'venue', array(
			$this->field( 'field_venue_ev', 'event_venue', 'relationship' ),
			$this->field( 'field_venue_es', 'venue_event_space_relationship', 'relationship' ),
			$this->field( 'field_venue_pp', 'venue_parent_precinct', 'post_object', array( 'multiple' => 0 ) ),
			$this->field( 'field_venue_featured', 'featured_events', 'relationship', array( 'max' => 2 ) ),
		) );
		$this->group( 'precinct', array( $this->field( 'field_precinct_pp', 'venue_parent_precinct', 'relationship' ) ) );
		$this->group( 'event', array(
			$this->field( 'field_event_ev', 'event_venue', 'post_object', array( 'multiple' => 1 ) ),
			$this->field( 'field_event_featured', 'featuring_venue', 'post_object', array( 'multiple' => 0 ) ),
		) );
		$this->group( 'event_space', array( $this->field( 'field_space_es', 'venue_event_space_relationship', 'relationship' ) ) );

		$this->venue    = self::factory()->post->create( array( 'post_type' => 'venue' ) );
		$this->venue2   = self::factory()->post->create( array( 'post_type' => 'venue' ) );
		$this->venue3   = self::factory()->post->create( array( 'post_type' => 'venue' ) );
		$this->space    = self::factory()->post->create( array( 'post_type' => 'event_space' ) );
		$this->space2   = self::factory()->post->create( array( 'post_type' => 'event_space' ) );
		$this->event    = self::factory()->post->create( array( 'post_type' => 'event' ) );
		$this->event2   = self::factory()->post->create( array( 'post_type' => 'event' ) );
		$this->precinct = self::factory()->post->create( array( 'post_type' => 'precinct' ) );
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
		$this->save( 'field_event_ev', array( $this->venue, $this->venue2 ), $this->event );
		$this->assertSame( $this->strings( $this->event ), $this->stored( $this->venue, 'event_venue' ) );
		$this->assertSame( $this->strings( $this->event ), $this->stored( $this->venue2, 'event_venue' ) );
		$this->assertSame( 'field_venue_ev', get_post_meta( $this->venue, '_event_venue', true ) );
	}

	public function test_both_directions_work(): void {
		$this->save( 'field_space_es', array( $this->venue ), $this->space );
		$this->assertSame( $this->strings( $this->space ), $this->stored( $this->venue, 'venue_event_space_relationship' ) );
		$this->save( 'field_venue_es', array( $this->space, $this->space2 ), $this->venue );
		$this->assertSame( $this->strings( $this->venue ), $this->stored( $this->space2, 'venue_event_space_relationship' ) );
	}

	public function test_back_link_is_appended_and_order_is_kept(): void {
		$this->save( 'field_event_ev', array( $this->venue ), $this->event );
		$this->save( 'field_event_ev', array( $this->venue ), $this->event2 );
		$this->assertSame( $this->strings( $this->event, $this->event2 ), $this->stored( $this->venue, 'event_venue' ) );
	}

	public function test_unlinking_removes_the_back_link_and_keeps_the_others(): void {
		$this->save( 'field_event_ev', array( $this->venue, $this->venue2 ), $this->event );
		$this->save( 'field_event_ev', array( $this->venue ), $this->event2 );
		$this->save( 'field_event_ev', array( $this->venue2 ), $this->event );
		$this->assertSame( $this->strings( $this->event2 ), $this->stored( $this->venue, 'event_venue' ) );
		$this->assertSame( $this->strings( $this->event ), $this->stored( $this->venue2, 'event_venue' ) );
		$this->save( 'field_event_ev', array(), $this->event );
		$this->assertSame( array(), $this->stored( $this->venue2, 'event_venue' ) );
	}

	public function test_replacing_moves_the_link(): void {
		$this->save( 'field_venue_es', array( $this->space ), $this->venue );
		$this->save( 'field_venue_es', array( $this->space2 ), $this->venue );
		$this->assertSame( array(), $this->stored( $this->space, 'venue_event_space_relationship' ) );
		$this->assertSame( $this->strings( $this->venue ), $this->stored( $this->space2, 'venue_event_space_relationship' ) );
	}

	public function test_relinking_does_not_duplicate(): void {
		$this->save( 'field_event_ev', array( $this->venue ), $this->event );
		$before = get_post_meta( $this->venue );
		$this->save( 'field_event_ev', array( $this->venue ), $this->event );
		$this->save( 'field_event_ev', array( $this->venue ), $this->event );
		$this->assertSame( $this->strings( $this->event ), $this->stored( $this->venue, 'event_venue' ) );
		$this->assertSame( $before, get_post_meta( $this->venue ) );
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
		$this->save( 'field_venue_es', array( $this->space ), $this->venue );
		$this->assertSame( 1, $runs, 'One save runs the filter once: the back-link write does not re-enter it.' );
		$this->save( 'field_space_es', array( $this->venue ), $this->space );
		$this->assertSame( $this->strings( $this->space ), $this->stored( $this->venue, 'venue_event_space_relationship' ) );
		$this->assertSame( $this->strings( $this->venue ), $this->stored( $this->space, 'venue_event_space_relationship' ) );
	}

	public function test_post_object_single_side_stores_a_string_and_clears_to_empty(): void {
		$this->save( 'field_precinct_pp', array( $this->venue, $this->venue2 ), $this->precinct );
		$this->assertSame( (string) $this->precinct, $this->stored( $this->venue, 'venue_parent_precinct' ) );
		$this->save( 'field_venue_pp', $this->precinct, $this->venue3 );
		$this->assertSame( $this->strings( $this->venue, $this->venue2, $this->venue3 ), $this->stored( $this->precinct, 'venue_parent_precinct' ) );
		$this->save( 'field_venue_pp', '', $this->venue3 );
		$this->assertSame( $this->strings( $this->venue, $this->venue2 ), $this->stored( $this->precinct, 'venue_parent_precinct' ) );
		$this->save( 'field_precinct_pp', array( $this->venue2 ), $this->precinct );
		$this->assertSame( '', $this->stored( $this->venue, 'venue_parent_precinct' ) );
	}

	public function test_post_object_multiple_is_an_array_of_strings(): void {
		$this->save( 'field_venue_ev', array( $this->event ), $this->venue );
		$this->assertSame( $this->strings( $this->venue ), $this->stored( $this->event, 'event_venue' ) );
	}

	public function test_a_single_side_that_already_has_another_post_is_left_alone(): void {
		$this->save( 'field_precinct_pp', array( $this->venue ), $this->precinct );
		$other = self::factory()->post->create( array( 'post_type' => 'precinct' ) );
		$this->save( 'field_precinct_pp', array( $this->venue ), $other );
		$this->assertSame( (string) $this->precinct, $this->stored( $this->venue, 'venue_parent_precinct' ) );
		$this->assertSame( $this->strings( $this->venue ), $this->stored( $other, 'venue_parent_precinct' ) );
	}

	public function test_a_relationship_side_at_its_max_is_left_alone(): void {
		// Different field names, paired by key, with a max of 2 on the venue side.
		$e3 = self::factory()->post->create( array( 'post_type' => 'event' ) );
		foreach ( array( $this->event, $this->event2, $e3 ) as $event ) {
			$this->save( 'field_event_featured', $this->venue, $event );
		}
		$this->assertSame( $this->strings( $this->event, $this->event2 ), $this->stored( $this->venue, 'featured_events' ) );
	}

	public function test_pairs_may_use_different_field_keys(): void {
		$this->save( 'field_event_featured', $this->venue, $this->event );
		$this->assertSame( $this->strings( $this->event ), $this->stored( $this->venue, 'featured_events' ) );
		$this->save( 'field_venue_featured', array( $this->event2 ), $this->venue );
		$this->assertSame( '', $this->stored( $this->event, 'featuring_venue' ) );
		$this->assertSame( (string) $this->venue, $this->stored( $this->event2, 'featuring_venue' ) );
	}

	public function test_unpaired_fields_are_ignored(): void {
		remove_all_filters( 'shakewell_relationship_sync_pairs' );
		$this->save( 'field_event_ev', array( $this->venue ), $this->event );
		$this->assertSame( '', $this->stored( $this->venue, 'event_venue' ) );
	}

	public function test_malformed_pairs_are_dropped(): void {
		add_filter( 'shakewell_relationship_sync_pairs', static fn() => array( 'x', array( 'a' ), array( 'a', '' ), array( 'a', 1 ), array( 'a', 'b' ) ), 20 );
		$this->assertSame( array( array( 'a', 'b' ) ), Sync::pairs() );
	}

	public function test_unknown_targets_revisions_and_non_post_locations_are_ignored(): void {
		$this->save( 'field_event_ev', array( $this->venue, 999999 ), $this->event );
		$this->assertSame( array(), get_post_meta( 999999 ) );
		$revision = wp_insert_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $this->event ) );
		$this->assertTrue( (bool) wp_is_post_revision( $revision ) );
		$field    = acf_get_field( 'field_event_ev' );
		$this->assertSame( array( $this->venue2 ), Sync::on_update( array( $this->venue2 ), (int) $revision, $field ) );
		Sync::on_update( array( $this->venue2 ), 'options', $field );
		Sync::on_update( array( $this->venue2 ), 0, $field );
		Sync::on_update( array( $this->venue2 ), 'user_1', $field );
		$this->assertSame( '', $this->stored( $this->venue2, 'event_venue' ) );
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
		$this->save( 'field_event_ev', array( $this->venue ), $this->event );
		wp_trash_post( $this->event );
		wp_untrash_post( $this->event );
		$this->assertSame( $this->strings( $this->event ), $this->stored( $this->venue, 'event_venue' ) );
	}

	public function test_trashed_posts_are_linked_and_unlinked_like_any_other(): void {
		wp_trash_post( $this->venue );
		$this->save( 'field_event_ev', array( $this->venue ), $this->event );
		$this->assertSame( $this->strings( $this->event ), $this->stored( $this->venue, 'event_venue' ) );
		$this->save( 'field_event_ev', array(), $this->event );
		$this->assertSame( array(), $this->stored( $this->venue, 'event_venue' ) );
	}

	public function test_a_user_who_cannot_edit_the_other_post_still_gets_the_back_link(): void {
		// Same as the original mu-plugin: no capability check on the other post.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( current_user_can( 'edit_post', $this->venue ) );
		$this->save( 'field_event_ev', array( $this->venue ), $this->event );
		$this->assertSame( $this->strings( $this->event ), $this->stored( $this->venue, 'event_venue' ) );
	}

	public function test_random_saves_leave_relationship_pairs_consistent(): void {
		mt_srand( 42 );
		$venues = array( $this->venue, $this->venue2, $this->venue3 );
		$spaces = array( $this->space, $this->space2 );
		for ( $i = 0; $i < 60; $i++ ) {
			if ( mt_rand( 0, 1 ) ) {
				shuffle( $spaces );
				$this->save( 'field_venue_es', array_slice( $spaces, 0, mt_rand( 0, 2 ) ), $venues[ mt_rand( 0, 2 ) ] );
			} else {
				shuffle( $venues );
				$this->save( 'field_space_es', array_slice( $venues, 0, mt_rand( 0, 3 ) ), $spaces[ mt_rand( 0, 1 ) ] );
			}
		}
		foreach ( $venues as $venue ) {
			foreach ( Sync::ids( $this->stored( $venue, 'venue_event_space_relationship' ) ) as $space ) {
				$this->assertContains( $venue, Sync::ids( $this->stored( $space, 'venue_event_space_relationship' ) ) );
			}
		}
		foreach ( $spaces as $space ) {
			foreach ( Sync::ids( $this->stored( $space, 'venue_event_space_relationship' ) ) as $venue ) {
				$this->assertContains( $space, Sync::ids( $this->stored( $venue, 'venue_event_space_relationship' ) ) );
			}
		}
	}

	/** Stored one-sided, as after an import that bypassed ACF. */
	private function one_sided(): void {
		update_post_meta( $this->event, 'event_venue', $this->strings( $this->venue, $this->venue2 ) );
		update_post_meta( $this->event2, 'event_venue', $this->strings( $this->venue ) );
		update_post_meta( $this->space, 'venue_event_space_relationship', $this->strings( 999999 ) );
	}

	public function test_repair_dry_run_reports_and_writes_nothing(): void {
		$this->one_sided();
		$counts = Cli::run( true );
		$this->assertSame( 3, $counts['added'] );
		$this->assertSame( 1, $counts['dangling'] );
		$this->assertSame( '', $this->stored( $this->venue, 'event_venue' ) );
	}

	public function test_repair_adds_back_links_in_order_and_is_idempotent(): void {
		$this->one_sided();
		$counts = Cli::run( false );
		$this->assertSame( 3, $counts['added'] );
		$this->assertSame( $this->strings( $this->event, $this->event2 ), $this->stored( $this->venue, 'event_venue' ) );
		$this->assertSame( $this->strings( $this->event ), $this->stored( $this->venue2, 'event_venue' ) );
		$again = Cli::run( false );
		$this->assertSame( 0, $again['added'] );
		$this->assertSame( $this->strings( $this->event, $this->event2 ), $this->stored( $this->venue, 'event_venue' ) );
	}

	public function test_repair_leaves_a_full_single_side_alone_and_counts_it(): void {
		update_post_meta( $this->venue, 'venue_parent_precinct', (string) $this->precinct );
		$other = self::factory()->post->create( array( 'post_type' => 'precinct' ) );
		update_post_meta( $other, 'venue_parent_precinct', $this->strings( $this->venue ) );
		$counts = Cli::run( false );
		$this->assertSame( 1, $counts['full'] );
		$this->assertSame( (string) $this->precinct, $this->stored( $this->venue, 'venue_parent_precinct' ) );
	}

	public function test_repair_batches_past_one_page(): void {
		$events = self::factory()->post->create_many( 205, array( 'post_type' => 'event' ) );
		foreach ( $events as $event ) {
			update_post_meta( $event, 'event_venue', $this->strings( $this->venue ) );
		}
		$this->assertSame( 205, Cli::run( false )['added'] );
		$this->assertCount( 205, Sync::ids( $this->stored( $this->venue, 'event_venue' ) ) );
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
