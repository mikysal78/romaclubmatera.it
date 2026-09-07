<?php
/**
 * Plugin Name: RCM - Le pagine delle partite si presentano nei motori
 * Description: Titolo, descrizione e dati strutturati per le pagine evento di SportsPress. Stavano gia' in prima pagina per ricerche come "roma real madrid data", ma nel risultato non dicevano niente: nessuna descrizione, e un titolo senza la data che la ricerca chiedeva. Zero clic su duecento impressioni.
 * Version: 1.0.0
 * Author: Roma Club Matera
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tutto quello che serve di una partita, letto una volta sola.
 *
 * @param int $id ID dell'evento.
 * @return array|null Null se non e' un evento leggibile.
 */
function rcm_ev_dati( $id ) {
	static $cache = array();
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}

	$squadre = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $id, 'sp_team', false ) ) ) );
	if ( count( $squadre ) < 2 ) {
		$cache[ $id ] = null;
		return null;
	}

	$post = get_post( $id );
	$lega = get_the_terms( $id, 'sp_league' );
	$sede = get_the_terms( $id, 'sp_venue' );

	$dati = array(
		'casa'      => get_the_title( $squadre[0] ),
		'ospiti'    => get_the_title( $squadre[1] ),
		// post_date e' gia' ora locale: si legge come tale, senza riconversioni.
		'quando'    => date_create_immutable( $post->post_date, wp_timezone() ),
		'giornata'  => (int) get_post_meta( $id, 'sp_day', true ),
		'lega'      => ( $lega && ! is_wp_error( $lega ) ) ? $lega[0]->name : '',
		'sede'      => ( $sede && ! is_wp_error( $sede ) ) ? $sede[0] : null,
		'risultato' => rcm_ev_risultato( $id, $squadre ),
	);

	$cache[ $id ] = $dati;
	return $dati;
}

/**
 * Il punteggio come "0-4", oppure '' se la partita non e' stata giocata.
 */
function rcm_ev_risultato( $id, $squadre ) {
	$grezzo = get_post_meta( $id, 'sp_results', true );
	if ( ! is_array( $grezzo ) ) {
		return '';
	}

	$gol = array();
	foreach ( $squadre as $s ) {
		$v = isset( $grezzo[ $s ]['goals'] ) ? $grezzo[ $s ]['goals'] : '';
		if ( '' === $v || null === $v ) {
			return '';
		}
		$gol[] = (int) $v;
	}

	return count( $gol ) === 2 ? $gol[0] . '-' . $gol[1] : '';
}

/**
 * La data in italiano, coi mesi minuscoli.
 *
 * La localizzazione di WordPress restituisce "31 Agosto 2026": in italiano il
 * mese vuole la minuscola, e in un titolo di ricerca la maiuscola di troppo si
 * nota. I nomi dei giorni arrivano gia' minuscoli, quindi si puo' abbassare
 * tutta la stringa senza rompere niente.
 *
 * @param DateTimeInterface $quando  Momento da scrivere.
 * @param string            $formato Formato per wp_date().
 * @return string
 */
function rcm_ev_data( $quando, $formato ) {
	return mb_strtolower( wp_date( $formato, $quando->getTimestamp() ), 'UTF-8' );
}

/**
 * "Roma-Inter, 19 settembre 2026": la data nel titolo, perche' e' quello che
 * la ricerca chiede. "roma real madrid data" faceva 101 impressioni e zero
 * clic contro un titolo che diceva solo "AS Roma vs Real Madrid".
 */
add_filter( 'wpseo_title', 'rcm_ev_titolo' );
function rcm_ev_titolo( $titolo ) {
	if ( ! is_singular( 'sp_event' ) ) {
		return $titolo;
	}
	$d = rcm_ev_dati( get_the_ID() );
	if ( ! $d ) {
		return $titolo;
	}

	return sprintf(
		'%s-%s, %s - Roma Club Matera',
		$d['casa'],
		$d['ospiti'],
		rcm_ev_data( $d['quando'], 'j F Y' )
	);
}

/**
 * La descrizione: cosa, quando, dove, e perche' riguarda il Club.
 */
add_filter( 'wpseo_metadesc', 'rcm_ev_descrizione' );
function rcm_ev_descrizione( $desc ) {
	if ( ! is_singular( 'sp_event' ) ) {
		return $desc;
	}
	$d = rcm_ev_dati( get_the_ID() );
	if ( ! $d ) {
		return $desc;
	}

	$giocata = '' !== $d['risultato'];
	$scontro = $giocata
		? sprintf( '%s-%s %s', $d['casa'], $d['ospiti'], $d['risultato'] )
		: sprintf( '%s-%s', $d['casa'], $d['ospiti'] );

	$quando = $giocata
		? rcm_ev_data( $d['quando'], 'j F Y' )
		: rcm_ev_data( $d['quando'], 'l j F Y \a\l\l\e H:i' );

	$dove = $d['sede'] ? ' allo ' . $d['sede']->name : '';

	$turno = '';
	if ( $d['giornata'] && $d['lega'] ) {
		$turno = sprintf( ', %d&ordf; giornata di %s', $d['giornata'], $d['lega'] );
	} elseif ( $d['lega'] ) {
		$turno = ', ' . $d['lega'];
	}

	$coda = $giocata
		? ' Il calendario della Roma sul sito del Roma Club Matera.'
		: ' Dove vederla con il Roma Club Matera.';

	$testo = $scontro . ', ' . $quando . $dove . $turno . '.' . $coda;

	// Se sfora, si taglia la coda promozionale: i dati vengono prima.
	if ( mb_strlen( $testo ) > 158 ) {
		$testo = $scontro . ', ' . $quando . $dove . $turno . '.';
	}

	return html_entity_decode( $testo, ENT_QUOTES, 'UTF-8' );
}

/**
 * Dati strutturati SportsEvent, ma solo quando c'e' lo stadio con l'indirizzo.
 *
 * Google, per un evento dal vivo, pretende nome, data di inizio e luogo *con
 * indirizzo*: senza, il markup non e' incompleto, e' sbagliato, e finisce in
 * Search Console come errore. Le diciotto sedi della Serie A hanno indirizzo e
 * coordinate; le partite di Champions no, perche' football-data non manda il
 * campo, e per quelle si resta al titolo e alla descrizione.
 */
add_filter( 'wpseo_schema_graph', 'rcm_ev_schema', 11, 1 );
function rcm_ev_schema( $grafo ) {
	if ( ! is_singular( 'sp_event' ) ) {
		return $grafo;
	}
	$d = rcm_ev_dati( get_the_ID() );
	if ( ! $d || ! $d['sede'] ) {
		return $grafo;
	}

	$indirizzo = get_term_meta( $d['sede']->term_id, 'sp_address', true );
	if ( ! $indirizzo ) {
		return $grafo;
	}

	$luogo = array(
		'@type'   => 'Place',
		'name'    => $d['sede']->name,
		'address' => array(
			'@type'         => 'PostalAddress',
			'streetAddress' => $indirizzo,
		),
	);

	$lat = get_term_meta( $d['sede']->term_id, 'sp_latitude', true );
	$lon = get_term_meta( $d['sede']->term_id, 'sp_longitude', true );
	if ( $lat && $lon ) {
		$luogo['geo'] = array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) $lat,
			'longitude' => (float) $lon,
		);
	}

	$grafo[] = array(
		'@type'                => 'SportsEvent',
		'@id'                  => get_permalink() . '#evento',
		'name'                 => $d['casa'] . ' - ' . $d['ospiti'],
		'startDate'            => $d['quando']->format( 'c' ),
		'eventStatus'          => 'https://schema.org/EventScheduled',
		'eventAttendanceMode'  => 'https://schema.org/OfflineEventAttendanceMode',
		'location'             => $luogo,
		'competitor'           => array(
			array( '@type' => 'SportsTeam', 'name' => $d['casa'] ),
			array( '@type' => 'SportsTeam', 'name' => $d['ospiti'] ),
		),
		'url'                  => get_permalink(),
		'description'          => wp_strip_all_tags( rcm_ev_descrizione( '' ) ),
	);

	return $grafo;
}
