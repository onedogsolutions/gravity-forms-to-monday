# Project State

**Plugin:** Gravity Forms to Monday
**Current version:** 1.1.3
**Status:** Feature-complete for v1 scope; in staging testing.

## What works

- **Bootstrap & registration** — plugin registers as a Gravity Forms feed add-on; degrades gracefully if Gravity Forms is missing or below 2.7.
- **Master settings** — Monday API token stored in add-on settings, validated live via the `me` query; `gform_monday_api_token` filter allows constant-based storage.
- **Discovery** — boards, groups, and columns pulled from the Monday GraphQL API v2, cached in 5-minute transients; cache flushed when the token changes.
- **Feed UI** — per-form feeds; pick a board (re-renders on change) and group; set an item name with merge tags; map discovered columns to form fields.
- **Field mapping** — one row per supported Monday column, with the form-field dropdown filtered to compatible field types; plus an "Additional Columns" section for anything else.
- **Custom values & additional columns (1.1.1)** — a `generic_map` section lets you set any Monday column to a form field, static text, or a merge tag, and target a custom Monday Column ID the discovery query did not return. (The `field_map` type used for the auto-listed columns cannot do custom values — that is a framework limitation — so custom values are handled in this separate section.)
- **Processing** — `process_feed` builds `column_values`, creates the item, stores `monday_item_id` / `monday_item_url` as entry meta, and adds a linking entry note; failures logged via the GF logging UI and recorded on the entry.
- **Entry detail** — sidebar box links the created Monday item.

## Column type support

`text`, `long_text`, `numbers`, `email`, `phone`, `date`, `status`, `dropdown`, `checkbox`, `link`, `country`, `rating`, `hour`, `world_clock`, `name`.

**Files/photos:** `file` columns are populated by uploading GF file-upload field files *after* the item is created (`add_file_to_column`, multipart `/v2/file` endpoint) — they are never placed in `column_values`. Map a file-upload field to a file column in the Custom Values & Additional Columns section.

**Location:** `location` columns are mapped in the dedicated "Location Columns" section — Latitude + Longitude (required) and an optional address, assembled into `{lat,lng,address}`. The section only appears when the board has a location column. The Geolocation add-on supplies lat/lng as sub-inputs of the GF Address field (`Address (Latitude)` / `Address (Longitude)`).

Still unsupported (v2 candidates): `people`, `board_relation`, mirror/formula. Handle via the `gform_monday_column_value` filter meanwhile.

## Performance

Feed processing runs in the **background** (`$_async_feed_processing = true`), so `create_item` and photo uploads do not block form submission. If a host's WP background processing (loopback/cron) is unreliable and feeds stop running, disable it site-wide with `add_filter( 'gform_is_feed_asynchronous', '__return_false' )`.

## Resilience & diagnostics (1.1.2)

- The full `column_values` payload is logged before sending, and Monday's error `extensions` (including the offending `column_id`) are logged on failure — so a rejected column is identifiable without guessing.
- If Monday rejects one column value, the item is retried once without that column so the lead still lands; the dropped column is logged and noted on the entry.
- Phone columns send digits-only values with a country code (default `US`, override via `gform_monday_default_country`).

## Local verification

- All PHP files pass `php -l`.
- `php tests/test-column-mapper.php` — 24/24 passing (pure value-formatting logic, incl. phone; no WordPress required).

The Gravity Forms framework integration (settings rendering, feed UI, `create_item`/file upload against a live board) cannot run without a WordPress + Gravity Forms + Monday stack, so it is verified on the staging site.

## Verify on staging

1. **Round trip** — submit an entry; confirm the item is created and lands in the correct group (the log records the item ID and group).
2. **Location** — in the Location Columns section map Latitude/Longitude (and optional address) to the Address field's sub-inputs; confirm the Monday location column populates with a pin. If no Location Columns section appears, the Monday "Address" column is not a `location` type — report its actual type.
3. **Submission speed** — with async on, the confirmation page should return immediately; the Monday item + photos appear a few seconds later once the background process runs.
4. **Photos** — map a file-upload field to a Monday file column; confirm files attach to the created item.
5. **Custom values / custom column ID** — set a column to Add Custom Value (static text and a merge tag) and add a custom Monday Column ID; confirm both land.
6. **Deactivated labels** — a status/dropdown value that is *deactivated* on the Monday board (e.g. "Google") is rejected; resilience drops just that column and notes it. Reactivate the label in Monday or align the form choices with active labels. ("Create missing labels" only helps for labels that don't exist, not deactivated ones.)

### Known unknown — generic_map row storage shape

Custom values and custom keys are read by parsing the raw `monday_dynamic_columns` setting rows in `build_column_values()` (`class-gf-monday.php`), expecting each row to carry `key` / `custom_key` / `value` / `custom_value` (the standard map row shape). The first time a row is processed, its array keys are written to the GF add-on log (`generic_map row keys: ...`) so any framework difference is immediately visible.

If a custom value or custom column ID comes through empty on staging:
1. Enable GF logging for "Gravity Forms Monday Add-On" and read the `generic_map row keys:` debug line to see the actual row shape.
2. Cross-check against the saved feed meta (`SELECT meta FROM wp_gf_addon_feed WHERE form_id = <id>;`).
3. Adjust the `rgar( $row, ... )` keys in the custom-mappings loop to match.

Note: `field_map` (the auto-listed "Columns" section) supports form-field mapping only — custom values are intentionally handled in the separate `generic_map` "Custom Values & Additional Columns" section, since `field_map` has no custom-value option in the framework.

## Branch / release workflow

- Development happens on `claude/gravity-forms-monday-connector-6791oj`, restarted from `main` for each change, then merged to `main`.
- Version bumps touch `gravity-forms-to-monday.php` (header + `GF_MONDAY_VERSION`) and `readme.txt` (stable tag + changelog).
