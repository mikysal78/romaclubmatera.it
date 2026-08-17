<?php
/**
 * Plugin Name: RCM News Query
 * Description: Tiene gli articoli storici della categoria "Eventi" fuori dall'elenco /news/ e dal widget "Articoli recenti".
 *
 * Gli articoli storici (2012-2022) servono alla voce di menu "Eventi"
 * (/category/eventi/) e restano pubblicati e raggiungibili: qui li togliamo
 * solo dalla pagina degli articoli, che deve mostrare le news correnti.
 */

// Pagina degli articoli (/news/): solo le news correnti.
// I widget "Articoli recenti" sono blocchi core/latest-posts: li si filtra
// dalle loro impostazioni (categoria News), non da qui.
add_action( 'pre_get_posts', function ( $query ) {
    if ( is_admin() || ! $query->is_main_query() || ! $query->is_home() ) {
        return;
    }

    $eventi = get_category_by_slug( 'eventi' );
    if ( $eventi && ! is_wp_error( $eventi ) ) {
        $query->set( 'category__not_in', array( (int) $eventi->term_id ) );
    }
} );
