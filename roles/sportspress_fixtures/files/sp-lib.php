<?php
/**
 * Funzioni condivise fra create-fixtures.php, update-fixtures.php e
 * update-standings.php.
 *
 * Tutti e tre leggono la stessa config e devono abbinare le squadre della
 * API a quelle di WordPress allo stesso modo: se le tre cose divergessero,
 * il calendario e la classifica potrebbero attribuire lo stesso match o lo
 * stesso punteggio a squadre diverse.
 */

/**
 * Meta in cui si tiene l'identificativo del match su football-data.
 *
 * Lo scrive create-fixtures.php quando crea (o riconosce) un evento, e lo
 * rilegge update-fixtures.php: è l'unico abbinamento che non deve
 * indovinare niente.
 */
const RCM_META_MATCH_ID = '_rcm_fd_match_id';

/**
 * L'evento marcato con un certo match della API.
 *
 * @param int   $match_id ID del match su football-data.
 * @param array $esclusi  ID di eventi già usati in questo giro.
 * @return WP_Post|null
 */
function rcm_evento_per_match_id( $match_id, $esclusi = array() ) {
	if ( ! $match_id ) {
		return null;
	}

	$posts = get_posts(
		array(
			'post_type'      => 'sp_event',
			'posts_per_page' => 1,
			'post_status'    => 'any',
			'post__not_in'   => $esclusi,
			'meta_key'       => RCM_META_MATCH_ID,
			'meta_value'     => (string) $match_id,
		)
	);
	return $posts ? $posts[0] : null;
}

/**
 * Legge la config (ini con sezioni) e verifica che ci sia il token.
 *
 * @return array Config completa.
 */
function rcm_conf() {
	$config_file = getenv( 'SP_FIXTURES_CONF' ) ?: '/etc/default/sp-fixtures';
	$conf        = is_readable( $config_file ) ? parse_ini_file( $config_file, true ) : false;
	if ( ! $conf || empty( $conf['FD_TOKEN'] ) ) {
		WP_CLI::error( "Config mancante o FD_TOKEN vuoto in $config_file" );
	}
	return $conf;
}

/**
 * Le competizioni configurate, una per sezione del file ini.
 *
 * Retrocompatibile: se il file non ha sezioni (la vecchia config con una
 * sola competizione) le chiavi in cima valgono come competizione unica,
 * così un aggiornamento degli script senza rilanciare Ansible non rompe
 * il timer notturno.
 *
 * @param array $conf Config già letta da parse_ini_file con le sezioni.
 * @return array Nome della competizione => sue impostazioni.
 */
function rcm_competizioni( $conf ) {
	$sezioni = array();
	foreach ( $conf as $nome => $valore ) {
		if ( is_array( $valore ) ) {
			$sezioni[ $nome ] = $valore;
		}
	}
	if ( $sezioni ) {
		return $sezioni;
	}

	if ( empty( $conf['SP_LEAGUE'] ) ) {
		return array();
	}
	return array( $conf['SP_LEAGUE'] => $conf );
}

/**
 * Una GET alla API football-data, già decodificata.
 *
 * @param string $url   URL completo.
 * @param string $token Token football-data.
 * @param string $eti   Etichetta per i messaggi di errore.
 * @return array|null Corpo decodificato, o null se la chiamata è fallita.
 */
function rcm_api( $url, $token, $eti ) {
	$resp = wp_remote_get(
		$url,
		array(
			'headers' => array( 'X-Auth-Token' => $token ),
			'timeout' => 30,
		)
	);
	if ( is_wp_error( $resp ) ) {
		WP_CLI::warning( "$eti API: " . $resp->get_error_message() );
		return null;
	}
	$http = wp_remote_retrieve_response_code( $resp );
	if ( 200 !== $http ) {
		WP_CLI::warning( "$eti API HTTP $http" );
		return null;
	}
	return json_decode( wp_remote_retrieve_body( $resp ), true );
}

/**
 * Quanto il nome di una squadra WordPress somiglia a una squadra della API.
 *
 * La API dà tre forme del nome ("AS Roma" / "Roma" / "ROM") e i titoli su
 * WordPress non seguono nessuna di queste in modo costante ("AS Roma",
 * "Fiorentina", "Como"). L'uguaglianza vale più della sottostringa perché
 * "Milan" è contenuto in "FC Internazionale Milano": prendendo la
 * sottostringa come prova certa si assegnerebbe il punteggio dell'Inter
 * al Milan.
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
 * Gli ID delle squadre WordPress di una lega/stagione, per titolo.
 *
 * @param string $league_slug Slug sp_league.
 * @param string $season_slug Slug sp_season.
 * @return array ID => titolo.
 */
function rcm_squadre( $league_slug, $season_slug ) {
	$posts = get_posts(
		array(
			'post_type'      => 'sp_team',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
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

	$out = array();
	foreach ( $posts as $p ) {
		$out[ $p->ID ] = $p->post_title;
	}
	return $out;
}

/**
 * La squadra WordPress che corrisponde a una squadra della API.
 *
 * @param array $api_team Squadra della API.
 * @param array $squadre  ID => titolo, da rcm_squadre().
 * @return int|null ID della squadra, o null se non è univoca.
 */
function rcm_abbina_squadra( $api_team, $squadre ) {
	$migliore  = null;
	$punteggio = 0;
	$pari      = false;

	foreach ( $squadre as $id => $titolo ) {
		$p = rcm_somiglianza( $titolo, $api_team );
		if ( 0 === $p ) {
			continue;
		}
		if ( $p > $punteggio ) {
			$migliore  = $id;
			$punteggio = $p;
			$pari      = false;
		} elseif ( $p === $punteggio ) {
			$pari = true;
		}
	}

	return $pari ? null : $migliore;
}
