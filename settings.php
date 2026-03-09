<?php
/* Settings page for Meldetool ------------------------------------------------- */

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
        $val = isset($opts['from_email']) ? esc_attr($opts['from_email']) : 'orga@the-race-days-stuttgart.de';
        printf('<input type="email" name="meldetool_options[from_email]" value="%s" class="regular-text" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('reply_to', 'Reply-To', function() {
        $opts = get_option('meldetool_options', array());
        $val = isset($opts['reply_to']) ? esc_attr($opts['reply_to']) : '';
        printf('<input type="email" name="meldetool_options[reply_to]" value="%s" class="regular-text" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('confirmation_subject', 'E-Mail Betreff', function() {
        $opts = get_option('meldetool_options', array());
        $val = isset($opts['confirmation_subject']) ? esc_attr($opts['confirmation_subject']) : '';
        printf('<input type="text" name="meldetool_options[confirmation_subject]" value="%s" class="regular-text" />', $val);
    }, 'meldetool_settings', 'meldetool_main');

    add_settings_field('confirmation_message', 'E-Mail Nachricht (Platzhalter: {teamname})', function() {
        $opts = get_option('meldetool_options', array());
        $val = isset($opts['confirmation_message']) ? esc_textarea($opts['confirmation_message']) : '';
        printf('<textarea name="meldetool_options[confirmation_message]" rows="8" class="large-text">%s</textarea>', $val);
    }, 'meldetool_settings', 'meldetool_main');
});

function meldetool_sanitize_options($input) {
    $out = array();
    $out['send_confirmation'] = !empty($input['send_confirmation']) ? 1 : 0;
    $out['from_email'] = !empty($input['from_email']) && is_email($input['from_email']) ? sanitize_email($input['from_email']) : '';
    $out['reply_to'] = !empty($input['reply_to']) && is_email($input['reply_to']) ? sanitize_email($input['reply_to']) : '';
    $out['confirmation_subject'] = !empty($input['confirmation_subject']) ? sanitize_text_field($input['confirmation_subject']) : '';
    $out['confirmation_message'] = !empty($input['confirmation_message']) ? wp_kses_post($input['confirmation_message']) : '';
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