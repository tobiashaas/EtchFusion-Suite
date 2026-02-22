<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$styles = get_option( 'etch_styles', array() );
$hits   = array();
foreach ( $styles as $id => $entry ) {
	$sel = $entry['selector'] ?? '';
	if (
		false !== strpos( $sel, 'bg--' )
		|| false !== strpos( $sel, 'is-bg' )
		|| false !== strpos( $sel, 'hidden-accessible' )
		|| 'smart-spacing' === ltrim( $sel, '.' )
	) {
		$hits[] = $id . ' => ' . $sel;
	}
}
sort( $hits );
echo implode( "\n", $hits ) . "\n";
echo 'Total ACSS/utility entries: ' . count( $hits ) . "\n";
