=== Gravity Forms to Monday ===
Contributors: onedogsolutions
Tags: gravity forms, monday, monday.com, crm, integration
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically push new Gravity Forms entries to Monday.com as board items, with per-form field mapping.

== Description ==

Gravity Forms to Monday connects your Gravity Forms to a Monday.com account. Each form can have one or more feeds that create a new item on a chosen Monday board when an entry is submitted.

* Enter a Monday personal API token once in the add-on settings; the connection is validated live.
* Per-form feeds let you pick a board and group, and map Gravity Forms fields to Monday columns.
* Column choices are pulled directly from the Monday API, so you always map against the board's real columns.
* Supports text, long text, numbers, email, phone, date, status, dropdown, checkbox, link, country, rating, and hour columns.
* Conditional logic decides when a feed runs. Multiple feeds per form are supported.
* Successful items are linked back from the entry detail screen, and an optional entry note records the item URL.

Requires an active Gravity Forms license (2.7 or greater).

== Installation ==

1. Upload the `gravity-forms-to-monday` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Forms → Settings → Monday and enter your Monday API token.
4. Edit a form, open the Monday tab, and create a feed to map fields.

== Frequently Asked Questions ==

= Where do I get a Monday API token? =

In Monday, click your avatar → Developers → My access tokens, then copy your personal token.

= Which Monday column types are supported? =

Text, long text, numbers, email, phone, date, status, dropdown, checkbox, link, country, rating, and hour. Unsupported types (people, files, connected boards, mirror/formula) can be handled with the `gform_monday_column_value` filter.

= Can I store the token in wp-config.php instead of the database? =

Yes. Use the `gform_monday_api_token` filter to return a constant.

== Changelog ==

= 1.1.1 =
* Fix: custom values now work. The "Custom Values & Additional Columns" section uses the generic_map field type, which supports the "Add Custom Value" option (the previous field_map / dynamic_field_map approach did not). Map any column to a form field, static text, or a merge tag, and target Monday Column IDs the discovery query did not return.

= 1.1.0 =
* Field mapping: attempted custom-value support (superseded by 1.1.1).
* Additional Columns: support custom Monday Column IDs to target columns the discovery query did not return.

= 1.0.0 =
* Initial release: master API settings, per-form feeds, live board/column discovery, field mapping, and entry-to-item creation.
