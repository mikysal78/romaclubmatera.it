<?php
/**
 * Aggiorna data/ora degli eventi SportsPress (sp_event) dalla API football-data.org.
 *
 * Abbinamento per giornata (meta sp_day) nella league/season configurate,
 * con verifica che le squadre della API corrispondano al titolo dell'evento.
 * Aggiorna SOLO i match con orario ufficiale (status TIMED o successivi):
 * con SCHEDULED football-data espone date/orari provvisori che possono
 * essere meno aggiornati del calendario ufficiale della Lega già caricato.
 *
 * Uso: wp eval-file update-fixtures.php --path=<wordpress>
 * Config (formato ini): /etc/default/sp-fixtures, override con env SP_FIXTURES_CONF.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$config_file = getenv( 'SP_FIXTURES_CONF' ) ?: '/etc/default/sp-fixtures';
$conf        = is_readable( $config_file ) ? parse_ini_file( $config_file ) : false;
if ( ! $conf || empty( $conf['FD_TOKEN'] ) ) {
	WP_CLI::error( "Config mancante o FD_TOKEN vuoto in $config_file" );
}

$token       = $conf['FD_TOKEN'];
$team_id     = $conf['FD_TEAM_ID'] ?? '100';
$fd_season   = $conf['FD_SEASON'] ?? '2026';
$competition = $conf['FD_COMPETITION'] ?? 'SA';
$league_slug = $conf['SP_LEAGUE'] ?? 'serie-a';
$season_slug = $conf['SP_SEASON'] ?? '2026-27';

$url  = "https://api.football-data.org/v4/teams/{$team_id}/matches"
	. '?' . http_build_query( array( 'competitions' => $competition, 'season' => $fd_season ) );
$resp = wp_remote_get( $url, array( 'headers' => array( 'X-Auth-Token' => $token ), 'timeout' => 30 ) );
if ( is_wp_error( $resp ) ) {
	WP_CLI::error( 'API: ' . $resp->get_error_message() );
}
$http = wp_remote_retrieve_response_code( $resp );
if ( 200 !== $http ) {
	WP_CLI::error( "API HTTP $http" );
}
$data = json_decode( wp_remote_retrieve_body( $resp ), true );
if ( empty( $data['matches'] ) ) {
	WP_CLI::error( 'API: nessun match nella risposta' );
}

$tz      = wp_timezone();
$updated = 0;
$same    = 0;
$warned  = 0;

$official = array( 'TIMED', 'IN_PLAY', 'PAUSED', 'FINISHED' );

foreach ( $data['matches'] as $m ) {
	$day    = (string) $m['matchday'];
	$status = $m['status'];

	if ( ! in_array( $status, $official, true ) ) {
		++$same;
		continue;
	}

	$q = new WP_Query(
		array(
			'post_type'      => 'sp_event',
			'post_status'    => array( 'future', 'publish' ),
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => 'sp_day',
					'value' => $day,
				),
			),
			'tax_query'      => array(
				array(
					'taxonomy' => 'sp_league',
					'field'    => 'slug',
					'terms'    => $league_slug,
				),
				array(
					'taxonomy' => 'sp_season',
					'field'    => 'slug',
					'terms'    => $season_slug,
				),
			),
		)
	);
	if ( ! $q->have_posts() ) {
		WP_CLI::warning( "Giornata $day: nessun evento trovato" );
		++$warned;
		continue;
	}
	$ev = $q->posts[0];

	$home = $m['homeTeam']['shortName'];
	$away = $m['awayTeam']['shortName'];
	if ( false === stripos( $ev->post_title, $home ) && false === stripos( $ev->post_title, $away ) ) {
		WP_CLI::warning( "Giornata $day: API '$home-$away' non corrisponde a '{$ev->post_title}', salto" );
		++$warned;
		continue;
	}

	$local    = ( new DateTimeImmutable( $m['utcDate'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
	$new_date = $local->format( 'Y-m-d H:i:s' );
	if ( $new_date === $ev->post_date ) {
		++$same;
		continue;
	}

	wp_update_post(
		array(
			'ID'            => $ev->ID,
			'post_date'     => $new_date,
			'post_date_gmt' => $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'edit_date'     => true,
		)
	);
	WP_CLI::log( "Giornata $day: {$ev->post_title} {$ev->post_date} -> $new_date [$status]" );
	++$updated;
}

WP_CLI::success( "Aggiornati: $updated, invariati: $same, avvisi: $warned" );
