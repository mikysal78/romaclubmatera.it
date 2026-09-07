<?php
/**
 * Plugin Name: RCM - Report settimanale della newsletter
 * Description: Una volta a settimana manda per email il punto sugli iscritti alla newsletter: quanti sono, chi si e' iscritto e chi si e' cancellato negli ultimi sette giorni. I dati arrivano dalle API di Mailchimp, con la chiave gia' configurata nel plugin MC4WP: non ce n'e' una seconda da tenere aggiornata.
 * Version: 1.0.0
 * Author: Roma Club Matera
 */

defined( 'ABSPATH' ) || exit;

const RCM_NL_OPZIONI = 'rcm_newsletter_report';
const RCM_NL_HOOK    = 'rcm_newsletter_report_settimanale';
const RCM_NL_CAP     = 'manage_options';

/** Quanti nomi elencare per esteso prima di limitarsi a contarli. */
const RCM_NL_MAX_ELENCO = 25;

function rcm_nl_opzioni() {
	return wp_parse_args(
		get_option( RCM_NL_OPZIONI, array() ),
		array(
			'attivo' => 1,
			'a'      => 'info@romaclubmatera.it',
			'giorno' => 1,       // 1 = lunedi.
			'ora'    => '09:00',
			'lista'  => '',      // Vuoto: la prima lista dell'account.
		)
	);
}

/* -------------------------------------------------------------------------
 * Mailchimp
 * ---------------------------------------------------------------------- */

/**
 * La chiave API, presa da MC4WP.
 *
 * Non se ne tiene una copia: due copie della stessa chiave sono due cose da
 * cambiare il giorno che si rigenera, e la seconda ci si scorda.
 */
function rcm_nl_chiave() {
	if ( ! function_exists( 'mc4wp_get_options' ) ) {
		return '';
	}
	$opzioni = mc4wp_get_options();
	return isset( $opzioni['api_key'] ) ? trim( $opzioni['api_key'] ) : '';
}

/**
 * Una chiamata alle API di Mailchimp.
 *
 * @param string $percorso Percorso dopo /3.0, con lo slash davanti.
 * @param array  $args     Parametri in query string.
 * @return array|WP_Error
 */
function rcm_nl_chiama( $percorso, $args = array() ) {
	$chiave = rcm_nl_chiave();
	if ( ! $chiave || ! str_contains( $chiave, '-' ) ) {
		return new WP_Error( 'rcm_nl_chiave', 'Manca la chiave API di Mailchimp. La prendo da MC4WP: controlla che il plugin sia attivo e configurato.' );
	}

	// La chiave finisce con il datacenter, tipo "-us18": e' quello il
	// sottodominio a cui parlare.
	$datacenter = substr( strrchr( $chiave, '-' ), 1 );

	$risposta = wp_remote_get(
		add_query_arg( $args, 'https://' . $datacenter . '.api.mailchimp.com/3.0' . $percorso ),
		array(
			'timeout' => 15,
			'headers' => array(
				// Mailchimp vuole il Basic auth; l'utente puo' essere qualunque cosa.
				'Authorization' => 'Basic ' . base64_encode( 'rcm:' . $chiave ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- e' l'autenticazione HTTP, non un offuscamento.
			),
		)
	);

	if ( is_wp_error( $risposta ) ) {
		return $risposta;
	}

	$codice = wp_remote_retrieve_response_code( $risposta );
	$corpo  = json_decode( wp_remote_retrieve_body( $risposta ), true );

	if ( 200 !== $codice ) {
		return new WP_Error(
			'rcm_nl_api',
			sprintf(
				'Mailchimp ha risposto %d: %s',
				$codice,
				is_array( $corpo ) && isset( $corpo['detail'] ) ? $corpo['detail'] : 'nessun dettaglio'
			)
		);
	}

	return is_array( $corpo ) ? $corpo : array();
}

/**
 * La lista da guardare: quella scelta nelle impostazioni, o l'unica che c'e'.
 *
 * @return array|WP_Error Con chiavi id, nome, iscritti, cancellati.
 */
function rcm_nl_lista() {
	$opzioni = rcm_nl_opzioni();

	if ( $opzioni['lista'] ) {
		$dati = rcm_nl_chiama( '/lists/' . rawurlencode( $opzioni['lista'] ) );
	} else {
		$elenco = rcm_nl_chiama( '/lists', array( 'count' => 1 ) );
		if ( is_wp_error( $elenco ) ) {
			return $elenco;
		}
		if ( empty( $elenco['lists'] ) ) {
			return new WP_Error( 'rcm_nl_lista', 'Su Mailchimp non c\'e' . '\'' . ' nessuna lista.' );
		}
		$dati = $elenco['lists'][0];
	}

	if ( is_wp_error( $dati ) ) {
		return $dati;
	}

	$stat = isset( $dati['stats'] ) ? $dati['stats'] : array();

	return array(
		'id'         => $dati['id'],
		'nome'       => $dati['name'],
		'iscritti'   => (int) ( $stat['member_count'] ?? 0 ),
		'cancellati' => (int) ( $stat['unsubscribe_count'] ?? 0 ),
	);
}

/* -------------------------------------------------------------------------
 * Il report
 * ---------------------------------------------------------------------- */

/**
 * Raccoglie i numeri della settimana.
 *
 * @return array|WP_Error
 */
function rcm_nl_dati() {
	$lista = rcm_nl_lista();
	if ( is_wp_error( $lista ) ) {
		return $lista;
	}

	$da = current_datetime()->modify( '-7 days' );

	// Chi si e' iscritto negli ultimi sette giorni, con nome e data.
	$nuovi = rcm_nl_chiama(
		'/lists/' . $lista['id'] . '/members',
		array(
			'since_timestamp_opt' => $da->format( 'c' ),
			'status'              => 'subscribed',
			'sort_field'          => 'timestamp_opt',
			'sort_dir'            => 'DESC',
			'count'               => RCM_NL_MAX_ELENCO,
			'fields'              => 'total_items,members.email_address,members.timestamp_opt,members.merge_fields',
		)
	);
	if ( is_wp_error( $nuovi ) ) {
		return $nuovi;
	}

	// Chi se n'e' andato. Qui la data buona e' l'ultima modifica: la
	// cancellazione non ha un campo suo.
	$usciti = rcm_nl_chiama(
		'/lists/' . $lista['id'] . '/members',
		array(
			'since_last_changed' => $da->format( 'c' ),
			'status'             => 'unsubscribed',
			'count'              => RCM_NL_MAX_ELENCO,
			'fields'             => 'total_items,members.email_address,members.last_changed',
		)
	);
	if ( is_wp_error( $usciti ) ) {
		return $usciti;
	}

	// Quattordici giorni di attivita': i primi sette sono la settimana appena
	// passata, gli altri sette quella prima. Serve a dire se si sta salendo o
	// scendendo, che da solo un numero non lo dice.
	$attivita  = rcm_nl_chiama( '/lists/' . $lista['id'] . '/activity', array( 'count' => 14 ) );
	$settimana = 0;
	$prima     = 0;
	if ( ! is_wp_error( $attivita ) && ! empty( $attivita['activity'] ) ) {
		foreach ( array_values( $attivita['activity'] ) as $i => $giorno ) {
			$quanti = (int) ( $giorno['subs'] ?? 0 ) + (int) ( $giorno['other_adds'] ?? 0 );
			if ( $i < 7 ) {
				$settimana += $quanti;
			} elseif ( $i < 14 ) {
				$prima += $quanti;
			}
		}
	}

	return array(
		'lista'             => $lista,
		'da'                => $da,
		'nuovi'             => $nuovi['members'] ?? array(),
		'nuovi_totale'      => (int) ( $nuovi['total_items'] ?? 0 ),
		'usciti'            => $usciti['members'] ?? array(),
		'usciti_totale'     => (int) ( $usciti['total_items'] ?? 0 ),
		'nuovi_settimana'   => $settimana,
		'nuovi_precedente'  => $prima,
	);
}

/**
 * Il corpo del messaggio, in HTML.
 */
function rcm_nl_corpo( $dati ) {
	$fino = current_datetime();

	$html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:22px;color:#2b2b2b">';
	$html .= '<p style="color:#6b6155">Newsletter <strong>' . esc_html( $dati['lista']['nome'] ) . '</strong><br>'
		. 'dal ' . esc_html( wp_date( 'j F', $dati['da']->getTimestamp() ) )
		. ' al ' . esc_html( wp_date( 'j F Y', $fino->getTimestamp() ) ) . '</p>';

	$html .= '<p style="font-size:34px;line-height:40px;margin:18px 0 0"><strong>' . (int) $dati['lista']['iscritti'] . '</strong></p>'
		. '<p style="margin:0;color:#6b6155">iscritti in tutto</p>';

	$diff = $dati['nuovi_settimana'] - $dati['nuovi_precedente'];
	$html .= '<p style="margin-top:18px">'
		. '<strong>' . (int) $dati['nuovi_totale'] . '</strong> ' . ( 1 === (int) $dati['nuovi_totale'] ? 'nuovo iscritto' : 'nuovi iscritti' )
		. ' &middot; <strong>' . (int) $dati['usciti_totale'] . '</strong> ' . ( 1 === (int) $dati['usciti_totale'] ? 'cancellazione' : 'cancellazioni' )
		. '<br><span style="color:#6b6155">settimana precedente: ' . (int) $dati['nuovi_precedente'] . ' iscrizioni'
		. ( 0 !== $diff ? ' (' . ( $diff > 0 ? '+' : '' ) . (int) $diff . ')' : '' )
		. '</span></p>';

	if ( $dati['nuovi'] ) {
		$html .= '<h3 style="font-size:15px;margin-bottom:6px">Chi si &egrave; iscritto</h3><ul style="padding-left:18px;margin-top:0">';
		foreach ( $dati['nuovi'] as $m ) {
			$nome = trim( ( $m['merge_fields']['FNAME'] ?? '' ) . ' ' . ( $m['merge_fields']['LNAME'] ?? '' ) );
			$html .= '<li style="margin-bottom:4px">'
				. ( $nome ? '<strong>' . esc_html( $nome ) . '</strong> &middot; ' : '' )
				. esc_html( $m['email_address'] )
				. '<br><span style="color:#6b6155;font-size:13px">'
				. esc_html( wp_date( 'j F, H:i', strtotime( $m['timestamp_opt'] ) ) )
				. '</span></li>';
		}
		$html .= '</ul>';
		if ( $dati['nuovi_totale'] > count( $dati['nuovi'] ) ) {
			$html .= '<p style="color:#6b6155;font-size:13px">e altri ' . ( (int) $dati['nuovi_totale'] - count( $dati['nuovi'] ) ) . '.</p>';
		}
	}

	if ( $dati['usciti'] ) {
		$html .= '<h3 style="font-size:15px;margin-bottom:6px">Chi si &egrave; cancellato</h3><ul style="padding-left:18px;margin-top:0">';
		foreach ( $dati['usciti'] as $m ) {
			$html .= '<li style="margin-bottom:4px">' . esc_html( $m['email_address'] ) . '</li>';
		}
		$html .= '</ul>';
	}

	if ( ! $dati['nuovi'] && ! $dati['usciti'] ) {
		$html .= '<p style="color:#6b6155">Nessun movimento questa settimana.</p>';
	}

	$html .= '<p style="color:#6b6155;font-size:13px;margin-top:24px">'
		. 'Report automatico del sito. Si spegne da Impostazioni &rsaquo; Report newsletter.</p></div>';

	return $html;
}

/**
 * Prepara e manda il report.
 *
 * @param string $destinatario Facoltativo, per la prova.
 * @return true|WP_Error
 */
function rcm_nl_manda( $destinatario = '' ) {
	$opzioni = rcm_nl_opzioni();
	$a       = $destinatario ? $destinatario : $opzioni['a'];

	if ( ! is_email( $a ) ) {
		return new WP_Error( 'rcm_nl_a', 'Indirizzo del destinatario non valido.' );
	}

	$dati = rcm_nl_dati();
	if ( is_wp_error( $dati ) ) {
		return $dati;
	}

	$quanti  = (int) $dati['nuovi_totale'];
	$oggetto = 0 === $quanti
		? 'Newsletter: nessun nuovo iscritto questa settimana'
		: sprintf(
			1 === $quanti ? 'Newsletter: 1 nuovo iscritto questa settimana' : 'Newsletter: %d nuovi iscritti questa settimana',
			$quanti
		);

	$esito = wp_mail( $a, $oggetto, rcm_nl_corpo( $dati ), array( 'Content-Type: text/html; charset=UTF-8' ) );

	if ( ! $esito ) {
		return new WP_Error( 'rcm_nl_mail', 'wp_mail ha rifiutato il messaggio: controlla WP Mail SMTP.' );
	}

	return true;
}

/* -------------------------------------------------------------------------
 * Pianificazione
 * ---------------------------------------------------------------------- */

/**
 * Il prossimo giorno della settimana all'ora scelta.
 *
 * @param int    $giorno 1 = lunedi ... 7 = domenica.
 * @param string $ora    'HH:MM'.
 */
function rcm_nl_prossimo_avvio( $giorno, $ora ) {
	$nomi = array( 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday' );
	$nome = $nomi[ (int) $giorno ] ?? 'monday';

	$prossimo = current_datetime()->modify( 'next ' . $nome )->modify( $ora );

	return $prossimo->getTimestamp();
}

function rcm_nl_pianifica( $forza = false ) {
	$opzioni = rcm_nl_opzioni();
	$attuale = wp_next_scheduled( RCM_NL_HOOK );

	if ( ( $forza || empty( $opzioni['attivo'] ) ) && $attuale ) {
		wp_unschedule_event( $attuale, RCM_NL_HOOK );
		$attuale = false;
	}
	if ( ! $attuale && ! empty( $opzioni['attivo'] ) ) {
		wp_schedule_event( rcm_nl_prossimo_avvio( $opzioni['giorno'], $opzioni['ora'] ), 'weekly', RCM_NL_HOOK );
	}
}
add_action( 'init', 'rcm_nl_pianifica' );

add_action( RCM_NL_HOOK, 'rcm_nl_giro' );
function rcm_nl_giro() {
	$esito = rcm_nl_manda();

	update_option(
		'rcm_newsletter_report_ultimo',
		array(
			'quando' => current_time( 'mysql' ),
			'esito'  => is_wp_error( $esito ) ? $esito->get_error_message() : 'inviato',
		),
		false
	);
}

/* -------------------------------------------------------------------------
 * Impostazioni
 * ---------------------------------------------------------------------- */

add_action(
	'admin_menu',
	function () {
		add_options_page( 'Report newsletter', 'Report newsletter', RCM_NL_CAP, 'rcm-newsletter-report', 'rcm_nl_pagina' );
	}
);

function rcm_nl_pagina() {
	$opzioni = rcm_nl_opzioni();

	if ( isset( $_POST['rcm_azione'] ) && check_admin_referer( 'rcm_nl' ) ) {
		$azione = sanitize_text_field( wp_unslash( $_POST['rcm_azione'] ) );

		if ( 'salva' === $azione ) {
			$ora     = sanitize_text_field( wp_unslash( $_POST['ora'] ?? '09:00' ) );
			$opzioni = array(
				'attivo' => empty( $_POST['attivo'] ) ? 0 : 1,
				'a'      => sanitize_email( wp_unslash( $_POST['a'] ?? '' ) ),
				'giorno' => min( 7, max( 1, (int) ( $_POST['giorno'] ?? 1 ) ) ),
				'ora'    => preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $ora ) ? $ora : '09:00',
				'lista'  => sanitize_text_field( wp_unslash( $_POST['lista'] ?? '' ) ),
			);
			update_option( RCM_NL_OPZIONI, $opzioni );
			rcm_nl_pianifica( true );
			echo '<div class="notice notice-success is-dismissible"><p>Impostazioni salvate.</p></div>';
		}

		if ( 'prova' === $azione ) {
			$a     = sanitize_email( wp_unslash( $_POST['prova_a'] ?? '' ) );
			$esito = rcm_nl_manda( $a );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				is_wp_error( $esito ) ? 'error' : 'success',
				is_wp_error( $esito ) ? esc_html( $esito->get_error_message() ) : 'Report mandato a ' . esc_html( $a ) . '.'
			);
		}
	}

	$lista    = rcm_nl_lista();
	$prossimo = wp_next_scheduled( RCM_NL_HOOK );
	$ultimo   = get_option( 'rcm_newsletter_report_ultimo' );
	$giorni   = array( 1 => 'lunedì', 2 => 'martedì', 3 => 'mercoledì', 4 => 'giovedì', 5 => 'venerdì', 6 => 'sabato', 7 => 'domenica' );
	?>
	<div class="wrap">
		<h1>Report newsletter</h1>

		<?php if ( is_wp_error( $lista ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $lista->get_error_message() ); ?></p></div>
		<?php else : ?>
			<p>
				Lista <strong><?php echo esc_html( $lista['nome'] ); ?></strong>:
				<strong><?php echo (int) $lista['iscritti']; ?></strong> iscritti,
				<?php echo (int) $lista['cancellati']; ?> cancellazioni in tutto.
			</p>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'rcm_nl' ); ?>
			<input type="hidden" name="rcm_azione" value="salva">
			<table class="form-table">
				<tr>
					<th scope="row">Invio</th>
					<td>
						<label><input type="checkbox" name="attivo" value="1" <?php checked( $opzioni['attivo'], 1 ); ?>> manda il report ogni settimana</label>
						<p class="description">
							<?php if ( $prossimo ) : ?>
								Prossimo invio: <strong><?php echo esc_html( wp_date( 'd/m/Y H:i', $prossimo ) ); ?></strong>.
							<?php endif; ?>
							<?php if ( $ultimo ) : ?>
								Ultimo: <?php echo esc_html( mysql2date( 'd/m/Y H:i', $ultimo['quando'] ) ); ?> — <?php echo esc_html( $ultimo['esito'] ); ?>.
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rcm-nl-a">Destinatario</label></th>
					<td><input id="rcm-nl-a" name="a" type="email" class="regular-text" value="<?php echo esc_attr( $opzioni['a'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="rcm-nl-giorno">Quando</label></th>
					<td>
						<select id="rcm-nl-giorno" name="giorno">
							<?php foreach ( $giorni as $n => $nome ) : ?>
								<option value="<?php echo (int) $n; ?>" <?php selected( $opzioni['giorno'], $n ); ?>><?php echo esc_html( $nome ); ?></option>
							<?php endforeach; ?>
						</select>
						alle
						<input name="ora" type="time" value="<?php echo esc_attr( $opzioni['ora'] ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rcm-nl-lista">Lista Mailchimp</label></th>
					<td>
						<input id="rcm-nl-lista" name="lista" class="regular-text" value="<?php echo esc_attr( $opzioni['lista'] ); ?>" placeholder="<?php echo esc_attr( is_wp_error( $lista ) ? '' : $lista['id'] ); ?>">
						<p class="description">Da riempire solo se un giorno le liste diventano più d'una. Vuoto: la prima.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Salva impostazioni' ); ?>
		</form>

		<h2>Prova</h2>
		<form method="post">
			<?php wp_nonce_field( 'rcm_nl' ); ?>
			<input type="hidden" name="rcm_azione" value="prova">
			<p>
				<input name="prova_a" type="email" class="regular-text" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
				<?php submit_button( 'Manda il report adesso', 'secondary', '', false ); ?>
			</p>
			<p class="description">Manda il report vero, con i dati di questa settimana, all'indirizzo che scrivi qui.</p>
		</form>
	</div>
	<?php
}
