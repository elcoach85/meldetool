<?php
/**
 * Meldetool – E-Mail und Bestätigungsmail-Funktionen
 * 
 * Dieses Modul enthält alle Funktionen und Hooks für:
 * - Double-Opt-In Workflow für Fahrer (E-Mail-Bestätigung)
 * - Team-Bestätigungsmails (Eingang- und Veröffentlichungs-Bestätigung)
 * - E-Mail-Versand mit Platzhalter-Ersetzung und Logging
 * - Detail-Formatierung für Fahrer und Teams
 */

defined( 'ABSPATH' ) or die( 'Are you ok?' );

/**
 * Schreibt optionale Debug-Eintraege in mail_log.txt, wenn Logging in den Settings aktiv ist.
 */
function meldetool_debug_log($event, $data = array()) {
    if (!function_exists('meldetool_is_logging_enabled') || !meldetool_is_logging_enabled()) {
        return;
    }

    $logfile = MELDETOOL_PLUGIN_DIR . 'mail_log.txt';
    $entry = date('Y-m-d H:i:s') . ' | DEBUG | ' . $event . "\n";
    if (!empty($data)) {
        $entry .= print_r($data, true) . "\n";
    }
    $entry .= str_repeat('-', 60) . "\n";
    file_put_contents($logfile, $entry, FILE_APPEND);
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
 * @param bool $send_copy_to_orga - Separate Kopie an Orga-E-Mail versenden (cc_email-Option oder Fallback)?
 * @param bool $append_team_details - Team-Details automatisch anhängen falls {teamdetails} nicht gesetzt?
 * @return bool true wenn wp_mail erfolgreich war, false sonst
 */
function meldetool_send_team_mail($email, $teamname, $subject, $message, $team_id = 0, $send_copy_to_orga = false, $append_team_details = true) {
    $opts = get_option('meldetool_options', array());
    $from_name = 'Race Days Meldungen-Team';
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
    // Orga-Kopie-Adresse aus Settings (cc_email). Wird als separater Versand gesendet, nicht als
    // Cc-Header, da SMTP-Plugins/Transaktionsdienste Cc-Header häufig ignorieren oder entfernen.
    // Kein Versand, wenn cc_email in den Einstellungen leer ist.
    $orga_copy_email = '';
    if ($send_copy_to_orga && !empty($opts['cc_email']) && is_email($opts['cc_email'])) {
        $orga_copy_email = $opts['cc_email'];
    }

    // HTML-Entitäten dekodieren (z.B. &#8211; → –), da E-Mail als Plain Text versendet wird
    $subject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Haupt-Mail an Teammanager versenden
    $mail_result = wp_mail($email, $subject, $message, $headers);

    // Orga-Kopie als eigene Mail senden (nicht als Cc), damit SMTP-Plugins sie zuverlässig zustellen
    $orga_result = null;
    if (!empty($orga_copy_email) && strcasecmp($orga_copy_email, $email) !== 0) {
        $orga_result = wp_mail($orga_copy_email, $subject, $message, $headers);
    }

    /**
     * Alle E-Mails loggen (unabhängig von Erfolg/Fehler)
     * Log-Datei: plugins/meldetool/mail_log.txt
     * Nützlich für Troubleshooting und Audit-Trail
     */
    $logfile = MELDETOOL_PLUGIN_DIR . 'mail_log.txt';
    $log_entry = date('Y-m-d H:i:s') . " | TEAM_MAIL | " . ($mail_result ? 'SUCCESS' : 'FAIL') . "\n";
    $log_entry .= "To: $email\nSubject: $subject\nHeaders: " . print_r($headers, true) . "\n";
    if ($orga_result !== null) {
        $log_entry .= "Orga-Copy-To: $orga_copy_email | " . ($orga_result ? 'SUCCESS' : 'FAIL') . "\n";
    }
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
    $from_name = 'Race Days Meldungen-Team';
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
    meldetool_debug_log('TEAM_PODS_SAVE_HOOK_FIRED', array(
        'id'         => (int) $id,
        'data_keys'  => is_array($data) ? array_keys($data) : array(),
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
    ));

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
