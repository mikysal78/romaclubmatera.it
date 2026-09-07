<?php
/**
 * Plugin Name: RCM - Dati strutturati del Club
 * Description: Completa il nodo Organization dei dati strutturati che Yoast mette in ogni pagina, aggiungendo indirizzo, telefono, email e anno di fondazione. Senza, ai motori di ricerca il Club risultava un nome e un logo senza un posto nel mondo.
 * Version: 1.0.0
 * Author: Roma Club Matera
 */

defined( 'ABSPATH' ) || exit;

/**
 * Yoast costruisce il grafo schema.org di ogni pagina e passa da qui il nodo
 * dell'organizzazione prima di stamparlo.
 *
 * Sul tipo si resta su Organization e basta. La tentazione era SportsClub, ma
 * in schema.org quello e' un *luogo dove si pratica sport*, e un club di tifosi
 * non lo e': dichiararlo sarebbe una parola in piu' e un'informazione sbagliata.
 * Quello che mancava davvero, e che ai motori serve, e' il resto: dove sta il
 * Club, come lo si chiama, da quando esiste.
 *
 * Vale la pena ricordarlo: per comparire nelle ricerche locali e nelle mappe
 * questi dati aiutano ma non bastano. Quella partita si gioca su un profilo
 * dell'attivita' su Google, che va aperto e verificato da chi ha in mano
 * l'indirizzo e il telefono del Club.
 *
 * @param array $dati Il nodo Organization montato da Yoast.
 * @return array
 */
add_filter( 'wpseo_schema_organization', 'rcm_schema_club' );
function rcm_schema_club( $dati ) {
	$dati['address'] = array(
		'@type'           => 'PostalAddress',
		'streetAddress'   => 'Via Lupo Protospata 62 bis',
		'postalCode'      => '75100',
		'addressLocality' => 'Matera',
		'addressRegion'   => 'MT',
		'addressCountry'  => 'IT',
	);

	$dati['telephone']    = '+393772814538';
	$dati['email']        = 'info@romaclubmatera.it';
	$dati['foundingDate'] = '2012';

	// Niente openingHoursSpecification: la sede apre in funzione delle partite
	// ("circa 30 minuti prima"), non su un orario fisso. Inventarne uno
	// significherebbe mandare qualcuno a trovare la porta chiusa.

	return $dati;
}
