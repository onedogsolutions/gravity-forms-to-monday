<?php
/**
 * Standalone tests for GF_Monday_Column_Mapper.
 *
 * Runs without WordPress or PHPUnit: `php tests/test-column-mapper.php`.
 * Exits non-zero on the first failing assertion so it can gate CI.
 *
 * @package GravityFormsToMonday
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require __DIR__ . '/../includes/class-gf-monday-column-mapper.php';

$failures = 0;

/**
 * Assert that two values are JSON-equal.
 *
 * @param string $label    Test label.
 * @param mixed  $got      Actual value.
 * @param mixed  $expected Expected value.
 * @return void
 */
function gf_monday_assert( $label, $got, $expected ) {
	global $failures;
	$ok = wp_json_equal( $got, $expected );
	if ( ! $ok ) {
		++$failures;
	}
	printf(
		"[%s] %s\n",
		$ok ? 'PASS' : 'FAIL',
		$label
	);
	if ( ! $ok ) {
		printf( "     got:      %s\n     expected: %s\n", json_encode( $got ), json_encode( $expected ) );
	}
}

/**
 * Compare two values by JSON encoding.
 *
 * @param mixed $a First value.
 * @param mixed $b Second value.
 * @return bool
 */
function wp_json_equal( $a, $b ) {
	return json_encode( $a ) === json_encode( $b );
}

$m = 'GF_Monday_Column_Mapper';

gf_monday_assert( 'text', $m::format( 'text', 'Hello' ), 'Hello' );
gf_monday_assert( 'long_text', $m::format( 'long_text', 'Body' ), array( 'text' => 'Body' ) );
gf_monday_assert( 'numbers strips commas', $m::format( 'numbers', '1,234.50' ), '1234.5' );
gf_monday_assert( 'numbers non-numeric -> null', $m::format( 'numbers', 'abc' ), null );
gf_monday_assert( 'email', $m::format( 'email', 'a@b.co' ), array( 'email' => 'a@b.co', 'text' => 'a@b.co' ) );
gf_monday_assert( 'date only', $m::format( 'date', '2026-07-06' ), array( 'date' => '2026-07-06' ) );
gf_monday_assert( 'date with time', $m::format( 'date', '2026-07-06 14:30' ), array( 'date' => '2026-07-06', 'time' => '14:30:00' ) );
gf_monday_assert( 'status', $m::format( 'status', 'Done' ), array( 'label' => 'Done' ) );
gf_monday_assert( 'dropdown from array', $m::format( 'dropdown', array( 'A', 'B', '' ) ), array( 'labels' => array( 'A', 'B' ) ) );
gf_monday_assert( 'dropdown from csv', $m::format( 'dropdown', 'A, B' ), array( 'labels' => array( 'A', 'B' ) ) );
gf_monday_assert( 'checkbox truthy', $m::format( 'checkbox', '1' ), array( 'checked' => 'true' ) );
gf_monday_assert( 'checkbox falsy', $m::format( 'checkbox', '0' ), array( 'checked' => 'false' ) );
gf_monday_assert( 'link', $m::format( 'link', 'https://x.co' ), array( 'url' => 'https://x.co', 'text' => 'https://x.co' ) );
gf_monday_assert( 'country code', $m::format( 'country', 'us' ), array( 'countryCode' => 'US', 'countryName' => 'US' ) );
gf_monday_assert( 'rating rounds', $m::format( 'rating', '4.2' ), '4' );
gf_monday_assert( 'hour', $m::format( 'hour', '14:30' ), array( 'hour' => 14, 'minute' => 30 ) );
gf_monday_assert( 'phone strips formatting, US default', $m::format( 'phone', '+1 319 290 6881' ), array( 'phone' => '13192906881', 'countryShortName' => 'US' ) );
gf_monday_assert( 'phone empty -> null', $m::format( 'phone', '   ' ), null );
$m::set_default_country( 'ca' );
gf_monday_assert( 'phone honors default country', $m::format( 'phone', '416-555-0199' ), array( 'phone' => '4165550199', 'countryShortName' => 'CA' ) );
$m::set_default_country( 'US' );
gf_monday_assert( 'empty string -> null', $m::format( 'text', '   ' ), null );
gf_monday_assert( 'empty array -> null', $m::format( 'dropdown', array( '', ' ' ) ), null );
gf_monday_assert( 'is_supported people == false', $m::is_supported( 'people' ), false );
gf_monday_assert( 'is_supported status == true', $m::is_supported( 'status' ), true );
gf_monday_assert( 'email field types', $m::compatible_gf_field_types( 'email' ), array( 'email' ) );

echo "\n";
if ( $failures > 0 ) {
	printf( "%d test(s) failed.\n", $failures );
	exit( 1 );
}
echo "All tests passed.\n";
exit( 0 );
