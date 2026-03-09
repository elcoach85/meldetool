<?php
// Taxonomien und Terms bei Plugin-Aktivierung mit Pods anlegen
register_activation_hook(__FILE__, function() {
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
    );
    foreach ($kategorien as $kategorie) {
        if (!term_exists($kategorie, 'kategorie')) {
            wp_insert_term($kategorie, 'kategorie');
        }
    }

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
    );
    foreach ($rennklassen as $rennklasse) {
        if (!term_exists($rennklasse, 'rennklasse')) {
            wp_insert_term($rennklasse, 'rennklasse');
        }
    }

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
            ),
        ));
        if (is_wp_error($res)) {
            $errors = array_merge($errors, $res->get_error_messages());
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
                    'data' => array(
                        'DEU' => 'Deutschland',
                        'FRA' => 'Frankreich',
                        'GRC' => 'Griechenland',
                        'CHE' => 'Schweiz',
                        'AUT' => 'Österreich',
                        'CZE' => 'Tschechische Republik',
                        'LUX' => 'Luxemburg',
                        'BEL' => 'Belgien',
                        'NLD' => 'Niederlande',
                        'ITA' => 'Italien',
                        'AUS' => 'Australien',
                        'NOR' => 'Norwegen',
                        'USA' => 'Vereinigte Staaten von Amerika',
                        'ZAF' => 'Südafrika',
                    ), 'allow_other' => true, 'required' => true),
                array('name' => 'iban', 'label' => 'IBAN (nur Einzelstarter)', 'type' => 'text'),
                array('name' => 'bic', 'label' => 'BIC (nur Einzelstarter)', 'type' => 'text'),
                array('name' => 'kontoinhaber', 'label' => 'Kontoinhaber (nur Einzelstarter)', 'type' => 'text'),
            ),
        ));
        if (is_wp_error($res)) {
            $errors = array_merge($errors, $res->get_error_messages());
        }
    }
    }

    // Verbindung Taxonomie 'rennklasse' mit Post Type 'team' sicherstellen
    //register_taxonomy_for_object_type('rennklasse', 'team');
    // Verbindung Taxonomie 'kategorie' mit Post Type 'fahrer' sicherstellen
    //register_taxonomy_for_object_type('kategorie', 'fahrer');
    
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

    // Hinweis für Administratoren setzen: manuelle Verknüpfung in Pods prüfen
    set_transient('meldetool_show_pod_connections_notice', 1, 60);
    if (!empty($errors)) {
        set_transient('meldetool_activation_errors', $errors, 60);
    }
    // Meldetool-Optionen mit Defaults anlegen, falls nicht vorhanden
    if (!get_option('meldetool_options')) {
        $defaults = array(
            'send_confirmation' => 1,
            'from_email' => '',
            'reply_to' => '',
            'confirmation_subject' => 'Bestätigung: Team-Anmeldung erhalten',
            'confirmation_message' => "Hallo\n\nIhr Team '{teamname}' wurde erfolgreich für die Race Days Stuttgart angemeldet.\n\nFalls Änderungen nötig sind, können Sie sich bei uns melden.\n\nMit freundlichen Grüßen\nIhr Race-Days-Team"
        );
        add_option('meldetool_options', $defaults);
    }
});

// Beim Admin-Login nach Aktivierung Hinweis anzeigen, dass Pods-Verbindungen manuell geprüft werden sollen
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    if (!get_transient('meldetool_show_pod_connections_notice')) return;

    // Entfernen, damit der Hinweis nur einmal angezeigt wird
    delete_transient('meldetool_show_pod_connections_notice');

    echo '<div class="notice notice-info is-dismissible"><p><strong>Meldetool:</strong> Bitte in Pods → rennklasse → Verbindungen den Eintrag "team" anhaken und in Pods → kategorie → Verbindungen den Eintrag "fahrer" anhaken. Zusätzliche Pods → Team → Feld "Rennklasse" → Relationship-Optionen → Sync anhaken. Selbes mit Fahrer → Feld "Kategorie" → Relationship-Optionen → Sync anhaken. Danach ggf. Pods-Cache leeren.</p></div>';
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
register_uninstall_hook(__FILE__, 'meldetool_uninstall');

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


