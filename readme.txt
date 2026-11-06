=== Shakewell Two-way Relationships for ACF ===
Contributors: shakewell
Tags: acf, relationship, post object, bidirectional, advanced custom fields
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

When a post is linked to another through an ACF relationship or post object field, the other post links back. Unlinking removes the link back.

== Description ==

Link a venue to an event space and the event space shows the venue too. Remove the link on either side and it disappears from both.

You tell the plugin which fields belong together. It then keeps both posts in step whenever Advanced Custom Fields saves one of them. It stores values exactly as ACF does, so nothing needs migrating and your REST output and templates do not change.

How it behaves:

* Works with ACF relationship fields and post object fields, single or multiple.
* Adds the back-link at the end of the other list, so the order of existing links is kept. Linking twice never duplicates.
* A single-value side, or a relationship side that has reached its maximum, is left as it is. The plugin never replaces another link.
* Ignores revisions, autosaves and non-post locations such as options pages and users.
* Does not listen to trashing, restoring or deleting a post. Links to a trashed post stay until you change them.
* The other post is updated whenever the editor may save the first post. There is no separate capability check on the other post.
* It does not use ACF's own "bidirectional" setting, so it also works with data created before that setting existed.
* No settings screen, no database tables, no front-end output.

Requires Advanced Custom Fields, free or Pro. WordPress cannot express "free or Pro" as a plugin dependency, so the plugin checks at run time and shows an admin notice if ACF is not active.

= Setup =

Add the pairs with the `shakewell_relationship_sync_pairs` filter, for example in your theme's `functions.php` or a small must-use plugin. Each pair is two field names or field keys. The pair works in both directions. A field that shares its name with the field on the other post type is paired with itself:

`
add_filter( 'shakewell_relationship_sync_pairs', function () {
	return array(
		// Same field name in both field groups.
		array( 'venue_event_space_relationship', 'venue_event_space_relationship' ),
		// Different names, matched by field key.
		array( 'field_64a1b2c3d4e5f', 'field_64a1b2c3d4e60' ),
	);
} );
`

Field keys are shown in the field group editor when you turn on "Field keys" in the screen options.

= Repair after an import =

Imports and direct database edits skip ACF, so back-links can be missing. With WP-CLI:

`
wp shakewell-relationship-sync repair --dry-run
wp shakewell-relationship-sync repair
`

The command walks every post that stores a paired field, in batches, and adds the missing back-links. It never removes anything and is safe to run again. It reports posts and links checked, back-links added, links left alone because the other side was full, and links that point at deleted posts.

= External services =

None. The plugin makes no outside requests and collects no data.

== Installation ==

1. Install and activate Advanced Custom Fields.
2. Upload the plugin folder to `/wp-content/plugins/`, or install it with Composer, and activate it.
3. Add your pairs with the filter shown in the description.

== Frequently Asked Questions ==

= Do I need ACF Pro? =

No. The free ACF plugin is enough.

= Is anything stored in a new format? =

No. Values are written the way ACF writes them: an array of ids as text for multiple fields, one id as text (or an empty string) for a single post object, plus the usual field reference row.

= What happens if both sides are saved? =

Nothing extra. The plugin writes the other post directly, without triggering ACF again, so there is no loop and saving twice changes nothing.

= Why was a link not added on the other post? =

The other post has no paired field (check the field group location rules), or its side is single-value or at its maximum and already holds a different post.

= A user cannot edit the other post. Will it still be updated? =

Yes. Anyone who can save the first post can link it, and the back-link is written without checking their rights on the other post. Do not use this plugin where that is not acceptable.

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

= Moving from Post 2 Post for ACF =

Post 2 Post for ACF pairs fields that share a name across field groups. Add one pair per shared name with the filter, using the same field name on both sides. Stored data is already in the right format. The plugin stays silent while Post 2 Post for ACF is active, so you can install both, then deactivate and delete Post 2 Post for ACF. Run `wp shakewell-relationship-sync repair --dry-run` afterwards to confirm nothing is missing. The Post 2 Post tuning filters (`acf/post2post/...`) have no equivalent.

== Changelog ==

= 0.1.0 =
* First release.
