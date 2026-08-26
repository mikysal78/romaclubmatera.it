<?php
/**
 * Aggiorna data/ora e risultati degli eventi SportsPress (sp_event)
 * dalla API football-data.org.
 *
 * Abbinamento per giornata (meta sp_day) nella league/season configurate,
 * con verifica che le squadre della API corrispondano al titolo dell'evento.
 *
 * DATA/ORA: solo per i match con orario ufficiale (status TIMED o successivi).
 * Con SCHEDULED football-data espone date/orari provvisori che possono essere
 * meno aggiornati del calendario ufficiale della Lega già caricato.
 *
 * RISULTATI: solo per i match conclusi (FINISHED o AWARDED). Niente punteggi
 * a partita in corso: sul calendario finirebbero come se fossero definitivi.
 * Si scrivono le tre variabili configurate su SportsPress - goals, firsthalf,
 * secondhalf - più l'esito (vittoria/pareggio/sconfitta), che il plugin ricava
 * dalle sp_outcome in base alla loro condizione (>, =, <).
 * Con "Ora/Risultati" in colonna combinata il calendario mostra da solo il
 * punteggio al posto dell'orario appena l'evento ha un risultato.
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

$do_results = ! isset( $conf['SP_RESULTS'] ) || filter_var( $conf['SP_RESULTS'], FILTER_VALIDATE_BOOLEAN );

$tz         = wp_timezone();
$updated    = 0;
$same       = 0;
$warned     = 0;
$scored     = 0;

$official = array( 'TIMED', 'IN_PLAY', 'PAUSED', 'FINISHED' );
// Solo a partita conclusa: AWARDED è il match assegnato a tavolino.
$played   = array( 'FINISHED', 'AWARDED' );

/**
 * Quanto il nome di una squadra WordPress somiglia a una squadra della API.
 *
 * La API dà tre forme ("AS Roma" / "Roma" / "ROM") e i titoli su WordPress
 * non seguono nessuna di queste in modo costante ("AS Roma", "Fiorentina",
 * "Como"). L'uguaglianza vale più della sottostringa perché "Milan" è
 * contenuto in "FC Internazionale Milano": prendendo la sottostringa come
 * prova certa si assegnerebbe il punteggio dell'Inter al Milan.
 *
 * @param string $wp_title Titolo della squadra su WordPress.
 * @param array  $api_team Squadra come la restituisce football-data.
 * @return int Punteggio di somiglianza: 3 uguale, 2 contenuta, 0 diversa.
 */
function rcm_somiglianza( $wp_title, $api_team ) {
	$wp    = trim( (string) $wp_title );
	$forme = array_filter( array( $api_team['name'] ?? '', $api_team['shortName'] ?? '' ) );

	foreach ( $forme as $forma ) {
		if ( 0 === strcasecmp( $wp, $forma ) ) {
			return 3;
		}
	}
	foreach ( $forme as $forma ) {
		if ( '' !== $wp && ( false !== stripos( $forma, $wp ) || false !== stripos( $wp, $forma ) ) ) {
			return 2;
		}
	}
	return 0;
}

/**
 * Gli slug delle sp_outcome che rispondono a una condizione (>, =, <).
 *
 * SportsPress salva la condizione a volte con i caratteri HTML (&gt;),
 * a seconda di come è stata inserita: si cercano entrambe le forme.
 *
 * @param string $condizione '>', '=' oppure '<'.
 * @return array Slug degli esiti corrispondenti.
 */
function rcm_esiti( $condizione ) {
	static $cache = array();
	if ( isset( $cache[ $condizione ] ) ) {
		return $cache[ $condizione ];
	}

	$html  = array(
		'>' => '&gt;',
		'<' => '&lt;',
		'=' => '=',
	);
	$posts = get_posts(
		array(
			'post_type'      => 'sp_outcome',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'   => 'sp_condition',
					'value' => $condizione,
				),
				array(
					'key'   => 'sp_condition',
					'value' => $html[ $condizione ],
				),
			),
		)
	);

	$cache[ $condizione ] = wp_list_pluck( $posts, 'post_name' );
	return $cache[ $condizione ];
}

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
	} else {
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

	// ----- Risultato -----

	if ( ! $do_results || ! in_array( $status, $played, true ) ) {
		continue;
	}

	$gol_casa    = $m['score']['fullTime']['home'] ?? null;
	$gol_ospiti  = $m['score']['fullTime']['away'] ?? null;
	if ( null === $gol_casa || null === $gol_ospiti ) {
		WP_CLI::warning( "Giornata $day: '{$ev->post_title}' risulta $status ma senza punteggio, salto" );
		++$warned;
		continue;
	}

	// Le squadre dell'evento, nell'ordine in cui SportsPress le mostra.
	$sp_teams = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $ev->ID, 'sp_team', false ) ) ) );
	if ( 2 !== count( $sp_teams ) ) {
		WP_CLI::warning( "Giornata $day: '{$ev->post_title}' non ha esattamente due squadre, salto il risultato" );
		++$warned;
		continue;
	}

	// Quale delle due è in casa: si provano entrambi gli accoppiamenti e si
	// tiene il migliore. Deve vincere nettamente, altrimenti non si indovina.
	$titolo_a = get_the_title( $sp_teams[0] );
	$titolo_b = get_the_title( $sp_teams[1] );
	$diretto  = rcm_somiglianza( $titolo_a, $m['homeTeam'] ) + rcm_somiglianza( $titolo_b, $m['awayTeam'] );
	$invertito = rcm_somiglianza( $titolo_a, $m['awayTeam'] ) + rcm_somiglianza( $titolo_b, $m['homeTeam'] );

	if ( $diretto === $invertito ) {
		WP_CLI::warning(
			"Giornata $day: non riesco ad abbinare '$titolo_a'/'$titolo_b' a "
			. "'{$m['homeTeam']['shortName']}'-'{$m['awayTeam']['shortName']}', salto il risultato"
		);
		++$warned;
		continue;
	}

	if ( $diretto > $invertito ) {
		$id_casa   = $sp_teams[0];
		$id_ospiti = $sp_teams[1];
	} else {
		$id_casa   = $sp_teams[1];
		$id_ospiti = $sp_teams[0];
	}

	// Primo tempo dalla API, secondo tempo per differenza. Se il primo tempo
	// manca si scrive solo il totale invece di inventare la ripartizione.
	$pt_casa   = $m['score']['halfTime']['home'] ?? null;
	$pt_ospiti = $m['score']['halfTime']['away'] ?? null;

	$risultati = array(
		$id_casa   => array( 'goals' => (string) $gol_casa ),
		$id_ospiti => array( 'goals' => (string) $gol_ospiti ),
	);
	if ( null !== $pt_casa && null !== $pt_ospiti ) {
		$risultati[ $id_casa ]['firsthalf']    = (string) $pt_casa;
		$risultati[ $id_casa ]['secondhalf']   = (string) ( $gol_casa - $pt_casa );
		$risultati[ $id_ospiti ]['firsthalf']  = (string) $pt_ospiti;
		$risultati[ $id_ospiti ]['secondhalf'] = (string) ( $gol_ospiti - $pt_ospiti );
	}

	// Esito: SportsPress lo tiene dentro sp_results insieme al punteggio.
	if ( $gol_casa === $gol_ospiti ) {
		$risultati[ $id_casa ]['outcome']   = rcm_esiti( '=' );
		$risultati[ $id_ospiti ]['outcome'] = rcm_esiti( '=' );
	} else {
		$vince = $gol_casa > $gol_ospiti ? $id_casa : $id_ospiti;
		$perde = $gol_casa > $gol_ospiti ? $id_ospiti : $id_casa;

		$risultati[ $vince ]['outcome'] = rcm_esiti( '>' );
		$risultati[ $perde ]['outcome'] = rcm_esiti( '<' );
	}

	if ( get_post_meta( $ev->ID, 'sp_results', true ) === $risultati ) {
		++$same;
		continue;
	}

	update_post_meta( $ev->ID, 'sp_results', $risultati );

	// Un evento ancora programmato non si vedrebbe in calendario: se la
	// partita è finita va pubblicato, altrimenti il risultato resta invisibile.
	if ( 'future' === $ev->post_status ) {
		wp_update_post(
			array(
				'ID'          => $ev->ID,
				'post_status' => 'publish',
			)
		);
		WP_CLI::log( "Giornata $day: '{$ev->post_title}' era ancora programmato, pubblicato" );
	}

	WP_CLI::log( "Giornata $day: {$ev->post_title} risultato $gol_casa-$gol_ospiti [$status]" );
	++$scored;
}

WP_CLI::success( "Date aggiornate: $updated, risultati scritti: $scored, invariati: $same, avvisi: $warned" );
