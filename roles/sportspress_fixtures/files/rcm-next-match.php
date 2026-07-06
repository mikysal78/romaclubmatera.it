<?php
/**
 * Plugin Name: RCM Next Match Highlight
 * Description: Evidenzia la riga della prossima partita nelle tabelle event-list di SportsPress e svuota le cache quando un evento programmato viene pubblicato (partita giocata), così l'evidenziazione passa da sola alla gara successiva.
 * Author: Roma Club Matera
 */

defined( 'ABSPATH' ) || exit;

/**
 * Aggiunge la classe sp-next-match alla riga del prossimo evento futuro.
 */
add_filter(
	'the_content',
	function ( $content ) {
		if ( false === strpos( $content, 'sp-event-list' ) ) {
			return $content;
		}
		$next = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => 'future',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
		if ( empty( $next ) ) {
			return $content;
		}
		$link  = get_permalink( $next[0]->ID );
		$parts = explode( '<tr class="sp-row sp-post', $content );
		foreach ( $parts as $i => $part ) {
			if ( $i > 0 && false !== strpos( $part, '"' . $link . '"' ) ) {
				$parts[ $i ] = ' sp-next-match' . $part;
			}
		}
		return implode( '<tr class="sp-row sp-post', $parts );
	},
	20
);

/**
 * Orario 00:00 = non ancora ufficializzato: mostra "da definire"
 * al posto di "0:00" (tabella calendario e banner prossima partita).
 */
function rcm_event_time_is_tbd( $event_id ) {
	return '00:00' === get_post_time( 'H:i', false, $event_id )
		&& get_post_time( 'U', true, $event_id ) > time();
}

add_filter(
	'sportspress_event_time',
	function ( $time, $event_id ) {
		return rcm_event_time_is_tbd( $event_id ) ? 'da definire' : $time;
	},
	10,
	2
);

add_filter(
	'sportspress_event_blocks_team_result_or_time',
	function ( $results, $event_id ) {
		return rcm_event_time_is_tbd( $event_id ) ? array( 'da definire' ) : $results;
	},
	10,
	2
);

/**
 * Quando un sp_event passa da "programmato" a "pubblicato" (partita giocata)
 * svuota object cache e page cache, così calendario e banner si aggiornano.
 */
add_action(
	'publish_future_post',
	function ( $post_id ) {
		if ( 'sp_event' !== get_post_type( $post_id ) ) {
			return;
		}
		wp_cache_flush();
		// Super Page Cache, se attivo.
		do_action( 'swcfpc_purge_everything' );
	}
);
