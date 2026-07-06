<?php
/**
 * Monday.com GraphQL API client.
 *
 * @package GravityFormsToMonday
 */

defined( 'ABSPATH' ) || die();

/**
 * Thin, dependency-free wrapper around the Monday.com GraphQL API v2.
 *
 * All calls go through {@see GF_Monday_API::request()} which returns either the
 * decoded `data` payload or a WP_Error describing a transport, HTTP, or GraphQL
 * failure. Read queries (boards/columns) are cached in transients to keep the
 * feed settings screen responsive and within Monday's complexity budget.
 */
class GF_Monday_API {

	/**
	 * Monday GraphQL endpoint.
	 */
	const ENDPOINT = 'https://api.monday.com/v2';

	/**
	 * Pinned API version. Bump deliberately after verifying against the changelog.
	 *
	 * @link https://developer.monday.com/api-reference/reference/api-versioning
	 */
	const API_VERSION = '2024-10';

	/**
	 * Transient TTL for cached discovery queries, in seconds.
	 */
	const CACHE_TTL = 300;

	/**
	 * Personal API token.
	 *
	 * @var string
	 */
	protected $api_token;

	/**
	 * Optional logger callback: function( string $message, string $level ).
	 *
	 * @var callable|null
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param string        $api_token Monday personal API token.
	 * @param callable|null $logger    Optional logging callback.
	 */
	public function __construct( $api_token, $logger = null ) {
		$this->api_token = trim( (string) $api_token );
		$this->logger    = $logger;
	}

	/**
	 * Whether a non-empty token is present.
	 *
	 * @return bool
	 */
	public function has_token() {
		return '' !== $this->api_token;
	}

	/**
	 * Execute a GraphQL request.
	 *
	 * @param string $query     GraphQL query or mutation.
	 * @param array  $variables Variables map.
	 * @return array|WP_Error Decoded `data` array on success, WP_Error on failure.
	 */
	public function request( $query, $variables = array() ) {
		if ( ! $this->has_token() ) {
			return new WP_Error( 'gf_monday_no_token', __( 'No Monday API token configured.', 'gravity-forms-to-monday' ) );
		}

		$body = array( 'query' => $query );
		if ( ! empty( $variables ) ) {
			$body['variables'] = $variables;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => $this->api_token,
					'Content-Type'  => 'application/json',
					'API-Version'   => self::API_VERSION,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'Transport error: ' . $response->get_error_message(), 'error' );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		// Rate limit / complexity budget exhausted.
		if ( 429 === $code ) {
			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$this->log( 'Rate limited by Monday API (429).', 'error' );
			return new WP_Error(
				'gf_monday_rate_limited',
				__( 'Monday API rate limit reached. Please try again shortly.', 'gravity-forms-to-monday' ),
				array( 'retry_after' => $retry )
			);
		}

		if ( ! is_array( $data ) ) {
			$this->log( 'Non-JSON response (HTTP ' . $code . ').', 'error' );
			return new WP_Error(
				'gf_monday_bad_response',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Unexpected response from Monday (HTTP %d).', 'gravity-forms-to-monday' ),
					$code
				)
			);
		}

		// GraphQL-level errors are returned with HTTP 200.
		if ( ! empty( $data['errors'] ) ) {
			$messages = wp_list_pluck( $data['errors'], 'message' );
			$message  = implode( '; ', array_filter( (array) $messages ) );
			$this->log( 'GraphQL error: ' . $message, 'error' );
			return new WP_Error( 'gf_monday_graphql_error', $message ? $message : __( 'Monday API returned an error.', 'gravity-forms-to-monday' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'gf_monday_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Monday API returned HTTP %d.', 'gravity-forms-to-monday' ),
					$code
				)
			);
		}

		return isset( $data['data'] ) ? $data['data'] : array();
	}

	/**
	 * Validate the token by querying the current account.
	 *
	 * @return array|WP_Error Account info ( name, email, account name ) or WP_Error.
	 */
	public function validate_token() {
		$data = $this->request( 'query { me { id name email account { name } } }' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['me'] ) ) {
			return new WP_Error( 'gf_monday_invalid_token', __( 'The Monday API token appears to be invalid.', 'gravity-forms-to-monday' ) );
		}

		return $data['me'];
	}

	/**
	 * Retrieve active boards the token can access.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|WP_Error List of boards ( id, name, workspace name ).
	 */
	public function get_boards( $force = false ) {
		$cache_key = $this->cache_key( 'boards' );

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$boards = array();
		$page   = 1;

		// Paginate defensively; most accounts fit well under this ceiling.
		do {
			$data = $this->request(
				'query ($page: Int!) { boards (limit: 100, page: $page, order_by: used_at, state: active) { id name workspace { name } } }',
				array( 'page' => $page )
			);

			if ( is_wp_error( $data ) ) {
				return $data;
			}

			$batch = isset( $data['boards'] ) ? $data['boards'] : array();
			$boards = array_merge( $boards, $batch );
			++$page;
		} while ( count( $batch ) === 100 && $page <= 20 );

		set_transient( $cache_key, $boards, self::CACHE_TTL );

		return $boards;
	}

	/**
	 * Retrieve a single board's groups and columns.
	 *
	 * @param string|int $board_id Board ID.
	 * @param bool       $force    Bypass the cache.
	 * @return array|WP_Error Board data ( groups, columns ) or WP_Error.
	 */
	public function get_board( $board_id, $force = false ) {
		$board_id = (string) $board_id;

		if ( '' === $board_id ) {
			return new WP_Error( 'gf_monday_no_board', __( 'No board specified.', 'gravity-forms-to-monday' ) );
		}

		$cache_key = $this->cache_key( 'board_' . $board_id );

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$data = $this->request(
			'query ($ids: [ID!]) { boards (ids: $ids) { id name groups { id title } columns { id title type settings_str } } }',
			array( 'ids' => array( $board_id ) )
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['boards'][0] ) ) {
			return new WP_Error(
				'gf_monday_board_not_found',
				sprintf(
					/* translators: %s: board ID. */
					__( 'Board %s was not found. It may have been deleted.', 'gravity-forms-to-monday' ),
					$board_id
				)
			);
		}

		$board = $data['boards'][0];
		set_transient( $cache_key, $board, self::CACHE_TTL );

		return $board;
	}

	/**
	 * Create an item on a board.
	 *
	 * @param string|int  $board_id             Board ID.
	 * @param string      $item_name            Item name.
	 * @param array       $column_values        Map of column ID => formatted value.
	 * @param string|null $group_id             Optional group ID.
	 * @param bool        $create_labels        Create missing status/dropdown labels.
	 * @return array|WP_Error Created item ( id, name, url ) or WP_Error.
	 */
	public function create_item( $board_id, $item_name, $column_values = array(), $group_id = null, $create_labels = false ) {
		$variables = array(
			'boardId'      => (string) $board_id,
			'itemName'     => $item_name,
			'columnValues' => wp_json_encode( (object) $column_values ),
			'createLabels' => (bool) $create_labels,
		);

		$group_clause = '';
		if ( ! empty( $group_id ) ) {
			$variables['groupId'] = (string) $group_id;
			$group_clause         = ', group_id: $groupId';
		}

		$query = 'mutation ($boardId: ID!, $itemName: String!, $columnValues: JSON, $createLabels: Boolean' .
			( $group_clause ? ', $groupId: String' : '' ) .
			') { create_item (board_id: $boardId, item_name: $itemName, column_values: $columnValues, create_labels_if_missing: $createLabels' .
			$group_clause .
			') { id name url } }';

		$data = $this->request( $query, $variables );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['create_item']['id'] ) ) {
			return new WP_Error( 'gf_monday_create_failed', __( 'Monday did not return a created item.', 'gravity-forms-to-monday' ) );
		}

		return $data['create_item'];
	}

	/**
	 * Clear all cached discovery data for this token.
	 *
	 * @return void
	 */
	public function flush_cache() {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_gf_monday_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		$like_timeout = $wpdb->esc_like( '_transient_timeout_gf_monday_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) );
	}

	/**
	 * Build a token-scoped cache key.
	 *
	 * @param string $suffix Key suffix.
	 * @return string
	 */
	protected function cache_key( $suffix ) {
		return 'gf_monday_' . md5( $this->api_token ) . '_' . $suffix;
	}

	/**
	 * Route a message to the injected logger, if any.
	 *
	 * @param string $message Message (token is never included by callers).
	 * @param string $level   'debug' or 'error'.
	 * @return void
	 */
	protected function log( $message, $level = 'debug' ) {
		if ( is_callable( $this->logger ) ) {
			call_user_func( $this->logger, $message, $level );
		}
	}
}
