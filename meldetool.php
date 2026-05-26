<?php
/**
 * Plugin Name: Meldetool
 * Description: A solution to let team managers create their team and add participants to the teams.
 * Version: 1.0.2
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
 * Prüft ob ein Teamname auf ein Einzelstarter-Team hinweist.
 *
 * Wird in Mail-Versand und Formulardesign verwendet, um Einzelstarter-spezifisches
 * Verhalten (Preistabelle, IBAN-Felder, HTML-Mails) zu aktivieren.
 *
 * @param string $teamname Teamname (Post-Titel oder Meta-Wert)
 * @return bool true wenn der Name "Einzelstarter" enthält (Groß-/Kleinschreibung egal)
 */
function meldetool_is_einzelstarter_team($teamname) {
    return stripos((string) $teamname, 'Einzelstarter') !== false;
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
 * Liest den Teamnamen direkt aus dem aktuellen Admin-Request (Pods/WP-Formulare).
 *
 * Pods speichert Meta-Werte teils erst nach save_post. Mit diesem Helper kann
 * save_post_team dennoch den neuen Namen beim ersten Speichern verwenden.
 *
 * @return string
 */
function meldetool_get_posted_teamname() {
    if (empty($_POST) || !is_array($_POST)) {
        return '';
    }

    $candidates = array();
    if (isset($_POST['teamname'])) {
        $candidates[] = $_POST['teamname'];
    }
    if (isset($_POST['pods_meta_teamname'])) {
        $candidates[] = $_POST['pods_meta_teamname'];
    }
    if (isset($_POST['pods_meta']) && is_array($_POST['pods_meta']) && isset($_POST['pods_meta']['teamname'])) {
        $candidates[] = $_POST['pods_meta']['teamname'];
    }

    foreach ($candidates as $raw) {
        if (is_array($raw)) {
            continue;
        }
        $value = trim(sanitize_text_field(wp_unslash((string) $raw)));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
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
    $posted_teamname = meldetool_get_posted_teamname();
    meldetool_sync_team_post_title($post_id, $posted_teamname);

}, 10, 3);

/**
 * Schlanker Admin-Fallback fuer die Nationalitaetsliste.
 *
 * Single Source of Truth bleibt die Meldetool-Einstellung
 * `meldetool_options[nationality_codes]`.
 * Dieser Hook sorgt nur fuer die Darstellung im Admin, falls Pods intern
 * aus Cache/Altdefinitionen rendert.
 */
add_action('admin_footer', function() {
    if (!is_admin() || !function_exists('meldetool_get_configured_nationality_pick_data')) {
        return;
    }

    $configured = meldetool_get_configured_nationality_pick_data();
    if (!is_array($configured) || empty($configured)) {
        return;
    }
    ?>
    <script>
    (function() {
        var configured = <?php echo wp_json_encode($configured); ?>;
        if (!configured || typeof configured !== 'object') return;

        function normalize(name) {
            var n = String(name || '').toLowerCase();
            if (n.indexOf('pods_field_') === 0) n = n.substring(11);
            return n;
        }

        function applyNationalityOptions() {
            var selects = document.querySelectorAll('select');
            var changed = false;

            Array.prototype.forEach.call(selects, function(select) {
                var fieldName = normalize(select.name || select.id || '');
                if (fieldName !== 'nationalitaet') return;

                var current = String(select.value || '');
                var placeholder = '-- Auswaehlen --';
                Array.prototype.forEach.call(select.options || [], function(opt) {
                    if (opt && opt.value === '') placeholder = opt.text || placeholder;
                });

                while (select.options.length > 0) select.remove(0);

                var empty = document.createElement('option');
                empty.value = '';
                empty.text = placeholder;
                select.appendChild(empty);

                Object.keys(configured).forEach(function(code) {
                    var option = document.createElement('option');
                    option.value = String(code);
                    option.text = String(configured[code]);
                    select.appendChild(option);
                });

                select.value = current;
                if (select.tomselect && typeof select.tomselect.sync === 'function') {
                    select.tomselect.sync();
                }

                changed = true;
            });

            return changed;
        }

        if (!applyNationalityOptions()) {
            setTimeout(applyNationalityOptions, 250);
            setTimeout(applyNationalityOptions, 1000);
        }
    })();
    </script>
    <?php
});

/**
 * Synchronisiert Post-Title mit Teamname direkt nach dem Speichern via Pods.
 *
 * Pods schreibt Metadaten NACH dem save_post-Hook, weshalb save_post_team
 * noch den alten Wert liest. Dieser Hook liefert den neuen Teamnamen
 * direkt aus den gespeicherten Pods-Felddaten, ohne auf Post Meta angewiesen zu sein.
 *
 * Hook: pods_api_post_save_pod_item_team
 */
add_action('pods_api_post_save_pod_item_team', function($pieces) {
    $post_id = isset($pieces['id']) ? (int) $pieces['id'] : 0;
    if (!$post_id) {
        return;
    }

    $new_teamname = '';
    if (!empty($pieces['fields']['teamname']['value'])) {
        $new_teamname = trim((string) $pieces['fields']['teamname']['value']);
    } elseif (!empty($pieces['fields']['teamname'])) {
        $val = $pieces['fields']['teamname'];
        if (is_string($val)) {
            $new_teamname = trim($val);
        }
    }

    meldetool_sync_team_post_title($post_id, $new_teamname);
}, 10, 1);

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
    $columns['email_rider'] = 'E-Mail';
    $columns['nationalitaet'] = 'Nationalität';
    $columns['team'] = 'Team';
	$columns['rennklasse'] = 'Rennklasse';
	$columns['kategorie'] = 'Kategorie';
    $columns['etappen_auswahl'] = 'Etappe(n)';
    $columns['lizenznummer'] = 'Lizenznummer';
    $columns['uci_id'] = 'UCI-ID';
    $columns['bezahlt'] = 'Bezahlt (€)';
	
	# remove date and statistics column
    #unset($columns['date']);
	unset($columns['stats']);
    return $columns;
});

add_filter('manage_team_posts_columns', function($columns) {
    $columns['teamname'] = 'Teamname';
	$columns['rennklasse'] = 'Rennklasse';
    $columns['bezahlt'] = 'Bezahlt (€)';
    $columns['fahrer_gesamt'] = 'Gemeldete Fahrer';
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

function meldetool_get_team_rider_counts() {
    static $counts = null;

    if ($counts !== null) {
        return $counts;
    }

    global $wpdb;

    $counts = array();
    $rows = $wpdb->get_results(
        "SELECT CAST(pm.meta_value AS UNSIGNED) AS team_id, COUNT(1) AS rider_count
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = 'team')
        WHERE p.post_type = 'fahrer'
          AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')
        GROUP BY CAST(pm.meta_value AS UNSIGNED)",
        ARRAY_A
    );

    if (!empty($rows)) {
        foreach ($rows as $row) {
            $team_id = (int) $row['team_id'];
            if ($team_id > 0) {
                $counts[$team_id] = (int) $row['rider_count'];
            }
        }
    }

    return $counts;
}


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
        case 'email_rider':
        case 'nationalitaet':
        case 'uci_id':
        case 'lizenznummer':
		case 'etappen_auswahl':
		case 'bezahlt':
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
        case 'bezahlt':
            $value = get_post_meta($post_id, $column, true);
            echo ($value !== '' && $value !== null) ? esc_html($value) : '—';
            break;

        case 'fahrer_gesamt':
            $counts = meldetool_get_team_rider_counts();
            echo (int) ($counts[(int) $post_id] ?? 0);
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
 * Admin Listen: Filter-Dropdowns in Fahrer-Liste
 * 
 * Ermöglicht schnelle Filterung nach Team und Rennklasse über Dropdowns.
 * Die Rennklasse filtert indirekt über die dem Team zugewiesene Taxonomie.
 * 
 * Hook: restrict_manage_posts (Post List Filters)
 */
add_action('restrict_manage_posts', function ($post_type) {
    if ($post_type !== 'fahrer' && $post_type !== 'team') {
        return;
    }

    $rennklassen = get_terms([
        'taxonomy'   => 'rennklasse',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    $current_rennklasse = isset($_GET['rennklasse_filter']) ? (int) $_GET['rennklasse_filter'] : 0;

    if ($post_type === 'fahrer') {
        // Teams laden (nur fuer Fahrer-Liste)
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
    }

    echo '<select name="rennklasse_filter" style="max-width:200px; margin-left:8px;">';
    echo '<option value="0">Alle Rennklassen</option>';

    if (!is_wp_error($rennklassen) && !empty($rennklassen)) {
        foreach ($rennklassen as $rennklasse) {
            printf(
                '<option value="%d"%s>%s</option>',
                $rennklasse->term_id,
                selected($current_rennklasse, $rennklasse->term_id, false),
                esc_html($rennklasse->name)
            );
        }
    }

    echo '</select>';
});

/**
 * Admin Listen: Team- und Rennklassen-Filter umsetzen.
 * 
 * Der Teamfilter wirkt direkt auf das Fahrer-Meta "team".
 * Der Rennklassenfilter ermittelt zuerst passende Teams und filtert dann
 * die Fahrer ebenfalls über deren Team-Meta.
 * 
 * Hook: pre_get_posts (vor Query-Ausführung, ermöglicht Filterung)
 */
add_action('pre_get_posts', function ($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    $post_type = (string) $query->get('post_type');

    if ($post_type === 'team') {
        if ($query->get('orderby') === 'bezahlt') {
            $query->set('meta_key', 'bezahlt');
            $query->set('orderby', 'meta_value_num');
        }

        if (!empty($_GET['rennklasse_filter']) && intval($_GET['rennklasse_filter']) > 0) {
            $tax_query = (array) $query->get('tax_query');
            $tax_query[] = array(
                'taxonomy' => 'rennklasse',
                'field'    => 'term_id',
                'terms'    => array(intval($_GET['rennklasse_filter'])),
            );
            $query->set('tax_query', $tax_query);
        }
        return;
    }

    if ($post_type !== 'fahrer') {
        return;
    }

    $meta_query = array();

    if (!empty($_GET['team_filter']) && intval($_GET['team_filter']) > 0) {
        $meta_query[] = array(
            'key'   => 'team',
            'value' => intval($_GET['team_filter']),
        );
    }

    if (!empty($_GET['rennklasse_filter']) && intval($_GET['rennklasse_filter']) > 0) {
        $team_ids_for_rennklasse = get_posts(array(
            'post_type'      => 'team',
            'post_status'    => 'any',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'tax_query'      => array(
                array(
                    'taxonomy' => 'rennklasse',
                    'field'    => 'term_id',
                    'terms'    => array(intval($_GET['rennklasse_filter'])),
                ),
            ),
        ));

        if (empty($team_ids_for_rennklasse)) {
            $query->set('post__in', array(0));
        } else {
            $meta_query[] = array(
                'key'     => 'team',
                'value'   => array_map('intval', $team_ids_for_rennklasse),
                'compare' => 'IN',
            );
        }
    }

    if (!empty($meta_query)) {
        if (count($meta_query) > 1) {
            $meta_query['relation'] = 'AND';
        }
        $query->set('meta_query', $meta_query);
    }
	
    // UCI-ID als sortierbare Admin-Spalte
    if ($query->get('orderby') === 'uci_id') {
        $query->set('meta_key', 'uci_id');
        $query->set('orderby', 'meta_value');
    }
});

add_filter('manage_edit-fahrer_sortable_columns', function ($columns) {
    $columns['team'] = 'team';
    $columns['rennklasse'] = 'rennklasse';
    $columns['kategorie'] = 'kategorie';
    $columns['uci_id'] = 'uci_id';
    $columns['bezahlt'] = 'bezahlt';
    return $columns;
});

add_filter('manage_edit-team_sortable_columns', function ($columns) {
    $columns['bezahlt'] = 'bezahlt';
    $columns['rennklasse'] = 'rennklasse';
    $columns['fahrer_gesamt'] = 'fahrer_gesamt';
    return $columns;
});

/**
 * Standard-Sortierung in Fahrer-Adminliste:
 * 1) Rennklasse, 2) Team, 3) Kategorie.
 */
add_filter('posts_clauses', function($clauses, $query) {
    if (!is_admin() || !$query->is_main_query()) {
        return $clauses;
    }

    global $pagenow;
    if ($pagenow !== 'edit.php') {
        return $clauses;
    }

    $post_type = (string) $query->get('post_type');

    if ($post_type === 'team') {
        $orderby = (string) $query->get('orderby');
        if (!in_array($orderby, array('rennklasse', 'fahrer_gesamt'), true)) {
            return $clauses;
        }

        $order = strtoupper((string) $query->get('order'));
        if (!in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'ASC';
        }

        global $wpdb;

        if ($orderby === 'rennklasse') {
            $clauses['join'] .= "\nLEFT JOIN {$wpdb->term_relationships} AS tr_rk_t ON ({$wpdb->posts}.ID = tr_rk_t.object_id)";
            $clauses['join'] .= "\nLEFT JOIN {$wpdb->term_taxonomy} AS tt_rk_t ON (tr_rk_t.term_taxonomy_id = tt_rk_t.term_taxonomy_id AND tt_rk_t.taxonomy = 'rennklasse')";
            $clauses['join'] .= "\nLEFT JOIN {$wpdb->terms} AS t_rk_t ON (tt_rk_t.term_id = t_rk_t.term_id)";
            $clauses['groupby'] = "{$wpdb->posts}.ID";
            $clauses['orderby'] = "COALESCE(MIN(t_rk_t.name), 'ZZZ') {$order}, {$wpdb->posts}.post_title ASC";
        } elseif ($orderby === 'fahrer_gesamt') {
            $clauses['join'] .= "\nLEFT JOIN (
                SELECT CAST(pm.meta_value AS UNSIGNED) AS team_id, COUNT(1) AS rider_count
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} fp ON (fp.ID = pm.post_id AND fp.post_type = 'fahrer' AND fp.post_status NOT IN ('trash','auto-draft','inherit'))
                WHERE pm.meta_key = 'team'
                GROUP BY CAST(pm.meta_value AS UNSIGNED)
            ) AS rc ON (rc.team_id = {$wpdb->posts}.ID)";
            $clauses['orderby'] = "COALESCE(rc.rider_count, 0) {$order}, {$wpdb->posts}.post_title ASC";
        }

        return $clauses;
    }

    if ($post_type !== 'fahrer') {
        return $clauses;
    }

    $orderby = (string) $query->get('orderby');

    // Nur fuer Default- oder explizite Listen-Sortierung dieser drei Spalten eingreifen.
    if (!in_array($orderby, array('', 'team', 'rennklasse', 'kategorie', 'bezahlt'), true)) {
        return $clauses;
    }

    $order = strtoupper((string) $query->get('order'));
    if (!in_array($order, array('ASC', 'DESC'), true)) {
        $order = 'ASC';
    }

    global $wpdb;

    $clauses['join'] .= "\nLEFT JOIN {$wpdb->postmeta} AS mt_team ON ({$wpdb->posts}.ID = mt_team.post_id AND mt_team.meta_key = 'team')";
    $clauses['join'] .= "\nLEFT JOIN {$wpdb->posts} AS team_post ON (team_post.ID = CAST(mt_team.meta_value AS UNSIGNED))";

    $clauses['join'] .= "\nLEFT JOIN {$wpdb->term_relationships} AS tr_rk ON (team_post.ID = tr_rk.object_id)";
    $clauses['join'] .= "\nLEFT JOIN {$wpdb->term_taxonomy} AS tt_rk ON (tr_rk.term_taxonomy_id = tt_rk.term_taxonomy_id AND tt_rk.taxonomy = 'rennklasse')";
    $clauses['join'] .= "\nLEFT JOIN {$wpdb->terms} AS t_rk ON (tt_rk.term_id = t_rk.term_id)";

    $clauses['join'] .= "\nLEFT JOIN {$wpdb->term_relationships} AS tr_kat ON ({$wpdb->posts}.ID = tr_kat.object_id)";
    $clauses['join'] .= "\nLEFT JOIN {$wpdb->term_taxonomy} AS tt_kat ON (tr_kat.term_taxonomy_id = tt_kat.term_taxonomy_id AND tt_kat.taxonomy = 'kategorie')";
    $clauses['join'] .= "\nLEFT JOIN {$wpdb->terms} AS t_kat ON (tt_kat.term_id = t_kat.term_id)";

    $clauses['groupby'] = "{$wpdb->posts}.ID";

    if ($orderby === 'team') {
        $clauses['orderby'] = "COALESCE(team_post.post_title, 'ZZZ') {$order}, {$wpdb->posts}.post_title ASC";
    } elseif ($orderby === 'rennklasse') {
        $clauses['orderby'] = "COALESCE(t_rk.name, 'ZZZ') {$order}, COALESCE(team_post.post_title, 'ZZZ') ASC, {$wpdb->posts}.post_title ASC";
    } elseif ($orderby === 'kategorie') {
        $clauses['orderby'] = "COALESCE(t_kat.name, 'ZZZ') {$order}, COALESCE(team_post.post_title, 'ZZZ') ASC, {$wpdb->posts}.post_title ASC";
    } elseif ($orderby === 'bezahlt') {
        $clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} AS pm_bezahlt ON (pm_bezahlt.post_id = {$wpdb->posts}.ID AND pm_bezahlt.meta_key = 'bezahlt')";
        $clauses['orderby'] = "CAST(COALESCE(pm_bezahlt.meta_value, '0') AS DECIMAL(10,2)) {$order}, {$wpdb->posts}.post_title ASC";
    } else {
        // Default-Sortierung: Rennklasse -> Team -> Kategorie.
        $clauses['orderby'] = "COALESCE(t_rk.name, 'ZZZ') ASC, COALESCE(team_post.post_title, 'ZZZ') ASC, COALESCE(t_kat.name, 'ZZZ') ASC, {$wpdb->posts}.post_title ASC";
    }

    return $clauses;
}, 20, 2);



/**
 * Shortcode [meldetool_starterliste]
 *
 * Gibt die Starterliste als HTML-Tabelle aus, gruppiert nach Rennklassen.
 * Sortierung: Rennklasse (A–Z) → Team (A–Z) → Kapitän zuerst → Nachname → Vorname.
 * Spalten: Nachname, Vorname | Teamname | Kategorie, UCI-ID
 *
 * Verwendung: Shortcode [meldetool_starterliste] auf einer beliebigen Seite einfügen.
 * Optional: [meldetool_starterliste einzel="einzelstarter"] (Standard-Schlüsselwort für Einzel-Teams)
 */
add_shortcode('meldetool_starterliste', function($atts) {
    $atts = shortcode_atts(array('einzel' => 'einzelstarter'), $atts, 'meldetool_starterliste');
    $einzel_keyword = trim((string) $atts['einzel']);

    $is_einzel = function($team_title) use ($einzel_keyword) {
        if ($einzel_keyword === '') return false;
        return (stripos($team_title, $einzel_keyword) !== false);
    };

    $rennklassen = get_terms(array(
        'taxonomy'   => 'rennklasse',
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ));
    if (is_wp_error($rennklassen) || empty($rennklassen)) {
        return '<p>Keine Starterliste verfügbar.</p>';
    }

    $html  = '<style>';
    $html .= '.meldetool-starterliste { font-family: sans-serif; }';
    $html .= '.meldetool-starterliste__rennklasse { margin-top: 1.6em; margin-bottom: 0.3em; border-bottom: 2px solid currentColor; padding-bottom: 2px; }';
    $html .= '.meldetool-starterliste__tabelle { border-collapse: collapse; width: 100%; }';
    $html .= '.meldetool-starterliste__tabelle th { text-align: left; padding: 3px 8px; border-bottom: 1px solid #ccc; white-space: nowrap; }';
    $html .= '.meldetool-starterliste__tabelle td { padding: 2px 8px; vertical-align: top; }';
    $html .= '.meldetool-starterliste__tabelle td:first-child { white-space: nowrap; }';
    $html .= '.meldetool-starterliste__tabelle tbody tr:nth-child(even) { background: rgba(0,0,0,.04); }';
    $html .= '</style>';
    $html .= '<div class="meldetool-starterliste">';

    foreach ($rennklassen as $rk_term) {
        $teams_in_rk = get_posts(array(
            'post_type'  => 'team',
            'post_status'=> 'publish',
            'numberposts'=> -1,
            'orderby'    => 'title',
            'order'      => 'ASC',
            'tax_query'  => array(array(
                'taxonomy' => 'rennklasse',
                'field'    => 'term_id',
                'terms'    => $rk_term->term_id,
            )),
        ));
        if (empty($teams_in_rk)) continue;

        $regular = array(); $einzel_teams = array();
        foreach ($teams_in_rk as $t) {
            if ($is_einzel($t->post_title)) $einzel_teams[] = $t; else $regular[] = $t;
        }
        usort($regular,       function($a,$b){ return strcasecmp($a->post_title, $b->post_title); });
        usort($einzel_teams,  function($a,$b){ return strcasecmp($a->post_title, $b->post_title); });

        $rows = array();
        foreach (array_merge($regular, $einzel_teams) as $team) {
            $fahrer = get_posts(array(
                'post_type'  => 'fahrer',
                'post_status'=> 'publish',
                'numberposts'=> -1,
                'meta_key'   => 'team',
                'meta_value' => $team->ID,
            ));
            if (empty($fahrer)) continue;

            usort($fahrer, function($a,$b){
                $ka = meldetool_bool_meta_sl($a->ID, 'ist_kapitaen') ? 1 : 0;
                $kb = meldetool_bool_meta_sl($b->ID, 'ist_kapitaen') ? 1 : 0;
                if ($ka !== $kb) return ($kb - $ka);
                $na = strtolower((string)get_post_meta($a->ID, 'nachname', true));
                $nb = strtolower((string)get_post_meta($b->ID, 'nachname', true));
                if ($na !== $nb) return strcmp($na, $nb);
                return strcmp(
                    strtolower((string)get_post_meta($a->ID, 'vorname', true)),
                    strtolower((string)get_post_meta($b->ID, 'vorname', true))
                );
            });

            foreach ($fahrer as $f) {
                $terms     = get_the_terms($f->ID, 'kategorie');
                $kategorie = (!empty($terms) && !is_wp_error($terms))
                    ? implode(', ', wp_list_pluck($terms, 'name')) : '';
                $rows[] = array(
                    'nachname'     => (string)get_post_meta($f->ID, 'nachname', true),
                    'vorname'      => (string)get_post_meta($f->ID, 'vorname', true),
                    'team'         => get_the_title($team->ID),
                    'kategorie'    => $kategorie,
                    'lizenznummer' => (string)get_post_meta($f->ID, 'lizenznummer', true),
                    'uci_id'       => (string)get_post_meta($f->ID, 'uci_id', true),
                    'etappen_auswahl' => (string)get_post_meta($f->ID, 'etappen_auswahl', true),
                );
            }
        }
        if (empty($rows)) continue;

        $count = count($rows);
        $count_label = ($count === 1) ? '1 Fahrer*in' : $count . ' Fahrer*innen';
        $html .= '<h3 class="meldetool-starterliste__rennklasse">' . esc_html($rk_term->name . ' (' . $count_label . ')') . '</h3>';
        $html .= '<table class="meldetool-starterliste__tabelle">';
        $html .= '<thead><tr>'
               . '<th>Name</th>'
               . '<th>Team</th>'
               . '<th>Kategorie / UCI-ID</th>'
               . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $show_etappen = (stripos($row['kategorie'], 'Hobby') !== false || stripos($row['kategorie'], 'U17') !== false);
            $rest_parts = array(
                esc_html($row['kategorie']),
                esc_html($row['uci_id']),
            );
            if ($show_etappen && !empty($row['etappen_auswahl'])) {
                $rest_parts[] = esc_html($row['etappen_auswahl']);
            }
            $rest = implode(', ', array_filter($rest_parts));
            $html .= '<tr>';
            $html .= '<td>' . esc_html($row['nachname'] . ', ' . $row['vorname']) . '</td>';
            $html .= '<td>' . esc_html($row['team']) . '</td>';
            $html .= '<td>' . $rest . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
    }

    $html .= '</div>';
    return $html;
});

/** Hilfsfunktion für Shortcode: interpretiert Post-Meta als bool */
function meldetool_bool_meta_sl($post_id, $key) {
    $v = get_post_meta($post_id, $key, true);
    if (is_bool($v)) return $v;
    return in_array(strtolower(trim((string)$v)), array('1','true','yes','ja','on'), true);
}

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

    // 2b) Roh-Meta der Relationship-Felder anzeigen (inkl. Mehrfachwerte)
    $raw_team_meta = get_post_meta($post_id, 'team', false);
    $raw_kat_meta = get_post_meta($post_id, 'fahrer-kategorie', false);
    echo '<p>RAW Meta team: <code>' . esc_html(print_r($raw_team_meta, true)) . '</code></p>';
    echo '<p>RAW Meta fahrer-kategorie: <code>' . esc_html(print_r($raw_kat_meta, true)) . '</code></p>';

    // 3) Team lesen
    $team_id = (int) get_post_meta($post_id, 'team', true);
    echo '<p>Team-ID: ' . $team_id . ' / Team-Titel: ' . ($team_id ? esc_html(get_the_title($team_id)) : '—') . '</p>';

    $team_post = $team_id ? get_post($team_id) : null;
    if ($team_id && (!$team_post || $team_post->post_type !== 'team')) {
        echo '<p style="color:#b32d2e;"><strong>WARNUNG:</strong> Team-Meta verweist auf keine gueltige Team-Post-ID.</p>';
    }

    $kategorie_meta_id = (int) get_post_meta($post_id, 'fahrer-kategorie', true);
    $kategorie_meta_term = $kategorie_meta_id ? get_term($kategorie_meta_id, 'kategorie') : null;
    echo '<p>Meta fahrer-kategorie ID: ' . $kategorie_meta_id;
    if ($kategorie_meta_term && !is_wp_error($kategorie_meta_term)) {
        echo ' / Term: ' . esc_html($kategorie_meta_term->name . ' (' . $kategorie_meta_term->slug . ')');
    } elseif ($kategorie_meta_id) {
        echo ' / <span style="color:#b32d2e;">ungueltige Term-ID</span>';
    }
    echo '</p>';

    // 4) Rennklasse am Team
    if ($team_id) {
        $t_rk = get_the_terms($team_id, 'rennklasse');
        echo '<p>Rennklasse Terms (Team): <code>' . esc_html(print_r($t_rk, true)) . '</code></p>';

        $team_rk_meta_id = (int) get_post_meta($team_id, 'team-rennklasse', true);
        $team_rk_meta_term = $team_rk_meta_id ? get_term($team_rk_meta_id, 'rennklasse') : null;
        echo '<p>Team-Meta team-rennklasse ID: ' . $team_rk_meta_id;
        if ($team_rk_meta_term && !is_wp_error($team_rk_meta_term)) {
            echo ' / Term: ' . esc_html($team_rk_meta_term->name . ' (' . $team_rk_meta_term->slug . ')');
        } elseif ($team_rk_meta_id) {
            echo ' / <span style="color:#b32d2e;">ungueltige Term-ID</span>';
        }
        echo '</p>';
    }

    // 5) Pods-Feldkonfiguration gegenchecken
    if (function_exists('pods_api')) {
        $pod = pods_api()->load_pod(array('name' => 'fahrer', 'type' => 'post_type'));
        if (is_array($pod) && !empty($pod['fields']) && is_array($pod['fields'])) {
            $team_field = null;
            $kategorie_field = null;
            foreach ($pod['fields'] as $field) {
                if (!empty($field['name']) && $field['name'] === 'team') {
                    $team_field = $field;
                }
                if (!empty($field['name']) && $field['name'] === 'fahrer-kategorie') {
                    $kategorie_field = $field;
                }
            }

            if (!empty($team_field)) {
                echo '<p>Pods-Feld team: pick_object=' . esc_html(isset($team_field['pick_object']) ? (string) $team_field['pick_object'] : '')
                    . ' / pick_val=' . esc_html(isset($team_field['pick_val']) ? (string) $team_field['pick_val'] : '') . '</p>';
            }
            if (!empty($kategorie_field)) {
                echo '<p>Pods-Feld fahrer-kategorie: pick_object=' . esc_html(isset($kategorie_field['pick_object']) ? (string) $kategorie_field['pick_object'] : '')
                    . ' / pick_val=' . esc_html(isset($kategorie_field['pick_val']) ? (string) $kategorie_field['pick_val'] : '') . '</p>';
            }
        }
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
