<?php
/* Settings page for Meldetool ------------------------------------------------- */

function meldetool_default_mail_texts() {
    return array(
        'confirmation_subject' => 'Bestätigung: Team-Anmeldung erhalten',
        'confirmation_message' => "Hallo\n\nIhr Team '{teamname}' wurde erfolgreich an den Veranstalter übermittelt.\n\nFalls Änderungen nötig sind, können Sie sich bei uns melden. Sobald das Team offiziell angemeldet ist, werden Sie von uns benachrichtigt.\n\nMit freundlichen Grüßen\nIhr Racedays-Team",
        'confirmation_subject_publish' => 'Ihr Team wurde in der Datenbank angelegt',
        'confirmation_message_publish' => "Hallo\n\nIhr Team '{teamname}' ist nun offiziell für die Race Days Stuttgart angemeldet.\n\nSie können nun Fahrer hinzufügen oder Änderungen vornehmen.\n\nMit freundlichen Grüßen\nIhr Racedays-Team",
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

    add_settings_field('send_confirmation', 'Bestätigungs-E-Mails senden', function() {
        $opts = get_option('meldetool_options', array());
        $val = isset($opts['send_confirmation']) ? (bool) $opts['send_confirmation'] : true;
        printf('<input type="checkbox" name="meldetool_options[send_confirmation]" value="1" %s />', checked(1, (int) $val, false));
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('from_email', 'Absender-E-Mail', function() {
        $opts = get_option('meldetool_options', array());
        $val = isset($opts['from_email']) ? esc_attr($opts['from_email']) : '';
        printf('<input type="email" name="meldetool_options[from_email]" value="%s" class="regular-text" placeholder="orga@the-race-days-stuttgart.de" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('reply_to', 'Reply-To', function() {
        $opts = get_option('meldetool_options', array());
        $val = isset($opts['reply_to']) ? esc_attr($opts['reply_to']) : '';
        printf('<input type="email" name="meldetool_options[reply_to]" value="%s" class="regular-text" placeholder="orga@the-race-days-stuttgart.de" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('cc_email', 'CC-E-Mail (Kopie der Bestätigung)', function() {
        $opts = get_option('meldetool_options', array());
        $val = isset($opts['cc_email']) ? esc_attr($opts['cc_email']) : '';
        printf('<input type="email" name="meldetool_options[cc_email]" value="%s" class="regular-text" placeholder="orga@the-race-days-stuttgart.de" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('confirmation_subject', 'E-Mail Betreff', function() {
        $opts = get_option('meldetool_options', array());
        $defaults = meldetool_default_mail_texts();
        $val = isset($opts['confirmation_subject']) && $opts['confirmation_subject'] !== ''
            ? esc_attr($opts['confirmation_subject'])
            : esc_attr($defaults['confirmation_subject']);
        printf('<input type="text" name="meldetool_options[confirmation_subject]" value="%s" class="regular-text" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('confirmation_message', 'E-Mail Nachricht (Platzhalter: {teamname})', function() {
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

    add_settings_field('confirmation_message_publish', 'E-Mail Nachricht (Veröffentlichung, Platzhalter: {teamname})', function() {
        $opts = get_option('meldetool_options', array());
        $defaults = meldetool_default_mail_texts();
        $val = isset($opts['confirmation_message_publish']) && $opts['confirmation_message_publish'] !== ''
            ? esc_textarea($opts['confirmation_message_publish'])
            : esc_textarea($defaults['confirmation_message_publish']);
        printf('<textarea name="meldetool_options[confirmation_message_publish]" rows="8" class="large-text">%s</textarea>', $val);
    }, 'meldetool_settings', 'meldetool_main');
});

function meldetool_sanitize_options($input) {
    $defaults = meldetool_default_mail_texts();
    $out = array();

    $out['send_confirmation'] = !empty($input['send_confirmation']) ? 1 : 0;
    $out['from_email'] = !empty($input['from_email']) && is_email($input['from_email']) ? sanitize_email($input['from_email']) : '';
    $out['reply_to'] = !empty($input['reply_to']) && is_email($input['reply_to']) ? sanitize_email($input['reply_to']) : '';
    $out['cc_email'] = !empty($input['cc_email']) && is_email($input['cc_email']) ? sanitize_email($input['cc_email']) : '';
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