<?php
/**
 * Translates Gravity Forms entry values into Monday column_values payloads.
 *
 * @package GravityFormsToMonday
 */

defined( 'ABSPATH' ) || die();

/**
 * Stateless helper that knows how each supported Monday column type is
 * represented in the create_item `column_values` JSON, and which Gravity Forms
 * field types make sensible sources for each.
 *
 * Kept free of WordPress/Gravity Forms globals so the formatting rules can be
 * unit-tested in isolation.
 */
class GF_Monday_Column_Mapper {

	/**
	 * Monday column types this connector can write in v1.
	 *
	 * @return string[]
	 */
	public static function supported_types() {
		return array(
			'text',
			'long_text',
			'numbers',
			'email',
			'phone',
			'date',
			'status',
			'dropdown',
			'checkbox',
			'link',
			'country',
			'rating',
			'hour',
			'world_clock',
			'name',
		);
	}

	/**
	 * Whether a Monday column type is writable by this connector.
	 *
	 * @param string $type Monday column type.
	 * @return bool
	 */
	public static function is_supported( $type ) {
		return in_array( $type, self::supported_types(), true );
	}

	/**
	 * Gravity Forms field types that make good sources for a given column type.
	 *
	 * Returns an empty array to mean "no restriction" (any field allowed).
	 *
	 * @param string $type Monday column type.
	 * @return string[]
	 */
	public static function compatible_gf_field_types( $type ) {
		switch ( $type ) {
			case 'email':
				return array( 'email' );
			case 'phone':
				return array( 'phone' );
			case 'date':
				return array( 'date' );
			case 'numbers':
			case 'rating':
				return array( 'number', 'quantity', 'total' );
			case 'link':
				return array( 'website' );
			case 'dropdown':
				return array( 'multiselect', 'checkbox', 'select', 'radio' );
			default:
				return array();
		}
	}

	/**
	 * Format a raw entry value for a Monday column.
	 *
	 * @param string $type  Monday column type.
	 * @param mixed  $value Raw value from the entry (already merge-tag resolved).
	 * @return mixed|null Value ready for JSON encoding, or null to skip the column.
	 */
	public static function format( $type, $value ) {
		if ( self::is_empty( $value ) ) {
			return null;
		}

		switch ( $type ) {
			case 'text':
			case 'name':
				return (string) $value;

			case 'long_text':
				return array( 'text' => (string) $value );

			case 'numbers':
				return self::format_number( $value );

			case 'rating':
				$number = self::format_number( $value );
				return null === $number ? null : (string) (int) round( (float) $number );

			case 'hour':
				return self::format_hour( $value );

			case 'email':
				return array(
					'email' => (string) $value,
					'text'  => (string) $value,
				);

			case 'phone':
				return array(
					'phone'            => (string) $value,
					'countryShortName' => '',
				);

			case 'date':
				return self::format_date( $value );

			case 'world_clock':
				return array( 'timezone' => (string) $value );

			case 'status':
				return array( 'label' => (string) $value );

			case 'dropdown':
				return array( 'labels' => self::to_labels( $value ) );

			case 'checkbox':
				return array( 'checked' => self::is_truthy( $value ) ? 'true' : 'false' );

			case 'link':
				return array(
					'url'  => (string) $value,
					'text' => (string) $value,
				);

			case 'country':
				return self::format_country( $value );

			default:
				return null;
		}
	}

	/**
	 * Whether a value counts as empty for mapping purposes.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	protected static function is_empty( $value ) {
		if ( is_array( $value ) ) {
			return 0 === count( array_filter( $value, static function ( $v ) {
				return '' !== trim( (string) $v );
			} ) );
		}

		return '' === trim( (string) $value );
	}

	/**
	 * Coerce common truthy strings to a boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	protected static function is_truthy( $value ) {
		$normalized = strtolower( trim( (string) $value ) );
		return in_array( $normalized, array( '1', 'true', 'yes', 'on', 'checked' ), true ) || ( is_numeric( $normalized ) && (float) $normalized > 0 );
	}

	/**
	 * Normalize a numeric string (strip thousands separators, keep decimal).
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	protected static function format_number( $value ) {
		$clean = preg_replace( '/[^0-9.\-]/', '', (string) $value );
		if ( '' === $clean || ! is_numeric( $clean ) ) {
			return null;
		}
		return (string) ( $clean + 0 );
	}

	/**
	 * Format an hour value ("14:30") into Monday's hour object.
	 *
	 * @param mixed $value Value.
	 * @return array|null
	 */
	protected static function format_hour( $value ) {
		if ( ! preg_match( '/(\d{1,2}):(\d{2})/', (string) $value, $m ) ) {
			return null;
		}
		return array(
			'hour'   => (int) $m[1],
			'minute' => (int) $m[2],
		);
	}

	/**
	 * Convert an entry value to a Monday date object (Y-m-d, optional time).
	 *
	 * @param mixed $value Value.
	 * @return array|null
	 */
	protected static function format_date( $value ) {
		$timestamp = strtotime( (string) $value );
		if ( false === $timestamp ) {
			return null;
		}

		$date = array( 'date' => gmdate( 'Y-m-d', $timestamp ) );

		// Include time only when the source carried one.
		if ( preg_match( '/\d{1,2}:\d{2}/', (string) $value ) ) {
			$date['time'] = gmdate( 'H:i:s', $timestamp );
		}

		return $date;
	}

	/**
	 * Split a multi-value entry field into an array of labels.
	 *
	 * @param mixed $value Value (array or comma-separated string).
	 * @return string[]
	 */
	protected static function to_labels( $value ) {
		$parts = is_array( $value ) ? $value : explode( ',', (string) $value );
		$parts = array_map( 'trim', $parts );

		return array_values( array_filter( $parts, static function ( $v ) {
			return '' !== $v;
		} ) );
	}

	/**
	 * Format a country column value from a country name or ISO code.
	 *
	 * Monday requires a two-letter ISO code plus a display name. When only a
	 * name is available and it is not a bare code, the raw text is passed as the
	 * name and the code is left blank for Monday to reconcile.
	 *
	 * @param mixed $value Value.
	 * @return array
	 */
	protected static function format_country( $value ) {
		$value = trim( (string) $value );

		if ( preg_match( '/^[A-Za-z]{2}$/', $value ) ) {
			return array(
				'countryCode' => strtoupper( $value ),
				'countryName' => strtoupper( $value ),
			);
		}

		return array(
			'countryCode' => '',
			'countryName' => $value,
		);
	}
}
