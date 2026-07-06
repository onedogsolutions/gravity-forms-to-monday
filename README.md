# Gravity Forms to Monday

A WordPress connector plugin that automatically pushes new Gravity Forms entries to Monday.com as board items, with per-form field mapping.

## What it does

- Adds a **Monday** settings page under Forms → Settings where you store and validate a Monday personal API token.
- Adds a **Monday** feed tab to every form. Each feed lets you:
  - pick a Monday **board** and **group** (both pulled live from the Monday API),
  - set an **item name** (with Gravity Forms merge tags),
  - **map** Gravity Forms fields to the board's columns,
  - gate processing with Gravity Forms **conditional logic**.
- On submission, creates a Monday **item** from the entry, stores the item ID/URL as entry meta, and (optionally) adds an entry note linking to it.

Built on the [Gravity Forms Add-On Framework](https://docs.gravityforms.com/gffeedaddon/) and the [Monday GraphQL API v2](https://developer.monday.com/api-reference/).

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Gravity Forms 2.7+
- A Monday.com account and personal API token

## Installation

1. Copy this directory into `wp-content/plugins/gravity-forms-to-monday`.
2. Activate **Gravity Forms to Monday** in Plugins.
3. Go to **Forms → Settings → Monday** and paste your Monday API token (Monday → avatar → Developers → My access tokens).
4. Edit a form → **Monday** tab → **Add New** to create a feed and map fields.

## Supported Monday column types

`text`, `long_text`, `numbers`, `email`, `phone`, `date`, `status`, `dropdown`, `checkbox`, `link`, `country`, `rating`, `hour`, `world_clock`, `name`.

Unsupported types (people, files, connected boards, mirror/formula) can be handled via the `gform_monday_column_value` filter.

## Extensibility

| Hook | Type | Purpose |
|---|---|---|
| `gform_monday_api_token` | filter | Override the stored token (e.g. from a constant). |
| `gform_monday_item_name` | filter | Override the computed item name. |
| `gform_monday_column_values` | filter | Modify the full `column_values` payload. |
| `gform_monday_column_value` | filter | Format/override a single column value (handles unsupported types). |
| `gform_monday_item_created` | action | React to a successfully created item. |

## Development

Project layout:

```
gravity-forms-to-monday.php            Bootstrap & add-on registration
class-gf-monday.php                    Main feed add-on (settings, feed UI, processing)
includes/class-gf-monday-api.php       Monday GraphQL client
includes/class-gf-monday-column-mapper.php   GF value → Monday column_values formatting
tests/test-column-mapper.php           Standalone mapper tests
docs/IMPLEMENTATION-PLAN.md            Full design & roadmap
```

Run the mapper tests (no WordPress required):

```bash
php tests/test-column-mapper.php
```

See [docs/IMPLEMENTATION-PLAN.md](docs/IMPLEMENTATION-PLAN.md) for the full design, roadmap, and deferred v2 features.
