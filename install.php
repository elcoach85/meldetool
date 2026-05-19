<?php

$meldetool_main_file = MELDETOOL_PLUGIN_DIR . 'meldetool.php';

/**
 * Zielzustand der Feldoptionen fuer etappen_auswahl.
 *
 * U17-Werte bleiben unveraendert; Hobby-Werte werden zusaetzlich erlaubt,
 * damit bestehende Installationen die neuen Werte serverseitig akzeptieren.
 *
 * @param array|null $configured_lists Optional: Listen im Format array('u17' => array(...), 'hobby' => array(...))
 * @return array
 */
function meldetool_get_all_etappen_pick_data($configured_lists = null) {
    $default_lists = array(
        'u17' => array('Etappe 1', 'Etappe 2-4', 'Etappe 1-4'),
        'hobby' => array('Solitude', 'Magstadt', 'Solitude & Magstadt'),
    );

    $lists = is_array($configured_lists) ? $configured_lists : null;
    if ($lists === null && function_exists('meldetool_get_configured_etappen_lists')) {
        $lists = meldetool_get_configured_etappen_lists();
    }
    if (!is_array($lists)) {
        $lists = $default_lists;
    }

    $combined_values = array_merge(
        isset($lists['u17']) && is_array($lists['u17']) ? $lists['u17'] : $default_lists['u17'],
        isset($lists['hobby']) && is_array($lists['hobby']) ? $lists['hobby'] : $default_lists['hobby']
    );

    $pick_data = array();
    $pick_data[''] = '-- Auswählen --';
    foreach ($combined_values as $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $pick_data[$value] = $value;
    }

    return $pick_data;
}

/**
 * Standardwerte fuer Nationalitaeten im Fahrer-Formular (Code => Bezeichnung).
 *
 * @return array
 */
function meldetool_default_nationality_pick_data() {
    return array(
        'DEU' => 'Deutschland',
        'FRA' => 'Frankreich',
        'GRC' => 'Griechenland',
        'CHE' => 'Schweiz',
        'AUT' => 'Oesterreich',
        'CZE' => 'Tschechische Republik',
        'LUX' => 'Luxemburg',
        'BEL' => 'Belgien',
        'NLD' => 'Niederlande',
        'ITA' => 'Italien',
        'AUS' => 'Australien',
        'NOR' => 'Norwegen',
        'USA' => 'Vereinigte Staaten von Amerika',
        'ZAF' => 'Suedafrika',
        'COL' => 'Kolumbien',
    );
}

/**
 * Konvertiert Pick-Daten (Code => Label) in das gespeicherte Zeilenformat CODE=Label.
 *
 * @param array $pick_data
 * @return string
 */
function meldetool_nationality_pick_data_to_option_text($pick_data) {
    $lines = array();
    foreach ((array) $pick_data as $code => $label) {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $code));
        if (strlen($code) < 2) {
            continue;
        }
        $label = sanitize_text_field((string) $label);
        if ($label === '') {
            $label = $code;
        }
        $lines[] = $code . '=' . $label;
    }

    return implode("\n", $lines);
}

/**
 * Normalisiert freie Nationalitaets-Eingaben auf ein Pick-Data-Array (Code => Label).
 *
 * Erlaubte Formate pro Zeile:
 * - CODE=Label
 * - CODE|Label
 * - CODE;Label
 * - CODE (Label entspricht dann dem Code)
 *
 * @param string|array $raw_value
 * @param array $fallback
 * @return array
 */
function meldetool_normalize_nationality_pick_data($raw_value, $fallback) {
    $lines = is_array($raw_value)
        ? $raw_value
        : preg_split('/\r\n|\r|\n/', (string) $raw_value);

    $pick_data = array();
    foreach ((array) $lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        $separator_pos = false;
        foreach (array('=', '|', ';') as $separator) {
            $separator_pos = strpos($line, $separator);
            if ($separator_pos !== false) {
                break;
            }
        }

        if ($separator_pos !== false) {
            $code_part = substr($line, 0, $separator_pos);
            $label_part = substr($line, $separator_pos + 1);
        } else {
            $code_part = $line;
            $label_part = '';
        }

        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $code_part));
        if (strlen($code) < 2) {
            continue;
        }

        $label = sanitize_text_field(trim((string) $label_part));
        if ($label === '') {
            $label = $code;
        }

        if (!isset($pick_data[$code])) {
            $pick_data[$code] = $label;
        }
    }

    if (empty($pick_data)) {
        return (array) $fallback;
    }

    return $pick_data;
}

/**
 * Liefert die in den Einstellungen konfigurierten Nationalitaetscodes als Pick-Daten.
 *
 * @param array|null $source_options Optionales Optionen-Array statt get_option.
 * @return array
 */
function meldetool_get_configured_nationality_pick_data($source_options = null) {
    $defaults = meldetool_default_nationality_pick_data();
    $opts = is_array($source_options) ? $source_options : get_option('meldetool_options', array());
    $raw = isset($opts['nationality_codes']) ? $opts['nationality_codes'] : '';

    return meldetool_normalize_nationality_pick_data($raw, $defaults);
}

/**
 * Synchronisiert bei bestehenden Installationen die Feldoptionen von etappen_auswahl.
 *
 * @param array $errors Referenz auf Fehlerarray
 * @param array|null $target_data Optionales Ziel-Data-Array fuer Pods-Feld
 * @return bool true bei Erfolg oder wenn kein Update noetig war
 */
function meldetool_sync_existing_etappen_auswahl_field(&$errors, $target_data = null) {
    if (!function_exists('pods_api')) {
        return false;
    }

    $api = pods_api();
    if (!is_object($api) || !method_exists($api, 'save_field')) {
        $errors[] = 'Pods-API unterstuetzt save_field nicht; etappen_auswahl konnte nicht automatisch aktualisiert werden.';
        return false;
    }

    // load_field(['pod'=>...,'name'=>...]) schlaegt fehl, weil load_pod() intern ein Objekt
    // statt eines Arrays zurueckgibt. Daher: Feld-IDs direkt per DB-Query ermitteln.
    global $wpdb;
    $fahrer_pod_id = (int) $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = '_pods_pod' AND post_name = 'fahrer' AND post_status = 'publish' LIMIT 1"
    );
    $field_post_id = $fahrer_pod_id ? (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = '_pods_field' AND post_parent = %d AND post_name = %s AND post_status = 'publish' LIMIT 1",
            $fahrer_pod_id, 'etappen_auswahl'
        )
    ) : 0;

    if ($field_post_id < 1) {
        return false;
    }

    if (!is_array($target_data)) {
        $target_data = meldetool_get_all_etappen_pick_data();
    }

    $result = $api->save_field(array(
        'id'               => $field_post_id,
        'pod_id'           => $fahrer_pod_id,
        'name'             => 'etappen_auswahl',
        'type'             => 'pick',
        'data'             => $target_data,
        'pick_format_type' => 'single',
    ));
    if (is_wp_error($result)) {
        $errors[] = sprintf(
            'Feld etappen_auswahl konnte nicht aktualisiert werden: %s',
            implode('; ', $result->get_error_messages())
        );
        return false;
    }

    return true;
}

/**
 * Synchronisiert bei bestehenden Installationen die Feldoptionen von nationalitaet.
 *
 * @param array $errors Referenz auf Fehlerarray
 * @param array|null $target_data Optionales Ziel-Data-Array fuer Pods-Feld
 * @return bool true bei Erfolg oder wenn kein Update noetig war
 */
function meldetool_sync_existing_nationalitaet_field(&$errors, $target_data = null) {
    if (!function_exists('pods_api')) {
        return false;
    }

    $api = pods_api();
    if (!is_object($api) || !method_exists($api, 'save_field')) {
        $errors[] = 'Pods-API unterstuetzt save_field nicht; Nationalitaeten konnten nicht automatisch aktualisiert werden.';
        return false;
    }

    // load_field(['pod'=>...,'name'=>...]) schlaegt fehl, weil load_pod() intern ein Objekt
    // statt eines Arrays zurueckgibt. Daher: Feld-IDs direkt per DB-Query ermitteln.
    global $wpdb;
    $fahrer_pod_id = (int) $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = '_pods_pod' AND post_name = 'fahrer' AND post_status = 'publish' LIMIT 1"
    );
    $field_post_id = $fahrer_pod_id ? (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = '_pods_field' AND post_parent = %d AND post_name = %s AND post_status = 'publish' LIMIT 1",
            $fahrer_pod_id, 'nationalitaet'
        )
    ) : 0;

    if ($field_post_id < 1) {
        return false;
    }

    if (!is_array($target_data)) {
        $target_data = meldetool_get_configured_nationality_pick_data();
    }

    $result = $api->save_field(array(
        'id'                 => $field_post_id,
        'pod_id'             => $fahrer_pod_id,
        'name'               => 'nationalitaet',
        'type'               => 'pick',
        'data'               => $target_data,
        'pick_format_type'   => 'single',
        'pick_format_single' => 'dropdown',
        'allow_other'        => true,
    ));
    if (is_wp_error($result)) {
        $errors[] = sprintf(
            'Feld nationalitaet konnte nicht aktualisiert werden: %s',
            implode('; ', $result->get_error_messages())
        );
        return false;
    }

    return true;
}

/**
 * Setzt die Waehrungseinstellung der bezahlt-Felder in Team und Fahrer auf EUR.
 *
 * Pods speichert Feldoptionen intern; ein einfaches update_post_meta genuegt nicht.
 * Diese Funktion korrigiert bestehende Installationen via save_field().
 *
 * @return bool true wenn alle Felder erfolgreich aktualisiert wurden
 */
function meldetool_sync_currency_fields_to_eur() {
    if (!function_exists('pods_api')) {
        return false;
    }

    $api = pods_api();
    if (!is_object($api) || !method_exists($api, 'load_pod') || !method_exists($api, 'save_field')) {
        return false;
    }

    $eur_options = array(
        'currency_format_sign'      => 'euro',
        'currency_format_placement' => 'after_space',
        'number_decimals'           => 2,
        'number_format_type'        => 'i18n',
    );

    $success = true;

    foreach (array('team', 'fahrer') as $pod_name) {
        $pod = $api->load_pod(array('name' => $pod_name, 'type' => 'post_type'));
        if (empty($pod) || !is_array($pod) || empty($pod['fields'])) {
            continue;
        }

        foreach ($pod['fields'] as $field) {
            if (empty($field['name']) || $field['name'] !== 'bezahlt') {
                continue;
            }
            if (empty($field['id'])) {
                continue;
            }

            // Pruefen ob bereits EUR gesetzt ist
            $current_sign = isset($field['currency_format_sign']) ? (string) $field['currency_format_sign'] : '';
            if ($current_sign === 'euro') {
                continue;
            }

            foreach ($eur_options as $key => $val) {
                $field[$key] = $val;
            }

            $result = $api->save_field($field);
            if (is_wp_error($result)) {
                $success = false;
            }
        }
    }

    return $success;
}

/**
 * Legt fehlende Terms fuer eine Taxonomie an und sammelt Fehler.
 *
 * @param string $taxonomy Taxonomie-Slug
 * @param array $term_names Liste von Term-Namen
 * @param array $errors Referenz auf Fehlerarray
 */
function meldetool_ensure_terms($taxonomy, $term_names, &$errors) {
    if (!taxonomy_exists($taxonomy)) {
        $errors[] = 'Taxonomie nicht registriert: ' . $taxonomy;
        return;
    }

    foreach ((array) $term_names as $term_name) {
        if (term_exists($term_name, $taxonomy)) {
            continue;
        }

        $inserted = wp_insert_term($term_name, $taxonomy);
        if (is_wp_error($inserted)) {
            $errors[] = sprintf(
                'Term konnte nicht angelegt werden (%s / %s): %s',
                $taxonomy,
                $term_name,
                implode('; ', $inserted->get_error_messages())
            );
        }
    }
}

// Taxonomien und Terms bei Plugin-Aktivierung mit Pods anlegen
register_activation_hook($meldetool_main_file, function() {
    if (!function_exists('pods_api')) {
        // Pods ist nicht aktiv
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Das Plugin "Pods" muss aktiviert sein, damit das Meldetool funktioniert.');
    }

    $errors = array();

    // Kategorie-Taxonomie (Meta Storage)
    if (!pods_api()->load_pod(array('name' => 'kategorie', 'type' => 'taxonomy'))) {
        $res = pods_api()->save_pod(array(
            'name' => 'kategorie',
            'label' => 'Fahrerkategorien',
            'label_singular' => 'Fahrerkategorie',
            'type' => 'taxonomy',
            'public' => true,
            'show_ui' => true,
            'hierarchical' => false,
            'storage' => 'meta',
            'object_types' => array('fahrer'),
        ));
        if (is_wp_error($res)) {
            $errors = array_merge($errors, $res->get_error_messages());
        }
    }

    // Taxonomien im aktuellen Aktivierungs-Request sicher registrieren,
    // damit term_exists/wp_insert_term sofort funktionieren.
    if (!taxonomy_exists('kategorie')) {
        register_taxonomy('kategorie', array('fahrer'), array(
            'public' => true,
            'show_ui' => true,
            'hierarchical' => false,
            'rewrite' => false,
        ));
    }
        
    // Kategorie-Terms anlegen
    $kategorien = array(
        'Amateure',
        'Elite Amateure',
        'Frauen und Frauen Elite',
        'Jugend männlich U17',
        'Jugend weiblich U17',
        'Junioren U19',
        'Juniorinnen U19',
        'Männer U23',
        'Schüler U15',
        'Schülerinnen U15',
        'Hobby',
    );
    meldetool_ensure_terms('kategorie', $kategorien, $errors);

    // Rennklasse-Taxonomie (Meta Storage)
    if (!pods_api()->load_pod(array('name' => 'rennklasse', 'type' => 'taxonomy'))) {
        $res = pods_api()->save_pod(array(
            'name' => 'rennklasse',
            'label' => 'Rennklassen',
            'label_singular' => 'Rennklasse',
            'type' => 'taxonomy',
            'public' => true,
            'show_ui' => true,
            'hierarchical' => false,
            'storage' => 'meta',
            'object_types' => array('team'),
        ));
        if (is_wp_error($res)) {
            $errors = array_merge($errors, $res->get_error_messages());
        }
    }

    if (!taxonomy_exists('rennklasse')) {
        register_taxonomy('rennklasse', array('team'), array(
            'public' => true,
            'show_ui' => true,
            'hierarchical' => false,
            'rewrite' => false,
        ));
    }

    // Rennklassen-Terms anlegen
    $rennklassen = array(
        'Elite Amateure und Männer U23',
        'Frauen und Frauen Elite',
        'Jugend männlich U17',
        'Jugend weiblich U17',
        'Junioren U19',
        'Juniorinnen U19',
        'Schüler U15',
        'Schülerinnen U15',
        'Hobby',
    );
    meldetool_ensure_terms('rennklasse', $rennklassen, $errors);

    // Team Pod anlegen
    if (!pods_api()->load_pod(array('name' => 'team', 'type' => 'post_type'))) {
        $res = pods_api()->save_pod(array(
            'name' => 'team',
            'label' => 'Teams',
            'label_singular' => 'Team',
            'type' => 'post_type',
            'public' => true,
            'show_ui' => true,
            'hierarchical' => false,
            'storage' => 'meta',
            'fields' => array(
                array('name' => 'teamname', 'label' => 'Teamname', 'type' => 'text', 'required' => true),
                array('name' => 'team-rennklasse', 'label' => 'Rennklasse', 'type' => 'pick', 'pick_object' => 'taxonomy', 'pick_val' => 'rennklasse', 'options' => array('sync' => 1), 'required' => true),
                array('name' => 'teammanager', 'label' => 'Name Sportlicher Leiter*in/Teammanager*in', 'type' => 'text', 'required' => true),
                array('name' => 'email_manager', 'label' => 'E-Mail Teammanager*in', 'type' => 'email', 'required' => true),
                array('name' => 'iban', 'label' => 'IBAN (für Preisgelder)', 'type' => 'text'),
                array('name' => 'bic', 'label' => 'BIC (für Preisgelder)', 'type' => 'text'),
                array('name' => 'kontoinhaber', 'label' => 'Kontoinhaber (für Preisgelder)', 'type' => 'text'),
                array(
                    'name'                      => 'bezahlt',
                    'label'                    => 'Bezahlt (€)',
                    'type'                     => 'currency',
                    'required'                 => false,
                    'currency_format_sign'      => 'euro',
                    'currency_format_placement' => 'after_space',
                    'number_decimals'           => 2,
                    'number_format_type'        => 'i18n',
                ),
            ),
        ));
        if (is_wp_error($res)) {
            $errors = array_merge($errors, $res->get_error_messages());
        }
    }

    // Fahrer Pod anlegen
    if (!pods_api()->load_pod(array('name' => 'fahrer', 'type' => 'post_type'))) {
        $res = pods_api()->save_pod(array(
            'name' => 'fahrer',
            'label' => 'Fahrer*innen',
            'label_singular' => 'Fahrer*in',
            'type' => 'post_type',
            'public' => true,
            'show_ui' => true,
            'hierarchical' => false,
            'storage' => 'meta',
            'fields' => array(
                array('name' => 'nachname', 'label' => 'Nachname', 'type' => 'text', 'required' => true),
                array('name' => 'vorname', 'label' => 'Vorname', 'type' => 'text', 'required' => true),
                array('name' => 'team', 'label' => 'Team', 'type' => 'pick', 'pick_object' => 'post_type', 'pick_val' => 'team', 'required' => true),
                array('name' => 'fahrer-kategorie', 'label' => 'Kategorie', 'type' => 'pick', 'pick_object' => 'taxonomy', 'pick_val' => 'kategorie', 'required' => true, 'options' => array('sync' => 1)),
                array('name' => 'lizenznummer', 'label' => 'Nationale Lizenznummer', 'type' => 'text', 'required' => true),
                array('name' => 'uci_id', 'label' => 'UCI-ID', 'type' => 'text', 'required' => true),
                array('name' => 'ist_kapitaen', 'label' => 'Fahrer*in ist Kapitän*in? (1x pro Team)', 'type' => 'boolean'),
                array('name' => 'email_rider', 'label' => 'E-Mail', 'type' => 'email', 'required' => true),
                array(
                    'name' => 'nationalitaet',
                    'label' => 'Nationalität',
                    'type' => 'pick',
                    'data' => meldetool_get_configured_nationality_pick_data(),
                    'allow_other' => true,
                    'required' => true,
                ),
                array('name' => 'iban', 'label' => 'IBAN (nur Einzelstarter)', 'type' => 'text'),
                array('name' => 'bic', 'label' => 'BIC (nur Einzelstarter)', 'type' => 'text'),
                array('name' => 'kontoinhaber', 'label' => 'Kontoinhaber (nur Einzelstarter)', 'type' => 'text'),
                array(
                    'name'             => 'etappen_auswahl',
                    'label'            => 'Etappenauswahl',
                    'type'             => 'pick',
                    'pick_format_type' => 'single',
                    'data'             => meldetool_get_all_etappen_pick_data(),
                    'required'         => false,
                ),
                array(
                    'name'                      => 'bezahlt',
                    'label'                    => 'Bezahlt (€)',
                    'type'                     => 'currency',
                    'required'                 => false,
                    'currency_format_sign'      => 'euro',
                    'currency_format_placement' => 'after_space',
                    'number_decimals'           => 2,
                    'number_format_type'        => 'i18n',
                ),
            ),
        ));
        if (is_wp_error($res)) {
            $errors = array_merge($errors, $res->get_error_messages());
        }
    }

    // Pods-Sicherheitseinstellung fuer anonyme Frontend-Formulare:
    // Session-Schutz aktivieren, damit Pods die Formulare fuer ausgeloggte Nutzer rendern kann.
    if (function_exists('pods_update_setting')) {
        pods_update_setting('session_auto_start', '1');
    }

    // Verbindung Taxonomien mit Post Types sicherstellen
    register_taxonomy_for_object_type('rennklasse', 'team');
    register_taxonomy_for_object_type('kategorie', 'fahrer');
    
    register_post_type('team', ['taxonomies' => ['rennklasse']]);
    register_post_type('fahrer', ['taxonomies' => ['kategorie']]);

    /*
    $res = pods_api()->save_pod(['name'=>'rennklasse','object_types'=>['team']]);
    if (is_wp_error($res)) {
        $errors = array_merge($errors, $res->get_error_messages());
    }
    $res = pods_api()->save_pod(['name'=>'kategorie','object_types'=>['fahrer']]);
    if (is_wp_error($res)) {
        $errors = array_merge($errors, $res->get_error_messages());
    }*/

    if (meldetool_sync_existing_etappen_auswahl_field($errors)) {
        update_option('meldetool_etappen_field_sync_version', '2026-05-etappen-v3', false);
        set_transient('meldetool_etappen_field_sync_success', 1, 60);
    }
    if (meldetool_sync_existing_nationalitaet_field($errors)) {
        update_option('meldetool_nationality_sync_version', '2026-05-nationality-v2', false);
    }

    // Hinweis für Administratoren setzen: manuelle Verknüpfung in Pods prüfen
    set_transient('meldetool_show_pod_connections_notice', 1, 60);
    if (!empty($errors)) {
        set_transient('meldetool_activation_errors', $errors, 60);
    }
    // Meldetool-Optionen mit Defaults anlegen, falls nicht vorhanden
    if (!get_option('meldetool_options')) {
        $mail_defaults = function_exists('meldetool_default_mail_texts')
            ? meldetool_default_mail_texts()
            : array(
                'confirmation_subject' => '[Meldetool] Standard-Bestaetigung',
                'confirmation_message' => "Hallo {teammanager},\n\ndies ist eine Standard-Nachricht aus dem Meldetool.\n\nTeam: {teamname}\n\nBitte passen Sie diesen Text in den Einstellungen an.",
                'confirmation_subject_publish' => '[Meldetool] Standard-Veroeffentlichung',
                'confirmation_message_publish' => "Hallo {teammanager},\n\ndies ist eine Standard-Nachricht zur Veroeffentlichung.\n\nTeam: {teamname}\n\nBitte passen Sie diesen Text in den Einstellungen an.",
                'rider_confirmation_subject' => '[Meldetool] Standard-Fahrerbestaetigung',
                'rider_confirmation_message' => "Hallo {ridername},\n\ndies ist eine Standard-Nachricht zur Fahrerbestaetigung.\n\nTeam: {teamname}\nLink: {confirm_url}\n\nBitte passen Sie diesen Text in den Einstellungen an.",
                'rider_details_subject' => '[Meldetool] Standard-Fahrerdetails',
                'rider_details_message' => "Hallo {ridername},\n\ndies ist eine Standard-Nachricht zu Fahrerdetails.\n\nTeam: {teamname}\n\n{riderdetails}\n\nBitte passen Sie diesen Text in den Einstellungen an.",
            );

        $defaults = array(
            'send_confirmation' => 1,
            'enable_logging' => 0,  // SICHERHEIT: Logging standardmäßig DEAKTIVIERT. Nur für Debugging aktivieren!
            'from_email' => '',
            'reply_to' => '',
            'cc_email' => '',
            'etappen_options_u17' => "Etappe 1\nEtappe 2-4\nEtappe 1-4",
            'etappen_options_hobby' => "Solitude\nMagstadt\nSolitude & Magstadt",
            'nationality_codes' => meldetool_nationality_pick_data_to_option_text(meldetool_default_nationality_pick_data()),
            'confirmation_subject' => $mail_defaults['confirmation_subject'],
            'confirmation_message' => $mail_defaults['confirmation_message'],
            'confirmation_subject_publish' => $mail_defaults['confirmation_subject_publish'],
            'confirmation_message_publish' => $mail_defaults['confirmation_message_publish'],
            'rider_confirmation_subject' => $mail_defaults['rider_confirmation_subject'],
            'rider_confirmation_message' => $mail_defaults['rider_confirmation_message'],
            'rider_details_subject' => $mail_defaults['rider_details_subject'],
            'rider_details_message' => $mail_defaults['rider_details_message'],
        );
        add_option('meldetool_options', $defaults);
    }

    // Anmeldungs-Seite anlegen, falls noch nicht vorhanden
    $existing_page = get_page_by_path('anmeldung');
    if (!$existing_page) {
        $page_content  = "<!-- wp:heading -->\n";
        $page_content .= "<h2 class=\"wp-block-heading\">Anmeldung Teams</h2>\n";
        $page_content .= "<!-- /wp:heading -->\n\n";
        $page_content .= "<!-- wp:shortcode -->\n";
        $page_content .= "[pods-form name=\"team\" fields=\"teamname,team-rennklasse,teammanager,email_manager,iban,bic,kontoinhaber,bezahlt\" encrypted=\"0\" logged_in=\"false\"]\n";
        $page_content .= "<!-- /wp:shortcode -->\n\n";
        $page_content .= "<!-- wp:heading -->\n";
        $page_content .= "<h2 class=\"wp-block-heading\">Anmeldung Fahrer*innen</h2>\n";
        $page_content .= "<!-- /wp:heading -->\n\n";
        $page_content .= "<!-- wp:shortcode -->\n";
        $page_content .= "[pods-form name=\"fahrer\" fields=\"nachname,vorname,team,fahrer-kategorie,lizenznummer,uci_id,ist_kapitaen,email_rider,nationalitaet,iban,bic,kontoinhaber,etappen_auswahl\" encrypted=\"0\" logged_in=\"false\"]\n";
        $page_content .= "<!-- /wp:shortcode -->\n";

        wp_insert_post(array(
            'post_title'   => 'Anmeldung',
            'post_name'    => 'anmeldung',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => $page_content,
        ));
    }
});

// Einmalige Feldmigration fuer bestehende Installationen ohne Reaktivierung.
add_action('admin_init', function() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $errors = array();

    // Etappen-Feldmigration (unabhängig)
    $etappen_sync_version = '2026-05-etappen-v3';
    $etappen_current = (string) get_option('meldetool_etappen_field_sync_version', '');
    if ($etappen_current !== $etappen_sync_version) {
        if (meldetool_sync_existing_etappen_auswahl_field($errors)) {
            update_option('meldetool_etappen_field_sync_version', $etappen_sync_version, false);
            set_transient('meldetool_etappen_field_sync_success', 1, 60);
        }
    }

    // Nationalitäts-Feldmigration (unabhängig)
    $nationality_sync_version = '2026-05-nationality-v2';
    $nationality_current = (string) get_option('meldetool_nationality_sync_version', '');
    if ($nationality_current !== $nationality_sync_version) {
        if (meldetool_sync_existing_nationalitaet_field($errors)) {
            update_option('meldetool_nationality_sync_version', $nationality_sync_version, false);
        }
    }

    if (!empty($errors)) {
        set_transient('meldetool_activation_errors', $errors, 60);
    }
});

// Beim Admin-Login nach Aktivierung Hinweis anzeigen, dass Pods-Verbindungen manuell geprüft werden sollen
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    if (!get_transient('meldetool_show_pod_connections_notice')) return;

    // Entfernen, damit der Hinweis nur einmal angezeigt wird
    delete_transient('meldetool_show_pod_connections_notice');

    echo '<div class="notice notice-info is-dismissible"><p><strong>Meldetool:</strong> Bitte in Pods → rennklasse → Verbindungen den Eintrag "team" anhaken und in Pods → kategorie → Verbindungen den Eintrag "fahrer" anhaken.<br>Zusätzlich: Pods → Team → Feld "Rennklasse" → Relationship-Optionen → Sync anhaken. Selbes mit Fahrer → Feld "Kategorie" → Relationship-Optionen → Sync anhaken. Danach ggf. Pods-Cache leeren.</p></div>';
});

// Admin notice: Zeigt erfolgreiche Etappenfeld-Migration einmalig an.
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    if (!get_transient('meldetool_etappen_field_sync_success')) return;

    delete_transient('meldetool_etappen_field_sync_success');

    echo '<div class="notice notice-success is-dismissible"><p><strong>Meldetool:</strong> Die Etappenauswahl wurde erfolgreich auf die neuen Optionen (inkl. Hobby-Etappen) aktualisiert.</p></div>';
});

// Admin notice: Zeige Pods-Aktivierungsfehler (falls vorhanden)
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    $errors = get_transient('meldetool_activation_errors');
    if (!$errors) return;

    delete_transient('meldetool_activation_errors');

    echo '<div class="notice notice-error is-dismissible"><p><strong>Meldetool Aktivierungsfehler (Pods):</strong></p><ul>';
    foreach ($errors as $err) {
        echo '<li>' . esc_html($err) . '</li>';
    }
    echo '</ul><p>Bitte prüfen Sie Pods → Einstellungen und leeren Sie ggf. den Pods-Cache.</p></div>';
});

// Deinstallationsroutine: Nutzer fragen, ob Pods und Terms gelöscht werden sollen (UNTESTED!)
register_uninstall_hook($meldetool_main_file, 'meldetool_uninstall');

function meldetool_uninstall() {
    // Immer alle zugehörigen Pods und Terms löschen
    if (function_exists('pods_api')) {
        pods_api()->delete_pod(array('name' => 'kategorie', 'type' => 'taxonomy'));
        pods_api()->delete_pod(array('name' => 'rennklasse', 'type' => 'taxonomy'));
        pods_api()->delete_pod(array('name' => 'fahrer', 'type' => 'post_type'));
        pods_api()->delete_pod(array('name' => 'team', 'type' => 'post_type'));
    }
    // Terms löschen (falls Pods nicht alles entfernt)
    $taxonomies = array('kategorie', 'rennklasse');
    foreach ($taxonomies as $taxonomy) {
        $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
        if (!is_wp_error($terms) && is_array($terms)) {
            foreach ($terms as $term) {
                wp_delete_term($term->term_id, $taxonomy);
            }
        }
    }
}


