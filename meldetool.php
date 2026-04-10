<?php
/**
 * Plugin Name: Meldetool
 * Description: A solution to let team managers create their team and add participants to the teams.
 * Version: 1.0.1
 * Plugin URI: https://the-race-days-stuttgart.org
 * Author: Nino Häberlen
 * Author URI: https://the-race-days-stuttgart.org
 * Tested up to: 
 * Text Domain: meldetool
 * Requires Pluging: pods
 * License: GPLv2
 *
 */

defined( 'ABSPATH' ) or die( 'Are you ok?' );

defined( 'MELDETOOL_PLUGIN_DIR' ) || define( 'MELDETOOL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Schreibt strukturierte Debug-Eintraege in die bestehende Logdatei.
 *
 * Logging erfolgt nur, wenn es in den Meldetool-Einstellungen aktiviert ist.
 */
function meldetool_debug_log($event, $context = array()) {
    if (!function_exists('meldetool_is_logging_enabled') || !meldetool_is_logging_enabled()) {
        return;
    }

    $logfile = MELDETOOL_PLUGIN_DIR . 'mail_log.txt';
    $payload = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        $payload = '{}';
    }

    $line = date('Y-m-d H:i:s') . ' | ' . $event . ' | ' . $payload . "\n";
    file_put_contents($logfile, $line, FILE_APPEND);
}

/**
 * Session-Diagnose fuer anonyme Frontend-Formulare auf /anmeldung.
 *
 * Hilft beim Eingrenzen von Pods-Fehlern wie
 * "Anonymous form submissions are not compatible with sessions on this site".
 */
add_action('template_redirect', function() {
    if (!function_exists('meldetool_is_logging_enabled') || !meldetool_is_logging_enabled()) {
        return;
    }

    if (is_admin()) {
        return;
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    if (stripos($uri, '/anmeldung') === false) {
        return;
    }

    $uri_decoded = rawurldecode($uri);
    $contains_embedded_url = (
        stripos($uri, "'https:/") !== false
        || stripos($uri, '&apos;https:/') !== false
        || stripos($uri_decoded, "'https:/") !== false
        || stripos($uri_decoded, '&apos;https:/') !== false
    );

    $headers_file = '';
    $headers_line = 0;
    $headers_sent = headers_sent($headers_file, $headers_line);

    $save_path = function_exists('session_save_path') ? (string) session_save_path() : '';
    $is_tcp_path = (stripos($save_path, 'tcp://') === 0);
    $tmp_dir = function_exists('sys_get_temp_dir') ? (string) sys_get_temp_dir() : '';
    $session_status_value = function_exists('session_status') ? (int) session_status() : -1;
    $session_status_text = 'unknown';
    if (defined('PHP_SESSION_DISABLED') && $session_status_value === PHP_SESSION_DISABLED) {
        $session_status_text = 'disabled';
    } elseif (defined('PHP_SESSION_NONE') && $session_status_value === PHP_SESSION_NONE) {
        $session_status_text = 'none';
    } elseif (defined('PHP_SESSION_ACTIVE') && $session_status_value === PHP_SESSION_ACTIVE) {
        $session_status_text = 'active';
    }

    $diag = array(
        'uri' => $uri,
        'uri_decoded' => $uri_decoded,
        'method' => isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '',
        'is_logged_in' => is_user_logged_in() ? 1 : 0,
        'contains_embedded_url' => $contains_embedded_url ? 1 : 0,
        'http_referer' => isset($_SERVER['HTTP_REFERER']) ? (string) wp_unslash($_SERVER['HTTP_REFERER']) : '',
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '',
        'headers_sent' => $headers_sent ? 1 : 0,
        'headers_file' => $headers_sent ? wp_normalize_path((string) $headers_file) : '',
        'headers_line' => $headers_sent ? (int) $headers_line : 0,
        'session_status' => $session_status_value,
        'session_status_text' => $session_status_text,
        'php_version' => function_exists('phpversion') ? (string) phpversion() : '',
        'php_sapi' => function_exists('php_sapi_name') ? (string) php_sapi_name() : '',
        'loaded_ini' => function_exists('php_ini_loaded_file') ? (string) php_ini_loaded_file() : '',
        'open_basedir' => (string) ini_get('open_basedir'),
        'session_save_handler' => (string) ini_get('session.save_handler'),
        'session_cookie_secure' => (string) ini_get('session.cookie_secure'),
        'session_use_strict_mode' => (string) ini_get('session.use_strict_mode'),
        'session_save_path' => $save_path,
        'session_save_path_exists' => ($save_path !== '' && !$is_tcp_path) ? (file_exists($save_path) ? 1 : 0) : null,
        'session_save_path_writable' => ($save_path !== '' && !$is_tcp_path) ? (is_writable($save_path) ? 1 : 0) : null,
        'sys_temp_dir' => $tmp_dir,
        'sys_temp_dir_exists' => ($tmp_dir !== '') ? (file_exists($tmp_dir) ? 1 : 0) : null,
        'sys_temp_dir_writable' => ($tmp_dir !== '') ? (is_writable($tmp_dir) ? 1 : 0) : null,
        'pods_session_auto_start' => function_exists('pods_session_auto_start') ? pods_session_auto_start() : 'n/a',
        'pods_can_use_sessions_env' => function_exists('pods_can_use_sessions') ? (pods_can_use_sessions(true) ? 1 : 0) : null,
        'pods_session_id_empty' => function_exists('pods_session_id') ? ((pods_session_id() === '') ? 1 : 0) : null,
    );

    meldetool_debug_log('PODS_SESSION_DIAG', $diag);

    if ($contains_embedded_url) {
        meldetool_debug_log('REQUEST_URI_ANOMALY', array(
            'uri' => $uri,
            'uri_decoded' => $uri_decoded,
            'http_referer' => isset($_SERVER['HTTP_REFERER']) ? (string) wp_unslash($_SERVER['HTTP_REFERER']) : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '',
        ));
    }
}, 20);

/**
 * Erkennt die konkrete Pods-Fehlermeldung im gerenderten Seiteninhalt
 * und loggt einen Marker zur Korrelation mit Session-Diagnosewerten.
 */
add_filter('the_content', function($content) {
    if (!function_exists('meldetool_is_logging_enabled') || !meldetool_is_logging_enabled()) {
        return $content;
    }

    if (is_admin() || !is_string($content) || $content === '') {
        return $content;
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    if (stripos($uri, '/anmeldung') === false) {
        return $content;
    }

    if (
        stripos($content, 'Anonyme Formularübermittlungen') !== false
        || stripos($content, 'Anonymous form submissions are not compatible with sessions') !== false
    ) {
        meldetool_debug_log('PODS_FORM_RENDER_ERROR', array(
            'uri' => $uri,
            'is_logged_in' => is_user_logged_in() ? 1 : 0,
            'contains_pods_form_markup' => (stripos($content, 'pods-form') !== false) ? 1 : 0,
        ));
    }

    return $content;
}, 20);

// Verbindung Taxonomien mit Post Types bei jedem Laden sicherstellen
add_action('init', function() {
    register_taxonomy_for_object_type('kategorie', 'fahrer');
    register_taxonomy_for_object_type('rennklasse', 'team');
});

/**
 * Registriere Meta-Felder als nicht öffentlich (REST API / unauthentifiziert)
 * 
 * SICHERHEIT: Bankdaten (IBAN, BIC, Kontoinhaber) und private E-Mails
 * sollten nicht via REST API oder für unauthentifizierte Nutzer einsehbar sein.
 */
add_action('init', function() {
    // Team: Bankdaten und Manager-Email schützen
    $team_sensitive = array('iban', 'bic', 'kontoinhaber', 'email_manager');
    foreach ($team_sensitive as $field) {
        register_meta('post', $field, array(
            'type'           => 'string',
            'object_subtype' => 'team',
            'single'         => true,
            'show_in_rest'   => false,
        ));
    }
    // Fahrer: Email und Bankdaten schützen
    $rider_sensitive = array('iban', 'bic', 'kontoinhaber', 'email_rider');
    foreach ($rider_sensitive as $field) {
        register_meta('post', $field, array(
            'type'           => 'string',
            'object_subtype' => 'fahrer',
            'single'         => true,
            'show_in_rest'   => false,
        ));
    }
});
/**
 * Liefert IDs aller Teams, bei denen Lizenznummer optional ist
 * 
 * Diese Funktion identifiziert Teams mit "Hobby" im Namen.
 * Bei Hobby-Teams sind Lizenznummer und UCI-ID nicht erforderlich.
 * 
 * @return array Team-IDs für optionale Lizenzfelder
 */
function meldetool_get_license_optional_team_ids() {
    $team_ids = array();
    $teams = get_posts(array(
        'post_type' => 'team',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields' => 'ids',
    ));

    foreach ($teams as $team_id) {
        $title = (string) get_the_title((int) $team_id);
        if (stripos($title, 'Hobby') !== false) {
            $team_ids[] = (int) $team_id;
        }
    }

    return $team_ids;
}

/**
 * Liefert IDs aller Teams, bei denen IBAN/BIC-Felder sichtbar sind
 * 
 * Diese Funktion identifiziert Teams mit "Einzelstarter" im Namen.
 * Bei Einzelstarter-Teams müssen Bankdaten (IBAN, BIC, Kontoinhaber) angegeben werden.
 * 
 * @return array Team-IDs mit sichtbaren IBAN/BIC-Feldern
 */
function meldetool_get_iban_bic_visible_team_ids() {
    $team_ids = array();
    $teams = get_posts(array(
        'post_type' => 'team',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields' => 'ids',
    ));

    foreach ($teams as $team_id) {
        $title = (string) get_the_title((int) $team_id);
        if (stripos($title, 'Einzelstarter') !== false) {
            $team_ids[] = (int) $team_id;
        }
    }

    return $team_ids;
}

/**
 * Liefert IDs aller Teams, die einer U17-Rennklasse zugeordnet sind
 *
 * @return array Team-IDs mit Etappenauswahl-Pflicht
 */
function meldetool_get_u17_team_ids() {
    $u17_terms = get_terms(array(
        'taxonomy'   => 'rennklasse',
        'hide_empty' => false,
        'search'     => 'U17',
    ));
    $team_ids = array();

    if (!is_wp_error($u17_terms) && !empty($u17_terms)) {
        $u17_term_ids = wp_list_pluck($u17_terms, 'term_id');
        $teams = get_posts(array(
            'post_type'   => 'team',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
            'tax_query'   => array(
                array(
                    'taxonomy' => 'rennklasse',
                    'field'    => 'term_id',
                    'terms'    => $u17_term_ids,
                ),
            ),
        ));
        $team_ids = array_map('intval', (array) $teams);
    }

    // Fallback fuer bestehende Datensaetze: einige Teams tragen die U17-Info nur im Titel.
    $all_team_ids = get_posts(array(
        'post_type'   => 'team',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
    ));

    foreach ((array) $all_team_ids as $team_id) {
        $team_id = (int) $team_id;
        $title = (string) get_the_title($team_id);
        if (stripos($title, 'U17') !== false) {
            $team_ids[] = $team_id;
        }
    }

    return array_values(array_unique(array_map('intval', $team_ids)));
}











/**
 * Prüft eine IBAN per ISO-13616-Algorithmus (Modulo 97).
 * Gibt eine Fehlermeldung zurück oder null bei Gültigkeit.
 */
function meldetool_validate_iban($value) {
    $iban = strtoupper(preg_replace('/\s+/', '', (string) $value));
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$/', $iban)) {
        return 'Die IBAN ist ungültig. Bitte eine gültige IBAN eingeben.';
    }
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    for ($i = 0; $i < strlen($rearranged); $i++) {
        $c = $rearranged[$i];
        $numeric .= ctype_alpha($c) ? (string)(ord($c) - 55) : $c;
    }
    $remainder = 0;
    for ($i = 0; $i < strlen($numeric); $i++) {
        $remainder = ($remainder * 10 + (int)$numeric[$i]) % 97;
    }
    if ($remainder !== 1) {
        return 'Die IBAN ist ungültig. Bitte eine gültige IBAN eingeben.';
    }
    return null;
}

/**
 * UCI-ID Serverseite: Muss aus genau 11 Ziffern bestehen
 * Wird beim Speichern über das Pods-Formular geprüft.
 */
add_filter('pods_form_validate_field_fahrer', function($valid, $value, $name, $options, $pod, $id) {
    if ($name === 'uci_id' && !empty($value) && $value !== 'n/a') {
        if (!preg_match('/^\d{11}$/', (string) $value)) {
            return 'Die UCI-ID muss aus genau 11 Ziffern bestehen (nur Ziffern, keine Leerzeichen).';
        }
    }
    if ($name === 'iban' && !empty($value)) {
        $error = meldetool_validate_iban($value);
        if ($error !== null) return $error;
    }
    return $valid;
}, 10, 6);

add_filter('pods_form_validate_field_team', function($valid, $value, $name, $options, $pod, $id) {
    if ($name === 'iban' && !empty($value)) {
        $error = meldetool_validate_iban($value);
        if ($error !== null) return $error;
    }
    return $valid;
}, 10, 6);



/**
 * Synchronisiert den Fahrer-Post-Titel mit Nachname + Vorname.
 *
 * Wird sowohl von save_post_fahrer als auch direkt nach Pods-Save genutzt,
 * damit neue Fahrer sofort einen konsistenten Titel erhalten.
 *
 * @param int $post_id WordPress Post-ID des Fahrers
 * @param string $vorname Optionaler Vorname (sonst aus Post Meta)
 * @param string $nachname Optionaler Nachname (sonst aus Post Meta)
 */
function meldetool_sync_rider_post_title($post_id, $vorname = '', $nachname = '') {
    $post_id = (int) $post_id;
    if (!$post_id) {
        return;
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'fahrer') {
        return;
    }

    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    $vorname = (string) ($vorname !== '' ? $vorname : get_post_meta($post_id, 'vorname', true));
    $nachname = (string) ($nachname !== '' ? $nachname : get_post_meta($post_id, 'nachname', true));
    $new_title = trim($nachname . ' ' . $vorname);
    if ($new_title === '' || $new_title === $post->post_title) {
        return;
    }

    static $is_updating = array();
    if (!empty($is_updating[$post_id])) {
        return;
    }

    $is_updating[$post_id] = true;
    wp_update_post(array(
        'ID'         => $post_id,
        'post_title' => $new_title,
        'post_name'  => sanitize_title($new_title),
    ));
    unset($is_updating[$post_id]);
}





/**
 * Synchronisiert den Team-Post-Titel mit dem Teamnamen.
 *
 * Wird sowohl von save_post_team als auch direkt nach Pods-Save genutzt,
 * damit neue Teams sofort einen konsistenten Titel erhalten.
 *
 * @param int $post_id WordPress Post-ID des Teams
 * @param string $teamname Optionaler Teamname (sonst aus Post Meta)
 */
function meldetool_sync_team_post_title($post_id, $teamname = '') {
    $post_id = (int) $post_id;
    if (!$post_id) {
        return;
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'team') {
        return;
    }

    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    $new_title = trim((string) ($teamname !== '' ? $teamname : get_post_meta($post_id, 'teamname', true)));
    if ($new_title === '' || $new_title === $post->post_title) {
        return;
    }

    static $is_updating = array();
    if (!empty($is_updating[$post_id])) {
        return;
    }

    $is_updating[$post_id] = true;
    wp_update_post(array(
        'ID'         => $post_id,
        'post_title' => $new_title,
        'post_name'  => sanitize_title($new_title),
    ));
    unset($is_updating[$post_id]);
}

/**
 * Synchronisiert Post-Title mit Teamname (Post Meta)
 * 
 * Macht Teamname in Admin-Liste und überall sichtbar
 * Nur beim Anlegen/Bearbeiten ausgeführt (nicht bei Autosaves/Revisions)
 * Verhindert Rekursion durch statisches Flag
 * 
 * Hook: save_post_team (native WordPress Hook)
 */
add_action('save_post_team', function($post_id, $post, $update) {
    meldetool_sync_team_post_title($post_id);

}, 10, 3);

/**
 * Synchronisiert Post-Title mit Fahrer-Name (Vorname + Nachname)
 * 
 * Macht Fahrernamen in Admin-Listen suchbar und sichtbar
 * Nur beim Anlegen/Bearbeiten ausgeführt (nicht bei Autosaves/Revisions)
 * 
 * Hook: save_post_fahrer (native WordPress Hook)
 */
add_action('save_post_fahrer', function($post_id, $post, $update) {
    meldetool_sync_rider_post_title($post_id);

}, 10, 3);

/**
 * Admin Listen: Benutzerdefinierte Spalten definieren
 * 
 * Zeigt relevante Fahrer-Informationen direkt in der Übersicht:
 * Nachname, Vorname, Team, Rennklasse, Kategorie, Lizenzen, UCI-ID
 * 
 * Hook: manage_fahrer_posts_columns (WordPress List Table)
 */
add_filter('manage_fahrer_posts_columns', function($columns) {
    $columns['nachname'] = 'Nachname';
    $columns['vorname'] = 'Vorname';
    $columns['team'] = 'Team';
	$columns['rennklasse'] = 'Rennklasse';
	$columns['kategorie'] = 'Kategorie';
    $columns['etappen_auswahl'] = 'Etappe(n)';
    $columns['lizenznummer'] = 'Lizenznummer';
    $columns['uci_id'] = 'UCI-ID';
	
	# remove date and statistics column
    #unset($columns['date']);
	unset($columns['stats']);
    return $columns;
});

add_filter('manage_team_posts_columns', function($columns) {
    $columns['teamname'] = 'Teamname';
	$columns['rennklasse'] = 'Rennklasse';
    $columns['teammanager'] = 'Name Sportlicher Leiter/Teammanager';
	$columns['email_manager'] = 'E-Mail';
    //$columns['iban'] = 'IBAN';
    //$columns['bic'] = 'BIC';
    //$columns['kontoinhaber'] = 'Kontoinhaber';
	# remove date and statistics column
    #unset($columns['date']);
	unset($columns['stats']);
    return $columns;
});


/**
 * Admin Listen: Spalten mit Inhalten füllen
 * 
 * Holt die eigentlichen Daten aus Post Meta oder Taxonomien
 * Behandelt spezielle Fälle wie Team-Links und Kategorie-Namen
 * 
 * Hook: manage_fahrer_posts_custom_column (WordPress List Table)
 */
add_action('manage_fahrer_posts_custom_column', function($column, $post_id) {
    switch ($column) {
        case 'vorname':
        case 'nachname':
        case 'uci_id':
        case 'lizenznummer':
		case 'etappen_auswahl':
		#case 'kategorie':
		#case 'rennklasse':
            $value = get_post_meta($post_id, $column, true);
            echo ($value !== '' && $value !== null) ? esc_html($value) : '—';
            break;

        case 'team': # 'team' ist post_meta
            $team_id = get_post_meta($post_id, $column, true);
            if ($team_id) echo esc_html(get_the_title($team_id));
            break;
			
		case 'kategorie':
            // Taxonomie "kategorie" direkt am Fahrer
            $terms = get_the_terms($post_id, 'kategorie');
            if (!empty($terms) && !is_wp_error($terms)) {
                echo esc_html( implode(', ', wp_list_pluck($terms, 'name')) );
            } else {
                echo '—';
            }
            break;

        case 'rennklasse':
            // Aus Team ableiten: erst Team-ID holen, dann Terms der Taxonomie "rennklasse" am Team
            $team_id = (int) get_post_meta($post_id, 'team', true);
            if ($team_id) {
                $terms = get_the_terms($team_id, 'rennklasse');
                if (!empty($terms) && !is_wp_error($terms)) {
                    echo esc_html( implode(', ', wp_list_pluck($terms, 'name')) );
                } else {
                    echo '—';
                }
            } else {
                echo '—';
            }
            break;
    }
}, 10, 2);


add_action('manage_team_posts_custom_column', function($column, $post_id) {
    /**
     * Füllt Team-Spalten mit Inhalten aus Post Meta oder Taxonomien
     * 
     * Rennklasse wird aus der Taxonomie am Team geholt
     * Andere Felder (Teamname, Manager, E-Mail) aus Post Meta
     */
    switch ($column) {
        case 'teamname':
        case 'teammanager':
        case 'email_manager':
            echo esc_html(get_post_meta($post_id, $column, true));
            break;

        case 'rennklasse':
            // Aus Team ableiten: erst Team-ID holen, dann Terms der Taxonomie "rennklasse" am Team
            $team_id = (int) get_post_meta($post_id, 'team', true);
			$terms = get_the_terms($post_id, 'rennklasse');
			if (!empty($terms) && !is_wp_error($terms)) {
				echo esc_html( implode(', ', wp_list_pluck($terms, 'name')) );
			} else {
				echo '—';
            }
            break;
    }
}, 10, 2);

/**
 * Admin Listen: CSS-Styling für kompakte Darstellung
 * 
 * Verkürzt Zeilenhöhe und versteckt Action-Links ("Bearbeiten", "Papierkorb", etc)
 * für bessere Übersichtlichkeit bei vielen Einträgen
 */
add_action('admin_head', function () {
    $screen = get_current_screen();
    if (($screen->post_type === 'fahrer') || ($screen->post_type === 'team')) {
        echo '<style>
            .wp-list-table.widefat.fixed.striped tbody tr {
                height: 20px;
            }
            .wp-list-table .row-actions {
                display: none !important;
            }
            .wp-list-table td, .wp-list-table th {
                padding: 4px 6px !important;
            }
        </style>';
    }
});

/**
 * Admin Listen: Team-Filter-Dropdown in Fahrer-Liste
 * 
 * Ermöglicht schnelle Filterung nach Teams über Dropdown
 * Wird oben in der Post-Listen-Kopfzeile angezeigt
 * 
 * Hook: restrict_manage_posts (Post List Filters)
 */
add_action('restrict_manage_posts', function ($post_type) {
    if ($post_type !== 'fahrer') {
        return;
    }

    // Teams laden
    $teams = get_posts([
        'post_type'      => 'team',
        'post_status'    => 'any',
        'numberposts'    => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    $current_team = isset($_GET['team_filter']) ? (int) $_GET['team_filter'] : 0;

    echo '<select name="team_filter" style="max-width:200px;">';
    echo '<option value="0">Alle Teams</option>';

    foreach ($teams as $team) {
        printf(
            '<option value="%d"%s>%s</option>',
            $team->ID,
            selected($current_team, $team->ID, false),
            esc_html($team->post_title)
        );
    }

    echo '</select>';
});

/**
 * Admin Listen: Team-Filter mit Post-Meta-Query umsetzen
 * 
 * Modifiziert WordPress Query wenn GET-Parameter "team_filter" vorhanden
 * Sortierung nach Teams möglich über "orderby=team"
 * 
 * Hook: pre_get_posts (vor Query-Ausführung, ermöglicht Filterung)
 */
add_action('pre_get_posts', function ($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    if ($query->get('post_type') !== 'fahrer') {
        return;
    }

    if (!empty($_GET['team_filter']) && intval($_GET['team_filter']) > 0) {
        $query->set('meta_query', [
            [
                'key'   => 'team',
                'value' => intval($_GET['team_filter']),
            ]
        ]);
    }
	
	# nach teams sortieren
    if ($query->get('orderby') === 'team') {
        $query->set('meta_key', 'team');
        $query->set('orderby', 'meta_value');
    }
});

add_filter('manage_edit-fahrer_sortable_columns', function ($columns) {
    $columns['team'] = 'team';
    return $columns;
});



/**
 * Debug-Tool: Fahrerinformationen ausgeben
 * 
 * Verwendung:
 * 1. Als Admin anmelden
 * 2. Folgende URL aufrufen: wp-admin/edit.php?post_type=fahrer&debug_fahrer=6355
 * (6355 durch echte Fahrer-ID ersetzen)
 * 3. Info-Box mit Debugging-Informationen wird oben angezeigt
 * 
 * Zeigt:
 * - Verfügbare Taxonomien
 * - Kategorie-Terms des Fahrers
 * - Team-Informationen
 * - Rennklasse des Teams
 * 
 * Hook: admin_notices (Admin Interface Notices)
 */
add_action('admin_notices', function () {
    if (!is_admin()) return;

    // Bitte hier eine echte Fahrer-ID einsetzen
    $post_id = isset($_GET['debug_fahrer']) ? (int) $_GET['debug_fahrer'] : 0;
    if (!$post_id) return;

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'fahrer') return;

    echo '<div class="notice notice-info"><p><strong>Debug Fahrer ID ' . $post_id . '</strong></p>';

    // 1) Welche Taxonomien kennt der Fahrer?
    $taxes = get_object_taxonomies('fahrer');
    echo '<p>Taxonomien am Post Type fahrer: <code>' . esc_html(implode(', ', $taxes)) . '</code></p>';

    // 2) Kategorie direkt am Fahrer
    $t_kat = get_the_terms($post_id, 'kategorie');
    echo '<p>Kategorie Terms: <code>' . esc_html(print_r($t_kat, true)) . '</code></p>';

    // 3) Team lesen
    $team_id = (int) get_post_meta($post_id, 'team', true);
    echo '<p>Team-ID: ' . $team_id . ' / Team-Titel: ' . ($team_id ? esc_html(get_the_title($team_id)) : '—') . '</p>';

    // 4) Rennklasse am Team
    if ($team_id) {
        $t_rk = get_the_terms($team_id, 'rennklasse');
        echo '<p>Rennklasse Terms (Team): <code>' . esc_html(print_r($t_rk, true)) . '</code></p>';
    }

    echo '</div>';
});

/*
// Synchronisation: Wenn im Fahrer-Edit Screen die Kategorie ausgewählt wird, soll automatisch die entsprechende Kategorie-Taxonomie am Fahrer gesetzt werden (und umgekehrt)
add_action('save_post', 'sync_relationship_field_with_taxonomy', 10, 3);

function sync_relationship_field_with_taxonomy($post_id) {
    $post_type = get_post_type($post_id);

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    if ($post_type == 'team') {
        $relationship_field = get_post_meta($post_id, 'rennklasse', true);
        if (!empty($relationship_field)) {
            wp_set_post_terms($post_id, array($relationship_field), 'rennklasse');
        } else {
            wp_set_post_terms($post_id, array(), 'rennklasse');
        }
    }
    if ($post_type == 'fahrer') {
        $relationship_field = get_post_meta($post_id, 'fahrer-kategorie', true);
        if (!empty($relationship_field)) {
            wp_set_post_terms($post_id, array($relationship_field), 'kategorie');
        } else {
            wp_set_post_terms($post_id, array(), 'kategorie');
        }
    }
}
    */

/**
 * Zusätzliche Plugin-Module laden
 */
require_once MELDETOOL_PLUGIN_DIR . 'mail.php';             // E-Mail und Bestätigungsmails
require_once MELDETOOL_PLUGIN_DIR . 'export_rider_list.php'; // CSV-Export Funktionalität
require_once MELDETOOL_PLUGIN_DIR . 'backup_tools.php';     // Vollbackup Export/Import
require_once MELDETOOL_PLUGIN_DIR . 'install.php';          // Installation & Aktivierung
require_once MELDETOOL_PLUGIN_DIR . 'settings.php';         // Admin-Einstellungen Seite
require_once MELDETOOL_PLUGIN_DIR . 'formulardesign.php';   // Frontend-Formular-Logik
require_once MELDETOOL_PLUGIN_DIR . 'rest-security.php';    // Zusätzliche Sicherheitsprüfungen für REST-API Endpunkte
