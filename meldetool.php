<?php
/**
 * Plugin Name: Meldetool
 * Description: A solution to let team managers create their team and add participants to the teams.
 * Version: 0.2.0
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

// Verbindung Taxonomien mit Post Types bei jedem Laden sicherstellen
add_action('init', function() {
    register_taxonomy_for_object_type('kategorie', 'fahrer');
    register_taxonomy_for_object_type('rennklasse', 'team');
});

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

add_action('wp_footer', function() {

    $optional_team_ids = meldetool_get_license_optional_team_ids();
    // Debug: zeigt im HTML-Source welche Teams PHP gefunden hat
    // und gibt alle Team-Titel aus, damit der Präfix-Vergleich geprüft werden kann
    $all_teams_debug = array();
    $all_posts = get_posts(array('post_type' => 'team', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids'));
    foreach ($all_posts as $tid) {
        $all_teams_debug[(int)$tid] = get_the_title((int)$tid);
    }
    ?>
    <!-- meldetool debug: optional_team_ids=<?php echo esc_html(wp_json_encode($optional_team_ids)); ?> all_teams=<?php echo esc_html(wp_json_encode($all_teams_debug)); ?> -->
    <script>
    (function() {
        var optionalTeamIds = <?php echo wp_json_encode(array_values($optional_team_ids)); ?>;
        console.log('[meldetool] optional team IDs:', optionalTeamIds);

        function asInt(value) {
            var parsed = parseInt(value, 10);
            return isNaN(parsed) ? 0 : parsed;
        }

        // Pods renders row wrappers with "pods-field-" prefix in class names
        // e.g. <div class="pods-form-ui-row pods-form-ui-row-name-pods-field-lizenznummer">
        // Inputs get id="pods-form-ui-pods-field-{field}" and name="pods_field_{field}"
        function findFieldWrap(fieldName) {
            return document.querySelector('.pods-form-ui-row-name-pods-field-' + fieldName)
                || document.querySelector('.pods-form-ui-row-name-' + fieldName)
                || document.querySelector('.pods-form-ui-field-name-' + fieldName);
        }

        function findFieldInput(fieldName) {
            return document.getElementById('pods-form-ui-pods-field-' + fieldName)
                || document.getElementById('pods-form-ui-' + fieldName)
                || document.querySelector('input[name="pods_field_' + fieldName + '"]')
                || document.querySelector('input[name="' + fieldName + '"]');
        }

        function findTeamSelect() {
            // Use exact name match to avoid hitting "pods_field_team-rennklasse" etc.
            return document.querySelector('select[name="pods_field_team"]')
                || document.querySelector('select[name="team"]')
                || document.getElementById('pods-form-ui-pods-field-team')
                || document.getElementById('pods-form-ui-team');
        }

        function logAllSelects() {
            var selects = document.querySelectorAll('select');
            console.log('[meldetool] all <select> elements found (' + selects.length + '):');
            selects.forEach(function(s) {
                console.log('  id="' + s.id + '" name="' + s.name + '" class="' + s.className + '"');
            });
            ['lizenznummer', 'uci_id'].forEach(function(fieldName) {
                var wrap = findFieldWrap(fieldName);
                var input = findFieldInput(fieldName);
                console.log('[meldetool] field "' + fieldName + '": wrap=', wrap, 'input=', input);
            });
        }

        function applyVisibility() {
            var teamSelect = findTeamSelect();
            if (!teamSelect) {
                return;
            }

            var selectedTeamId = asInt(teamSelect.value);
            var isOptional = optionalTeamIds.indexOf(selectedTeamId) !== -1;

            ['lizenznummer', 'uci_id'].forEach(function(fieldName) {
                var wrap = findFieldWrap(fieldName);
                var input = findFieldInput(fieldName);
                if (!wrap || !input) {
                    return;
                }

                wrap.style.display = isOptional ? 'none' : '';
                input.required = !isOptional;

                if (isOptional && !input.value) {
                    input.value = 'nicht erforderlich';
                }
                if (!isOptional && input.value === 'nicht erforderlich') {
                    input.value = '';
                }
            });
        }

        function boot() {
            var teamSelect = findTeamSelect();
            if (!teamSelect) {
                return false;
            }
            console.log('[meldetool] team select found:', teamSelect, 'optional IDs:', optionalTeamIds);
            logAllSelects();
            applyVisibility();
            teamSelect.addEventListener('change', applyVisibility);
            return true;
        }

        if (!boot()) {
            var tries = 0;
            var timer = setInterval(function() {
                tries++;
                if (boot() || tries > 20) {
                    if (tries > 20) {
                        console.warn('[meldetool] team select not found after ' + tries + ' attempts.');
                        logAllSelects();
                    }
                    clearInterval(timer);
                }
            }, 250);
        }
    })();
    </script>
    <?php
});

/**
 * Gemeinsame Funktion für den Mailversand an Teammanager
 */
function meldetool_get_team_details_text($team_id, $teamname = '') {
    $details = array();

    if (!empty($teamname)) {
        $details[] = 'Teamname: ' . $teamname;
    }

    if (!empty($team_id)) {
        $teammanager = get_post_meta($team_id, 'teammanager', true);
        $email_manager = get_post_meta($team_id, 'email_manager', true);
        $kontoinhaber = get_post_meta($team_id, 'kontoinhaber', true);
        $iban = get_post_meta($team_id, 'iban', true);
        $bic = get_post_meta($team_id, 'bic', true);

        if (!empty($teammanager)) {
            $details[] = 'Teammanager: ' . $teammanager;
        }
        if (!empty($email_manager)) {
            $details[] = 'E-Mail Teammanager: ' . $email_manager;
        }
        if (!empty($kontoinhaber)) {
            $details[] = 'Kontoinhaber: ' . $kontoinhaber;
        }
        if (!empty($iban)) {
            $details[] = 'IBAN: ' . $iban;
        }
        if (!empty($bic)) {
            $details[] = 'BIC: ' . $bic;
        }

        $terms = get_the_terms($team_id, 'rennklasse');
        if (!empty($terms) && !is_wp_error($terms)) {
            $details[] = 'Rennklasse: ' . implode(', ', wp_list_pluck($terms, 'name'));
        }
    }

    return implode("\n", $details);
}

function meldetool_send_team_mail($email, $teamname, $subject, $message, $team_id = 0, $send_copy_to_orga = false, $append_team_details = true) {
    $opts = get_option('meldetool_options', array());
    $from_name = 'Race Days Orga-Team';
    $from_email = (!empty($opts['from_email']) && is_email($opts['from_email']))
        ? $opts['from_email']
        : get_option('admin_email');
    $teammanager = !empty($team_id) ? get_post_meta((int) $team_id, 'teammanager', true) : '';
    $team_details = meldetool_get_team_details_text((int) $team_id, $teamname);
    $has_teamdetails_placeholder = (strpos($message, '{teamdetails}') !== false);
    $has_teammanager_placeholder = (strpos($message, '{teammanager}') !== false);
    $message = str_replace(
        array('{teamname}', '{teamdetails}', '{teammanager}'),
        array($teamname, $team_details, $teammanager),
        $message
    );
    // Ensure the team manager confirmation is personalized even with old templates.
    if (!$has_teammanager_placeholder && !empty($teammanager) && !empty($email) && !empty($team_id)) {
        $manager_email = get_post_meta((int) $team_id, 'email_manager', true);
        if (!empty($manager_email) && strcasecmp((string) $manager_email, (string) $email) === 0) {
            $message = "Hallo " . $teammanager . ",\n\n" . ltrim((string) $message);
        }
    }
    if ($append_team_details && !$has_teamdetails_placeholder && !empty($team_details)) {
        $message .= "\n\nTeamdetails:\n" . $team_details;
    }

    $headers = array('Content-Type: text/plain; charset=UTF-8');
    if (!empty($from_email) && is_email($from_email)) {
        $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
    }
    if (!empty($opts['reply_to']) && is_email($opts['reply_to'])) {
        $headers[] = 'Reply-To: ' . $opts['reply_to'];
    }
    if ($send_copy_to_orga) {
        $cc = !empty($opts['cc_email']) && is_email($opts['cc_email']) ? $opts['cc_email'] : 'orga@the-race-days-stuttgart.de';
        if (!empty($cc) && is_email($cc)) {
            $headers[] = 'Cc: ' . $cc;
        }
    }
    $mail_result = wp_mail($email, $subject, $message, $headers);
    // Logging
    $logfile = MELDETOOL_PLUGIN_DIR . 'mail_log.txt';
    $log_entry = date('Y-m-d H:i:s') . " | TEAM_MAIL | " . ($mail_result ? 'SUCCESS' : 'FAIL') . "\n";
    $log_entry .= "To: $email\nSubject: $subject\nHeaders: " . print_r($headers, true) . "\n";
    $log_entry .= "Message: $message\n";
    if (!$mail_result) {
        $log_entry .= "Error: Mailversand fehlgeschlagen.\n";
    }
    $log_entry .= str_repeat('-', 60) . "\n";
    file_put_contents($logfile, $log_entry, FILE_APPEND);
    return $mail_result;
}

function meldetool_get_rider_details_text($rider_id) {
    $details = array();
    $rider_id = (int) $rider_id;

    if (!$rider_id) {
        return '';
    }

    $vorname = get_post_meta($rider_id, 'vorname', true);
    $nachname = get_post_meta($rider_id, 'nachname', true);
    $email_rider = get_post_meta($rider_id, 'email_rider', true);
    $lizenznummer = get_post_meta($rider_id, 'lizenznummer', true);
    $uci_id = get_post_meta($rider_id, 'uci_id', true);
    $nationalitaet = get_post_meta($rider_id, 'nationalitaet', true);
    $ist_kapitaen = get_post_meta($rider_id, 'ist_kapitaen', true);
    $team_id = (int) get_post_meta($rider_id, 'team', true);

    $rider_name = trim($vorname . ' ' . $nachname);
    if (!empty($rider_name)) {
        $details[] = 'Name: ' . $rider_name;
    }
    if (!empty($email_rider)) {
        $details[] = 'E-Mail: ' . $email_rider;
    }
    if (!empty($lizenznummer)) {
        $details[] = 'Lizenznummer: ' . $lizenznummer;
    }
    if (!empty($uci_id)) {
        $details[] = 'UCI-ID: ' . $uci_id;
    }
    if (!empty($nationalitaet)) {
        $details[] = 'Nationalitaet: ' . $nationalitaet;
    }
    if (!empty($ist_kapitaen)) {
        $details[] = 'Kapitaen: Ja';
    }

    if ($team_id) {
        $details[] = 'Team: ' . get_the_title($team_id);

        $terms = get_the_terms($team_id, 'rennklasse');
        if (!empty($terms) && !is_wp_error($terms)) {
            $details[] = 'Rennklasse: ' . implode(', ', wp_list_pluck($terms, 'name'));
        }
    }

    $kategorie_terms = get_the_terms($rider_id, 'kategorie');
    if (!empty($kategorie_terms) && !is_wp_error($kategorie_terms)) {
        $details[] = 'Kategorie: ' . implode(', ', wp_list_pluck($kategorie_terms, 'name'));
    }

    return implode("\n", $details);
}

function meldetool_send_rider_confirmation_mail($rider_id, $rider_email, $rider_name, $teamname, $confirm_url) {
    $opts = get_option('meldetool_options', array());
    $from_name = 'Race Days Orga-Team';
    $from_email = (!empty($opts['from_email']) && is_email($opts['from_email']))
        ? $opts['from_email']
        : get_option('admin_email');
    $defaults = function_exists('meldetool_default_mail_texts') ? meldetool_default_mail_texts() : array();

    $subject = !empty($opts['rider_confirmation_subject'])
        ? $opts['rider_confirmation_subject']
        : (isset($defaults['rider_confirmation_subject']) ? $defaults['rider_confirmation_subject'] : '[Race Days] Bitte E-Mail bestaetigen');

    $message = !empty($opts['rider_confirmation_message'])
        ? $opts['rider_confirmation_message']
        : (isset($defaults['rider_confirmation_message']) ? $defaults['rider_confirmation_message'] : "Hallo {ridername},\n\nbitte bestaetigen Sie Ihre E-Mail-Adresse ueber folgenden Link:\n{confirm_url}\n");

    $message = str_replace(
        array('{ridername}', '{teamname}', '{confirm_url}'),
        array($rider_name, $teamname, $confirm_url),
        $message
    );

    $headers = array('Content-Type: text/plain; charset=UTF-8');
    if (!empty($from_email) && is_email($from_email)) {
        $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
    }
    if (!empty($opts['reply_to']) && is_email($opts['reply_to'])) {
        $headers[] = 'Reply-To: ' . $opts['reply_to'];
    }

    $mail_result = wp_mail($rider_email, $subject, $message, $headers);

    $logfile = MELDETOOL_PLUGIN_DIR . 'mail_log.txt';
    $log_entry = date('Y-m-d H:i:s') . " | RIDER_CONFIRMATION_MAIL | " . ($mail_result ? 'SUCCESS' : 'FAIL') . "\n";
    $log_entry .= "Rider-ID: $rider_id\nTo: $rider_email\nSubject: $subject\n";
    if (!$mail_result) {
        $log_entry .= "Error: Mailversand fehlgeschlagen.\n";
    }
    $log_entry .= str_repeat('-', 60) . "\n";
    file_put_contents($logfile, $log_entry, FILE_APPEND);

    return $mail_result;
}

function meldetool_send_rider_details_mail($rider_id) {
    $rider_id = (int) $rider_id;
    if (!$rider_id) {
        return;
    }

    $details_sent_meta = '_meldetool_rider_details_sent';
    if (get_post_meta($rider_id, $details_sent_meta, true)) {
        return;
    }

    $opts = get_option('meldetool_options', array());
    $defaults = function_exists('meldetool_default_mail_texts') ? meldetool_default_mail_texts() : array();

    $vorname = get_post_meta($rider_id, 'vorname', true);
    $nachname = get_post_meta($rider_id, 'nachname', true);
    $rider_name = trim($vorname . ' ' . $nachname);
    $rider_email = get_post_meta($rider_id, 'email_rider', true);
    $team_id = (int) get_post_meta($rider_id, 'team', true);
    $teamname = $team_id ? get_the_title($team_id) : '';
    $manager_email = $team_id ? get_post_meta($team_id, 'email_manager', true) : '';
    $rider_details = meldetool_get_rider_details_text($rider_id);

    $subject = !empty($opts['rider_details_subject'])
        ? $opts['rider_details_subject']
        : (isset($defaults['rider_details_subject']) ? $defaults['rider_details_subject'] : '[Race Days] Fahrerdetails bestaetigt');

    $message = !empty($opts['rider_details_message'])
        ? $opts['rider_details_message']
        : (isset($defaults['rider_details_message']) ? $defaults['rider_details_message'] : "Hallo,\n\ndie E-Mail-Adresse fuer Fahrer {ridername} wurde bestaetigt.\n\n{riderdetails}\n");

    $rider_message = str_replace(
        array('{ridername}', '{teamname}', '{riderdetails}'),
        array($rider_name, $teamname, $rider_details),
        $message
    );

    $sent_any = false;
    if (!empty($rider_email) && is_email($rider_email)) {
        meldetool_send_team_mail($rider_email, $teamname, $subject, $rider_message, $team_id, false, false);
        $sent_any = true;
    }

    if (!empty($manager_email) && is_email($manager_email) && $manager_email !== $rider_email) {
        $manager_message = "Hallo {teammanager},\n\n";
        $manager_message .= "deinem Team wurde eine*e neue*r Fahrer*in hinzugefügt.\n\n";
        $manager_message .= "Fahrerdetails:\n{riderdetails}";
        $manager_message = str_replace('{riderdetails}', $rider_details, $manager_message);
        meldetool_send_team_mail($manager_email, $teamname, $subject, $manager_message, $team_id, false, false);
        $sent_any = true;
    }

    if ($sent_any) {
        update_post_meta($rider_id, $details_sent_meta, 1);
    }
}

// Fahrer Double-Opt-In: Erst bestaetigen, dann Details versenden
add_action('pods_api_post_save_pod_item_fahrer', function($data, $pod, $id) {
    $id = (int) $id;
    if (!$id) {
        return;
    }

    $confirmation_sent_meta = '_meldetool_rider_confirmation_sent';
    $confirmed_meta = '_meldetool_rider_email_confirmed';
    if (get_post_meta($id, $confirmation_sent_meta, true) || get_post_meta($id, $confirmed_meta, true)) {
        return;
    }

    $rider_email = isset($data['email_rider']) ? $data['email_rider'] : get_post_meta($id, 'email_rider', true);
    if (empty($rider_email) || !is_email($rider_email)) {
        return;
    }

    $opts = get_option('meldetool_options', array());
    $enabled = isset($opts['send_confirmation']) ? (bool) $opts['send_confirmation'] : true;
    if (!$enabled) {
        return;
    }

    $vorname = isset($data['vorname']) ? $data['vorname'] : get_post_meta($id, 'vorname', true);
    $nachname = isset($data['nachname']) ? $data['nachname'] : get_post_meta($id, 'nachname', true);
    $rider_name = trim($vorname . ' ' . $nachname);
    $team_id = isset($data['team']) ? (int) $data['team'] : (int) get_post_meta($id, 'team', true);
    $teamname = $team_id ? get_the_title($team_id) : '';

    $token = wp_generate_password(32, false, false);
    update_post_meta($id, '_meldetool_rider_confirmation_token', $token);

    $confirm_url = add_query_arg(
        array(
            'meldetool_confirm_rider' => 1,
            'rider_id' => $id,
            'token' => rawurlencode($token),
        ),
        home_url('/')
    );

    $mail_result = meldetool_send_rider_confirmation_mail($id, $rider_email, $rider_name, $teamname, $confirm_url);
    if ($mail_result) {
        update_post_meta($id, $confirmation_sent_meta, 1);
    }
}, 10, 3);

add_action('template_redirect', function() {
    if (!isset($_GET['meldetool_confirm_rider'])) {
        return;
    }

    $rider_id = isset($_GET['rider_id']) ? (int) $_GET['rider_id'] : 0;
    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
    if (!$rider_id || empty($token)) {
        wp_die('Ungueltiger Bestaetigungslink.', 'Meldetool', array('response' => 400));
    }

    $stored_token = get_post_meta($rider_id, '_meldetool_rider_confirmation_token', true);
    if (empty($stored_token) || !hash_equals((string) $stored_token, (string) $token)) {
        wp_die('Bestaetigungslink ist ungueltig oder abgelaufen.', 'Meldetool', array('response' => 400));
    }

    if (!get_post_meta($rider_id, '_meldetool_rider_email_confirmed', true)) {
        update_post_meta($rider_id, '_meldetool_rider_email_confirmed', 1);
        delete_post_meta($rider_id, '_meldetool_rider_confirmation_token');
        meldetool_send_rider_details_mail($rider_id);
    }

    wp_die('Vielen Dank. Ihre E-Mail-Adresse wurde erfolgreich bestaetigt.', 'Meldetool', array('response' => 200));
});

// Bestätigungsmail direkt nach Frontend-Formular (Pods) absenden
// Bestätigungsmail auch über pods_api_post_save_pod_item_team absenden
add_action('pods_api_post_save_pod_item_team', function($data, $pod, $id) {
    $mail_sent_meta_key = '_meldetool_confirmation_sent';

    $testlog = MELDETOOL_PLUGIN_DIR . 'mail_log.txt';
    file_put_contents($testlog, date('Y-m-d H:i:s') . " | pods_api_post_save_pod_item_team | set teamname: " . get_post_meta($id, 'teamname', true) . " | data: " . print_r($data, true) . "\n", FILE_APPEND);

    // Nur einmal senden: wenn bereits versendet, Hook sofort verlassen.
    if (get_post_meta($id, $mail_sent_meta_key, true)) {
        file_put_contents($testlog, date('Y-m-d H:i:s') . " | pods_api_post_save_pod_item_team | skip: confirmation already sent\n", FILE_APPEND);
        return;
    }
	
	// dieser Teil darf nur beim Anlegen ausgeführt werden 
    $teamname = isset($data['teamname']) ? $data['teamname'] : get_post_meta($id, 'teamname', true);
    $email = isset($data['email_manager']) ? $data['email_manager'] : get_post_meta($id, 'email_manager', true);
    if (!empty($email) && is_email($email)) {
        $opts = get_option('meldetool_options', array());
        $enabled = isset($opts['send_confirmation']) ? (bool) $opts['send_confirmation'] : true;
        if ($enabled) {
            $subject = !empty($opts['confirmation_subject']) ? $opts['confirmation_subject'] : '';
            $message = !empty($opts['confirmation_message']) ? $opts['confirmation_message'] : '';
            meldetool_send_team_mail($email, $teamname, $subject, $message, $id, true);
            update_post_meta($id, $mail_sent_meta_key, 1);
        }
    }
}, 10, 3);

/**
 * Teamname
 */
add_action('save_post_team', function($post_id, $post, $update) {
    static $is_updating_team_post = false;

    if ($is_updating_team_post) {
        return;
    }

    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
	
    // Test-Log: Wird die Funktion überhaupt aufgerufen?
    $testlog = MELDETOOL_PLUGIN_DIR . 'mail_log.txt';
    file_put_contents($testlog, date('Y-m-d H:i:s') . " | save_post_team HOOK called | post_id: $post_id | update: $update | is_admin: " . (is_admin() ? '1' : '0') . " | wp_is_post_autosave: " . (wp_is_post_autosave($post_id) ? '1' : '0') . " | wp_is_post_revision: " . (wp_is_post_revision($post_id) ? '1' : '0') . " | post_status: ". $post->post_status . "\n", FILE_APPEND);

    $teamname = get_post_meta($post_id, 'teamname', true);
    
    // Beim Anlegen eines Teams über das Formular ist der teamname NULL/empty (vielleicht alle post_meta-Infos?). Erst beim "veröffentlichen" wird dieser angelegt.
    if ($teamname) {
        $new_title = trim($teamname);

        if ($new_title && $new_title !== $post->post_title) {
            $is_updating_team_post = true;
            wp_update_post([
                'ID'         => $post_id,
                'post_title' => $new_title,
                'post_name'  => sanitize_title($new_title),
            ]);
            $is_updating_team_post = false;
        }
    }
	
}, 10, 3);

/**
 * "Team offiziell gemeldet"-Mail: einmalig senden, sobald das Team auf 'publish' steht.
 * wp_after_insert_post feuert erst nach dem vollständigen Save inkl. aller Meta-Daten.
 */
add_action('wp_after_insert_post', function($post_id, $post, $update) {
    if ($post->post_type !== 'team') return;
    if ($post->post_status !== 'publish') return;

    $mail_sent_meta = '_meldetool_publish_mail_sent';
    if (get_post_meta($post_id, $mail_sent_meta, true)) return;

    $email = get_post_meta($post_id, 'email_manager', true);
    if (empty($email) || !is_email($email)) return;

    $opts = get_option('meldetool_options', array());
    $enabled = isset($opts['send_confirmation']) ? (bool) $opts['send_confirmation'] : true;
    if (!$enabled) return;

    $teamname = get_post_meta($post_id, 'teamname', true) ?: $post->post_title;
    $subject = !empty($opts['confirmation_subject_publish']) ? $opts['confirmation_subject_publish'] : '';
    $message = !empty($opts['confirmation_message_publish']) ? $opts['confirmation_message_publish'] : '';
    meldetool_send_team_mail($email, $teamname, $subject, $message, $post_id);
    update_post_meta($post_id, $mail_sent_meta, 1);
}, 10, 3);

/**
 * Fahrername
 */
add_action('save_post_fahrer', function($post_id, $post, $update) {

    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    $vorname = get_post_meta($post_id, 'vorname', true);
    $nachname = get_post_meta($post_id, 'nachname', true);
    // Beim Anlegen eines Fahrers über das Formular ist der vorname/nachname NULL/empty (vielleicht alle post_meta-Infos?). Erst beim "veröffentlichen" werden diese angelegt.
    if ($vorname || $nachname) {
        $new_title = trim($nachname . ' ' . $vorname);

        if ($new_title && $new_title !== $post->post_title) {
            wp_update_post([
                'ID'         => $post_id,
                'post_title' => $new_title,
                'post_name'  => sanitize_title($new_title),
            ]);
        }
    }

}, 10, 3);

/**
 *  show custom columns
 */
add_filter('manage_fahrer_posts_columns', function($columns) {
    $columns['nachname'] = 'Nachname';
    $columns['vorname'] = 'Vorname';
    $columns['team'] = 'Team';
	$columns['rennklasse'] = 'Rennklasse';
	$columns['kategorie'] = 'Kategorie';
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
 *  fill columns with desired content
 */
add_action('manage_fahrer_posts_custom_column', function($column, $post_id) {
    switch ($column) {
        case 'vorname':
        case 'nachname':
        case 'uci_id':
        case 'lizenznummer':
		#case 'kategorie':
		#case 'rennklasse':
            echo esc_html(get_post_meta($post_id, $column, true));
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
 * compact optics for the table (one line per entry, remove "Bearbeiten", "Papierkorb", "Purge from cache" etc)
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
 * Team-Filter-Dropdown in der Fahrer-Liste einblenden
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
 * Filter in Fahrer-Liste anwenden
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
 * DEBUG: 'admin_notices'
 * z.B. folgende URL im browser aufrufen:
 * wp-admin/edit.php?post_type=fahrer&debug_fahrer=6355
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

require_once MELDETOOL_PLUGIN_DIR . 'export_rider_list.php';
require_once MELDETOOL_PLUGIN_DIR . 'install.php';
require_once MELDETOOL_PLUGIN_DIR . 'settings.php';