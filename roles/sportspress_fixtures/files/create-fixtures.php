<?php
/**
 * Crea gli eventi SportsPress (sp_event) che ancora non esistono, dalla
 * API football-data.org.
 *
 * PERCHÉ SERVE. update-fixtures.php aggiorna data, ora e risultato di
 * eventi che devono già esserci: per la Serie A i 38 eventi erano stati
 * caricati a mano a inizio stagione, e va bene, il calendario esce tutto
 * insieme a luglio. Per le coppe no: il sorteggio della fase campionato
 * arriva a fine agosto e i turni a eliminazione si conoscono uno alla
 * volta, a distanza di mesi. Senza qualcosa che crei gli eventi, ogni
 * turno andrebbe inserito a mano - e chi lo fa deve indovinare gli stessi
 * nomi che poi update-fixtures cerca, o l'aggiornamento automatico non
 * abbina più niente.
 *
 * SI CREA SOLO DOVE È CHIESTO. La creazione è per competizione e spenta
 * di default (CREA=1 nella sezione). Sulla Serie A resta spenta apposta:
 * i suoi eventi ci sono già con date provvisorie che possono distare
 * settimane da quelle della API, e un abbinamento sbagliato non
 * sovrascriverebbe l'evento esistente ma ne creerebbe un secondo, dando
 * due volte la stessa partita in calendario.
 *
 * COME SI EVITANO I DOPPIONI. Ogni evento creato si porta dietro
 * l'identificativo del match nella API (meta _rcm_fd_match_id): al giro
 * dopo lo si ritrova per ID, non per somiglianza. Per gli eventi creati
 * altrove c'è una seconda rete: se nella stessa lega e stagione ce n'è
 * già uno vicino di data, con le stesse due squadre E nel verso giusto,
 * si considera quello e gli si scrive l'identificativo, invece di
 * crearne un altro. Il verso conta: andata e ritorno di un turno a
 * eliminazione hanno lo stesso titolo a parti invertite, e senza quel
 * controllo il ritorno verrebbe scambiato per l'andata e non verrebbe
 * mai creato.
 *
 * LE SQUADRE. Un avversario che non c'è viene creato come sp_team, con
 * lo stemma preso dalla API. Se invece la squadra esiste già in un'altra
 * competizione - la Roma sta in Serie A, l'Atalanta pure - non se ne fa
 * una copia: le si aggiunge la lega e la stagione nuove. Due post per la
 * stessa squadra vorrebbero dire due righe in classifica e uno stemma
 * mancante metà delle volte.
 *
 * Uso:  wp eval-file create-fixtures.php --path=<wordpress>
 * Prova: SP_CREA_DRY=1 wp eval-file create-fixtures.php --path=<wordpress>
 *        (dice cosa farebbe senza scrivere niente)
 * Config: la stessa di update-fixtures.php.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once __DIR__ . '/sp-lib.php';

/** Estensioni di stemma che WordPress accetta senza plugin aggiuntivi. */
const RCM_STEMMI_OK = array( 'png', 'jpg', 'jpeg', 'gif', 'webp' );

/**
 * Squadra che nella prova sarebbe stata creata ma non esiste.
 *
 * Serve perché la prova possa comunque dire quali eventi creerebbe: con
 * null si fermerebbe alla prima squadra mancante e non mostrerebbe mai
 * la cosa che interessa davvero, cioè il calendario che verrebbe fuori.
 */
const RCM_SQUADRA_FINTA = -1;

/**
 * Un evento già presente che sembra essere lo stesso match.
 *
 * Serve per gli eventi caricati a mano, che l'identificativo non ce
 * l'hanno. Non basta che le due squadre compaiano nel titolo: andata e
 * ritorno di un turno a eliminazione hanno lo stesso titolo a parti
 * invertite, e prendendo la prima che capita il ritorno verrebbe
 * scambiato per l'andata e non verrebbe mai creato. Si controlla quindi
 * anche chi gioca in casa, leggendolo dall'ordine dei meta sp_team.
 *
 * Se l'evento le squadre non ce le ha proprio - capita agli eventi
 * abbozzati a mano - si ripiega sul titolo, ma stringendo la finestra a
 * due giorni: due gare della stessa coppia non distano mai così poco.
 *
 * @param array        $m           Match della API.
 * @param string       $league_slug Slug sp_league.
 * @param string       $season_slug Slug sp_season.
 * @param DateTimeZone $tz          Fuso di WordPress.
 * @param int          $giorni      Ampiezza della finestra, in giorni.
 * @return WP_Post|null
 */
function rcm_evento_simile( $m, $league_slug, $season_slug, $tz, $giorni ) {
	$local = ( new DateTimeImmutable( $m['utcDate'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
	$da    = $local->modify( "-$giorni days" )->format( 'Y-m-d H:i:s' );
	$a     = $local->modify( "+$giorni days" )->format( 'Y-m-d H:i:s' );

	$posts = get_posts(
		array(
			'post_type'      => 'sp_event',
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'date_query'     => array(
				array(
					'after'     => $da,
					'before'    => $a,
					'inclusive' => true,
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

	$stretta = $local->modify( '-2 days' )->format( 'Y-m-d H:i:s' );
	$stretta_a = $local->modify( '+2 days' )->format( 'Y-m-d H:i:s' );

	foreach ( $posts as $p ) {
		$squadre = array_values(
			array_filter( array_map( 'intval', (array) get_post_meta( $p->ID, 'sp_team', false ) ) )
		);

		if ( 2 === count( $squadre ) ) {
			$casa   = rcm_somiglianza( get_the_title( $squadre[0] ), $m['homeTeam'] );
			$ospiti = rcm_somiglianza( get_the_title( $squadre[1] ), $m['awayTeam'] );
			if ( $casa > 0 && $ospiti > 0 ) {
				return $p;
			}
			continue;
		}

		if ( $p->post_date < $stretta || $p->post_date > $stretta_a ) {
			continue;
		}
		if ( rcm_somiglianza_titolo( $p->post_title, $m['homeTeam'] )
			&& rcm_somiglianza_titolo( $p->post_title, $m['awayTeam'] ) ) {
			return $p;
		}
	}
	return null;
}

/**
 * Se il titolo di un evento contiene il nome di una squadra della API.
 *
 * @param string $titolo   Titolo dell'evento.
 * @param array  $api_team Squadra della API.
 * @return bool
 */
function rcm_somiglianza_titolo( $titolo, $api_team ) {
	foreach ( array( $api_team['shortName'] ?? '', $api_team['name'] ?? '' ) as $forma ) {
		if ( '' !== $forma && false !== stripos( $titolo, $forma ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Tutte le squadre su WordPress, senza filtro di lega.
 *
 * L'elenco si legge una volta sola e poi si tiene: serve a ogni match e
 * su otto partite sarebbero sedici query uguali. Chi crea una squadra la
 * passa in $aggiungi, se no due partite contro lo stesso avversario ne
 * creerebbero due post.
 *
 * @param array|null $aggiungi ID e titolo di una squadra appena creata.
 * @return array ID => titolo.
 */
function rcm_tutte_le_squadre( $aggiungi = null ) {
	static $cache = null;

	if ( null === $cache ) {
		$cache = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'sp_team',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft' ),
			)
		) as $p ) {
			$cache[ $p->ID ] = $p->post_title;
		}
	}

	if ( null !== $aggiungi ) {
		$cache[ $aggiungi['id'] ] = $aggiungi['titolo'];
	}
	return $cache;
}

/**
 * Scarica lo stemma della squadra e lo mette come immagine in evidenza.
 *
 * Non è essenziale: se fallisce la squadra resta senza stemma e si va
 * avanti, perché un calendario senza loghi è comunque un calendario.
 *
 * @param int    $team_id ID del post sp_team.
 * @param string $url     URL dello stemma dalla API.
 * @param string $nome    Nome della squadra, per la descrizione.
 * @param string $eti     Etichetta per i messaggi.
 * @return void
 */
function rcm_stemma( $team_id, $url, $nome, $eti ) {
	if ( '' === $url || has_post_thumbnail( $team_id ) ) {
		return;
	}

	$est = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?: '', PATHINFO_EXTENSION ) );
	if ( ! in_array( $est, RCM_STEMMI_OK, true ) ) {
		// Gli stemmi in SVG WordPress non li accetta senza plugin: non è
		// un errore, semplicemente quella squadra resta senza logo.
		WP_CLI::log( "$eti stemma in .$est non caricabile, salto" );
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$id = media_sideload_image( $url, 0, "Stemma $nome", 'id' );
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( "$eti stemma non scaricato: " . $id->get_error_message() );
		return;
	}
	set_post_thumbnail( $team_id, $id );
}

/**
 * L'ID WordPress di una squadra della API, creandola se non c'è.
 *
 * @param array  $api_team    Squadra della API.
 * @param string $league_slug Slug sp_league.
 * @param string $season_slug Slug sp_season.
 * @param bool   $dry         Se true non scrive niente.
 * @param string $eti         Etichetta per i messaggi.
 * @param array  $stats       Contatori, per riferimento.
 * @return int|null ID della squadra, o null se non si è potuta ottenere.
 */
function rcm_squadra( $api_team, $league_slug, $season_slug, $dry, $eti, &$stats ) {
	$nome = $api_team['shortName'] ?: $api_team['name'];

	// Nella prova ogni squadra si annuncia una volta sola per competizione:
	// la Roma comparirebbe in tutte le partite e il log sarebbe illeggibile.
	static $viste = array();
	$visto = "$league_slug/$nome";

	// Già nella lega e stagione giuste: non c'è niente da fare.
	$id = rcm_abbina_squadra( $api_team, rcm_squadre( $league_slug, $season_slug ) );
	if ( $id ) {
		return $id;
	}

	// C'è ma in un'altra competizione: si aggiungono lega e stagione al
	// post che esiste, invece di farne un secondo con lo stesso nome.
	$id = rcm_abbina_squadra( $api_team, rcm_tutte_le_squadre() );
	if ( $id ) {
		if ( $dry ) {
			if ( ! isset( $viste[ $visto ] ) ) {
				$viste[ $visto ] = true;
				WP_CLI::log( "$eti (prova) a '" . get_the_title( $id ) . "' aggiungerei lega $league_slug e stagione $season_slug" );
				++$stats['squadre_estese'];
			}
			return $id;
		}
		wp_set_object_terms( $id, $league_slug, 'sp_league', true );
		wp_set_object_terms( $id, $season_slug, 'sp_season', true );
		WP_CLI::log( "$eti '" . get_the_title( $id ) . "' aggiunta a $league_slug $season_slug" );
		++$stats['squadre_estese'];
		return $id;
	}

	if ( $dry ) {
		if ( ! isset( $viste[ $visto ] ) ) {
			$viste[ $visto ] = true;
			WP_CLI::log( "$eti (prova) creerei la squadra '$nome'" );
			++$stats['squadre'];
		}
		return RCM_SQUADRA_FINTA;
	}

	$id = wp_insert_post(
		array(
			'post_type'   => 'sp_team',
			'post_title'  => $nome,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( "$eti squadra '$nome' non creata: " . $id->get_error_message() );
		++$stats['avvisi'];
		return null;
	}

	wp_set_object_terms( $id, $league_slug, 'sp_league', true );
	wp_set_object_terms( $id, $season_slug, 'sp_season', true );
	rcm_stemma( $id, $api_team['crest'] ?? '', $nome, $eti );

	WP_CLI::log( "$eti creata la squadra '$nome' (#$id)" );
	++$stats['squadre'];

	rcm_tutte_le_squadre( array( 'id' => $id, 'titolo' => $nome ) );
	return $id;
}

/**
 * Come si chiamerà la squadra nel titolo dell'evento.
 *
 * Nella prova la squadra può non esistere ancora: si usa il nome della
 * API, che è poi quello con cui verrebbe creata.
 *
 * @param int   $id       ID della squadra, o RCM_SQUADRA_FINTA.
 * @param array $api_team Squadra della API.
 * @return string
 */
function rcm_nome_squadra( $id, $api_team ) {
	if ( RCM_SQUADRA_FINTA === $id ) {
		return $api_team['shortName'] ?: $api_team['name'];
	}
	return get_the_title( $id );
}

/**
 * Il termine sp_venue per lo stadio dato dalla API.
 *
 * @param string $venue Nome dello stadio.
 * @return int|null ID del termine.
 */
function rcm_venue( $venue ) {
	$venue = trim( (string) $venue );
	if ( '' === $venue ) {
		return null;
	}

	$term = get_term_by( 'name', $venue, 'sp_venue' );
	if ( $term ) {
		return (int) $term->term_id;
	}

	$nuovo = wp_insert_term( $venue, 'sp_venue' );
	return is_wp_error( $nuovo ) ? null : (int) $nuovo['term_id'];
}

/**
 * Crea gli eventi mancanti di una competizione.
 *
 * @param string       $nome    Nome leggibile (la sezione della config).
 * @param array        $c       Impostazioni della competizione.
 * @param string       $token   Token football-data.
 * @param string       $team_id ID squadra su football-data.
 * @param DateTimeZone $tz      Fuso di WordPress.
 * @param bool         $dry     Se true non scrive niente.
 * @param array        $stats   Contatori, per riferimento.
 * @return void
 */
function rcm_crea_competizione( $nome, $c, $token, $team_id, $tz, $dry, &$stats ) {
	$competition = $c['FD_COMPETITION'] ?? '';
	$fd_season   = $c['FD_SEASON'] ?? '';
	$league_slug = $c['SP_LEAGUE'] ?? '';
	$season_slug = $c['SP_SEASON'] ?? '';
	$giorni      = isset( $c['GIORNI'] ) ? max( 1, (int) $c['GIORNI'] ) : 7;
	$eti         = "[$nome]";

	if ( '' === $competition || '' === $league_slug || '' === $season_slug ) {
		WP_CLI::warning( "$eti configurazione incompleta, salto" );
		++$stats['avvisi'];
		return;
	}

	if ( ! term_exists( $league_slug, 'sp_league' ) ) {
		WP_CLI::warning( "$eti manca il termine sp_league '$league_slug': crealo prima" );
		++$stats['avvisi'];
		return;
	}
	if ( ! term_exists( $season_slug, 'sp_season' ) ) {
		WP_CLI::warning( "$eti manca il termine sp_season '$season_slug': crealo prima" );
		++$stats['avvisi'];
		return;
	}

	$url  = 'https://api.football-data.org/v4/teams/' . rawurlencode( $team_id ) . '/matches'
		. '?' . http_build_query(
			array(
				'competitions' => $competition,
				'season'       => $fd_season,
			)
		);
	$data = rcm_api( $url, $token, $eti );
	if ( null === $data ) {
		++$stats['avvisi'];
		return;
	}
	if ( empty( $data['matches'] ) ) {
		// Prima del sorteggio è la normalità: la coppa c'è, le partite no.
		WP_CLI::log( "$eti la API non ha ancora match per questa stagione" );
		return;
	}

	foreach ( $data['matches'] as $m ) {
		$match_id = (int) ( $m['id'] ?? 0 );
		$stage    = strtolower( str_replace( '_', ' ', $m['stage'] ?? 'match' ) );
		$etim     = "$eti $stage";

		if ( ! $match_id ) {
			WP_CLI::warning( "$etim match senza id nella API, salto" );
			++$stats['avvisi'];
			continue;
		}

		if ( rcm_evento_per_match_id( $match_id ) ) {
			++$stats['gia_presenti'];
			continue;
		}

		$simile = rcm_evento_simile( $m, $league_slug, $season_slug, $tz, $giorni );
		if ( $simile ) {
			if ( $dry ) {
				WP_CLI::log( "$etim [prova] marcherei '{$simile->post_title}' come match $match_id" );
			} else {
				update_post_meta( $simile->ID, RCM_META_MATCH_ID, (string) $match_id );
				WP_CLI::log( "$etim '{$simile->post_title}' esisteva già, marcato" );
			}
			++$stats['marcati'];
			continue;
		}

		$casa   = rcm_squadra( $m['homeTeam'], $league_slug, $season_slug, $dry, $etim, $stats );
		$ospiti = rcm_squadra( $m['awayTeam'], $league_slug, $season_slug, $dry, $etim, $stats );
		if ( null === $casa || null === $ospiti ) {
			WP_CLI::warning( "$etim squadre non risolte, evento non creato" );
			++$stats['avvisi'];
			continue;
		}

		$local  = ( new DateTimeImmutable( $m['utcDate'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
		$titolo = rcm_nome_squadra( $casa, $m['homeTeam'] ) . ' vs ' . rcm_nome_squadra( $ospiti, $m['awayTeam'] );

		if ( $dry ) {
			WP_CLI::log( "$etim (prova) creerei '$titolo' il " . $local->format( 'd/m/Y H:i' ) );
			++$stats['creati'];
			continue;
		}

		$ev_id = wp_insert_post(
			array(
				'post_type'     => 'sp_event',
				'post_title'    => $titolo,
				'post_status'   => 'publish',
				'post_date'     => $local->format( 'Y-m-d H:i:s' ),
				'post_date_gmt' => $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			),
			true
		);
		if ( is_wp_error( $ev_id ) ) {
			WP_CLI::warning( "$etim evento non creato: " . $ev_id->get_error_message() );
			++$stats['avvisi'];
			continue;
		}

		// Lo slug numerico è la convenzione degli eventi già sul sito e
		// toglie di mezzo le collisioni con gli articoli: una notizia
		// "Roma-Atalanta" e l'evento omonimo si contenderebbero lo slug.
		wp_update_post(
			array(
				'ID'        => $ev_id,
				'post_name' => (string) $ev_id,
			)
		);

		// L'ordine dei meta sp_team è quello che dice a SportsPress chi
		// gioca in casa: prima la squadra di casa, poi gli ospiti.
		add_post_meta( $ev_id, 'sp_team', $casa );
		add_post_meta( $ev_id, 'sp_team', $ospiti );
		update_post_meta( $ev_id, 'sp_format', 'league' );
		update_post_meta( $ev_id, RCM_META_MATCH_ID, (string) $match_id );
		if ( isset( $m['matchday'] ) && null !== $m['matchday'] ) {
			update_post_meta( $ev_id, 'sp_day', (string) $m['matchday'] );
		}

		wp_set_object_terms( $ev_id, $league_slug, 'sp_league' );
		wp_set_object_terms( $ev_id, $season_slug, 'sp_season' );
		$venue = rcm_venue( $m['venue'] ?? '' );
		if ( $venue ) {
			wp_set_object_terms( $ev_id, array( $venue ), 'sp_venue' );
		}

		WP_CLI::log( "$etim creato '$titolo' (#$ev_id) il " . $local->format( 'd/m/Y H:i' ) );
		++$stats['creati'];
	}
}

// ---------------------------------------------------------------------

$conf         = rcm_conf();
$token        = $conf['FD_TOKEN'];
$team_id      = $conf['FD_TEAM_ID'] ?? '100';
$competizioni = rcm_competizioni( $conf );
$dry          = filter_var( getenv( 'SP_CREA_DRY' ) ?: '0', FILTER_VALIDATE_BOOLEAN );

if ( ! $competizioni ) {
	WP_CLI::error( 'Nessuna competizione configurata' );
}

$tz    = wp_timezone();
$stats = array(
	'creati'          => 0,
	'marcati'         => 0,
	'gia_presenti'    => 0,
	'squadre'         => 0,
	'squadre_estese'  => 0,
	'avvisi'          => 0,
);

$attive = 0;
foreach ( $competizioni as $nome => $c ) {
	if ( ! filter_var( $c['CREA'] ?? '0', FILTER_VALIDATE_BOOLEAN ) ) {
		continue;
	}
	++$attive;
	rcm_crea_competizione( $nome, $c, $token, $team_id, $tz, $dry, $stats );
}

if ( ! $attive ) {
	WP_CLI::success( 'Nessuna competizione con creazione attiva (CREA=1), niente da fare' );
	return;
}

$prefisso = $dry ? '[prova] ' : '';
WP_CLI::success(
	$prefisso . "Eventi creati: {$stats['creati']}, già marcati: {$stats['marcati']}, "
	. "già presenti: {$stats['gia_presenti']}, squadre nuove: {$stats['squadre']}, "
	. "squadre estese: {$stats['squadre_estese']}, avvisi: {$stats['avvisi']}"
);
