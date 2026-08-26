<?php
/**
 * Riempie le classifiche SportsPress (sp_table) dalla API football-data.org.
 *
 * PERCHÉ NON LE CALCOLA SPORTSPRESS. Il plugin ricava la classifica dagli
 * eventi che ha in archivio, e sul sito ci sono solo le partite della Roma:
 * una classifica calcolata mostrerebbe una riga sola con i dati veri e
 * diciannove a zero. Qui si scrivono invece i valori "manuali" nel meta
 * sp_teams, che SportsPress usa al posto di quelli calcolati quando ci sono.
 * Così la tabella è quella ufficiale senza dover importare tutte le 380
 * partite del campionato.
 *
 * ORDINAMENTO. SportsPress riordina sempre da sé, per priorità di colonna:
 * punti, differenza reti, gol fatti. In Serie A però il primo criterio a
 * parità di punti è lo scontro diretto, che con le sole partite della Roma
 * non è calcolabile — e una tabella ordinata "quasi" bene sarebbe peggio di
 * una sbagliata dichiarata. Si scrive quindi anche la posizione ufficiale
 * in una colonna nascosta con priorità 1: l'ordine finale è esattamente
 * quello della Lega. La colonna non compare fra quelle mostrate.
 *
 * La tabella da riempire si trova da sola: è la sp_table con la stessa
 * lega e la stessa stagione della competizione. Nessun ID nella config.
 *
 * Uso: wp eval-file update-standings.php --path=<wordpress>
 * Config: la stessa di update-fixtures.php.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once __DIR__ . '/sp-lib.php';

/** Slug della colonna nascosta che tiene la posizione ufficiale. */
const RCM_COLONNA_POS = 'posizione';

/**
 * Campo della API => slug della colonna SportsPress.
 *
 * Le otto colonne standard del plugin corrispondono una a una ai campi
 * della classifica di football-data.
 */
const RCM_COLONNE = array(
	'playedGames'    => 'p',
	'won'            => 'w',
	'draw'           => 'd',
	'lost'           => 'l',
	'goalsFor'       => 'f',
	'goalsAgainst'   => 'a',
	'goalDifference' => 'gd',
	'points'         => 'pts',
);

/**
 * La tabella (sp_table) di una lega/stagione.
 *
 * @param string $league_slug Slug sp_league.
 * @param string $season_slug Slug sp_season.
 * @param string $eti         Etichetta per i messaggi.
 * @return WP_Post|null
 */
function rcm_tabella( $league_slug, $season_slug, $eti ) {
	$posts = get_posts(
		array(
			'post_type'      => 'sp_table',
			'posts_per_page' => -1,
			'post_status'    => array( 'publish', 'draft' ),
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

	if ( ! $posts ) {
		WP_CLI::log( "$eti nessuna classifica (sp_table) per $league_slug / $season_slug, salto" );
		return null;
	}
	if ( count( $posts ) > 1 ) {
		WP_CLI::warning( "$eti più di una classifica per $league_slug / $season_slug, non so quale riempire" );
		return null;
	}
	return $posts[0];
}

$conf         = rcm_conf();
$token        = $conf['FD_TOKEN'];
$competizioni = rcm_competizioni( $conf );
if ( ! $competizioni ) {
	WP_CLI::error( 'Nessuna competizione configurata' );
}

// La colonna nascosta è ciò che tiene l'ordine uguale a quello ufficiale:
// senza, la tabella si riordinerebbe da sé e a parità di punti sbaglierebbe.
$colonna_pos = get_posts(
	array(
		'post_type'      => 'sp_column',
		'name'           => RCM_COLONNA_POS,
		'posts_per_page' => 1,
		'post_status'    => 'publish',
	)
);
if ( ! $colonna_pos ) {
	WP_CLI::warning(
		'Manca la colonna "' . RCM_COLONNA_POS . '": la classifica verrà riordinata da SportsPress '
		. 'per punti e differenza reti, che a parità di punti in Serie A non è il criterio giusto'
	);
}

$scritte  = 0;
$invariate = 0;
$avvisi   = 0;

foreach ( $competizioni as $nome => $c ) {
	$eti = "[$nome]";

	if ( isset( $c['CLASSIFICA'] ) && ! filter_var( $c['CLASSIFICA'], FILTER_VALIDATE_BOOLEAN ) ) {
		continue;
	}

	$competition = $c['FD_COMPETITION'] ?? '';
	$fd_season   = $c['FD_SEASON'] ?? '';
	$league_slug = $c['SP_LEAGUE'] ?? '';
	$season_slug = $c['SP_SEASON'] ?? '';
	if ( '' === $competition || '' === $league_slug || '' === $season_slug ) {
		WP_CLI::warning( "$eti configurazione incompleta, salto" );
		++$avvisi;
		continue;
	}

	$tabella = rcm_tabella( $league_slug, $season_slug, $eti );
	if ( ! $tabella ) {
		continue;
	}

	$url  = 'https://api.football-data.org/v4/competitions/' . rawurlencode( $competition ) . '/standings'
		. '?' . http_build_query( array( 'season' => $fd_season ) );
	$data = rcm_api( $url, $token, $eti );
	if ( null === $data ) {
		++$avvisi;
		continue;
	}

	$blocchi = array_values(
		array_filter(
			$data['standings'] ?? array(),
			function ( $s ) {
				return 'TOTAL' === ( $s['type'] ?? '' );
			}
		)
	);
	if ( ! $blocchi ) {
		WP_CLI::log( "$eti la API non ha ancora una classifica per questa stagione" );
		continue;
	}
	if ( count( $blocchi ) > 1 ) {
		// Competizione a gironi: le posizioni ripartono da 1 in ognuno e
		// una tabella unica non vorrebbe dire niente.
		WP_CLI::warning( "$eti la classifica è divisa in " . count( $blocchi ) . ' gironi, non gestita' );
		++$avvisi;
		continue;
	}

	$squadre = rcm_squadre( $league_slug, $season_slug );
	if ( ! $squadre ) {
		WP_CLI::warning( "$eti nessuna squadra con lega $league_slug e stagione $season_slug" );
		++$avvisi;
		continue;
	}

	$valori = array();
	foreach ( $blocchi[0]['table'] as $riga ) {
		$id = rcm_abbina_squadra( $riga['team'], $squadre );
		if ( ! $id ) {
			WP_CLI::warning( "$eti non trovo su WordPress la squadra '{$riga['team']['name']}'" );
			++$avvisi;
			continue;
		}

		$dati = array();
		foreach ( RCM_COLONNE as $campo => $colonna ) {
			if ( isset( $riga[ $campo ] ) ) {
				$dati[ $colonna ] = (string) $riga[ $campo ];
			}
		}
		if ( $colonna_pos && isset( $riga['position'] ) ) {
			$dati[ RCM_COLONNA_POS ] = (string) $riga['position'];
		}

		$valori[ $id ] = $dati;
	}

	if ( ! $valori ) {
		WP_CLI::warning( "$eti nessuna riga abbinata, non tocco la classifica" );
		++$avvisi;
		continue;
	}

	if ( get_post_meta( $tabella->ID, 'sp_teams', true ) === $valori ) {
		++$invariate;
		continue;
	}

	update_post_meta( $tabella->ID, 'sp_teams', $valori );
	$prima = reset( $blocchi[0]['table'] );
	WP_CLI::log(
		"$eti classifica aggiornata: " . count( $valori ) . " squadre, "
		. "in testa {$prima['team']['shortName']} con {$prima['points']} punti"
	);
	++$scritte;
}

WP_CLI::success( "Classifiche aggiornate: $scritte, invariate: $invariate, avvisi: $avvisi" );
