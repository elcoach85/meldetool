<?php
/**
 * Plugin Name: Meldetool
 * Description: A solution to let team managers create their team and add participants to the teams.
 * Version: 0.1.0
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

// Bestätigungsmail direkt nach Frontend-Formular (Pods) absenden
add_action('pods_form_post_save_team', function($fields, $pod) {
        $logfile = MELDETOOL_PLUGIN_DIR . 'mail_log.txt';
        $log_entry = date('Y-m-d H:i:s') . " | PODS_FORM_POST_SAVE_TEAM | fields_id: " . $fields['id'] . "\n";
        $log_entry .= str_repeat('-', 60) . "\n";
        file_put_contents($logfile, $log_entry, FILE_APPEND);
    $post_id = isset($fields['ID']) ? $fields['ID'] : (isset($fields['id']) ? $fields['id'] : null);
    if (!$post_id) return;
    $teamname = isset($fields['teamname']) ? $fields['teamname'] : get_post_meta($post_id, 'teamname', true);
    $email = isset($fields['email_manager']) ? $fields['email_manager'] : get_post_meta($post_id, 'email_manager', true);
    if (!empty($email) && is_email($email)) {
        $opts = get_option('meldetool_options', array());
        $enabled = isset($opts['send_confirmation']) ? (bool) $opts['send_confirmation'] : true;
        if ($enabled) {
            $subject = !empty($opts['confirmation_subject']) ? $opts['confirmation_subject'] : 'Bestätigung: Team-Anmeldung erhalten';
            $default_message = "Hallo\n\n" . sprintf("Ihr Team \"%s\" wurde erfolgreich für die Veranstaltung angemeldet.\n\n", $teamname) . "Falls Änderungen nötig sind, können Sie sich bei uns melden.\n\nMit freundlichen Grüßen\nIhr Meldetool-Team";
            $message = !empty($opts['confirmation_message']) ? $opts['confirmation_message'] : $default_message;
            $message = str_replace('{teamname}', $teamname, $message);
            $headers = array('Content-Type: text/plain; charset=UTF-8');
            if (!empty($opts['from_email']) && is_email($opts['from_email'])) {
                $headers[] = 'From: ' . $opts['from_email'];
            }
            if (!empty($opts['reply_to']) && is_email($opts['reply_to'])) {
                $headers[] = 'Reply-To: ' . $opts['reply_to'];
            }
            $cc = !empty($opts['cc_email']) && is_email($opts['cc_email']) ? $opts['cc_email'] : 'orga@the-race-days-stuttgart.de';
            $headers[] = 'Cc: ' . $cc;
            $mail_result = wp_mail($email, $subject, $message, $headers);
            // Logging
            $logfile = MELDETOOL_PLUGIN_DIR . 'mail_log.txt';
            $log_entry = date('Y-m-d H:i:s') . " | PODS_FORM_POST_SAVE_TEAM | " . ($mail_result ? 'SUCCESS' : 'FAIL') . "\n";
            $log_entry .= "To: $email\nSubject: $subject\nHeaders: " . print_r($headers, true) . "\n";
            $log_entry .= "Message: $message\n";
            if (!$mail_result) {
                $log_entry .= "Error: Mailversand fehlgeschlagen.\n";
            }
            $log_entry .= str_repeat('-', 60) . "\n";
            file_put_contents($logfile, $log_entry, FILE_APPEND);
        }
    }
}, 10, 2);


/**
 * Fahrername
 */
add_action('save_post_fahrer', function($post_id, $post, $update) {

    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    $vorname = get_post_meta($post_id, 'vorname', true);
    $nachname = get_post_meta($post_id, 'nachname', true);

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
 * Teamname
 */
add_action('save_post_team', function($post_id, $post, $update) {
    // Test-Log: Wird die Funktion überhaupt aufgerufen?
    $testlog = MELDETOOL_PLUGIN_DIR . 'mail_log.txt';
    file_put_contents($testlog, date('Y-m-d H:i:s') . " | save_post_team HOOK called | post_id: $post_id | update: $update | is_admin: " . (is_admin() ? '1' : '0') . "\n", FILE_APPEND);

    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    $teamname = get_post_meta($post_id, 'teamname', true);

    if ($teamname) {
        $new_title = trim($teamname);

        if ($new_title && $new_title !== $post->post_title) {
            wp_update_post([
                'ID'         => $post_id,
                'post_title' => $new_title,
                'post_name'  => sanitize_title($new_title),
            ]);
        }
    }
            // ...existing code...

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