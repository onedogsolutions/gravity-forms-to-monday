# Implementation Plan: Gravity Forms → Monday CRM Connector

**Plugin name:** Gravity Forms to Monday
**Working slug:** `gravity-forms-to-monday` (add-on slug: `gravityformstomonday`)
**Goal:** A WordPress plugin that automatically pushes new Gravity Forms entries to Monday.com as items, with per-form feeds that map Gravity Forms fields to Monday board columns discovered live from the Monday API.

---

## 1. Architecture Overview

The plugin is built on the **Gravity Forms Add-On Framework**, specifically extending
[`GFFeedAddOn`](https://docs.gravityforms.com/gffeedaddon/) rather than the base `GFAddOn`.
The feed framework gives us, for free:

- A **plugin settings page** (Forms → Settings → Monday) for global credentials.
- A **per-form feed UI** (Form Settings → Monday) supporting multiple feeds per form,
  feed conditional logic, drag-and-drop feed ordering, and a feed list table.
- **Automatic feed processing** after entry creation (`process_feed()` is called once per
  active feed whose conditional logic passes), plus built-in logging, notes, and
  delayed-payment support.

On the Monday side, all communication goes through the **Monday GraphQL API v2**
(`https://api.monday.com/v2`), authenticated with a personal **API token** in the
`Authorization` header and pinned to a specific `API-Version` header. See the
[Monday API reference](https://developer.monday.com/api-reference/reference/column-values-v2).

```
┌────────────────────────────┐        ┌──────────────────────────┐
│ WordPress / Gravity Forms  │        │ Monday.com GraphQL API   │
│                            │        │ https://api.monday.com/v2│
│  GF_Monday (GFFeedAddOn)   │        │                          │
│   ├─ plugin settings ──────┼─ me ──▶│  token validation        │
│   ├─ feed settings ────────┼ query ▶│  boards / groups /       │
│   │   (board, group,       │        │  columns discovery       │
│   │    field map)          │        │                          │
│   └─ process_feed() ───────┼ mutate▶│  create_item(...)        │
│                            │        │                          │
│  GF_Monday_API (client)    │        │                          │
└────────────────────────────┘        └──────────────────────────┘
```

### File structure

```
gravity-forms-to-monday/
├── gravity-forms-to-monday.php      # Bootstrap: constants, requirements, registration
├── class-gf-monday.php              # Main add-on class (extends GFFeedAddOn)
├── includes/
│   ├── class-gf-monday-api.php      # Monday GraphQL API client
│   └── class-gf-monday-column-mapper.php  # GF value → Monday column_values formatting
├── js/
│   └── feed-settings.js             # Dynamic board/group/column refresh in feed UI
├── css/
│   └── admin.css
├── languages/
├── readme.txt                       # WP.org-style readme
└── docs/
    └── IMPLEMENTATION-PLAN.md       # This document
```

---

## 2. Phase 1 — Plugin Bootstrap & Add-On Registration

**Deliverable:** an activatable plugin that registers with Gravity Forms and shows up under Forms → Settings.

1. **Main plugin file** (`gravity-forms-to-monday.php`)
   - Standard plugin header (name, version, requires PHP 7.4+, requires Plugins: `gravityforms`).
   - Define constants: `GF_MONDAY_VERSION`, `GF_MONDAY_MIN_GF_VERSION` (target GF ≥ 2.7).
   - Hook `gform_loaded` and register the add-on:

   ```php
   add_action( 'gform_loaded', array( 'GF_Monday_Bootstrap', 'load' ), 5 );

   class GF_Monday_Bootstrap {
       public static function load() {
           if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
               return;
           }
           GFForms::include_feed_addon_framework();
           require_once __DIR__ . '/class-gf-monday.php';
           GFAddOn::register( 'GF_Monday' );
       }
   }
   ```

2. **Main add-on class** (`class-gf-monday.php`) — `GF_Monday extends GFFeedAddOn`, singleton via `get_instance()`, with the standard protected properties:

   | Property | Value |
   |---|---|
   | `$_version` | `GF_MONDAY_VERSION` |
   | `$_min_gravityforms_version` | `2.7` |
   | `$_slug` | `gravityformstomonday` |
   | `$_path` | `gravity-forms-to-monday/gravity-forms-to-monday.php` |
   | `$_title` / `$_short_title` | `Gravity Forms Monday Add-On` / `Monday` |
   | `$_capabilities_settings_page` etc. | custom caps `gravityforms_monday`, `gravityforms_monday_uninstall` |

3. Graceful degradation: admin notice if Gravity Forms is missing or below the minimum version.

---

## 3. Phase 2 — Master Settings (Credentials)

**Deliverable:** Forms → Settings → Monday page where an admin enters and validates the Monday API token.

Override `plugin_settings_fields()`:

- **API Token** (`text` field, `input_type => 'password'`) — a personal API token from the
  Monday Developer Center (Profile → Developers → My access tokens).
- **Connection status feedback** — use the framework's `feedback_callback` on the token field
  to run a live validation query, and show the connected account name on success:

  ```graphql
  query { me { id name email account { name } } }
  ```

- **Save handling:** flush cached board/column data (transients) whenever the token changes.

Notes:

- Auth model is a **personal API token** (v1 scope). OAuth is deliberately out of scope for
  v1 — it requires a registered Monday app and adds redirect-flow complexity; the token
  covers the "connect my own account" use case this connector targets. Revisit for v2.
- Store the token via the framework's own settings storage (a `gravityformsaddon_{slug}_settings`
  option). Add a `gform_monday_api_token` filter so advanced users can inject the token from
  `wp-config.php` instead of the database.
- Never write the token to logs; mask it in any debug output.

---

## 4. Phase 3 — Monday API Client

**Deliverable:** `GF_Monday_API`, a small dependency-free GraphQL client wrapping `wp_remote_post()`.

### Core request method

```php
public function request( string $query, array $variables = array() ) {
    $response = wp_remote_post( 'https://api.monday.com/v2', array(
        'headers' => array(
            'Authorization' => $this->api_token,
            'Content-Type'  => 'application/json',
            'API-Version'   => self::API_VERSION, // pin, e.g. '2026-01'; bump deliberately
        ),
        'body'    => wp_json_encode( array( 'query' => $query, 'variables' => $variables ) ),
        'timeout' => 20,
    ) );
    // → return decoded data or WP_Error (transport errors, HTTP != 200,
    //   GraphQL `errors` array, complexity/rate-limit responses).
}
```

### Operations needed

| Method | GraphQL | Used by |
|---|---|---|
| `validate_token()` | `query { me { name email } }` | Settings feedback callback |
| `get_boards()` | `query { boards(limit: 100, order_by: used_at, state: active) { id name workspace { name } } }` (paginate with `page`) | Feed settings: board dropdown |
| `get_board( $id )` | `query ($id:[ID!]) { boards(ids:$id) { groups { id title } columns { id title type settings_str } } }` | Feed settings: group dropdown + field map |
| `create_item()` | `mutation ($board:ID!,$group:String,$name:String!,$vals:JSON) { create_item(board_id:$board, group_id:$group, item_name:$name, column_values:$vals, create_labels_if_missing:true) { id url } }` | `process_feed()` |
| `add_update( $item_id, $body )` | `mutation { create_update(...) }` | Optional "send long text as an update" feature (Phase 6) |

### Caching & limits

- Cache `get_boards()` and `get_board()` responses in **transients (5 min TTL)** so the feed
  settings screen doesn't hammer the API; add a "Refresh" link in the feed UI that busts the cache.
- Respect Monday's **complexity budget / rate limits**: on a `429` or a complexity-exhausted
  error, honor `retry_in_seconds` when present with a single retry for reads; for writes during
  feed processing, fail the feed with a clear error (retry handled in Phase 7 via `gform_monday_retry`).
- Log every request/response summary through `$this->log_debug()` (Gravity Forms logging),
  with the token redacted.

---

## 5. Phase 4 — Feed Settings & Field Mapping

**Deliverable:** the per-form feed UI where a user picks a board and maps fields.

Override `feed_settings_fields()` returning these sections:

1. **Feed name** (`text`, required) — standard.
2. **Destination**
   - **Board** (`select`, required) — choices from `get_boards()`, with an "onchange → save & reload"
     so the rest of the settings re-render for the chosen board (same pattern the official
     GF add-ons like HubSpot/Zoho use: `'onchange' => 'jQuery(this).parents("form").submit();'`).
   - **Group** (`select`) — groups of the selected board; default = board's top group.
3. **Item** — **Item Name** (`text` with merge-tag support, required), e.g. default `{form_title} - {entry_id}`.
4. **Field mapping** (only rendered once a board is chosen)
   - **`field_map`** for the board's *mappable* columns, generated dynamically:

     ```php
     foreach ( $columns as $column ) {
         if ( ! GF_Monday_Column_Mapper::is_supported( $column['type'] ) ) {
             continue;
         }
         $field_map[] = array(
             'name'       => 'monday_column_' . $column['id'],
             'label'      => $column['title'],
             'field_type' => GF_Monday_Column_Mapper::compatible_gf_field_types( $column['type'] ),
         );
     }
     ```

     `field_type` restricts the GF field dropdown to sensible sources per column type
     (e.g. Monday `email` column → GF `email` fields; `date` column → GF `date` fields).
   - **`dynamic_field_map`** as an "Additional columns" section for anything not surfaced
     above, letting users pair an arbitrary column ID with any form field / merge tag.
5. **Options**
   - Checkbox: *Create missing status/dropdown labels* (maps to `create_labels_if_missing`).
   - Checkbox: *Attach entry note with a link to the Monday item*.
6. **Conditional logic** — the framework's standard `feed_condition` field ("Process this feed if …").

Also override:

- `feed_list_columns()` → show Feed Name + Board name in the feed list.
- `can_create_feed()` → `return $this->initialize_api();` so users are told to configure the
  API token first (`configure_addon_message()` links to the plugin settings).
- `save_feed_settings()` — no override needed; framework persists to the `gf_addon_feed` table.

### Column type support matrix (v1)

| Monday column type | GF source | `column_values` JSON format |
|---|---|---|
| `text` | any | `"value"` |
| `long_text` | textarea/any | `{"text": "value"}` |
| `numbers` | number | `"42.5"` |
| `email` | email | `{"email": "a@b.c", "text": "a@b.c"}` |
| `phone` | phone | `{"phone": "+15551234567", "countryShortName": "US"}` |
| `date` | date | `{"date": "2026-07-06"}` (convert from entry format) |
| `status` | select/radio/text | `{"label": "Done"}` |
| `dropdown` | multiselect/checkbox | `{"labels": ["A","B"]}` |
| `checkbox` | consent/checkbox | `{"checked": "true"}` |
| `link` | website | `{"url": "...", "text": "..."}` |
| `country` | address (country) | `{"countryCode": "US", "countryName": "United States"}` |
| `location` | address | `{"lat":..,"lng":..,"address":".."}` — v1: skip geocoding, mark unsupported |
| `people`, `board_relation`, `file`, formula/mirror | — | **Out of scope v1** (read-only or needs extra APIs); filter `gform_monday_column_value` lets devs handle them |

All formatting logic lives in `GF_Monday_Column_Mapper` so it is unit-testable without WordPress.

---

## 6. Phase 5 — Feed Processing (Entry → Item)

**Deliverable:** new entries create Monday items reliably, with observable success/failure.

Override `process_feed( $feed, $entry, $form )`:

1. Bail early (return `WP_Error`) if the API can't initialize.
2. Resolve **item name** via `GFCommon::replace_variables()` (merge tags).
3. Build `column_values`:
   - Iterate the `field_map` + `dynamic_field_map` settings via `get_field_map_fields()` /
     `get_dynamic_field_map_fields()` and `get_field_value( $form, $entry, $field_id )`.
   - Run each value through `GF_Monday_Column_Mapper::format( $column_type, $raw_value )`.
   - Skip empty values (don't overwrite Monday defaults with blanks).
   - Apply filter `gform_monday_column_values` (`$values, $feed, $entry, $form`) before sending.
4. Call `create_item`. On success:
   - Store `monday_item_id` and `monday_item_url` as **entry meta** (`gform_update_meta`).
   - Add an **entry note** with a link to the created item (if enabled).
   - `return true` → framework records the feed as processed (prevents duplicates on re-save).
5. On failure:
   - `$this->add_feed_error( $message, $feed, $entry, $form )` → logs + entry note + failed status.
   - Return the `WP_Error` so the framework marks the feed failed (visible in entry detail).

Notes:

- `GFFeedAddOn` already guarantees feeds run **once per entry** after `gform_entry_post_save`
  and skips spam entries; no custom submission hooks needed.
- Add `gform_monday_item_created` action (`$item_id, $feed, $entry, $form`) for extensions
  (e.g. later pushing file uploads as Monday assets).

---

## 7. Phase 6 — Admin Polish

- **Entry detail meta box** showing the linked Monday item (URL from entry meta).
- **Uninstall** support via the framework (`$_capabilities_uninstall`): delete settings,
  feeds, and transients. Never touch Monday data.
- **Logging** integration with Gravity Forms' built-in logging UI (comes free with the framework
  — just use `log_debug()`/`log_error()` consistently).
- **i18n**: text domain `gravity-forms-to-monday`, all strings translatable.
- Optional (time-permitting): a **"Send to Monday"** single-entry re-run action using the
  framework's standard `maybe_process_feed` re-processing pattern.

---

## 8. Phase 7 — Hardening, Testing, Release

**Resilience**

- Timeouts + single retry with backoff for transient network errors on `create_item`.
- Handle Monday API version deprecation: pin `API-Version`, log a warning if the response
  signals deprecation.
- Handle deleted boards/columns: if the mapped board/column no longer exists, fail the feed
  with an actionable message ("Board X not found — edit the feed").

**Security**

- Capability checks come from the framework caps; ensure the custom caps are registered with
  Members/GF role management (`$_capabilities` property).
- Sanitize all settings on save (framework `save_callback`s); escape all admin output.
- API token: password-type input, redacted in logs, filterable for constant-based storage.

**Testing**

- **Unit tests (PHPUnit + Brain Monkey):** `GF_Monday_Column_Mapper` (every column type,
  empty/edge values, date format conversion), API client error branches (HTTP error, GraphQL
  `errors`, 429).
- **Integration/manual test matrix:** valid/invalid token; board with every supported column
  type; multi-feed form with conditional logic; feed with deleted board; rate-limit simulation.
- **Coding standards:** PHPCS with WordPress-Extra + Gravity Forms rulesets in CI (GitHub Action).

**Release checklist:** readme.txt, changelog, version bump script, tag → build zip via GitHub Action.

---

## 9. Extensibility Surface (filters/actions shipped in v1)

| Hook | Purpose |
|---|---|
| `gform_monday_api_token` | Override stored token (e.g. from a constant) |
| `gform_monday_column_values` | Modify the final column_values payload per entry |
| `gform_monday_column_value` | Format/override a single column's value (enables unsupported types) |
| `gform_monday_item_name` | Override the computed item name |
| `gform_monday_item_created` | React to successful item creation |

---

## 10. Milestones

| # | Milestone | Scope | Est. |
|---|---|---|---|
| 1 | Skeleton | Phase 1 — bootstrap, registration, CI (PHPCS) | 0.5 day |
| 2 | Credentials | Phase 2 + minimal API client (`me` validation) | 0.5 day |
| 3 | Discovery | Phase 3 complete — boards/groups/columns + caching | 1 day |
| 4 | Feed UI | Phase 4 — board select, dynamic field map | 1.5 days |
| 5 | Processing | Phase 5 — create_item, column mapper, entry meta/notes | 1.5 days |
| 6 | Polish + QA | Phases 6–7 — tests, hardening, readme | 1.5 days |

**Total: ~6.5 dev-days** to a releasable v1.

## 11. Explicitly Deferred (v2 candidates)

- OAuth app authentication (multi-account / marketplace distribution).
- Update-instead-of-create (dedupe by email → `items_page_by_column_values` lookup).
- File upload fields → Monday file columns (`add_file_to_column`, multipart endpoint).
- Subitems, people-column mapping via user lookup, connected boards.
- Two-way sync via Monday webhooks.

## 12. References

- Gravity Forms Add-On Framework: [GFAddOn](https://docs.gravityforms.com/gfaddon/) · [GFFeedAddOn](https://docs.gravityforms.com/gffeedaddon/) · [Creating a Feed Settings Page](https://docs.gravityforms.com/creating-a-feed-settings-page/) · [Mapped field values during feed processing](https://docs.gravityforms.com/mapped-field-values-during-feed-processing/)
- Monday API: [API basics](https://developer.monday.com/api-reference/docs/basics) · [Column values](https://developer.monday.com/api-reference/reference/column-values-v2) · [Items / create_item](https://developer.monday.com/api-reference/reference/items) · [Changing column values](https://developer.monday.com/api-reference/docs/change-column-values)
