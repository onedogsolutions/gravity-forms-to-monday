<?php
/**
 * Main Gravity Forms feed add-on class for Monday.com.
 *
 * @package GravityFormsToMonday
 */

defined( 'ABSPATH' ) || die();

/**
 * Pushes Gravity Forms entries to Monday.com as board items.
 */
class GF_Monday extends GFFeedAddOn {

	/**
	 * Add-on version.
	 *
	 * @var string
	 */
	protected $_version = GF_MONDAY_VERSION;

	/**
	 * Minimum Gravity Forms version.
	 *
	 * @var string
	 */
	protected $_min_gravityforms_version = GF_MONDAY_MIN_GF_VERSION;

	/**
	 * Add-on slug.
	 *
	 * @var string
	 */
	protected $_slug = 'gravityformstomonday';

	/**
	 * Plugin path relative to the plugins directory.
	 *
	 * @var string
	 */
	protected $_path = 'gravity-forms-to-monday/gravity-forms-to-monday.php';

	/**
	 * Full plugin path.
	 *
	 * @var string
	 */
	protected $_full_path = GF_MONDAY_PLUGIN_FILE;

	/**
	 * Add-on title.
	 *
	 * @var string
	 */
	protected $_title = 'Gravity Forms Monday Add-On';

	/**
	 * Add-on short title.
	 *
	 * @var string
	 */
	protected $_short_title = 'Monday';

	/**
	 * One feed per entry can create one item; multiple feeds are allowed.
	 *
	 * @var bool
	 */
	protected $_multiple_feeds = true;

	/**
	 * Capabilities.
	 *
	 * @var string|array
	 */
	protected $_capabilities = array( 'gravityforms_monday', 'gravityforms_monday_uninstall' );

	/**
	 * Settings page capability.
	 *
	 * @var string
	 */
	protected $_capabilities_settings_page = 'gravityforms_monday';

	/**
	 * Form settings capability.
	 *
	 * @var string
	 */
	protected $_capabilities_form_settings = 'gravityforms_monday';

	/**
	 * Uninstall capability.
	 *
	 * @var string
	 */
	protected $_capabilities_uninstall = 'gravityforms_monday_uninstall';

	/**
	 * Singleton instance.
	 *
	 * @var GF_Monday|null
	 */
	private static $_instance = null;

	/**
	 * Cached API client.
	 *
	 * @var GF_Monday_API|null
	 */
	private $api = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return GF_Monday
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Register admin asset hooks.
	 *
	 * @return void
	 */
	public function init_admin() {
		parent::init_admin();

		add_action( 'gform_entry_detail_sidebar_after', array( $this, 'entry_detail_meta_box' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// API client
	// -------------------------------------------------------------------------

	/**
	 * Build (and cache) an API client from the stored token.
	 *
	 * @return GF_Monday_API
	 */
	public function get_api() {
		if ( $this->api instanceof GF_Monday_API ) {
			return $this->api;
		}

		$logger    = array( $this, 'log_api' );
		$this->api = new GF_Monday_API( $this->get_api_token(), $logger );

		return $this->api;
	}

	/**
	 * Resolve the API token, allowing override via constant/filter.
	 *
	 * @return string
	 */
	public function get_api_token() {
		$token = $this->get_plugin_setting( 'api_token' );

		/**
		 * Filter the Monday API token before use.
		 *
		 * Allows storing the token in wp-config.php instead of the database.
		 *
		 * @param string $token Stored token.
		 */
		return (string) apply_filters( 'gform_monday_api_token', $token );
	}

	/**
	 * Whether a usable API connection is configured.
	 *
	 * @return bool
	 */
	public function initialize_api() {
		return $this->get_api()->has_token();
	}

	/**
	 * Bridge the API client's logger to Gravity Forms logging.
	 *
	 * @param string $message Message.
	 * @param string $level   Level.
	 * @return void
	 */
	public function log_api( $message, $level = 'debug' ) {
		if ( 'error' === $level ) {
			$this->log_error( __METHOD__ . '(): ' . $message );
		} else {
			$this->log_debug( __METHOD__ . '(): ' . $message );
		}
	}

	// -------------------------------------------------------------------------
	// Plugin (master) settings — credentials
	// -------------------------------------------------------------------------

	/**
	 * Master settings fields.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'title'       => esc_html__( 'Monday API Settings', 'gravity-forms-to-monday' ),
				'description' => wp_kses_post(
					__( 'Enter a Monday.com personal API token. Find it in Monday under your avatar → <strong>Developers → My access tokens</strong>.', 'gravity-forms-to-monday' )
				),
				'fields'      => array(
					array(
						'name'              => 'api_token',
						'label'             => esc_html__( 'API Token', 'gravity-forms-to-monday' ),
						'type'              => 'text',
						'input_type'        => 'password',
						'class'             => 'medium',
						'required'          => true,
						'feedback_callback' => array( $this, 'validate_settings_token' ),
						'description'       => $this->get_connection_status_message(),
					),
				),
			),
		);
	}

	/**
	 * Feedback callback for the token field (green tick / red cross).
	 *
	 * @param string $value Submitted token.
	 * @return bool|null
	 */
	public function validate_settings_token( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		$api    = new GF_Monday_API( $value, array( $this, 'log_api' ) );
		$result = $api->validate_token();

		return ! is_wp_error( $result );
	}

	/**
	 * Human-readable connection status shown under the token field.
	 *
	 * @return string
	 */
	protected function get_connection_status_message() {
		if ( ! $this->initialize_api() ) {
			return '';
		}

		$account = $this->get_api()->validate_token();
		if ( is_wp_error( $account ) ) {
			return sprintf(
				'<span style="color:#cc1818;">%s</span>',
				esc_html( $account->get_error_message() )
			);
		}

		$name    = isset( $account['name'] ) ? $account['name'] : '';
		$company = isset( $account['account']['name'] ) ? $account['account']['name'] : '';

		return sprintf(
			'<span style="color:#0c9d1c;">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: user name, 2: account name. */
					__( 'Connected as %1$s (%2$s).', 'gravity-forms-to-monday' ),
					$name,
					$company
				)
			)
		);
	}

	/**
	 * Flush cached discovery data whenever settings are saved (token may change).
	 *
	 * @param array $settings Saved settings.
	 * @return void
	 */
	public function update_plugin_settings( $settings ) {
		parent::update_plugin_settings( $settings );

		// Reset the cached client and its transients so a new token takes effect.
		$this->api = null;
		$this->get_api()->flush_cache();
	}

	// -------------------------------------------------------------------------
	// Feed settings — mapping
	// -------------------------------------------------------------------------

	/**
	 * Gate feed creation on a configured API connection.
	 *
	 * @return bool
	 */
	public function can_create_feed() {
		return $this->initialize_api();
	}

	/**
	 * Message shown when the add-on is not yet configured.
	 *
	 * @return string
	 */
	public function configure_addon_message() {
		$settings_url = add_query_arg(
			array(
				'page'    => 'gf_settings',
				'subview' => $this->_slug,
			),
			admin_url( 'admin.php' )
		);

		return sprintf(
			/* translators: 1: opening anchor tag, 2: closing anchor tag. */
			esc_html__( 'To get started, configure your Monday API token on the %1$ssettings page%2$s.', 'gravity-forms-to-monday' ),
			'<a href="' . esc_url( $settings_url ) . '">',
			'</a>'
		);
	}

	/**
	 * Feed list table columns.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feed_name'  => esc_html__( 'Name', 'gravity-forms-to-monday' ),
			'monday_board' => esc_html__( 'Monday Board', 'gravity-forms-to-monday' ),
		);
	}

	/**
	 * Render the board name for the feed list.
	 *
	 * @param array $feed Feed object.
	 * @return string
	 */
	public function get_column_value_monday_board( $feed ) {
		$board_id = rgars( $feed, 'meta/monday_board_id' );
		if ( empty( $board_id ) ) {
			return '&mdash;';
		}

		foreach ( $this->get_board_choices() as $choice ) {
			if ( (string) $choice['value'] === (string) $board_id ) {
				return esc_html( $choice['label'] );
			}
		}

		return esc_html( $board_id );
	}

	/**
	 * Feed settings fields.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		$destination_fields = array(
			array(
				'name'     => 'feed_name',
				'label'    => esc_html__( 'Feed Name', 'gravity-forms-to-monday' ),
				'type'     => 'text',
				'class'    => 'medium',
				'required' => true,
				'tooltip'  => '<h6>' . esc_html__( 'Feed Name', 'gravity-forms-to-monday' ) . '</h6>' . esc_html__( 'Enter a name to identify this feed.', 'gravity-forms-to-monday' ),
			),
			array(
				'name'     => 'monday_board_id',
				'label'    => esc_html__( 'Monday Board', 'gravity-forms-to-monday' ),
				'type'     => 'select',
				'required' => true,
				'choices'  => $this->get_board_choices(),
				'onchange' => "jQuery(this).parents('form').submit();",
				'tooltip'  => '<h6>' . esc_html__( 'Monday Board', 'gravity-forms-to-monday' ) . '</h6>' . esc_html__( 'Choose the board that new entries should be added to.', 'gravity-forms-to-monday' ),
			),
		);

		$board_id = $this->get_setting( 'monday_board_id' );

		if ( ! empty( $board_id ) ) {
			$destination_fields[] = array(
				'name'    => 'monday_group_id',
				'label'   => esc_html__( 'Group', 'gravity-forms-to-monday' ),
				'type'    => 'select',
				'choices' => $this->get_group_choices( $board_id ),
				'tooltip' => '<h6>' . esc_html__( 'Group', 'gravity-forms-to-monday' ) . '</h6>' . esc_html__( 'Choose the group new items are placed in. Defaults to the top group.', 'gravity-forms-to-monday' ),
			);

			$destination_fields[] = array(
				'name'          => 'monday_item_name',
				'label'         => esc_html__( 'Item Name', 'gravity-forms-to-monday' ),
				'type'          => 'text',
				'class'         => 'medium merge-tag-support mt-position-right',
				'required'      => true,
				'default_value' => '{form_title} - {entry_id}',
				'tooltip'       => '<h6>' . esc_html__( 'Item Name', 'gravity-forms-to-monday' ) . '</h6>' . esc_html__( 'The name of the created item. Merge tags are supported.', 'gravity-forms-to-monday' ),
			);
		}

		$sections = array(
			array(
				'title'  => esc_html__( 'Destination', 'gravity-forms-to-monday' ),
				'fields' => $destination_fields,
			),
		);

		if ( ! empty( $board_id ) ) {
			$field_map = $this->get_board_field_map( $board_id );

			if ( is_wp_error( $field_map ) ) {
				$sections[] = array(
					'title'       => esc_html__( 'Field Mapping', 'gravity-forms-to-monday' ),
					'description' => sprintf(
						'<div class="alert error">%s</div>',
						esc_html( $field_map->get_error_message() )
					),
					'fields'      => array(),
				);
			} else {
				$sections[] = array(
					'title'  => esc_html__( 'Field Mapping', 'gravity-forms-to-monday' ),
					'description' => esc_html__( 'Map Monday columns to Gravity Forms fields. Leave a column blank to skip it.', 'gravity-forms-to-monday' ),
					'fields' => array(
						array(
							'name'      => 'monday_columns',
							'label'     => esc_html__( 'Columns', 'gravity-forms-to-monday' ),
							'type'      => 'field_map',
							'field_map' => $field_map,
						),
						array(
							'name'    => 'monday_dynamic_columns',
							'label'   => esc_html__( 'Additional Columns', 'gravity-forms-to-monday' ),
							'type'    => 'dynamic_field_map',
							'key_field' => array(
								'title'   => esc_html__( 'Monday Column ID', 'gravity-forms-to-monday' ),
								'choices' => $this->get_all_column_choices( $board_id ),
							),
							'tooltip' => '<h6>' . esc_html__( 'Additional Columns', 'gravity-forms-to-monday' ) . '</h6>' . esc_html__( 'Map any remaining columns not listed above.', 'gravity-forms-to-monday' ),
						),
					),
				);

				$sections[] = array(
					'title'  => esc_html__( 'Options', 'gravity-forms-to-monday' ),
					'fields' => array(
						array(
							'name'    => 'monday_create_labels',
							'label'   => esc_html__( 'Labels', 'gravity-forms-to-monday' ),
							'type'    => 'checkbox',
							'choices' => array(
								array(
									'name'  => 'monday_create_labels',
									'label' => esc_html__( 'Create missing Status / Dropdown labels automatically', 'gravity-forms-to-monday' ),
								),
							),
						),
						array(
							'name'    => 'monday_add_note',
							'label'   => esc_html__( 'Entry Note', 'gravity-forms-to-monday' ),
							'type'    => 'checkbox',
							'choices' => array(
								array(
									'name'          => 'monday_add_note',
									'label'         => esc_html__( 'Add a note to the entry linking to the created Monday item', 'gravity-forms-to-monday' ),
									'default_value' => 1,
								),
							),
						),
					),
				);
			}
		}

		// Conditional logic section.
		$sections[] = array(
			'title'  => esc_html__( 'Conditional Logic', 'gravity-forms-to-monday' ),
			'fields' => array(
				array(
					'name'    => 'feed_condition',
					'label'   => esc_html__( 'Condition', 'gravity-forms-to-monday' ),
					'type'    => 'feed_condition',
					'tooltip' => '<h6>' . esc_html__( 'Conditional Logic', 'gravity-forms-to-monday' ) . '</h6>' . esc_html__( 'Only send the entry to Monday when the condition is met.', 'gravity-forms-to-monday' ),
				),
			),
		);

		return $sections;
	}

	/**
	 * Board choices for the feed dropdown.
	 *
	 * @return array
	 */
	protected function get_board_choices() {
		$choices = array(
			array(
				'label' => esc_html__( 'Select a Board', 'gravity-forms-to-monday' ),
				'value' => '',
			),
		);

		if ( ! $this->initialize_api() ) {
			return $choices;
		}

		$boards = $this->get_api()->get_boards();
		if ( is_wp_error( $boards ) ) {
			return $choices;
		}

		foreach ( $boards as $board ) {
			$label = $board['name'];
			if ( ! empty( $board['workspace']['name'] ) ) {
				$label .= ' (' . $board['workspace']['name'] . ')';
			}
			$choices[] = array(
				'label' => $label,
				'value' => (string) $board['id'],
			);
		}

		return $choices;
	}

	/**
	 * Group choices for the selected board.
	 *
	 * @param string $board_id Board ID.
	 * @return array
	 */
	protected function get_group_choices( $board_id ) {
		$choices = array(
			array(
				'label' => esc_html__( 'Top of board (default)', 'gravity-forms-to-monday' ),
				'value' => '',
			),
		);

		$board = $this->get_api()->get_board( $board_id );
		if ( is_wp_error( $board ) || empty( $board['groups'] ) ) {
			return $choices;
		}

		foreach ( $board['groups'] as $group ) {
			$choices[] = array(
				'label' => $group['title'],
				'value' => (string) $group['id'],
			);
		}

		return $choices;
	}

	/**
	 * Build the field_map definition from a board's supported columns.
	 *
	 * @param string $board_id Board ID.
	 * @return array|WP_Error
	 */
	protected function get_board_field_map( $board_id ) {
		$board = $this->get_api()->get_board( $board_id );
		if ( is_wp_error( $board ) ) {
			return $board;
		}

		$field_map = array();

		foreach ( (array) rgar( $board, 'columns' ) as $column ) {
			if ( ! GF_Monday_Column_Mapper::is_supported( $column['type'] ) ) {
				continue;
			}

			$entry = array(
				'name'  => 'col_' . $column['id'],
				'label' => $column['title'],
			);

			$field_types = GF_Monday_Column_Mapper::compatible_gf_field_types( $column['type'] );
			if ( ! empty( $field_types ) ) {
				$entry['field_type'] = $field_types;
			}

			$field_map[] = $entry;
		}

		return $field_map;
	}

	/**
	 * All column choices (id => title) for the dynamic map key field.
	 *
	 * @param string $board_id Board ID.
	 * @return array
	 */
	protected function get_all_column_choices( $board_id ) {
		$choices = array(
			array(
				'label' => esc_html__( 'Select a Column', 'gravity-forms-to-monday' ),
				'value' => '',
			),
		);

		$board = $this->get_api()->get_board( $board_id );
		if ( is_wp_error( $board ) ) {
			return $choices;
		}

		foreach ( (array) rgar( $board, 'columns' ) as $column ) {
			$choices[] = array(
				'label' => $column['title'] . ' (' . $column['type'] . ')',
				'value' => (string) $column['id'],
			);
		}

		return $choices;
	}

	// -------------------------------------------------------------------------
	// Feed processing
	// -------------------------------------------------------------------------

	/**
	 * Process a feed: create a Monday item from the entry.
	 *
	 * @param array $feed  Feed object.
	 * @param array $entry Entry object.
	 * @param array $form  Form object.
	 * @return array|bool|WP_Error The entry on success, false/WP_Error on failure.
	 */
	public function process_feed( $feed, $entry, $form ) {
		if ( ! $this->initialize_api() ) {
			$this->add_feed_error( esc_html__( 'Monday API is not configured.', 'gravity-forms-to-monday' ), $feed, $entry, $form );
			return $entry;
		}

		$board_id = rgars( $feed, 'meta/monday_board_id' );
		if ( empty( $board_id ) ) {
			$this->add_feed_error( esc_html__( 'No Monday board configured for this feed.', 'gravity-forms-to-monday' ), $feed, $entry, $form );
			return $entry;
		}

		$group_id  = rgars( $feed, 'meta/monday_group_id' );
		$item_name = GFCommon::replace_variables( rgars( $feed, 'meta/monday_item_name' ), $form, $entry );
		$item_name = wp_strip_all_tags( $item_name );

		/**
		 * Filter the computed Monday item name.
		 *
		 * @param string $item_name Item name.
		 * @param array  $feed      Feed object.
		 * @param array  $entry     Entry object.
		 * @param array  $form      Form object.
		 */
		$item_name = apply_filters( 'gform_monday_item_name', $item_name, $feed, $entry, $form );

		$column_values = $this->build_column_values( $feed, $entry, $form, $board_id );

		/**
		 * Filter the final column_values payload before sending to Monday.
		 *
		 * @param array $column_values Map of column ID => value.
		 * @param array $feed          Feed object.
		 * @param array $entry         Entry object.
		 * @param array $form          Form object.
		 */
		$column_values = apply_filters( 'gform_monday_column_values', $column_values, $feed, $entry, $form );

		$create_labels = (bool) rgars( $feed, 'meta/monday_create_labels' );

		$this->log_debug( __METHOD__ . '(): Creating Monday item on board ' . $board_id . ' with ' . count( $column_values ) . ' column value(s).' );

		$item = $this->get_api()->create_item( $board_id, $item_name, $column_values, $group_id, $create_labels );

		if ( is_wp_error( $item ) ) {
			$this->add_feed_error(
				sprintf(
					/* translators: %s: error message. */
					esc_html__( 'Could not create Monday item: %s', 'gravity-forms-to-monday' ),
					$item->get_error_message()
				),
				$feed,
				$entry,
				$form
			);
			return $entry;
		}

		gform_update_meta( $entry['id'], 'monday_item_id', $item['id'] );
		gform_update_meta( $entry['id'], 'monday_item_url', rgar( $item, 'url' ) );

		if ( rgars( $feed, 'meta/monday_add_note' ) ) {
			$note = sprintf(
				/* translators: 1: item name, 2: item URL. */
				esc_html__( 'Created Monday item "%1$s": %2$s', 'gravity-forms-to-monday' ),
				$item['name'],
				rgar( $item, 'url' )
			);
			$this->add_note( $entry['id'], $note, 'success' );
		}

		/**
		 * Fires after a Monday item is successfully created.
		 *
		 * @param string $item_id Monday item ID.
		 * @param array  $feed    Feed object.
		 * @param array  $entry   Entry object.
		 * @param array  $form    Form object.
		 */
		do_action( 'gform_monday_item_created', $item['id'], $feed, $entry, $form );

		return $entry;
	}

	/**
	 * Assemble the column_values map from mapped and dynamic fields.
	 *
	 * @param array  $feed     Feed object.
	 * @param array  $entry    Entry object.
	 * @param array  $form     Form object.
	 * @param string $board_id Board ID.
	 * @return array
	 */
	protected function build_column_values( $feed, $entry, $form, $board_id ) {
		$board = $this->get_api()->get_board( $board_id );
		if ( is_wp_error( $board ) ) {
			return array();
		}

		// Index column types by ID for formatting.
		$types = array();
		foreach ( (array) rgar( $board, 'columns' ) as $column ) {
			$types[ (string) $column['id'] ] = $column['type'];
		}

		$values = array();

		// Mapped columns (field_map named "monday_columns", each key "col_<id>").
		$mapped = $this->get_field_map_fields( $feed, 'monday_columns' );
		foreach ( $mapped as $name => $field_id ) {
			if ( 0 !== strpos( $name, 'col_' ) || rgblank( $field_id ) ) {
				continue;
			}

			$column_id = substr( $name, 4 );
			$this->add_column_value( $values, $types, $column_id, $this->get_field_value( $form, $entry, $field_id ), $feed, $entry, $form );
		}

		// Dynamic columns.
		$dynamic = $this->get_dynamic_field_map_fields( $feed, 'monday_dynamic_columns' );
		foreach ( $dynamic as $column_id => $field_id ) {
			if ( rgblank( $column_id ) || rgblank( $field_id ) ) {
				continue;
			}
			$this->add_column_value( $values, $types, (string) $column_id, $this->get_field_value( $form, $entry, $field_id ), $feed, $entry, $form );
		}

		return $values;
	}

	/**
	 * Format and add a single column value, applying the per-column filter.
	 *
	 * @param array  $values    Accumulator (by reference).
	 * @param array  $types     Column ID => type map.
	 * @param string $column_id Column ID.
	 * @param mixed  $raw_value Raw entry value.
	 * @param array  $feed      Feed object.
	 * @param array  $entry     Entry object.
	 * @param array  $form      Form object.
	 * @return void
	 */
	protected function add_column_value( &$values, $types, $column_id, $raw_value, $feed, $entry, $form ) {
		$type      = isset( $types[ $column_id ] ) ? $types[ $column_id ] : 'text';
		$formatted = GF_Monday_Column_Mapper::format( $type, $raw_value );

		/**
		 * Filter a single formatted column value.
		 *
		 * Return null to skip the column, or provide a value for column types
		 * this connector does not format natively.
		 *
		 * @param mixed  $formatted Formatted value (null to skip).
		 * @param string $type      Monday column type.
		 * @param mixed  $raw_value Raw entry value.
		 * @param string $column_id Column ID.
		 * @param array  $feed      Feed object.
		 * @param array  $entry     Entry object.
		 * @param array  $form      Form object.
		 */
		$formatted = apply_filters( 'gform_monday_column_value', $formatted, $type, $raw_value, $column_id, $feed, $entry, $form );

		if ( null !== $formatted ) {
			$values[ $column_id ] = $formatted;
		}
	}

	// -------------------------------------------------------------------------
	// Entry detail
	// -------------------------------------------------------------------------

	/**
	 * Show the linked Monday item in the entry detail sidebar.
	 *
	 * @param array $form  Form object.
	 * @param array $entry Entry object.
	 * @return void
	 */
	public function entry_detail_meta_box( $form, $entry ) {
		$item_url = gform_get_meta( $entry['id'], 'monday_item_url' );
		$item_id  = gform_get_meta( $entry['id'], 'monday_item_id' );

		if ( empty( $item_id ) ) {
			return;
		}
		?>
		<div class="postbox">
			<h3 class="hndle"><span><?php esc_html_e( 'Monday', 'gravity-forms-to-monday' ); ?></span></h3>
			<div class="inside">
				<?php if ( ! empty( $item_url ) ) : ?>
					<p><a href="<?php echo esc_url( $item_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View item in Monday', 'gravity-forms-to-monday' ); ?></a></p>
				<?php endif; ?>
				<p><?php printf( esc_html__( 'Item ID: %s', 'gravity-forms-to-monday' ), esc_html( $item_id ) ); ?></p>
			</div>
		</div>
		<?php
	}
}
