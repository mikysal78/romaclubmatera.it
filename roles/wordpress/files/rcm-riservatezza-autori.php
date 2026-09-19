<?php
/**
 * Plugin Name: RCM - Riservatezza degli autori
 * Description: Non far sapere a tutti i nomi utente degli amministratori. L'API /wp/v2/users e' riservata a chi gestisce gli utenti, oEmbed non dice l'autore, e i link alle pagine autore (che Yoast gia' rimanda alla home) portano alla home, cosi' lo slug non compare piu' ne' nel tema ne' nei dati strutturati.
 * Version: 1.0.0
 * Author: Roma Club Matera
 *
 * Perche': nel controllo di sicurezza del 19/09/2026 l'API pubblica elencava
 * gli autori, e lo slug di uno (paola) coincide con il suo nome utente di
 * amministratore; lo stesso slug usciva in ogni articolo come /author/paola.
 * A chi prova a indovinare le password, meta' delle credenziali era gratis.
 * Il nome visualizzato ("Paola") resta: e' la firma degli articoli.
 */

defined( 'ABSPATH' ) || exit;

// L'elenco e il dettaglio degli utenti via REST: solo per chi li gestisce.
add_filter(
	'rest_endpoints',
	function ( $endpoint ) {
		if ( current_user_can( 'list_users' ) ) {
			return $endpoint;
		}
		foreach ( array_keys( $endpoint ) as $rotta ) {
			if ( preg_match( '#^/wp/v2/users(?:/|$)#', $rotta ) ) {
				unset( $endpoint[ $rotta ] );
			}
		}
		return $endpoint;
	}
);

// oEmbed: niente nome ne' link dell'autore.
add_filter(
	'oembed_response_data',
	function ( $dati ) {
		unset( $dati['author_name'], $dati['author_url'] );
		return $dati;
	}
);

// Ogni link a una pagina autore porta alla home (le pagine autore sono gia'
// rimandate li' da Yoast): lo slug non esce piu' da tema e dati strutturati.
add_filter(
	'author_link',
	function () {
		return home_url( '/' );
	}
);
