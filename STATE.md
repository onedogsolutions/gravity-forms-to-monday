# Project State

**Plugin:** Gravity Forms to Monday
**Current version:** 1.1.1
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

Unsupported (v2 candidates): `people`, `file`, `board_relation`, mirror/formula. Handle via the `gform_monday_column_value` filter meanwhile.

## Local verification

- All PHP files pass `php -l`.
- `php tests/test-column-mapper.php` — 21/21 passing (pure value-formatting logic, no WordPress required).

The Gravity Forms framework integration (settings rendering, feed UI, `create_item` against a live board) cannot run without a WordPress + Gravity Forms + Monday stack, so it is verified on the staging site.

## Verify on staging

1. **Round trip** — connect a token, create a feed covering each supported column type, submit an entry, confirm the item is created with correct values (check the GF add-on log for the sent `column_values`).
2. **Custom values** — set a discovered column to **Add Custom Value** with static text and with a merge tag (e.g. `{form_title}`); confirm both land on the item.
3. **Custom column ID** — add an Additional Columns row with a custom Monday Column ID; confirm it populates.
4. **API shape** — confirm `create_item` arguments and `API-Version` (`2024-10`, `includes/class-gf-monday-api.php`) are accepted by the workspace.

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
