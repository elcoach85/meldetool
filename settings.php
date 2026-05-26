<?php
/* Settings page for Meldetool ------------------------------------------------- */

function meldetool_is_logging_enabled() {
    $opts = get_option('meldetool_options', array());
    return !empty($opts['enable_logging']) ? true : false;
}

function meldetool_default_mail_texts() {
    return array(
        'confirmation_subject' => '[Race Days] Teammeldung erhalten',
        'confirmation_message' => "Hallo {teammanager},\n\nDein Team '{teamname}' wurde erfolgreich an den Veranstalter übermittelt.\n\nFalls Änderungen nötig sind, kannst du dich bei uns melden. Sobald das Team offiziell angemeldet ist, wirst du von uns benachrichtigt.\n\nMit sportlichen Grüßen\nDein Race Days-Team",
        'confirmation_subject_publish' => '[Race Days] Team gemeldet',
        'confirmation_message_publish' => "Hallo {teammanager},\n\nDein Team '{teamname}' ist nun offiziell für die Race Days Stuttgart angemeldet.\n\nDu kannst nun Fahrer hinzufügen.\n\nMit sportlichen Grüßen\nDein Race Days-Team",
        'rider_confirmation_subject' => '[Race Days] Bitte E-Mail-Adresse bestätigen',
        'rider_confirmation_message' => "Hallo {ridername},\n\nvielen Dank für deine Anmeldung im Team '{teamname}'.\n\nBitte bestätige deine E-Mail-Adresse über folgenden Link:\n{confirm_url}\n\nMit sportlichen Grüßen\nDein Race Days-Team",
        'rider_details_subject' => '[Race Days] Fahrerdetails bestätigt',
        'rider_details_message' => "Hallo {ridername},\n\ndeine E-Mail-Adresse wurde bestätigt und du bist nun für das Team {teamname} gemeldet.\n\nBitte vergewissere dich, dass alle Daten korrekt sind:\n{riderdetails}\n\nMit sportlichen Grüßen\nDein Race Days-Team",
    );
}

function meldetool_default_etappen_lists() {
    return array(
        'u17' => array('Etappe 1', 'Etappe 2-4', 'Etappe 1-4'),
        'hobby' => array('Solitude', 'Magstadt', 'Solitude & Magstadt'),
    );
}

function meldetool_normalize_etappen_list($raw_value, $fallback) {
    $lines = is_array($raw_value)
        ? $raw_value
        : preg_split('/\r\n|\r|\n/', (string) $raw_value);

    $normalized = array();
    foreach ((array) $lines as $line) {
        $value = sanitize_text_field(trim((string) $line));
        if ($value === '' || in_array($value, $normalized, true)) {
            continue;
        }
        $normalized[] = $value;
    }

    if (empty($normalized)) {
        return array_values((array) $fallback);
    }

    return $normalized;
}

function meldetool_get_configured_etappen_lists($source_options = null) {
    $defaults = meldetool_default_etappen_lists();
    $opts = is_array($source_options) ? $source_options : get_option('meldetool_options', array());

    return array(
        'u17' => meldetool_normalize_etappen_list(
            isset($opts['etappen_options_u17']) ? $opts['etappen_options_u17'] : '',
            $defaults['u17']
        ),
        'hobby' => meldetool_normalize_etappen_list(
            isset($opts['etappen_options_hobby']) ? $opts['etappen_options_hobby'] : '',
            $defaults['hobby']
        ),
    );
}

add_action('admin_menu', function() {
    add_options_page('Meldetool Einstellungen', 'Meldetool', 'manage_options', 'meldetool-settings', 'meldetool_settings_page');
});

add_action('admin_init', function() {
    register_setting('meldetool_settings', 'meldetool_options', 'meldetool_sanitize_options');

    add_settings_section('meldetool_main', 'Allgemeine Einstellungen', function() {
        echo '<p>Einstellungen für E-Mail-Benachrichtigungen und Vorlagen.</p>';
    }, 'meldetool_settings');

    add_settings_field('enable_logging', 'Logging aktivieren', function() {
        $opts = get_option('meldetool_options', array());
        $val = isset($opts['enable_logging']) ? (bool) $opts['enable_logging'] : false;
        printf('<input type="checkbox" name="meldetool_options[enable_logging]" value="1" %s />', checked(1, (int) $val, false));
        echo '<span class="description" style="color: #d63638; font-weight: bold;">⚠️ WARNUNG: Falls aktiviert, werden E-Mail-Versand-Logs in der Datei <code>mail_log.txt</code> protokolliert. Diese ist nur für Debugging gedacht! Logging im Produktivbetrieb deaktivieren, um sensible Daten zu schützen.</span>';
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('send_confirmation', 'Bestätigungs-E-Mails senden', function() {
        $opts = get_option('meldetool_options', array());
        $val = isset($opts['send_confirmation']) ? (bool) $opts['send_confirmation'] : true;
        printf('<input type="checkbox" name="meldetool_options[send_confirmation]" value="1" %s />', checked(1, (int) $val, false));
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('from_email', 'Absender-E-Mail', function() {
        $opts = get_option('meldetool_options', array());
        $val = isset($opts['from_email']) ? esc_attr($opts['from_email']) : '';
        printf('<input type="email" name="meldetool_options[from_email]" value="%s" class="regular-text" placeholder="meldungen@the-race-days-stuttgart.de" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('reply_to', 'Reply-To', function() {
        $opts = get_option('meldetool_options', array());
        $val = isset($opts['reply_to']) ? esc_attr($opts['reply_to']) : '';
        printf('<input type="email" name="meldetool_options[reply_to]" value="%s" class="regular-text" placeholder="meldungen@the-race-days-stuttgart.de" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('cc_email', 'CC-E-Mail (optional, Kopie der Bestätigung)', function() {
        $opts = get_option('meldetool_options', array());
        $val = isset($opts['cc_email']) ? esc_attr($opts['cc_email']) : '';
        printf('<input type="email" name="meldetool_options[cc_email]" value="%s" class="regular-text" placeholder="leer lassen = keine CC" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('etappen_options_u17', 'Etappenoptionen U17', function() {
        $lists = meldetool_get_configured_etappen_lists();
        $val = esc_textarea(implode("\n", $lists['u17']));
        printf('<textarea name="meldetool_options[etappen_options_u17]" rows="4" class="large-text code">%s</textarea>', $val);
        echo '<p class="description">Eine Option pro Zeile. Gilt nur für U17-Teams.</p>';
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('etappen_options_hobby', 'Etappenoptionen Hobby', function() {
        $lists = meldetool_get_configured_etappen_lists();
        $val = esc_textarea(implode("\n", $lists['hobby']));
        printf('<textarea name="meldetool_options[etappen_options_hobby]" rows="4" class="large-text code">%s</textarea>', $val);
        echo '<p class="description">Eine Option pro Zeile. Gilt nur für Hobbyteams.</p>';
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('nationality_codes', 'Nationalitätscodes (Fahrer)', function() {
        $pick_data = function_exists('meldetool_get_configured_nationality_pick_data')
            ? meldetool_get_configured_nationality_pick_data()
            : array('DEU' => 'Deutschland', 'COL' => 'Kolumbien');
        $val = function_exists('meldetool_nationality_pick_data_to_option_text')
            ? esc_textarea(meldetool_nationality_pick_data_to_option_text($pick_data))
            : esc_textarea("DEU=Deutschland\nCOL=Kolumbien");

        printf('<textarea name="meldetool_options[nationality_codes]" rows="10" class="large-text code">%s</textarea>', $val);
        echo '<p class="description">Eine Zeile pro Eintrag, z. B. <code>DEU=Deutschland</code> oder <code>COL=Kolumbien</code>. Trennzeichen <code>=</code>, <code>|</code> oder <code>;</code> sind erlaubt.</p>';
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('confirmation_subject', 'E-Mail Betreff', function() {
        $opts = get_option('meldetool_options', array());
        $defaults = meldetool_default_mail_texts();
        $val = isset($opts['confirmation_subject']) && $opts['confirmation_subject'] !== ''
            ? esc_attr($opts['confirmation_subject'])
            : esc_attr($defaults['confirmation_subject']);
        printf('<input type="text" name="meldetool_options[confirmation_subject]" value="%s" class="regular-text" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('confirmation_message', 'E-Mail Nachricht (Platzhalter: {teammanager}, {teamname}, {teamdetails})', function() {
        $opts = get_option('meldetool_options', array());
        $defaults = meldetool_default_mail_texts();
        $val = isset($opts['confirmation_message']) && $opts['confirmation_message'] !== ''
            ? esc_textarea($opts['confirmation_message'])
            : esc_textarea($defaults['confirmation_message']);
        printf('<textarea name="meldetool_options[confirmation_message]" rows="8" class="large-text">%s</textarea>', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('confirmation_subject_publish', 'E-Mail Betreff (Veröffentlichung)', function() {
        $opts = get_option('meldetool_options', array());
        $defaults = meldetool_default_mail_texts();
        $val = isset($opts['confirmation_subject_publish']) && $opts['confirmation_subject_publish'] !== ''
            ? esc_attr($opts['confirmation_subject_publish'])
            : esc_attr($defaults['confirmation_subject_publish']);
        printf('<input type="text" name="meldetool_options[confirmation_subject_publish]" value="%s" class="regular-text" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('confirmation_message_publish', 'E-Mail Nachricht (Veröffentlichung, Platzhalter: {teammanager}, {teamname}, {teamdetails})', function() {
        $opts = get_option('meldetool_options', array());
        $defaults = meldetool_default_mail_texts();
        $val = isset($opts['confirmation_message_publish']) && $opts['confirmation_message_publish'] !== ''
            ? esc_textarea($opts['confirmation_message_publish'])
            : esc_textarea($defaults['confirmation_message_publish']);
        printf('<textarea name="meldetool_options[confirmation_message_publish]" rows="8" class="large-text">%s</textarea>', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('rider_confirmation_subject', 'E-Mail Betreff (Fahrer-Bestaetigung)', function() {
        $opts = get_option('meldetool_options', array());
        $defaults = meldetool_default_mail_texts();
        $val = isset($opts['rider_confirmation_subject']) && $opts['rider_confirmation_subject'] !== ''
            ? esc_attr($opts['rider_confirmation_subject'])
            : esc_attr($defaults['rider_confirmation_subject']);
        printf('<input type="text" name="meldetool_options[rider_confirmation_subject]" value="%s" class="regular-text" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('rider_confirmation_message', 'E-Mail Nachricht (Fahrer-Bestaetigung, Platzhalter: {ridername}, {teamname}, {confirm_url})', function() {
        $opts = get_option('meldetool_options', array());
        $defaults = meldetool_default_mail_texts();
        $val = isset($opts['rider_confirmation_message']) && $opts['rider_confirmation_message'] !== ''
            ? esc_textarea($opts['rider_confirmation_message'])
            : esc_textarea($defaults['rider_confirmation_message']);
        printf('<textarea name="meldetool_options[rider_confirmation_message]" rows="8" class="large-text">%s</textarea>', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('rider_details_subject', 'E-Mail Betreff (Fahrerdetails nach Bestaetigung)', function() {
        $opts = get_option('meldetool_options', array());
        $defaults = meldetool_default_mail_texts();
        $val = isset($opts['rider_details_subject']) && $opts['rider_details_subject'] !== ''
            ? esc_attr($opts['rider_details_subject'])
            : esc_attr($defaults['rider_details_subject']);
        printf('<input type="text" name="meldetool_options[rider_details_subject]" value="%s" class="regular-text" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('rider_details_message', 'E-Mail Nachricht (Fahrerdetails, Platzhalter: {ridername}, {teamname}, {riderdetails})', function() {
        $opts = get_option('meldetool_options', array());
        $defaults = meldetool_default_mail_texts();
        $val = isset($opts['rider_details_message']) && $opts['rider_details_message'] !== ''
            ? esc_textarea($opts['rider_details_message'])
            : esc_textarea($defaults['rider_details_message']);
        printf('<textarea name="meldetool_options[rider_details_message]" rows="8" class="large-text">%s</textarea>', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('limits_rennklasse', 'Teilnehmerlimits Rennklassen', function() {
        $opts = get_option('meldetool_options', array());
        $limits = isset($opts['limits_rennklasse']) && is_array($opts['limits_rennklasse'])
            ? $opts['limits_rennklasse']
            : array();
        $terms = get_terms(array(
            'taxonomy'   => 'rennklasse',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));
        if (is_wp_error($terms) || empty($terms)) {
            echo '<p class="description">Keine Rennklassen vorhanden.</p>';
            return;
        }
        $counts = function_exists('meldetool_get_rennklasse_rider_counts')
            ? meldetool_get_rennklasse_rider_counts()
            : array();
        echo '<table class="widefat striped" style="max-width:620px;"><thead><tr><th>Rennklasse</th><th style="width:120px;">Limit</th><th style="width:120px;">Gemeldet</th><th style="width:140px;">Auslastung</th></tr></thead><tbody>';
        foreach ($terms as $term) {
            $term_id = (int) $term->term_id;
            $val = isset($limits[$term_id]) ? (int) $limits[$term_id] : 0;
            $count = (int) ($counts[$term_id] ?? 0);

            if ($val > 0) {
                $ratio = $count / $val;
                $color = '';
                if ($count >= $val) {
                    $color = '#d63638';
                } elseif ($ratio >= 0.9) {
                    $color = '#dba617';
                }
                $style = $color !== '' ? sprintf(' style="color:%s;font-weight:600;"', esc_attr($color)) : '';
                $auslastung_html = sprintf('<span%s>%d / %d</span>', $style, $count, $val);
            } else {
                $auslastung_html = esc_html($count . ' / —');
            }

            printf(
                '<tr><td>%s</td><td><input type="number" min="0" step="1" name="meldetool_options[limits_rennklasse][%d]" value="%s" class="small-text" /></td><td>%d</td><td>%s</td></tr>',
                esc_html($term->name),
                $term_id,
                $val > 0 ? esc_attr((string) $val) : '',
                $count,
                $auslastung_html
            );
        }
        echo '</tbody></table>';
        echo '<p class="description">Pro Rennklasse das maximal zulässige Teilnehmerlimit angeben. <strong>0 oder leer = kein Limit</strong>. Bei Erreichen werden Teams dieser Rennklasse im Fahrer-Anmeldeformular blockiert.</p>';
    }, 'meldetool_settings', 'meldetool_main');
});

function meldetool_sanitize_options($input) {
    $defaults = meldetool_default_mail_texts();
    $etappen_lists = meldetool_get_configured_etappen_lists($input);
    $nationality_pick_data = function_exists('meldetool_get_configured_nationality_pick_data')
        ? meldetool_get_configured_nationality_pick_data($input)
        : array('DEU' => 'Deutschland', 'COL' => 'Kolumbien');
    $out = array();

    $out['enable_logging'] = !empty($input['enable_logging']) ? 1 : 0;
    $out['send_confirmation'] = !empty($input['send_confirmation']) ? 1 : 0;
    $out['from_email'] = !empty($input['from_email']) && is_email($input['from_email']) ? sanitize_email($input['from_email']) : '';
    $out['reply_to'] = !empty($input['reply_to']) && is_email($input['reply_to']) ? sanitize_email($input['reply_to']) : '';
    $out['cc_email'] = !empty($input['cc_email']) && is_email($input['cc_email']) ? sanitize_email($input['cc_email']) : '';
    $out['etappen_options_u17'] = implode("\n", $etappen_lists['u17']);
    $out['etappen_options_hobby'] = implode("\n", $etappen_lists['hobby']);
    $out['nationality_codes'] = function_exists('meldetool_nationality_pick_data_to_option_text')
        ? meldetool_nationality_pick_data_to_option_text($nationality_pick_data)
        : "DEU=Deutschland\nCOL=Kolumbien";
    $out['confirmation_subject'] = isset($input['confirmation_subject']) && $input['confirmation_subject'] !== ''
        ? sanitize_text_field($input['confirmation_subject'])
        : $defaults['confirmation_subject'];
    $out['confirmation_message'] = isset($input['confirmation_message']) && $input['confirmation_message'] !== ''
        ? wp_kses_post($input['confirmation_message'])
        : $defaults['confirmation_message'];
    $out['confirmation_subject_publish'] = isset($input['confirmation_subject_publish']) && $input['confirmation_subject_publish'] !== ''
        ? sanitize_text_field($input['confirmation_subject_publish'])
        : $defaults['confirmation_subject_publish'];
    $out['confirmation_message_publish'] = isset($input['confirmation_message_publish']) && $input['confirmation_message_publish'] !== ''
        ? wp_kses_post($input['confirmation_message_publish'])
        : $defaults['confirmation_message_publish'];
    $out['rider_confirmation_subject'] = isset($input['rider_confirmation_subject']) && $input['rider_confirmation_subject'] !== ''
        ? sanitize_text_field($input['rider_confirmation_subject'])
        : $defaults['rider_confirmation_subject'];
    $out['rider_confirmation_message'] = isset($input['rider_confirmation_message']) && $input['rider_confirmation_message'] !== ''
        ? wp_kses_post($input['rider_confirmation_message'])
        : $defaults['rider_confirmation_message'];
    $out['rider_details_subject'] = isset($input['rider_details_subject']) && $input['rider_details_subject'] !== ''
        ? sanitize_text_field($input['rider_details_subject'])
        : $defaults['rider_details_subject'];
    $out['rider_details_message'] = isset($input['rider_details_message']) && $input['rider_details_message'] !== ''
        ? wp_kses_post($input['rider_details_message'])
        : $defaults['rider_details_message'];

    $out['limits_rennklasse'] = array();
    if (!empty($input['limits_rennklasse']) && is_array($input['limits_rennklasse'])) {
        foreach ($input['limits_rennklasse'] as $term_id => $limit) {
            $term_id = (int) $term_id;
            $limit   = (int) $limit;
            if ($term_id > 0 && $limit > 0) {
                $out['limits_rennklasse'][$term_id] = $limit;
            }
        }
    }

    if (function_exists('meldetool_sync_existing_etappen_auswahl_field') && function_exists('meldetool_get_all_etappen_pick_data')) {
        $errors = array();
        $target_data = meldetool_get_all_etappen_pick_data($etappen_lists);
        if (meldetool_sync_existing_etappen_auswahl_field($errors, $target_data)) {
            set_transient('meldetool_etappen_field_sync_success', 1, 60);
        } elseif (!empty($errors)) {
            foreach ($errors as $err) {
                add_settings_error('meldetool_options', 'meldetool_etappen_sync', $err, 'error');
            }
        }
    }

    if (function_exists('meldetool_sync_existing_nationalitaet_field')) {
        $errors = array();
        if (!meldetool_sync_existing_nationalitaet_field($errors, $nationality_pick_data) && !empty($errors)) {
            foreach ($errors as $err) {
                add_settings_error('meldetool_options', 'meldetool_nationality_sync', $err, 'error');
            }
        }
    }

    return $out;
}

function meldetool_settings_page() {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap">';
    echo '<h1>Meldetool Einstellungen</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('meldetool_settings');
    do_settings_sections('meldetool_settings');
    submit_button();
    echo '</form>';
    echo '</div>';
}

/* End settings --------------------------------------------------------------- */