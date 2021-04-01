<?php

/*
Plugin Name: Keyring Facebook Importer
Plugin URI: https://github.com/wayubi/Keyring-Facebook-Importer
Description: Imports your data from Facebook.
Version: 2.0.0
Author: Christopher Finke
Author URI: https://github.com/cfinke
Author: W. Latif Ayubi
Author URI: https://github.com/wayubi
License: GPL2
Depends: Keyring, Keyring Social Importers
*/

function keyring_facebook_enable_importer( $importers ) {
	$importers[] = plugin_dir_path( __FILE__ ) . 'keyring-facebook-importer/keyring-importer-facebook.php';
	return $importers;
}

add_filter( 'keyring_importers', 'keyring_facebook_enable_importer' );
