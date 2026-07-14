<?php
/**
 * Plugin Name: RCM Image Sizes
 * Description: Registra il formato 16:9 usato dalla gallery "Le trasferte dei tifosi".
 */

add_action( 'after_setup_theme', function () {
    add_image_size( 'rcm_gallery_16_9', 800, 450, true );
} );

add_filter( 'image_size_names_choose', function ( $sizes ) {
    $sizes['rcm_gallery_16_9'] = 'Gallery 16:9 (800x450)';
    return $sizes;
} );
