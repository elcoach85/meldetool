<?php
/**
 * Plugin Name: Meldetool
 * Description: A solution to let team managers create their team and add participants to the teams.
 * Version: 1.0.0
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
 * Erstellt formatiertes Text-Snippet mit Team-Detailinformationen
 * 
 * Wird verwendet in E-Mail-Benachrichtigungen als Placeholder {teamdetails}
 * Enthält Teammanager, E-Mail, Bankdaten, Rennklasse etc.
 * 
 * @param int $team_id - WordPress Post-ID des Teams
 * @param string $teamname - Optional: Teamname (wird von Post-Title abgeleitet falls leer)
 * @return string Formatierte Team-Details, zeilengetrennt
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

/**
 * Versendet E-Mail an Teammanager mit Platzhalter-Ersetzung und Logging
 * 
 * Platzhalter die ersetzt werden:
 * - {teamname}: Name des Teams
 * - {teammanager}: Name des Sportlichen Leiters/Teammanagers
 * - {teamdetails}: Vollständige Team-Informationen
 * 
 * Logging:
 * - Sämtliche versendeten E-Mails (erfolgreich/fehlgeschlagen) werden in mail_log.txt protokolliert
 * - Enthält Empfänger, Betreff, Header und Nachrichtentext
 * 
 * @param string $email - E-Mail-Adresse des Empfängers
 * @param string $teamname - Name des Teams
 * @param string $subject - E-Mail-Betreff
 * @param string $message - E-Mail-Nachrichtentext (mit Platzhaltern)
 * @param int $team_id - WordPress Post-ID des Teams (optional, für Detailinformationen)
 * @param bool $send_copy_to_orga - CC-Kopie an Orga-E-Mail versenden?
 * @param bool $append_team_details - Team-Details automatisch anhängen falls {teamdetails} nicht gesetzt?
 * @return bool true wenn wp_mail erfolgreich war, false sonst
 */
function meldetool_send_team_mail($email, $teamname, $subject, $message, $team_id = 0, $send_copy_to_orga = false, $append_team_details = true) {
    $opts = get_option('meldetool_options', array());
    $from_name = 'Race Days Orga-Team';
    $from_email = (!empty($opts['from_email']) && is_email($opts['from_email']))
        ? $opts['from_email']
        : get_option('admin_email');
    // Platzhalter-Ersetzung vorbereiten
    $teammanager = !empty($team_id) ? get_post_meta((int) $team_id, 'teammanager', true) : '';
    $team_details = meldetool_get_team_details_text((int) $team_id, $teamname);
    $has_teamdetails_placeholder = (strpos($message, '{teamdetails}') !== false);
    $has_teammanager_placeholder = (strpos($message, '{teammanager}') !== false);
    
    // Alle Platzhalter in der Nachricht ersetzen
    $message = str_replace(
        array('{teamname}', '{teamdetails}', '{teammanager}'),
        array($teamname, $team_details, $teammanager),
        $message
    );
    
    // Fallback-Personalisierung: Wenn {teammanager} nicht im Template, trotzdem mit Name grüßen
    if (!$has_teammanager_placeholder && !empty($teammanager) && !empty($email) && !empty($team_id)) {
        $manager_email = get_post_meta((int) $team_id, 'email_manager', true);
        if (!empty($manager_email) && strcasecmp((string) $manager_email, (string) $email) === 0) {
            $message = "Hallo " . $teammanager . ",\n\n" . ltrim((string) $message);
        }
    }
    
    // Team-Details automatisch anhängen, wenn nicht explizit im Template und vorhanden
    if ($append_team_details && !$has_teamdetails_placeholder && !empty($team_details)) {
        $message .= "\n\nTeamdetails:\n" . $team_details;
    }

    // E-Mail-Header zusammenstellen (From, Reply-To, CC)
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    if (!empty($from_email) && is_email($from_email)) {
        $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
    }
    if (!empty($opts['reply_to']) && is_email($opts['reply_to'])) {
        $headers[] = 'Reply-To: ' . $opts['reply_to'];
    }
    if ($send_copy_to_orga) {
        $cc = !empty($opts['cc_email']) && is_email($opts['cc_email']) ? $opts['cc_email'] : 'meldungen@the-race-days-stuttgart.de';
        if (!empty($cc) && is_email($cc)) {
            $headers[] = 'Cc: ' . $cc;
        }
    }
    
    // HTML-Entitäten dekodieren (z.B. &#8211; → –), da E-Mail als Plain Text versendet wird
    $subject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // E-Mail versenden
    $mail_result = wp_mail($email, $subject, $message, $headers);
    
    /**
     * Alle E-Mails loggen (unabhängig von Erfolg/Fehler)
     * Log-Datei: plugins/meldetool/mail_log.txt
     * Nützlich für Troubleshooting und Audit-Trail
     */
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

/**
 * Erstellt formatiertes Text-Snippet mit Fahrer-Detailinformationen
 * 
 * Wird in E-Mails und Bestätigungen verwendet als Placeholder {riderdetails}
 * Enthält Name, E-Mail, Lizenzen, Nationalität, Team, Kategorie, Kapitän-Status
 * 
 * @param int $rider_id - WordPress Post-ID des Fahrers
 * @return string Formatierte Fahrer-Details, zeilengetrennt (oder leerer String wenn ID ungültig)
 */
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

/**
 * Versendet Bestätigungs-E-Mail an Fahrer mit Bestätigungs-Link (Double-Opt-In)
 * 
 * Der Link enthält:
 * - meldetool_confirm_rider=1
 * - rider_id: WordPress Post-ID des Fahrers
 * - token: Eindeutiger Bestätigungs-Token (wird später verglichen)
 * 
 * Platzhalter-Variablen im Template:
 * - {ridername}: Name des Fahrers
 * - {teamname}: Name des Teams
 * - {confirm_url}: Bestätigungs-Link mit Token
 * 
 * @param int $rider_id - WordPress Post-ID des Fahrers
 * @param string $rider_email - E-Mail-Adresse des Fahrers
 * @param string $rider_name - Name des Fahrers
 * @param string $teamname - Name des Teams
 * @param string $confirm_url - Vollständige Bestätigungs-URL mit Token
 * @return bool true wenn wp_mail erfolgreich
 */
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

    // HTML-Entitaeten dekodieren, da Mail als Plain Text versendet wird
    $subject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

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

/**
 * Versendet Fahrerdetails-Bestätigung nach erfolgreicher E-Mail-Bestätigung
 * 
 * Wird automatisch nach Link-Bestätigung (Double-Opt-In) ausgelöst.
 * Versendet Mail an:
 * 1. Fahrer: Bestätigung seiner Daten
 * 2. Teammanager (falls unterschiedlich): Information über neuen Fahrer
 * 
 * Verhindert Doppelversand via Meta-Flag: _meldetool_rider_details_sent
 * 
 * @param int $rider_id - WordPress Post-ID des Fahrers
 */
function meldetool_send_rider_details_mail($rider_id) {
    $rider_id = (int) $rider_id;
    if (!$rider_id) {
        return;
    }

    // Verhindert Doppelversand: Flag wird nur gesetzt, wenn Mail erfolgreich versendet
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
        $manager_message .= "deinem Team '{teamname}' wurde eine*e neue*r Fahrer*in hinzugefügt.\n\n";
        $manager_message .= "Fahrerdetails:\n{riderdetails}";
        $manager_message = str_replace('{riderdetails}', $rider_details, $manager_message);
        meldetool_send_team_mail($manager_email, $teamname, $subject, $manager_message, $team_id, false, false);
        $sent_any = true;
    }

    if ($sent_any) {
        update_post_meta($rider_id, $details_sent_meta, 1);
    }
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
 * Double-Opt-In Workflow für Fahrer:
 * 1. Neuer Fahrer wird angelegt
 * 2. Bestätigungs-E-Mail mit Token wird versendet
 * 3. Fahrer klickt Link
 * 4. Token wird validiert und Fahrerdetails-E-Mail versendet
 * 
 * Hook: pods_api_post_save_pod_item_fahrer (Pods Formular-Save)
 * Verhindert Doppelversand durch Meta-Flags
 */
add_action('pods_api_post_save_pod_item_fahrer', function($data, $pod, $id) {
    $id = (int) $id;
    if (!$id) {
        return;
    }

    // Post-Titel sofort nach Pods-Save synchronisieren (auch ohne spaetere Admin-Bearbeitung)
    $vorname_sync = isset($data['vorname']) ? $data['vorname'] : '';
    $nachname_sync = isset($data['nachname']) ? $data['nachname'] : '';
    meldetool_sync_rider_post_title($id, $vorname_sync, $nachname_sync);

    // Verhindert erneuten Versand von Bestätigungsmails wenn bereits gesendet oder bestätigt
    $confirmation_sent_meta = '_meldetool_rider_confirmation_sent';
    $confirmed_meta = '_meldetool_rider_email_confirmed';
    if (get_post_meta($id, $confirmation_sent_meta, true) || get_post_meta($id, $confirmed_meta, true)) {
        return;
    }

    $rider_email = isset($data['email_rider']) ? $data['email_rider'] : get_post_meta($id, 'email_rider', true);
    if (empty($rider_email) || !is_email($rider_email)) {
        return;
    }

    // Bestätigungsmails ein-/ausschaltbar via Settings
    $opts = get_option('meldetool_options', array());
    $enabled = isset($opts['send_confirmation']) ? (bool) $opts['send_confirmation'] : true;
    if (!$enabled) {
        return;
    }

    // Fahrer-Daten sammeln (aus Form-Daten oder Post-Meta)
    $vorname = isset($data['vorname']) ? $data['vorname'] : get_post_meta($id, 'vorname', true);
    $nachname = isset($data['nachname']) ? $data['nachname'] : get_post_meta($id, 'nachname', true);
    $rider_name = trim($vorname . ' ' . $nachname);
    $team_id = isset($data['team']) ? (int) $data['team'] : (int) get_post_meta($id, 'team', true);
    $teamname = $team_id ? get_the_title($team_id) : '';

    // Eindeutiger Token für Bestätigungslink erstellen (32 Zeichen, alphanumerisch)
    $token = wp_generate_password(32, false, false);
    update_post_meta($id, '_meldetool_rider_confirmation_token', $token);

    // Bestätigungs-URL mit Token konstruieren
    $confirm_url = add_query_arg(
        array(
            'meldetool_confirm_rider' => 1,
            'rider_id' => $id,
            'token' => rawurlencode($token),
        ),
        home_url('/')
    );

    // E-Mail versenden und Erfolg protokollieren
    $mail_result = meldetool_send_rider_confirmation_mail($id, $rider_email, $rider_name, $teamname, $confirm_url);
    if ($mail_result) {
        update_post_meta($id, $confirmation_sent_meta, 1);
    }
}, 10, 3);

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
 * Verarbeitet Fahrer-E-Mail-Bestätigung (template_redirect)
 * 
 * Ablauf:
 * 1. URL-Parameter prüfen (meldetool_confirm_rider + rider_id + token)
 * 2. Token gegen gespeicherten Token validieren (timing-safe comparison)
 * 3. Meta-Flag setzen: _meldetool_rider_email_confirmed
 * 4. Fahrerdetails-Mail versenden
 * 5. Token löschen (einmalige Verwendung)
 * 
 * Hook: template_redirect (lädt vor Template, kann HTTP-Status setzen)
 */
add_action('template_redirect', function() {
    if (!isset($_GET['meldetool_confirm_rider'])) {
        return;
    }

    // Parameter aus GET extrahieren und validieren
    $rider_id = isset($_GET['rider_id']) ? (int) $_GET['rider_id'] : 0;
    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
    if (!$rider_id || empty($token)) {
        wp_die('Ungueltiger Bestaetigungslink.', 'Meldetool', array('response' => 400));
    }

    // Token-Validierung: Timing-safe Comparison verhindert Timing-Attacks
    $stored_token = get_post_meta($rider_id, '_meldetool_rider_confirmation_token', true);
    if (empty($stored_token) || !hash_equals((string) $stored_token, (string) $token)) {
        wp_die('Bestaetigungslink ist ungueltig oder abgelaufen.', 'Meldetool', array('response' => 400));
    }

    // Bestätigung nur einmal verarbeiten
    if (!get_post_meta($rider_id, '_meldetool_rider_email_confirmed', true)) {
        update_post_meta($rider_id, '_meldetool_rider_email_confirmed', 1);
        delete_post_meta($rider_id, '_meldetool_rider_confirmation_token'); // Token nach Verwendung löschen
        meldetool_send_rider_details_mail($rider_id); // Fahrerdetails-Mail versenden
    }

    wp_die('Vielen Dank. Ihre E-Mail-Adresse wurde erfolgreich bestaetigt.', 'Meldetool', array('response' => 200));
});

/**
 * Team-Bestätigungsmail: "Wir haben ihre Anmeldung erhalten"
 * 
 * Wird nach dem Speichern eines Teams über Pods-Formular versendet (template_redirect)
 * Verhindert Doppelversand durch Meta-Flag: _meldetool_confirmation_sent
 * 
 * Hook: pods_api_post_save_pod_item_team (Pods API nach Save)
 * 
 * Nachricht-Inhalte:
 * - Eingangsbestätigung: Wird sofort versendet
 * - Veröffentlichungs-Benachrichtigung: Wird versendet wenn Team publish wird (wp_after_insert_post)
 */
add_action('pods_api_post_save_pod_item_team', function($data, $pod, $id) {
    $mail_sent_meta_key = '_meldetool_confirmation_sent';

    // Verhindert Doppelversand: Wenn Meta-Flag bereits gesetzt, Hook beenden
    if (get_post_meta($id, $mail_sent_meta_key, true)) {
        return;
    }

    // Nur beim Anlegen ausführen: Teamname ist beim ersten Save noch nicht gesetzt 
    // Team-Informationen sammeln (aus Form-Daten oder Meta)
    $teamname = isset($data['teamname']) ? $data['teamname'] : get_post_meta($id, 'teamname', true);
    $email = isset($data['email_manager']) ? $data['email_manager'] : get_post_meta($id, 'email_manager', true);

    // Post-Titel sofort nach Pods-Save synchronisieren (auch ohne spaetere Admin-Bearbeitung)
    meldetool_sync_team_post_title($id, $teamname);

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
 * Veröffentlichungs-Bestätigung: "Team ist nun offiziell angemeldet"
 * 
 * Wird versendet wenn Team-Status auf 'publish' gesetzt wird (z.B. von Admin genehmigt)
 * Verhindert Doppelversand durch Meta-Flag: _meldetool_publish_mail_sent
 * 
 * Hook: wp_after_insert_post (feuert nach kompletten Save inkl. Meta-Daten)
 * 
 * Unterschied zu pods_api_post_save_pod_item_team:
 * - pods_api: Eingangbestätigung direkt nach Formular-Submit
 * - wp_after_insert_post: Veröffentlichungs-Bestätigung wenn Admin Team approved
 */
add_action('wp_after_insert_post', function($post_id, $post, $update) {
    if ($post->post_type !== 'team') return;
    if ($post->post_status !== 'publish') return;

    /**
     * "Team offiziell gemeldet"-Mail: Einmalig senden sobald Team publish ist
     * 
     * Meta-Flag verhindert Doppelversand: _meldetool_publish_mail_sent
     * Hook wp_after_insert_post feuert nach kompletten Save inklusive Meta-Daten
     */
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
 * für bessere Übersichlichkeit bei vielen Einträgen
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
require_once MELDETOOL_PLUGIN_DIR . 'export_rider_list.php'; // CSV-Export Funktionalität
require_once MELDETOOL_PLUGIN_DIR . 'backup_tools.php';     // Vollbackup Export/Import
require_once MELDETOOL_PLUGIN_DIR . 'install.php';          // Installation & Aktivierung
require_once MELDETOOL_PLUGIN_DIR . 'settings.php';         // Admin-Einstellungen Seite
require_once MELDETOOL_PLUGIN_DIR . 'formulardesign.php';   // Frontend-Formular-Logik