<?php
/**
 * Meldetool REST API Security
 * 
 * Blockiert sensitive Meta-Felder in REST API Responses.
 * Diese Datei wird von meldetool.php geladen und verhindert, dass Bankdaten
 * über die WordPress REST API (auch unauthentifiziert) ausgelesen werden können.
 */

defined( 'ABSPATH' ) or die( 'Are you ok?' );

/**
 * REST API Filter: Blockiere sensitive Felder in Team Responses
 * 
 * Verhindert, dass IBAN, BIC, Kontoinhaber und E-Mails über REST ausgelesen werden.
 */
add_filter('rest_prepare_team', function($response, $post) {
    if (isset($response->data['meta']) && is_array($response->data['meta'])) {
        $sensitive = array('iban', 'bic', 'kontoinhaber', 'email_manager');
        foreach ($sensitive as $key) {
            unset($response->data['meta'][$key]);
        }
    }
    return $response;
}, 10, 2);

/**
 * REST API Filter: Blockiere sensitive Felder in Fahrer/Rider Responses
 */
add_filter('rest_prepare_fahrer', function($response, $post) {
    if (isset($response->data['meta']) && is_array($response->data['meta'])) {
        $sensitive = array('iban', 'bic', 'kontoinhaber', 'email_rider');
        foreach ($sensitive as $key) {
            unset($response->data['meta'][$key]);
        }
    }
    return $response;
}, 10, 2);
