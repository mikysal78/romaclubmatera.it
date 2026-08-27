<?php
/**
 * Aggiorna data/ora e risultati degli eventi SportsPress (sp_event)
 * dalla API football-data.org, per una o più competizioni.
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
 * ABBINAMENTO API -> evento WordPress. Due modi, perché non ne basta uno:
 * - per giornata (meta sp_day), che funziona nei gironi e nei campionati;
 * - per data e squadre, per le fasi a eliminazione, dove la giornata NON è
 *   un identificativo: negli ottavi di Champions il matchday vale 1 o 2
 *   (andata e ritorno) e collide con le prime due giornate del girone.
 * Di default (ABBINAMENTO=auto) si usa la giornata nelle fasi elencate in
 * FASI_GIORNATA e la data in tutte le altre.
 *
 * Le funzioni condivise con update-standings.php (config, chiamate API,
 * abbinamento dei nomi delle squadre) stanno in sp-lib.php.
 *
 * Uso: wp eval-file update-fixtures.php --path=<wordpress>
 * Config (ini con sezioni): /etc/default/sp-fixtures, override con env
 * SP_FIXTURES_CONF. Ogni sezione è una competizione; una config vecchia
 * senza sezioni continua a funzionare come competizione unica.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once __DIR__ . '/sp-lib.php';

$conf       = rcm_conf();
$token      = $conf['FD_TOKEN'];
$team_id    = $conf['FD_TEAM_ID'] ?? '100';
$do_results = ! isset( $conf['SP_RESULTS'] ) || filter_var( $conf['SP_RESULTS'], FILTER_VALIDATE_BOOLEAN );

/**
 * Quale delle due squadre dell'evento gioca in casa secondo la API.
 *
 * Prova entrambi gli accoppiamenti possibili, li punteggia e tiene il
 * migliore. Se pareggiano non si indovina: si restituisce null e chi
 * chiama salta l'evento.
 *
 * @param int   $ev_id ID dell'evento SportsPress.
 * @param array $m     Match della API.
 * @return array|null casa, ospiti (ID squadra) e punteggio, o null.
 */
function rcm_orientamento( $ev_id, $m ) {
	$squadre = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $ev_id, 'sp_team', false ) ) ) );
	if ( 2 !== count( $squadre ) ) {
		return null;
	}

	$titolo_a = get_the_title( $squadre[0] );
	$titolo_b = get_the_title( $squadre[1] );

	$diretto   = rcm_somiglianza( $titolo_a, $m['homeTeam'] ) + rcm_somiglianza( $titolo_b, $m['awayTeam'] );
	$invertito = rcm_somiglianza( $titolo_a, $m['awayTeam'] ) + rcm_somiglianza( $titolo_b, $m['homeTeam'] );

	if ( $diretto === $invertito ) {
		return null;
	}
	if ( $diretto > $invertito ) {
		return array(
			'casa'      => $squadre[0],
			'ospiti'    => $squadre[1],
			'punteggio' => $diretto,
		);
	}
	return array(
		'casa'      => $squadre[1],
		'ospiti'    => $squadre[0],
		'punteggio' => $invertito,
	);
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

/**
 * I filtri di tassonomia comuni a tutte le ricerche di eventi.
 *
 * @param string $league_slug Slug del termine sp_league.
 * @param string $season_slug Slug del termine sp_season.
 * @return array tax_query per WP_Query.
 */
function rcm_tax_query( $league_slug, $season_slug ) {
	return array(
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
	);
}

/**
 * L'evento della giornata indicata (meta sp_day).
 *
 * @param string $day         Numero di giornata.
 * @param string $league_slug Slug sp_league.
 * @param string $season_slug Slug sp_season.
 * @param array  $usati       ID già abbinati in questa passata.
 * @return WP_Post|null
 */
function rcm_per_giornata( $day, $league_slug, $season_slug, $usati ) {
	$q = new WP_Query(
		array(
			'post_type'      => 'sp_event',
			'post_status'    => array( 'future', 'publish' ),
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'post__not_in'   => $usati,
			'meta_query'     => array(
				array(
					'key'   => 'sp_day',
					'value' => $day,
				),
				// Chi ha l'identificativo si abbina per quello, e se non
				// e' stato trovato per identificativo non e' questo match.
				// Senza questa esclusione la "giornata 1" del girone
				// pescherebbe l'andata di un turno a eliminazione, che ha
				// sp_day 1 pure lei.
				array(
					'key'     => RCM_META_MATCH_ID,
					'compare' => 'NOT EXISTS',
				),
			),
			'tax_query'      => rcm_tax_query( $league_slug, $season_slug ),
		)
	);
	return $q->have_posts() ? $q->posts[0] : null;
}

/**
 * L'evento che si gioca intorno a quella data fra quelle due squadre.
 *
 * Serve per le fasi a eliminazione, dove la giornata non identifica il
 * match. La finestra di giorni è larga perché la data caricata a mano può
 * essere approssimativa; a disambiguare ci pensano le squadre e il verso
 * casa/trasferta, che fra andata e ritorno è invertito.
 *
 * @param array            $m           Match della API.
 * @param string           $league_slug Slug sp_league.
 * @param string           $season_slug Slug sp_season.
 * @param DateTimeZone     $tz          Fuso di WordPress.
 * @param int              $giorni      Ampiezza della finestra, in giorni.
 * @param array            $usati       ID già abbinati in questa passata.
 * @return WP_Post|null Null anche quando i candidati pareggiano: meglio un
 *                      avviso che un abbinamento sbagliato.
 */
function rcm_per_data( $m, $league_slug, $season_slug, $tz, $giorni, $usati ) {
	$local = ( new DateTimeImmutable( $m['utcDate'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );

	$q = new WP_Query(
		array(
			'post_type'      => 'sp_event',
			'post_status'    => array( 'future', 'publish' ),
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'post__not_in'   => $usati,
			'meta_query'     => array(
				array(
					'key'     => RCM_META_MATCH_ID,
					'compare' => 'NOT EXISTS',
				),
			),
			'date_query'     => array(
				array(
					'after'     => $local->modify( "-$giorni days" )->format( 'Y-m-d H:i:s' ),
					'before'    => $local->modify( "+$giorni days" )->format( 'Y-m-d H:i:s' ),
					'inclusive' => true,
				),
			),
			'tax_query'      => rcm_tax_query( $league_slug, $season_slug ),
		)
	);

	$migliore = null;
	$pari     = false;
	foreach ( $q->posts as $ev ) {
		$o = rcm_orientamento( $ev->ID, $m );
		// Servono riconosciute entrambe le squadre: 2+2 è il minimo.
		if ( ! $o || $o['punteggio'] < 4 ) {
			continue;
		}
		if ( null === $migliore || $o['punteggio'] > $migliore['punteggio'] ) {
			$migliore = array(
				'evento'    => $ev,
				'punteggio' => $o['punteggio'],
			);
			$pari = false;
		} elseif ( $o['punteggio'] === $migliore['punteggio'] ) {
			$pari = true;
		}
	}

	return ( $migliore && ! $pari ) ? $migliore['evento'] : null;
}

/**
 * Aggiorna gli eventi di una competizione.
 *
 * @param string       $nome       Nome leggibile (la sezione della config).
 * @param array        $c          Impostazioni della competizione.
 * @param string       $token      Token football-data.
 * @param string       $team_id    ID squadra su football-data.
 * @param bool         $do_results Scrivere anche i risultati.
 * @param DateTimeZone $tz         Fuso di WordPress.
 * @param array        $stats      Contatori, aggiornati per riferimento.
 * @return void
 */
function rcm_competizione( $nome, $c, $token, $team_id, $do_results, $tz, &$stats ) {
	$competition = $c['FD_COMPETITION'] ?? '';
	$fd_season   = $c['FD_SEASON'] ?? '';
	$league_slug = $c['SP_LEAGUE'] ?? '';
	$season_slug = $c['SP_SEASON'] ?? '';
	$abbinamento = strtolower( $c['ABBINAMENTO'] ?? 'auto' );
	$giorni      = isset( $c['GIORNI'] ) ? max( 1, (int) $c['GIORNI'] ) : 7;
	$fasi        = array_filter(
		array_map( 'trim', explode( ',', $c['FASI_GIORNATA'] ?? 'REGULAR_SEASON,LEAGUE_STAGE,GROUP_STAGE' ) )
	);

	if ( '' === $competition || '' === $league_slug || '' === $season_slug ) {
		WP_CLI::warning( "[$nome] configurazione incompleta, salto" );
		++$stats['warned'];
		return;
	}

	$url  = 'https://api.football-data.org/v4/teams/' . rawurlencode( $team_id ) . '/matches'
		. '?' . http_build_query(
			array(
				'competitions' => $competition,
				'season'       => $fd_season,
			)
		);
	$data = rcm_api( $url, $token, "[$nome]" );
	if ( null === $data ) {
		++$stats['warned'];
		return;
	}
	if ( empty( $data['matches'] ) ) {
		// Normale prima del sorteggio: la coppa esiste ma non ha ancora
		// partite per noi. Non è un errore e non deve fermare le altre.
		WP_CLI::log( "[$nome] la API non ha ancora match per questa stagione" );
		return;
	}

	// Solo con orario ufficializzato.
	$official = array( 'TIMED', 'IN_PLAY', 'PAUSED', 'FINISHED' );
	// Solo a partita conclusa: AWARDED è il match assegnato a tavolino.
	$played   = array( 'FINISHED', 'AWARDED' );

	$usati = array();

	foreach ( $data['matches'] as $m ) {
		$status = $m['status'];
		$stage  = $m['stage'] ?? '';
		$day    = ( isset( $m['matchday'] ) && null !== $m['matchday'] ) ? (string) $m['matchday'] : '';
		$eti    = "[$nome] " . ( '' !== $day && in_array( $stage, $fasi, true )
			? "giornata $day"
			: strtolower( str_replace( '_', ' ', $stage ?: 'match' ) ) );

		if ( ! in_array( $status, $official, true ) ) {
			++$stats['same'];
			continue;
		}

		// La giornata identifica il match solo nelle fasi a girone: negli
		// ottavi vale 1 o 2 (andata/ritorno) e collide con le giornate 1 e 2.
		$per_giornata = 'data' !== $abbinamento && '' !== $day
			&& ( 'giornata' === $abbinamento || in_array( $stage, $fasi, true ) );

		// Prima di tutto l'identificativo del match, se l'evento ce l'ha:
		// e' l'unico abbinamento che non deve indovinare. Andata e ritorno
		// di uno stesso turno hanno le stesse due squadre a sei giorni di
		// distanza, e per data sono indistinguibili: ognuno dei due
		// pareggia col fratello e la ricerca per data si arrende.
		$ev = rcm_evento_per_match_id( (int) ( $m['id'] ?? 0 ), $usati );

		if ( ! $ev && $per_giornata ) {
			$ev = rcm_per_giornata( $day, $league_slug, $season_slug, $usati );

			$nomi = array( $m['homeTeam']['shortName'], $m['awayTeam']['shortName'] );
			if ( $ev && false === stripos( $ev->post_title, $nomi[0] ) && false === stripos( $ev->post_title, $nomi[1] ) ) {
				WP_CLI::warning( "$eti: API '$nomi[0]-$nomi[1]' non corrisponde a '{$ev->post_title}', salto" );
				++$stats['warned'];
				continue;
			}
		}
		if ( ! $ev && 'giornata' !== $abbinamento ) {
			$ev = rcm_per_data( $m, $league_slug, $season_slug, $tz, $giorni, $usati );
		}
		if ( ! $ev ) {
			WP_CLI::warning(
				"$eti: nessun evento per '{$m['homeTeam']['shortName']}'-'{$m['awayTeam']['shortName']}'"
			);
			++$stats['warned'];
			continue;
		}
		$usati[] = $ev->ID;

		$local    = ( new DateTimeImmutable( $m['utcDate'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
		$new_date = $local->format( 'Y-m-d H:i:s' );
		if ( $new_date === $ev->post_date ) {
			++$stats['same'];
		} else {
			wp_update_post(
				array(
					'ID'            => $ev->ID,
					'post_date'     => $new_date,
					'post_date_gmt' => $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
					'edit_date'     => true,
				)
			);
			WP_CLI::log( "$eti: {$ev->post_title} {$ev->post_date} -> $new_date [$status]" );
			++$stats['updated'];
		}

		// ----- Risultato -----

		if ( ! $do_results || ! in_array( $status, $played, true ) ) {
			continue;
		}

		$gol_casa   = $m['score']['fullTime']['home'] ?? null;
		$gol_ospiti = $m['score']['fullTime']['away'] ?? null;
		if ( null === $gol_casa || null === $gol_ospiti ) {
			WP_CLI::warning( "$eti: '{$ev->post_title}' risulta $status ma senza punteggio, salto" );
			++$stats['warned'];
			continue;
		}

		$o = rcm_orientamento( $ev->ID, $m );
		if ( ! $o ) {
			WP_CLI::warning( "$eti: non riesco a stabilire chi gioca in casa in '{$ev->post_title}', salto il risultato" );
			++$stats['warned'];
			continue;
		}
		$id_casa   = $o['casa'];
		$id_ospiti = $o['ospiti'];

		$risultati = array(
			$id_casa   => array( 'goals' => (string) $gol_casa ),
			$id_ospiti => array( 'goals' => (string) $gol_ospiti ),
		);

		// Primo tempo dalla API, secondo tempo per differenza. Con i
		// supplementari la differenza non tornerebbe (fullTime li comprende)
		// e su SportsPress non c'è una variabile dove metterli: si scrive
		// solo il totale invece di inventare la ripartizione.
		$durata    = $m['score']['duration'] ?? 'REGULAR';
		$pt_casa   = $m['score']['halfTime']['home'] ?? null;
		$pt_ospiti = $m['score']['halfTime']['away'] ?? null;
		if ( 'REGULAR' === $durata && null !== $pt_casa && null !== $pt_ospiti ) {
			$risultati[ $id_casa ]['firsthalf']    = (string) $pt_casa;
			$risultati[ $id_casa ]['secondhalf']   = (string) ( $gol_casa - $pt_casa );
			$risultati[ $id_ospiti ]['firsthalf']  = (string) $pt_ospiti;
			$risultati[ $id_ospiti ]['secondhalf'] = (string) ( $gol_ospiti - $pt_ospiti );
		} elseif ( 'REGULAR' !== $durata ) {
			WP_CLI::log( "$eti: '{$ev->post_title}' finita oltre i 90' ($durata), scrivo solo il totale" );
		}

		// Esito: SportsPress lo tiene dentro sp_results insieme al punteggio.
		if ( $gol_casa === $gol_ospiti ) {
			$risultati[ $id_casa ]['outcome']   = rcm_esiti( '=' );
			$risultati[ $id_ospiti ]['outcome'] = rcm_esiti( '=' );

			// Ai rigori il punteggio resta pari e l'esito è un pareggio:
			// chi passa il turno non è un dato che SportsPress registri.
			if ( 'PENALTY_SHOOTOUT' === $durata ) {
				WP_CLI::log( "$eti: qualificazione decisa ai rigori, in tabella resta il pareggio" );
			}
		} else {
			$vince = $gol_casa > $gol_ospiti ? $id_casa : $id_ospiti;
			$perde = $gol_casa > $gol_ospiti ? $id_ospiti : $id_casa;

			$risultati[ $vince ]['outcome'] = rcm_esiti( '>' );
			$risultati[ $perde ]['outcome'] = rcm_esiti( '<' );
		}

		if ( get_post_meta( $ev->ID, 'sp_results', true ) === $risultati ) {
			++$stats['same'];
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
			WP_CLI::log( "$eti: '{$ev->post_title}' era ancora programmato, pubblicato" );
		}

		WP_CLI::log( "$eti: {$ev->post_title} risultato $gol_casa-$gol_ospiti [$status]" );
		++$stats['scored'];
	}
}

$competizioni = rcm_competizioni( $conf );
if ( ! $competizioni ) {
	WP_CLI::error( "Nessuna competizione configurata in $config_file" );
}

$tz    = wp_timezone();
$stats = array(
	'updated' => 0,
	'scored'  => 0,
	'same'    => 0,
	'warned'  => 0,
);

foreach ( $competizioni as $nome => $c ) {
	rcm_competizione( $nome, $c, $token, $team_id, $do_results, $tz, $stats );
}

WP_CLI::success(
	"Date aggiornate: {$stats['updated']}, risultati scritti: {$stats['scored']}, "
	. "invariati: {$stats['same']}, avvisi: {$stats['warned']}"
);
