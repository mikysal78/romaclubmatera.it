<?php
/**
 * Plugin Name: RCM - Le pagine delle partite si presentano nei motori
 * Description: Le pagine delle partite: titolo con la data, descrizione, dati strutturati e il riquadro "Dove vederla" con sede, orario di apertura e richiesta biglietti. Stavano gia' in prima pagina per ricerche come "roma real madrid data" e prendevano zero clic, perche' nel risultato non dicevano niente e nella pagina non c'era una risposta.
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

/* -------------------------------------------------------------------------
 * Il riquadro "Dove vederla"
 * ---------------------------------------------------------------------- */

/** Quanto prima del fischio d'inizio apre la sede. */
const RCM_EV_APERTURA = 30;

/**
 * Attacca il riquadro in fondo alla pagina della partita.
 *
 * Si aggancia a the_content con priorita' 20, cioe' dopo SportsPress, invece
 * che a un hook dei suoi template: cosi' un aggiornamento del plugin non se
 * lo porta via.
 *
 * Solo sulle partite da giocare. Su una gia' giocata un invito a venire in
 * sede sarebbe una presa in giro, e la pagina resta il tabellino.
 */
add_filter( 'the_content', 'rcm_ev_dove_vederla', 20 );
function rcm_ev_dove_vederla( $contenuto ) {
	if ( ! is_singular( 'sp_event' ) || ! in_the_loop() || ! is_main_query() ) {
		return $contenuto;
	}

	$id = get_the_ID();
	$d  = rcm_ev_dati( $id );
	if ( ! $d ) {
		return $contenuto;
	}

	$quando = $d['quando'];
	if ( $quando->getTimestamp() < time() ) {
		return $contenuto;
	}

	$minuti   = (int) apply_filters( 'rcm_evento_apertura_minuti', RCM_EV_APERTURA, $id );
	$apertura = $quando->modify( '-' . $minuti . ' minutes' );

	ob_start();
	?>
	<aside class="rcm-dove" aria-labelledby="rcm-dove-titolo">
		<h2 id="rcm-dove-titolo" class="rcm-dove-titolo">Dove vederla</h2>

		<p class="rcm-dove-frase">
			<strong><?php echo esc_html( $d['casa'] . '-' . $d['ospiti'] ); ?></strong>
			si vede in sede, insieme.
			<?php if ( $d['sede'] ) : ?>
				Si gioca <?php echo esc_html( rcm_ev_data( $quando, 'l j F' ) ); ?>
				alle <?php echo esc_html( wp_date( 'H:i', $quando->getTimestamp() ) ); ?>
				allo <?php echo esc_html( $d['sede']->name ); ?>.
			<?php else : ?>
				Si gioca <?php echo esc_html( rcm_ev_data( $quando, 'l j F' ) ); ?>
				alle <?php echo esc_html( wp_date( 'H:i', $quando->getTimestamp() ) ); ?>.
			<?php endif; ?>
		</p>

		<dl class="rcm-dove-dati">
			<div>
				<dt>La sede apre alle</dt>
				<dd><strong><?php echo esc_html( wp_date( 'H:i', $apertura->getTimestamp() ) ); ?></strong>
					<span>&mdash; <?php echo (int) $minuti; ?> minuti prima</span></dd>
			</div>
			<div class="rcm-dove-sede">
				<dt>Dove</dt>
				<dd>Nella sede del Roma Club Matera &ldquo;Francesco Totti&rdquo;<br>
					<span>Via Lupo Protospata 62 bis &middot; 75100 Matera</span></dd>
			</div>
			<?php
			$tv = get_post_meta( $id, '_rcm_tv', true );
			if ( $tv ) :
				?>
				<div>
					<dt>In TV su</dt>
					<dd><?php echo esc_html( $tv ); ?></dd>
				</div>
			<?php endif; ?>
		</dl>

		<p class="rcm-dove-nota">
			Le serate in sede sono riservate ai tesserati.
			<a href="<?php echo esc_url( rcm_ev_link_tesseramento() ); ?>">Come ci si tessera</a>.
		</p>

		<?php
		$biglietti = function_exists( 'rcm_big_indirizzo' ) ? rcm_big_indirizzo( $id ) : '';
		$news      = rcm_ev_news_collegata( $id, $d );
		if ( $biglietti || $news ) :
			?>
			<p class="rcm-dove-azioni">
				<?php if ( $biglietti ) : ?>
					<a class="rcm-dove-bottone" href="<?php echo esc_url( $biglietti ); ?>">Vai allo stadio: richiedi i biglietti</a>
				<?php endif; ?>
				<?php if ( $news ) : ?>
					<a class="rcm-dove-link" href="<?php echo esc_url( get_permalink( $news ) ); ?>">Leggi la locandina della serata</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</aside>
	<?php
	return $contenuto . ob_get_clean();
}

/** La pagina del tesseramento, cercata per slug e non per id fisso. */
function rcm_ev_link_tesseramento() {
	$p = get_page_by_path( 'tesseramento-2026-27' );
	return $p ? get_permalink( $p ) : home_url( '/' );
}

/**
 * La news del Club su questa partita, se c'e'.
 *
 * Si cerca per nome dell'avversario fra gli articoli usciti nel mese prima
 * della gara. Accostare per data soltanto non basterebbe: nella stessa
 * settimana ci sono piu' partite.
 *
 * @return WP_Post|null
 */
function rcm_ev_news_collegata( $id, $d ) {
	$avversario = ( false !== stripos( $d['casa'], 'roma' ) ) ? $d['ospiti'] : $d['casa'];
	$avversario = trim( str_ireplace( array( 'AS ', 'FC ', 'SS ', 'US ' ), '', $avversario ) );
	if ( '' === $avversario ) {
		return null;
	}

	$trovati = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			's'              => $avversario,
			'date_query'     => array(
				array(
					'after'  => $d['quando']->modify( '-40 days' )->format( 'Y-m-d' ),
					'before' => $d['quando']->modify( '+2 days' )->format( 'Y-m-d' ),
				),
			),
		)
	);

	foreach ( $trovati as $post ) {
		// La ricerca di WordPress guarda anche nel corpo: si tiene solo chi ha
		// l'avversario nel titolo, o si aggancia la news sbagliata.
		if ( false !== mb_stripos( $post->post_title, $avversario ) ) {
			return $post;
		}
	}

	return null;
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

	// Per una partita da giocare la coda dice la cosa che nessun altro sito
	// puo' dire, ed e' quella che si cerca: dove la si vede e a che ora si apre.
	if ( $giocata ) {
		$coda = ' Il calendario della Roma sul sito del Roma Club Matera.';
	} else {
		$minuti   = (int) apply_filters( 'rcm_evento_apertura_minuti', RCM_EV_APERTURA, get_the_ID() );
		$apertura = $d['quando']->modify( '-' . $minuti . ' minutes' );
		$coda     = sprintf(
			' Si vede in sede a Matera, apriamo alle %s.',
			wp_date( 'H:i', $apertura->getTimestamp() )
		);
	}

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
