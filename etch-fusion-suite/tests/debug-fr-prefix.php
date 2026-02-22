<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$styles   = get_option( 'etch_styles', array() );
$with_fr  = 0;
$stripped = 0;
$ex_fr    = '';
$ex_ok    = '';

foreach ( $styles as $s ) {
	$sel = $s['selector'] ?? '';
	if ( false !== strpos( $sel, '.fr-' ) ) {
		$with_fr++;
		if ( ! $ex_fr ) {
			$ex_fr = $sel;
		}
	} elseif ( 1 === preg_match( '/^\.[a-z]/', $sel ) ) {
		$stripped++;
		if ( ! $ex_ok ) {
			$ex_ok = $sel;
		}
	}
}
echo "With .fr-  : $with_fr\n";
echo "Without fr-: $stripped\n";
echo "Example fr-: $ex_fr\n";
echo "Example ok : $ex_ok\n\n";

// Check brxe- in CSS
$brxe_count = 0;
foreach ( $styles as $s ) {
	if ( isset( $s['css'] ) && false !== strpos( $s['css'], 'brxe-' ) ) {
		$brxe_count++;
	}
}
echo "Styles with brxe- in CSS: $brxe_count\n";

// Check for hero-cali
foreach ( $styles as $id => $s ) {
	if ( '.hero-cali' === ( $s['selector'] ?? '' ) ) {
		echo "\nhero-cali selector: " . $s['selector'] . " (id=$id)\n";
	}
	if ( '.fr-hero-cali' === ( $s['selector'] ?? '' ) ) {
		echo "\nSTILL fr-hero-cali: " . $s['selector'] . " (id=$id)\n";
	}
}
