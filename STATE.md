# Project State

**Plugin:** Gravity Forms to Monday
**Current version:** 1.1.0
**Status:** Feature-complete for v1 scope; in staging testing.

## What works

- **Bootstrap & registration** — plugin registers as a Gravity Forms feed add-on; degrades gracefully if Gravity Forms is missing or below 2.7.
- **Master settings** — Monday API token stored in add-on settings, validated live via the `me` query; `gform_monday_api_token` filter allows constant-based storage.
- **Discovery** — boards, groups, and columns pulled from the Monday GraphQL API v2, cached in 5-minute transients; cache flushed when the token changes.
- **Feed UI** — per-form feeds; pick a board (re-renders on change) and group; set an item name with merge tags; map discovered columns to form fields.
- **Field mapping** — one row per supported Monday column, with the form-field dropdown filtered to compatible field types; plus an "Additional Columns" section for anything else.
- **Custom values (1.1.0)** — any mapped column can use **Add Custom Value** to send static text or merge tags instead of a form field, for columns with no matching form field.
- **Custom column IDs (1.1.0)** — the Additional Columns section accepts a custom Monday Column ID to target a column the discovery query did not return.
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

### Known unknown — custom-value companion meta key

The framework's storage key for a `gf_custom` field-map row's custom text has varied across Gravity Forms versions. `GF_Monday::get_custom_companion_value()` (`class-gf-monday.php`) checks several known conventions and logs a debug line listing the keys it checked if none match. If a **custom value on a discovered column** comes through empty on staging:

1. Enable GF logging for "Gravity Forms Monday Add-On" and look for the "No custom value companion found" debug line.
2. Inspect the saved feed meta to find the actual key (`SELECT meta FROM wp_gf_addon_feed WHERE form_id = <id>;`).
3. Add that key to the `$candidates` list in `get_custom_companion_value()`.

Custom values in the **Additional Columns** (dynamic map) section read the raw `custom_value` row field directly and do not depend on this lookup.

## Branch / release workflow

- Development happens on `claude/gravity-forms-monday-connector-6791oj`, restarted from `main` for each change, then merged to `main`.
- Version bumps touch `gravity-forms-to-monday.php` (header + `GF_MONDAY_VERSION`) and `readme.txt` (stable tag + changelog).
