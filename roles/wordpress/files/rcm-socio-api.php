<?php
/**
 * Plugin Name: RCM - API dell'app dei soci
 * Description: Il lato sito dell'app dei soci "Roma Club Matera": accesso con email e codice, tessera e QR, dati, partite prenotabili, prenotare e annullare biglietto e pullman, partenza del pullman per il promemoria. Le stesse regole e gli stessi dati dell'area soci del sito.
 * Version: 1.0.0
 * Author: Roma Club Matera
 *
 * COME FUNZIONA
 *
 * - Nessuna regola nuova: l'accesso usa i codici dell'area soci
 *   (rcm_as_crea_accesso, rcm_as_verifica_codice), le prenotazioni
 *   rcm_pr_salva e rcm_pr_annulla_per, l'interruttore spenta/prova/attiva
 *   rcm_as_puo_entrare. Quello che vale sul sito vale nell'app.
 * - L'app chiede il codice con /socio/richiedi: arriva la stessa email
 *   dell'area soci (link e codice); nell'app si scrive il codice. Risposta
 *   sempre uguale, che l'email sia di un socio o no.
 * - Con email e codice giusti /socio/entra restituisce un token, che l'app
 *   tiene nel portachiavi del telefono e manda in X-RCM-Socio-Token. E' una
 *   sessione dell'area soci (tabella wp_rcm_soci_sessioni) che dura 180
 *   giorni; "Esci" la chiude. A ogni chiamata si ricontrolla che il socio
 *   possa ancora entrare: tessera scaduta o area spenta chiudono fuori.
 * - Il promemoria della partenza lo programma l'app sul telefono, con i dati
 *   di /socio/partite (partenza del pullman scritta dal Club per partita).
 * - L'app si scarica dall'area soci, sezione "L'app del Club": solo il socio
 *   collegato la vede e la scarica. I soci non entrano mai in bacheca
 *   (Michele, 19/09/2026).
 */

defined( 'ABSPATH' ) || exit;

const RCM_SA_HEADER = 'X-RCM-Socio-Token';
const RCM_SA_APK    = '/var/www/rcm-privato/rcm-soci.apk'; // fuori dal docroot: lo serve PHP, solo ai soci collegati
const RCM_SA_DURATA = 15552000; // 180 giorni

/* -------------------------------------------------------------------------
 * Chi sta chiamando
 * ---------------------------------------------------------------------- */

function rcm_sa_socio( WP_REST_Request $req ) {
	static $cache = false;
	if ( false !== $cache ) {
		return $cache;
	}
	$cache = null;
	$token = (string) $req->get_header( str_replace( '-', '_', strtolower( RCM_SA_HEADER ) ) );
	if ( strlen( $token ) < 40 || ! function_exists( 'rcm_as_hash' ) ) {
		return null;
	}
	global $wpdb;
	$t = rcm_as_tab( 'sessioni' );
	$s = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE token_hash = %s AND scade_il > %s", rcm_as_hash( 's|' . $token ), rcm_as_ora_utc() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	if ( ! $s ) {
		return null;
	}
	$socio = rcm_as_socio( $s->socio_id );
	if ( ! rcm_as_puo_entrare( $socio ) ) {
		return null;
	}
	$aggiorna = array();
	if ( strtotime( $s->ultimo_uso . ' UTC' ) < time() - 600 ) {
		$aggiorna['ultimo_uso'] = rcm_as_ora_utc();
	}
	// la versione dell'app ("RCMSoci/1.0.4" nello User-Agent) segue gli
	// aggiornamenti: si tiene in fondo all'agente, "app: telefono · v1.0.4"
	if ( preg_match( '#RCMSoci/([0-9][0-9A-Za-z.+-]{0,19})#', (string) $req->get_header( 'user_agent' ), $m ) ) {
		$agente = preg_replace( '/ · v[^ ]+$/', '', $s->agente ) . ' · v' . $m[1];
		if ( $agente !== $s->agente ) {
			$aggiorna['agente'] = mb_substr( $agente, 0, 190 );
		}
	}
	if ( $aggiorna ) {
		$wpdb->update( $t, $aggiorna, array( 'id' => $s->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	$socio->sessione_id = (int) $s->id;
	$cache              = $socio;
	return $cache;
}

function rcm_sa_permesso( WP_REST_Request $req ) {
	return rcm_sa_socio( $req ) ? true : new WP_Error( 'rcm_sa_token', 'Accesso scaduto: entra di nuovo con la tua email.', array( 'status' => 401 ) );
}

function rcm_sa_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/* -------------------------------------------------------------------------
 * Le rotte: /wp-json/rcm/v1/socio/...
 * ---------------------------------------------------------------------- */

add_action(
	'rest_api_init',
	function () {
		$pubblico = '__return_true';
		$socio    = 'rcm_sa_permesso';
		$rotte    = array(
			array( 'richiedi', 'POST', $pubblico, 'rcm_sa_richiedi' ),
			array( 'entra', 'POST', $pubblico, 'rcm_sa_entra' ),
			array( 'io', 'GET', $socio, 'rcm_sa_io' ),
			array( 'dati', 'POST', $socio, 'rcm_sa_dati' ),
			array( 'partite', 'GET', $socio, 'rcm_sa_partite' ),
			array( 'prenota', 'POST', $socio, 'rcm_sa_prenota' ),
			array( 'annulla', 'POST', $socio, 'rcm_sa_annulla' ),
			array( 'esci', 'POST', $socio, 'rcm_sa_esci' ),
		);
		foreach ( $rotte as $r ) {
			register_rest_route(
				'rcm/v1',
				'/socio/' . $r[0],
				array(
					'methods'             => $r[1],
					'permission_callback' => $r[2],
					'callback'            => $r[3],
				)
			);
		}
	}
);

/** L'email con il codice. Sempre la stessa risposta: non si scopre chi e' socio. */
function rcm_sa_richiedi( WP_REST_Request $req ) {
	$email = strtolower( sanitize_email( (string) $req['email'] ) );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'rcm_sa_email', 'Scrivi un indirizzo email valido.', array( 'status' => 400 ) );
	}
	// senza Turnstile (e' un'app): limiti stretti per email e per indirizzo IP
	if ( rcm_as_limite( 'e|' . $email, 3, 15 * MINUTE_IN_SECONDS ) && rcm_as_limite( 'ip|' . rcm_sa_ip(), 10, HOUR_IN_SECONDS ) ) {
		$socio = rcm_as_socio_per_email( $email );
		if ( rcm_as_puo_entrare( $socio ) ) {
			rcm_as_manda_email( $socio, rcm_as_crea_accesso( (int) $socio->id ), false );
		}
	}
	return rest_ensure_response( array( 'ok' => true, 'messaggio' => 'Se l\'indirizzo è di un socio, ti abbiamo mandato un codice di 6 cifre.' ) );
}

function rcm_sa_entra( WP_REST_Request $req ) {
	if ( ! rcm_as_limite( 'entra|' . rcm_sa_ip(), 20, HOUR_IN_SECONDS ) ) {
		return new WP_Error( 'rcm_sa_troppi', 'Troppi tentativi: riprova fra un\'ora.', array( 'status' => 429 ) );
	}
	$email = strtolower( sanitize_email( (string) $req['email'] ) );
	$socio = rcm_as_socio_per_email( $email );
	if ( ! rcm_as_puo_entrare( $socio ) || ! rcm_as_verifica_codice( $socio, (string) $req['codice'] ) ) {
		return new WP_Error( 'rcm_sa_codice', 'Codice non valido o scaduto. Dopo cinque tentativi sbagliati ne serve uno nuovo.', array( 'status' => 401 ) );
	}
	global $wpdb;
	$token = rcm_as_token();
	$wpdb->insert(
		rcm_as_tab( 'sessioni' ),
		array(
			'socio_id'   => $socio->id,
			'token_hash' => rcm_as_hash( 's|' . $token ),
			'agente'     => mb_substr( 'app: ' . sanitize_text_field( (string) $req['dispositivo'] ), 0, 190 ),
			'creato_il'  => rcm_as_ora_utc(),
			'scade_il'   => rcm_as_ora_utc( RCM_SA_DURATA ),
			'ultimo_uso' => rcm_as_ora_utc(),
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return rest_ensure_response( array( 'token' => $token, 'nome' => $socio->nome ) );
}

/** Il socio, la tessera (gli stessi campi della tessera del sito) e il QR. */
function rcm_sa_io( WP_REST_Request $req ) {
	$s   = rcm_sa_socio( $req );
	$tip = rcm_soci_tipologie();
	return rest_ensure_response(
		array(
			'nome'     => $s->nome,
			'cognome'  => $s->cognome,
			'email'    => $s->email,
			'telefono' => $s->telefono && function_exists( 'rcm_soci_telefono_leggibile' ) ? rcm_soci_telefono_leggibile( $s->telefono ) : '',
			'tessera'  => array(
				'tipologia' => $tip[ $s->tipologia ] ?? '',
				'numero'    => '' !== (string) $s->numero_tessera ? $s->numero_tessera : 'VIRTUAL',
				'validita'  => substr( (string) $s->stagione, 2 ),
				'stagione'  => $s->stagione,
				'rilascio'  => mysql2date( 'd/m/Y', $s->creato_il ),
			),
			'qr'       => function_exists( 'rcm_qr_url' ) ? rcm_qr_url( $s ) : '',
		)
	);
}

function rcm_sa_dati( WP_REST_Request $req ) {
	$s        = rcm_sa_socio( $req );
	$grezzo   = sanitize_text_field( (string) $req['telefono'] );
	$telefono = '' === $grezzo ? '' : rcm_compleanni_telefono( $grezzo );
	if ( '' !== $grezzo && '' === $telefono ) {
		return new WP_Error( 'rcm_sa_telefono', 'Numero di cellulare non riconosciuto.', array( 'status' => 400 ) );
	}
	global $wpdb;
	$wpdb->update( rcm_compleanni_tabella(), array( 'telefono' => $telefono ), array( 'id' => $s->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return rest_ensure_response( array( 'ok' => true ) );
}

/** Una partita, con la prenotazione del socio se c'e'. */
function rcm_sa_partita_json( $partita, $p, $prenotabile ) {
	$stati    = rcm_pr_stati();
	$partenza = rcm_pr_partenza( $partita->id );
	$out      = array(
		'id'          => $partita->id,
		'titolo'      => $partita->titolo,
		'data'        => rcm_pr_data( $partita ),
		'quando'      => $partita->quando->format( 'c' ),
		'luogo'       => $partita->luogo,
		'casa'        => $partita->casa,
		'aperta'      => $partita->aperta,
		'prenotabile' => $prenotabile,
		'giorni'      => $partita->giorni,
		'entro'       => wp_date( 'l j F', $partita->entro->getTimestamp() ),
		'settori'     => $partita->casa ? rcm_pr_settori() : array( 'Settore ospiti' ),
		'prenotazione' => null,
	);
	if ( $p && 'annullato' !== $p->stato ) {
		$out['prenotazione'] = array(
			'stato'     => $p->stato,
			'stato_testo' => $stati[ $p->stato ] ?? $p->stato,
			'biglietto' => (bool) $p->biglietto,
			'settore'   => $p->settore,
			'pullman'   => (bool) $p->pullman,
			'persone'   => rcm_pr_persone( $p ),
			'note'      => $p->note,
			'nota_club' => $p->nota_club,
			// la partenza solo a chi ha il posto in pullman confermato
			'partenza'  => ( 'confermato' === $p->stato && $p->pullman && $partenza ) ? array(
				'data'  => $partenza['data'],
				'ora'   => $partenza['ora'],
				'luogo' => $partenza['luogo'],
				'testo' => rcm_pr_partenza_testo( $partenza ),
			) : null,
		);
	}
	return $out;
}

/** La prossima partita, le prenotabili (le prime 3 aperte) e le altre prenotazioni del socio. */
function rcm_sa_partite( WP_REST_Request $req ) {
	$s   = rcm_sa_socio( $req );
	$mie = array();
	foreach ( rcm_pr_prenotazioni_socio( $s->id ) as $p ) {
		$mie[ (int) $p->evento_id ] = $p;
	}
	$prenotabili = rcm_pr_prenotabili();
	$prossime    = rcm_pr_prossime();
	$mostrate    = array();
	$out         = array( 'prossima' => null, 'prenotabili' => array(), 'altre' => array() );
	if ( $prossime ) {
		$x               = $prossime[0];
		$out['prossima'] = rcm_sa_partita_json( $x, $mie[ $x->id ] ?? null, isset( $prenotabili[ $x->id ] ) );
		$mostrate[]      = $x->id;
	}
	foreach ( $prenotabili as $x ) {
		if ( in_array( $x->id, $mostrate, true ) ) {
			continue;
		}
		$out['prenotabili'][] = rcm_sa_partita_json( $x, $mie[ $x->id ] ?? null, true );
		$mostrate[]           = $x->id;
	}
	foreach ( $mie as $evento_id => $p ) {
		if ( in_array( $evento_id, $mostrate, true ) || 'annullato' === $p->stato ) {
			continue;
		}
		$x = rcm_pr_partita( $evento_id );
		if ( $x && $x->futura ) {
			$out['altre'][] = rcm_sa_partita_json( $x, $p, false );
		}
	}
	$out['regole'] = array(
		'max_persone' => RCM_PR_MAX_PERSONE,
		'giorni'      => RCM_PR_GIORNI,
		'pagamenti'   => function_exists( 'rcm_tess_pagamenti' ) ? wp_list_pluck( rcm_tess_pagamenti(), 'nome' ) : array(),
	);
	return rest_ensure_response( $out );
}

function rcm_sa_esito( $codice ) {
	if ( in_array( $codice, array( 'prenotato', 'modificato', 'annullato' ), true ) ) {
		$testi = array(
			'prenotato'  => 'Prenotazione registrata. Resta "Prenotato" finché il Club non riceve il pagamento.',
			'modificato' => 'Prenotazione aggiornata.',
			'annullato'  => 'Prenotazione annullata.',
		);
		return rest_ensure_response( array( 'ok' => true, 'esito' => $codice, 'messaggio' => $testi[ $codice ] ) );
	}
	return new WP_Error( 'rcm_sa_' . $codice, rcm_pr_messaggio( $codice ), array( 'status' => 400 ) );
}

function rcm_sa_prenota( WP_REST_Request $req ) {
	// le persone: [{nome, tesserato}], con la scelta obbligatoria per ognuna
	$persone = array();
	foreach ( (array) $req['persone'] as $x ) {
		$nome = mb_substr( trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) ( $x['nome'] ?? '' ) ) ) ), 0, 60 );
		if ( '' === $nome ) {
			continue;
		}
		if ( ! isset( $x['tesserato'] ) || ! is_bool( $x['tesserato'] ) ) {
			$persone = null;
			break;
		}
		$persone[] = array( 'nome' => $nome, 'tesserato' => $x['tesserato'] );
	}
	return rcm_sa_esito(
		rcm_pr_salva(
			rcm_sa_socio( $req ),
			absint( $req['evento'] ),
			array(
				'biglietto' => (bool) $req['biglietto'],
				'pullman'   => (bool) $req['pullman'],
				'settore'   => (string) $req['settore'],
				'persone'   => $persone,
				'note'      => (string) $req['note'],
			)
		)
	);
}

function rcm_sa_annulla( WP_REST_Request $req ) {
	return rcm_sa_esito( rcm_pr_annulla_per( rcm_sa_socio( $req ), absint( $req['evento'] ) ) );
}

function rcm_sa_esci( WP_REST_Request $req ) {
	$s = rcm_sa_socio( $req );
	global $wpdb;
	$wpdb->delete( rcm_as_tab( 'sessioni' ), array( 'id' => $s->sessione_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return rest_ensure_response( array( 'ok' => true ) );
}

/* -------------------------------------------------------------------------
 * Nell'area soci: la sezione per scaricare l'app
 * ---------------------------------------------------------------------- */

// Dopo biglietti e pullman (priorita' 10), prima dei dati.
add_action( 'rcm_as_sezioni', 'rcm_sa_sezione_app', 20, 1 );
function rcm_sa_sezione_app( $socio ) {
	if ( ! is_readable( RCM_SA_APK ) ) {
		return;
	}
	$meta = is_readable( RCM_SA_APK . '.json' ) ? (array) json_decode( (string) file_get_contents( RCM_SA_APK . '.json' ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	?>
	<section class="rcm-as-scheda rcm-sa-app">
		<h2 class="rcm-as-titolo">L'app del Club</h2>
		<p>Tessera con il QR code, prenotazioni di biglietto e pullman, i tuoi dati, e il <strong>promemoria il giorno prima della partenza</strong>: «domani si parte alle 5:30 da piazza…». Entri con la stessa email, senza password.</p>
		<p><a class="rcm-sa-scarica" href="<?php echo esc_url( admin_url( 'admin-post.php?action=rcm_sa_scarica' ) ); ?>">Scarica l'app per Android</a>
			<span class="rcm-as-nota"><?php echo esc_html( trim( ( $meta['versione'] ?? '' ) . ' · ' . size_format( filesize( RCM_SA_APK ), 0 ), ' ·' ) ); ?></span></p>
		<details class="rcm-sa-come">
			<summary>Come si installa</summary>
			<ol>
				<li>Tocca <strong>Scarica</strong> e apri il file <em>Roma-Club-Matera.apk</em>. Se Chrome avvisa che il file potrebbe essere dannoso, tocca <strong>Scarica comunque</strong>: l'app è del Club, non è sul Play Store.</li>
				<li>La prima volta Android chiede di <strong>consentire l'installazione da questa fonte</strong>: consentila e torna indietro.</li>
				<li>Se Google Play Protect dice <em>app non riconosciuta</em>: <strong>Altri dettagli › Installa comunque</strong>.</li>
				<li>Apri l'app <strong>Roma Club Matera</strong>, scrivi la tua email e il codice che ti arriva. Consenti le notifiche e l'uso in background: servono al promemoria della partenza.</li>
			</ol>
			<p class="rcm-as-nota">iPhone: l'app arriverà più avanti. Intanto la tessera e le prenotazioni sono qui, in questa pagina.</p>
		</details>
	</section>
	<?php
}

add_action( 'admin_post_nopriv_rcm_sa_scarica', 'rcm_sa_scarica' );
add_action( 'admin_post_rcm_sa_scarica', 'rcm_sa_scarica' );
function rcm_sa_scarica() {
	// solo il socio collegato all'area soci: nessun altro scarica l'app
	if ( ! function_exists( 'rcm_as_socio_corrente' ) || ! rcm_as_socio_corrente() ) {
		rcm_as_torna( array( 'avviso' => 'scaduto' ) );
	}
	if ( ! is_readable( RCM_SA_APK ) ) {
		rcm_as_torna();
	}
	$socio    = rcm_as_socio_corrente();
	$scaricati = (array) get_option( 'rcm_sa_download', array() );
	$prima     = $scaricati[ $socio->id ] ?? array( 'n' => 0, 'primo' => current_time( 'mysql' ) );
	$scaricati[ $socio->id ] = array(
		'n'      => (int) $prima['n'] + 1,
		'primo'  => $prima['primo'],
		'ultimo' => current_time( 'mysql' ),
	);
	update_option( 'rcm_sa_download', $scaricati, false );
	nocache_headers();
	header( 'Content-Type: application/vnd.android.package-archive' );
	header( 'Content-Disposition: attachment; filename="Roma-Club-Matera.apk"' );
	header( 'Content-Length: ' . filesize( RCM_SA_APK ) );
	readfile( RCM_SA_APK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}

/* -------------------------------------------------------------------------
 * In bacheca, Soci > Area soci: chi ha scaricato l'app e chi la usa
 * ---------------------------------------------------------------------- */

add_action( 'rcm_as_impostazioni_dopo', 'rcm_sa_admin_uso_app' );
function rcm_sa_admin_uso_app() {
	global $wpdb;
	$scaricati = (array) get_option( 'rcm_sa_download', array() );
	$t         = rcm_as_tab( 'sessioni' );
	// una riga per socio: le sessioni dell'app, la piu' usata di recente per prima
	$sessioni = $wpdb->get_results( $wpdb->prepare( "SELECT socio_id, agente, creato_il, ultimo_uso FROM $t WHERE agente LIKE %s AND scade_il > %s ORDER BY ultimo_uso DESC", 'app:%', rcm_as_ora_utc() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$uso      = array();
	foreach ( $sessioni as $r ) {
		$uso[ (int) $r->socio_id ][] = $r;
	}
	$ids = array_unique( array_merge( array_map( 'intval', array_keys( $scaricati ) ), array_keys( $uso ) ) );
	$soci = array();
	foreach ( $ids as $id ) {
		$s = rcm_as_socio( $id );
		if ( $s ) {
			$soci[] = $s;
		}
	}
	usort(
		$soci,
		function ( $a, $b ) {
			return strcasecmp( $a->cognome . $a->nome, $b->cognome . $b->nome );
		}
	);
	$quando = function ( $utc ) {
		return wp_date( 'd/m/Y H:i', strtotime( $utc . ' UTC' ) );
	};
	?>
	<h2 style="margin-top:2em">App dei soci</h2>
	<p><strong><?php echo count( $scaricati ); ?></strong> soci l'hanno scaricata dall'area soci · <strong><?php echo count( $uso ); ?></strong> la usano (collegati con l'app).</p>
	<?php if ( ! $soci ) : ?>
		<p>Ancora nessuno.</p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:70em">
			<thead><tr><th>Socio</th><th>Scaricata</th><th>Telefono e versione</th><th>Entrato la prima volta</th><th>Ultimo uso</th></tr></thead>
			<tbody>
			<?php foreach ( $soci as $s ) : ?>
				<?php $d = $scaricati[ $s->id ] ?? null; ?>
				<?php $righe = $uso[ (int) $s->id ] ?? array( null ); ?>
				<?php foreach ( $righe as $i => $r ) : ?>
					<tr>
						<td><?php echo 0 === $i ? esc_html( $s->nome . ' ' . $s->cognome ) : ''; ?></td>
						<td><?php echo 0 === $i ? ( $d ? esc_html( mysql2date( 'd/m/Y', $d['ultimo'] ) . ( $d['n'] > 1 ? ' (' . $d['n'] . ' volte)' : '' ) ) : '<span class="description">non dall\'area soci</span>' ) : ''; ?></td>
						<?php if ( $r ) : ?>
							<td><?php echo esc_html( trim( preg_replace( '/^app:\s*/', '', $r->agente ) ) ); ?></td>
							<td><?php echo esc_html( $quando( $r->creato_il ) ); ?></td>
							<td><?php echo esc_html( $quando( $r->ultimo_uso ) ); ?></td>
						<?php else : ?>
							<td colspan="3"><span class="description">scaricata, ma non ancora usata</span></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">Un socio può avere più telefoni. "Ultimo uso" si aggiorna quando l'app si collega al sito (all'apertura e con il controllo in background). I download si contano dal 19/09/2026.</p>
	<?php endif; ?>
	<?php
}
