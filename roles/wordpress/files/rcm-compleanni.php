<?php
/**
 * Plugin Name: RCM Compleanni
 * Description: Anagrafica soci importabile da CSV e invio automatico degli auguri di compleanno via il relay SMTP del sito.
 * Author: Roma Club Matera
 */

defined( 'ABSPATH' ) || exit;

define( 'RCM_COMPLEANNI_DB_VERSION', '1.0' );
define( 'RCM_COMPLEANNI_OPZIONI', 'rcm_compleanni_opzioni' );
define( 'RCM_COMPLEANNI_HOOK', 'rcm_compleanni_invio_giornaliero' );
define( 'RCM_COMPLEANNI_CAP', 'manage_options' );

/**
 * Nome della tabella dei soci.
 */
function rcm_compleanni_tabella() {
	global $wpdb;
	return $wpdb->prefix . 'rcm_soci';
}

/**
 * Impostazioni con i valori di default.
 */
function rcm_compleanni_opzioni() {
	$default = array(
		'attivo'    => 0,
		'ora'       => '08:00',
		'oggetto'   => 'Tanti auguri, {nome}!',
		'messaggio' => "Caro {nome},\n\ntutto il Roma Club Matera ti augura un felice compleanno!\n\nForza Roma sempre.\n\nIl Direttivo\nRoma Club Matera \"Francesco Totti\"",
		'copia_a'   => '',
	);
	return wp_parse_args( get_option( RCM_COMPLEANNI_OPZIONI, array() ), $default );
}

/* -------------------------------------------------------------------------
 * Tabella
 * ---------------------------------------------------------------------- */

/**
 * Crea o aggiorna la tabella dei soci quando cambia la versione dello schema.
 */
function rcm_compleanni_installa() {
	if ( get_option( 'rcm_compleanni_db_version' ) === RCM_COMPLEANNI_DB_VERSION ) {
		return;
	}

	global $wpdb;
	$tabella  = rcm_compleanni_tabella();
	$collate  = $wpdb->get_charset_collate();
	$sql      = "CREATE TABLE $tabella (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		nome varchar(100) NOT NULL DEFAULT '',
		cognome varchar(100) NOT NULL DEFAULT '',
		email varchar(191) NOT NULL,
		data_nascita date DEFAULT NULL,
		attivo tinyint(1) NOT NULL DEFAULT 1,
		ultimo_invio_anno smallint(6) DEFAULT NULL,
		creato_il datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY email (email),
		KEY data_nascita (data_nascita)
	) $collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'rcm_compleanni_db_version', RCM_COMPLEANNI_DB_VERSION );
}
add_action( 'admin_init', 'rcm_compleanni_installa' );

/* -------------------------------------------------------------------------
 * Pianificazione
 * ---------------------------------------------------------------------- */

/**
 * Timestamp UTC della prossima occorrenza dell'orario impostato (ora locale).
 */
function rcm_compleanni_prossimo_avvio( $ora ) {
	$fuso     = wp_timezone();
	$adesso   = new DateTime( 'now', $fuso );
	$prossimo = DateTime::createFromFormat( 'Y-m-d H:i', $adesso->format( 'Y-m-d' ) . ' ' . $ora, $fuso );

	if ( ! $prossimo ) {
		$prossimo = DateTime::createFromFormat( 'Y-m-d H:i', $adesso->format( 'Y-m-d' ) . ' 08:00', $fuso );
	}
	if ( $prossimo->getTimestamp() <= $adesso->getTimestamp() ) {
		$prossimo->modify( '+1 day' );
	}

	return $prossimo->getTimestamp();
}

/**
 * (Ri)pianifica l'evento giornaliero all'orario scelto.
 */
function rcm_compleanni_pianifica( $forza = false ) {
	$opzioni = rcm_compleanni_opzioni();
	$attuale = wp_next_scheduled( RCM_COMPLEANNI_HOOK );

	if ( $forza && $attuale ) {
		wp_unschedule_event( $attuale, RCM_COMPLEANNI_HOOK );
		$attuale = false;
	}
	if ( ! $attuale ) {
		wp_schedule_event( rcm_compleanni_prossimo_avvio( $opzioni['ora'] ), 'daily', RCM_COMPLEANNI_HOOK );
	}
}
add_action( 'admin_init', 'rcm_compleanni_pianifica' );

/* -------------------------------------------------------------------------
 * Invio
 * ---------------------------------------------------------------------- */

/**
 * Sostituisce i segnaposto nel testo con i dati del socio.
 */
function rcm_compleanni_sostituisci( $testo, $socio ) {
	$eta = '';
	if ( $socio->data_nascita ) {
		$nascita = new DateTime( $socio->data_nascita, wp_timezone() );
		$eta     = $nascita->diff( new DateTime( 'now', wp_timezone() ) )->y;
	}

	return strtr(
		$testo,
		array(
			'{nome}'         => $socio->nome,
			'{cognome}'      => $socio->cognome,
			'{eta}'          => $eta,
			'{anno_nascita}' => $socio->data_nascita ? substr( $socio->data_nascita, 0, 4 ) : '',
		)
	);
}

/**
 * Soci che compiono gli anni oggi e non hanno ancora ricevuto gli auguri quest'anno.
 * Il 28 febbraio degli anni non bisestili include anche i nati il 29.
 */
function rcm_compleanni_soci_di_oggi() {
	global $wpdb;

	$oggi = current_time( 'm-d' );
	$anno = (int) current_time( 'Y' );
	$date = array( $oggi );

	if ( '02-28' === $oggi && ! wp_date( 'L' ) ) {
		$date[] = '02-29';
	}

	$segnaposto = implode( ',', array_fill( 0, count( $date ), '%s' ) );
	$tabella    = rcm_compleanni_tabella();

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome tabella e segnaposto generati internamente.
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $tabella
			 WHERE attivo = 1
			   AND email <> ''
			   AND data_nascita IS NOT NULL
			   AND DATE_FORMAT( data_nascita, '%%m-%%d' ) IN ( $segnaposto )
			   AND ( ultimo_invio_anno IS NULL OR ultimo_invio_anno <> %d )",
			array_merge( $date, array( $anno ) )
		)
	);
	// phpcs:enable
}

/**
 * Manda gli auguri a un singolo socio. Restituisce true se wp_mail ha accettato il messaggio.
 */
function rcm_compleanni_invia( $socio, $segna_come_inviato = true ) {
	$opzioni = rcm_compleanni_opzioni();
	$oggetto = rcm_compleanni_sostituisci( $opzioni['oggetto'], $socio );
	$testo   = rcm_compleanni_sostituisci( $opzioni['messaggio'], $socio );

	$intestazioni = array( 'Content-Type: text/plain; charset=UTF-8' );
	if ( $opzioni['copia_a'] && is_email( $opzioni['copia_a'] ) ) {
		$intestazioni[] = 'Bcc: ' . $opzioni['copia_a'];
	}

	$esito = wp_mail( $socio->email, $oggetto, $testo, $intestazioni );

	if ( $esito && $segna_come_inviato ) {
		global $wpdb;
		$wpdb->update(
			rcm_compleanni_tabella(),
			array( 'ultimo_invio_anno' => (int) current_time( 'Y' ) ),
			array( 'id' => $socio->id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	return $esito;
}

/**
 * Giro giornaliero: auguri a chi compie gli anni oggi.
 */
function rcm_compleanni_giro_giornaliero() {
	$opzioni = rcm_compleanni_opzioni();
	if ( empty( $opzioni['attivo'] ) ) {
		return;
	}

	$inviate = 0;
	$errori  = 0;
	foreach ( rcm_compleanni_soci_di_oggi() as $socio ) {
		if ( rcm_compleanni_invia( $socio ) ) {
			++$inviate;
		} else {
			++$errori;
		}
	}

	update_option(
		'rcm_compleanni_ultimo_giro',
		array(
			'quando'  => current_time( 'mysql' ),
			'inviate' => $inviate,
			'errori'  => $errori,
		),
		false
	);
}
add_action( RCM_COMPLEANNI_HOOK, 'rcm_compleanni_giro_giornaliero' );

/* -------------------------------------------------------------------------
 * Import CSV
 * ---------------------------------------------------------------------- */

/**
 * Interpreta una data nei formati usati di solito (gg/mm/aaaa, aaaa-mm-gg, gg-mm-aaaa, gg.mm.aaaa).
 * Restituisce Y-m-d oppure stringa vuota.
 */
function rcm_compleanni_data( $valore ) {
	$valore = trim( $valore );
	if ( '' === $valore ) {
		return '';
	}

	foreach ( array( 'd/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y', 'j/n/Y', 'd/m/y' ) as $formato ) {
		$data = DateTime::createFromFormat( $formato . '|', $valore );
		if ( $data && $data->format( $formato ) === $valore ) {
			$anno = (int) $data->format( 'Y' );
			if ( $anno >= 1900 && $anno <= (int) current_time( 'Y' ) ) {
				return $data->format( 'Y-m-d' );
			}
		}
	}

	return '';
}

/**
 * Riconosce le colonne del CSV dall'intestazione, con i nomi più probabili.
 */
function rcm_compleanni_mappa_colonne( $intestazione ) {
	$alias = array(
		'nome'         => array( 'nome', 'name', 'first name', 'firstname' ),
		'cognome'      => array( 'cognome', 'surname', 'last name', 'lastname' ),
		'email'        => array( 'email', 'e-mail', 'mail', 'indirizzo email', 'posta elettronica' ),
		'data_nascita' => array( 'data_nascita', 'data di nascita', 'data nascita', 'nascita', 'compleanno', 'birthday', 'data' ),
	);

	$mappa = array();
	foreach ( $intestazione as $indice => $etichetta ) {
		$etichetta = strtolower( trim( $etichetta, " \t\n\r\0\x0B\"'\xEF\xBB\xBF" ) );
		foreach ( $alias as $campo => $nomi ) {
			if ( ! isset( $mappa[ $campo ] ) && in_array( $etichetta, $nomi, true ) ) {
				$mappa[ $campo ] = $indice;
			}
		}
	}

	return $mappa;
}

/**
 * Importa il CSV caricato. Aggiorna i soci già presenti (chiave: email).
 */
function rcm_compleanni_importa( $percorso ) {
	global $wpdb;

	$file = fopen( $percorso, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( ! $file ) {
		return new WP_Error( 'rcm_csv', 'Non riesco a leggere il file caricato.' );
	}

	// Excel italiano salva con il punto e virgola: deduco il separatore dalla prima riga.
	$prima      = fgets( $file );
	$separatore = substr_count( $prima, ';' ) > substr_count( $prima, ',' ) ? ';' : ',';
	rewind( $file );

	// Da PHP 8.4 il parametro $escape va passato esplicitamente: '' è il comportamento CSV corretto.
	$intestazione = fgetcsv( $file, 0, $separatore, '"', '' );
	$mappa        = $intestazione ? rcm_compleanni_mappa_colonne( $intestazione ) : array();

	if ( ! isset( $mappa['email'] ) ) {
		fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return new WP_Error( 'rcm_csv', 'Nella prima riga del file non trovo la colonna "email". Controlla che il CSV abbia l\'intestazione.' );
	}

	$esito = array(
		'nuovi'      => 0,
		'aggiornati' => 0,
		'saltati'    => 0,
		'avvisi'     => array(),
	);
	$riga  = 1;

	while ( false !== ( $colonne = fgetcsv( $file, 0, $separatore, '"', '' ) ) ) {
		++$riga;
		if ( array( null ) === $colonne ) {
			continue; // Riga vuota.
		}

		$leggi = static function ( $campo ) use ( $mappa, $colonne ) {
			return isset( $mappa[ $campo ], $colonne[ $mappa[ $campo ] ] ) ? trim( $colonne[ $mappa[ $campo ] ] ) : '';
		};

		$email = sanitize_email( $leggi( 'email' ) );
		if ( ! $email || ! is_email( $email ) ) {
			++$esito['saltati'];
			if ( count( $esito['avvisi'] ) < 10 ) {
				$esito['avvisi'][] = sprintf( 'Riga %d saltata: email mancante o non valida.', $riga );
			}
			continue;
		}

		$data = rcm_compleanni_data( $leggi( 'data_nascita' ) );
		if ( ! $data && $leggi( 'data_nascita' ) && count( $esito['avvisi'] ) < 10 ) {
			$esito['avvisi'][] = sprintf( 'Riga %d: data di nascita "%s" non riconosciuta, socio importato senza data.', $riga, $leggi( 'data_nascita' ) );
		}

		$dati = array(
			'nome'         => sanitize_text_field( $leggi( 'nome' ) ),
			'cognome'      => sanitize_text_field( $leggi( 'cognome' ) ),
			'data_nascita' => $data ? $data : null,
		);

		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . rcm_compleanni_tabella() . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		if ( $id ) {
			// Non sovrascrivo con celle vuote quello che è già in archivio.
			$dati = array_filter(
				$dati,
				static function ( $valore ) {
					return null !== $valore && '' !== $valore;
				}
			);
			if ( $dati ) {
				$wpdb->update( rcm_compleanni_tabella(), $dati, array( 'id' => $id ) );
			}
			++$esito['aggiornati'];
		} else {
			$dati['email']     = $email;
			$dati['attivo']    = 1;
			$dati['creato_il'] = current_time( 'mysql' );
			$wpdb->insert( rcm_compleanni_tabella(), $dati );
			++$esito['nuovi'];
		}
	}

	fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	return $esito;
}

/* -------------------------------------------------------------------------
 * Amministrazione
 * ---------------------------------------------------------------------- */

add_action(
	'admin_menu',
	function () {
		add_menu_page( 'Soci', 'Soci', RCM_COMPLEANNI_CAP, 'rcm-soci', 'rcm_compleanni_pagina_elenco', 'dashicons-groups', 26 );
		add_submenu_page( 'rcm-soci', 'Elenco soci', 'Elenco soci', RCM_COMPLEANNI_CAP, 'rcm-soci', 'rcm_compleanni_pagina_elenco' );
		add_submenu_page( 'rcm-soci', 'Importa CSV', 'Importa CSV', RCM_COMPLEANNI_CAP, 'rcm-soci-import', 'rcm_compleanni_pagina_import' );
		add_submenu_page( 'rcm-soci', 'Auguri di compleanno', 'Auguri', RCM_COMPLEANNI_CAP, 'rcm-soci-auguri', 'rcm_compleanni_pagina_auguri' );
	}
);

/**
 * Messaggio di servizio in cima alla pagina.
 */
function rcm_compleanni_avviso( $testo, $tipo = 'success' ) {
	printf(
		'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
		esc_attr( $tipo ),
		wp_kses( $testo, array( 'strong' => array(), 'br' => array() ) )
	);
}

/**
 * Elenco soci, ricerca, inserimento manuale e cancellazione.
 */
function rcm_compleanni_pagina_elenco() {
	global $wpdb;
	$tabella = rcm_compleanni_tabella();

	if ( isset( $_POST['rcm_azione'] ) && 'aggiungi' === $_POST['rcm_azione'] && check_admin_referer( 'rcm_socio' ) ) {
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			rcm_compleanni_avviso( 'Email non valida: socio non salvato.', 'error' );
		} else {
			$inserito = $wpdb->insert(
				$tabella,
				array(
					'nome'         => sanitize_text_field( wp_unslash( $_POST['nome'] ?? '' ) ),
					'cognome'      => sanitize_text_field( wp_unslash( $_POST['cognome'] ?? '' ) ),
					'email'        => $email,
					'data_nascita' => rcm_compleanni_data( sanitize_text_field( wp_unslash( $_POST['data_nascita'] ?? '' ) ) ) ?: null,
					'attivo'       => 1,
					'creato_il'    => current_time( 'mysql' ),
				)
			);
			rcm_compleanni_avviso( $inserito ? 'Socio aggiunto.' : 'Esiste già un socio con questa email.', $inserito ? 'success' : 'error' );
		}
	}

	if ( isset( $_GET['elimina'] ) && check_admin_referer( 'rcm_elimina_' . (int) $_GET['elimina'] ) ) {
		$wpdb->delete( $tabella, array( 'id' => (int) $_GET['elimina'] ), array( '%d' ) );
		rcm_compleanni_avviso( 'Socio eliminato.' );
	}

	$cerca   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$pagina  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$per_pag = 50;
	$filtro  = '';
	$valori  = array();

	if ( $cerca ) {
		$like   = '%' . $wpdb->esc_like( $cerca ) . '%';
		$filtro = 'WHERE nome LIKE %s OR cognome LIKE %s OR email LIKE %s';
		$valori = array( $like, $like, $like );
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$totale = $valori
		? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tabella $filtro", $valori ) )
		: (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tabella" );

	$soci = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $tabella $filtro ORDER BY cognome, nome LIMIT %d OFFSET %d",
			array_merge( $valori, array( $per_pag, ( $pagina - 1 ) * $per_pag ) )
		)
	);
	$senza_data = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tabella WHERE data_nascita IS NULL" );
	// phpcs:enable

	?>
	<div class="wrap">
		<h1>Soci</h1>
		<p>
			<strong><?php echo esc_html( $totale ); ?></strong> soci in archivio<?php
			if ( $senza_data ) {
				printf( ', di cui <strong>%d</strong> senza data di nascita (non ricevono gli auguri)', (int) $senza_data );
			}
			?>.
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=rcm-soci-import' ) ); ?>">Importa da CSV</a>
		</p>

		<form method="get" style="margin-bottom:1em">
			<input type="hidden" name="page" value="rcm-soci">
			<p class="search-box">
				<input type="search" name="s" value="<?php echo esc_attr( $cerca ); ?>" placeholder="Cerca nome o email">
				<?php submit_button( 'Cerca', '', '', false ); ?>
			</p>
		</form>

		<table class="widefat striped">
			<thead><tr><th>Cognome</th><th>Nome</th><th>Email</th><th>Data di nascita</th><th>Ultimi auguri</th><th></th></tr></thead>
			<tbody>
			<?php if ( ! $soci ) : ?>
				<tr><td colspan="6">Nessun socio. Comincia importando il CSV.</td></tr>
			<?php endif; ?>
			<?php foreach ( $soci as $socio ) : ?>
				<tr>
					<td><?php echo esc_html( $socio->cognome ); ?></td>
					<td><?php echo esc_html( $socio->nome ); ?></td>
					<td><?php echo esc_html( $socio->email ); ?></td>
					<td><?php echo $socio->data_nascita ? esc_html( mysql2date( 'd/m/Y', $socio->data_nascita ) ) : '<em>—</em>'; ?></td>
					<td><?php echo $socio->ultimo_invio_anno ? esc_html( $socio->ultimo_invio_anno ) : '—'; ?></td>
					<td>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rcm-soci&elimina=' . $socio->id ), 'rcm_elimina_' . $socio->id ) ); ?>"
						   onclick="return confirm('Eliminare <?php echo esc_js( $socio->email ); ?>?')">Elimina</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$pagine = (int) ceil( $totale / $per_pag );
		if ( $pagine > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $pagina,
						'total'   => $pagine,
					)
				)
			);
			echo '</div></div>';
		}
		?>

		<h2>Aggiungi un socio</h2>
		<form method="post">
			<?php wp_nonce_field( 'rcm_socio' ); ?>
			<input type="hidden" name="rcm_azione" value="aggiungi">
			<table class="form-table">
				<tr><th><label for="rcm-nome">Nome</label></th><td><input id="rcm-nome" name="nome" class="regular-text"></td></tr>
				<tr><th><label for="rcm-cognome">Cognome</label></th><td><input id="rcm-cognome" name="cognome" class="regular-text"></td></tr>
				<tr><th><label for="rcm-email">Email</label></th><td><input id="rcm-email" name="email" type="email" class="regular-text" required></td></tr>
				<tr><th><label for="rcm-data">Data di nascita</label></th><td><input id="rcm-data" name="data_nascita" placeholder="gg/mm/aaaa" class="regular-text"></td></tr>
			</table>
			<?php submit_button( 'Aggiungi socio' ); ?>
		</form>
	</div>
	<?php
}

/**
 * Import del CSV.
 */
function rcm_compleanni_pagina_import() {
	if ( isset( $_POST['rcm_azione'] ) && 'importa' === $_POST['rcm_azione'] && check_admin_referer( 'rcm_import' ) ) {
		if ( empty( $_FILES['csv']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['csv']['error']
			|| ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			rcm_compleanni_avviso( 'Nessun file caricato (o file troppo grande).', 'error' );
		} else {
			$esito = rcm_compleanni_importa( $_FILES['csv']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			if ( is_wp_error( $esito ) ) {
				rcm_compleanni_avviso( esc_html( $esito->get_error_message() ), 'error' );
			} else {
				rcm_compleanni_avviso(
					sprintf(
						'Import completato: <strong>%d</strong> nuovi, <strong>%d</strong> aggiornati, <strong>%d</strong> saltati.',
						$esito['nuovi'],
						$esito['aggiornati'],
						$esito['saltati']
					)
				);
				foreach ( $esito['avvisi'] as $avviso ) {
					rcm_compleanni_avviso( esc_html( $avviso ), 'warning' );
				}
			}
		}
	}
	?>
	<div class="wrap">
		<h1>Importa soci da CSV</h1>
		<p>Il file deve avere <strong>la prima riga con i nomi delle colonne</strong>. Vengono riconosciute le colonne
			<code>nome</code>, <code>cognome</code>, <code>email</code>, <code>data di nascita</code>
			(separatore virgola o punto e virgola, date in <code>gg/mm/aaaa</code> o <code>aaaa-mm-gg</code>).</p>
		<p>I soci già presenti vengono <strong>aggiornati</strong> in base all'email, non duplicati. Le celle vuote non
			cancellano i dati già in archivio.</p>
		<pre style="background:#fff;border:1px solid #ccd0d4;padding:1em;display:inline-block">nome;cognome;email;data di nascita
Mario;Rossi;mario.rossi@example.it;24/03/1978
Anna;Bianchi;anna.bianchi@example.it;02/11/1985</pre>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'rcm_import' ); ?>
			<input type="hidden" name="rcm_azione" value="importa">
			<p><input type="file" name="csv" accept=".csv,text/csv,text/plain" required></p>
			<?php submit_button( 'Importa' ); ?>
		</form>
	</div>
	<?php
}

/**
 * Impostazioni degli auguri, prova di invio e prossimi compleanni.
 */
function rcm_compleanni_pagina_auguri() {
	global $wpdb;
	$opzioni = rcm_compleanni_opzioni();

	if ( isset( $_POST['rcm_azione'] ) && check_admin_referer( 'rcm_auguri' ) ) {
		$azione = sanitize_text_field( wp_unslash( $_POST['rcm_azione'] ) );

		if ( 'salva' === $azione ) {
			$ora       = sanitize_text_field( wp_unslash( $_POST['ora'] ?? '08:00' ) );
			$ora_prima = $opzioni['ora'];
			$opzioni   = array(
				'attivo'    => empty( $_POST['attivo'] ) ? 0 : 1,
				'ora'       => preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $ora ) ? $ora : '08:00',
				'oggetto'   => sanitize_text_field( wp_unslash( $_POST['oggetto'] ?? '' ) ),
				'messaggio' => sanitize_textarea_field( wp_unslash( $_POST['messaggio'] ?? '' ) ),
				'copia_a'   => sanitize_email( wp_unslash( $_POST['copia_a'] ?? '' ) ),
			);
			update_option( RCM_COMPLEANNI_OPZIONI, $opzioni );
			rcm_compleanni_pianifica( $ora_prima !== $opzioni['ora'] );
			rcm_compleanni_avviso( 'Impostazioni salvate.' );
		}

		if ( 'prova' === $azione ) {
			$destinatario = sanitize_email( wp_unslash( $_POST['prova_email'] ?? '' ) );
			if ( ! is_email( $destinatario ) ) {
				rcm_compleanni_avviso( 'Indirizzo di prova non valido.', 'error' );
			} else {
				$finto = (object) array(
					'id'           => 0,
					'nome'         => 'Mario',
					'cognome'      => 'Rossi',
					'email'        => $destinatario,
					'data_nascita' => '1978-' . current_time( 'm-d' ),
				);
				$esito = rcm_compleanni_invia( $finto, false );
				rcm_compleanni_avviso(
					$esito ? 'Email di prova inviata a ' . esc_html( $destinatario ) . '.' : 'Invio fallito: controlla WP Mail SMTP.',
					$esito ? 'success' : 'error'
				);
			}
		}
	}

	$tabella = rcm_compleanni_tabella();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$prossimi = $wpdb->get_results(
		"SELECT nome, cognome, email, data_nascita,
		        DATE_FORMAT( data_nascita, '%d/%m' ) AS giorno,
		        ( DAYOFYEAR( data_nascita ) - DAYOFYEAR( CURDATE() ) + 366 ) % 366 AS mancano
		 FROM $tabella
		 WHERE attivo = 1 AND data_nascita IS NOT NULL
		 HAVING mancano <= 30
		 ORDER BY mancano
		 LIMIT 25"
	);
	// phpcs:enable

	$ultimo   = get_option( 'rcm_compleanni_ultimo_giro' );
	$prossimo = wp_next_scheduled( RCM_COMPLEANNI_HOOK );
	?>
	<div class="wrap">
		<h1>Auguri di compleanno</h1>

		<?php if ( empty( $opzioni['attivo'] ) ) : ?>
			<div class="notice notice-warning"><p>L'invio automatico è <strong>spento</strong>: controlla l'elenco soci, fai una prova e poi accendilo qui sotto.</p></div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'rcm_auguri' ); ?>
			<input type="hidden" name="rcm_azione" value="salva">
			<table class="form-table">
				<tr>
					<th scope="row">Invio automatico</th>
					<td>
						<label><input type="checkbox" name="attivo" value="1" <?php checked( $opzioni['attivo'], 1 ); ?>> manda gli auguri ogni giorno</label>
						<p class="description">
							<?php if ( $prossimo ) : ?>
								Prossimo controllo: <strong><?php echo esc_html( wp_date( 'd/m/Y H:i', $prossimo ) ); ?></strong>.
							<?php endif; ?>
							<?php if ( $ultimo ) : ?>
								Ultimo giro: <?php echo esc_html( mysql2date( 'd/m/Y H:i', $ultimo['quando'] ) ); ?>
								— <?php echo (int) $ultimo['inviate']; ?> inviate, <?php echo (int) $ultimo['errori']; ?> errori.
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rcm-ora">Ora di invio</label></th>
					<td><input id="rcm-ora" name="ora" type="time" value="<?php echo esc_attr( $opzioni['ora'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="rcm-oggetto">Oggetto</label></th>
					<td><input id="rcm-oggetto" name="oggetto" class="large-text" value="<?php echo esc_attr( $opzioni['oggetto'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="rcm-messaggio">Messaggio</label></th>
					<td>
						<textarea id="rcm-messaggio" name="messaggio" rows="10" class="large-text"><?php echo esc_textarea( $opzioni['messaggio'] ); ?></textarea>
						<p class="description">Segnaposto: <code>{nome}</code> <code>{cognome}</code> <code>{eta}</code> <code>{anno_nascita}</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rcm-copia">Copia nascosta a</label></th>
					<td>
						<input id="rcm-copia" name="copia_a" type="email" class="regular-text" value="<?php echo esc_attr( $opzioni['copia_a'] ); ?>">
						<p class="description">Facoltativo: riceve in Ccn una copia di ogni messaggio inviato.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Salva impostazioni' ); ?>
		</form>

		<h2>Prova di invio</h2>
		<form method="post">
			<?php wp_nonce_field( 'rcm_auguri' ); ?>
			<input type="hidden" name="rcm_azione" value="prova">
			<p>
				<input name="prova_email" type="email" class="regular-text" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
				<?php submit_button( 'Manda una prova', 'secondary', '', false ); ?>
			</p>
			<p class="description">Manda il messaggio con dati finti (Mario Rossi) senza toccare l'archivio soci.</p>
		</form>

		<h2>Prossimi compleanni (30 giorni)</h2>
		<table class="widefat striped">
			<thead><tr><th>Giorno</th><th>Socio</th><th>Email</th><th>Mancano</th></tr></thead>
			<tbody>
			<?php if ( ! $prossimi ) : ?>
				<tr><td colspan="4">Nessun compleanno nei prossimi 30 giorni.</td></tr>
			<?php endif; ?>
			<?php foreach ( $prossimi as $socio ) : ?>
				<tr>
					<td><?php echo esc_html( $socio->giorno ); ?></td>
					<td><?php echo esc_html( trim( $socio->nome . ' ' . $socio->cognome ) ); ?></td>
					<td><?php echo esc_html( $socio->email ); ?></td>
					<td><?php echo 0 === (int) $socio->mancano ? '<strong>oggi</strong>' : esc_html( $socio->mancano . ' giorni' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
