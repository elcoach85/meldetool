<?php
/**
 * Full backup export/import for Meldetool.
 * Exports teams and riders including post meta, taxonomy assignments, and plugin options.
 */

add_action('admin_menu', function () {
    add_management_page(
        'Meldetool Backup',
        'Meldetool Backup',
        'manage_options',
        'meldetool-backup',
        'meldetool_backup_tools_page_render'
    );
});

function meldetool_backup_tools_page_render() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung');
    }

    $status = isset($_GET['meldetool_backup_status']) ? sanitize_text_field(wp_unslash($_GET['meldetool_backup_status'])) : '';
    $message = isset($_GET['meldetool_backup_msg']) ? sanitize_text_field(wp_unslash($_GET['meldetool_backup_msg'])) : '';

    echo '<div class="wrap">';
    echo '<h1>Meldetool Backup</h1>';

    if ($status === 'ok' && !empty($message)) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    } elseif ($status === 'error' && !empty($message)) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    echo '<p>Exportiert und importiert Teams und Fahrer*innen inklusive Metadaten, Taxonomie-Zuordnungen und Meldetool-Einstellungen.</p>';

    echo '<h2>Backup exportieren</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('meldetool_backup_export');
    echo '<input type="hidden" name="action" value="meldetool_backup_export" />';
    echo '<p><button type="submit" class="button button-primary">Backup als JSON herunterladen</button></p>';
    echo '</form>';

    echo '<hr />';

    echo '<h2>Backup importieren</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
    wp_nonce_field('meldetool_backup_import');
    echo '<input type="hidden" name="action" value="meldetool_backup_import" />';
    echo '<p><input type="file" name="backup_file" accept="application/json,.json" required /></p>';
    echo '<p><label><input type="checkbox" name="purge_existing" value="1" /> Vor dem Import alle vorhandenen Teams und Fahrer*innen löschen</label></p>';
    echo '<p><button type="submit" class="button button-primary">Backup importieren</button></p>';
    echo '</form>';

    echo '</div>';
}

add_action('admin_post_meldetool_backup_export', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung');
    }
    check_admin_referer('meldetool_backup_export');

    $payload = array(
        'schema_version' => 1,
        'exported_at' => current_time('mysql'),
        'site_url' => home_url('/'),
        'options' => get_option('meldetool_options', array()),
        'taxonomies' => array(
            'rennklasse' => meldetool_backup_collect_terms('rennklasse'),
            'kategorie' => meldetool_backup_collect_terms('kategorie'),
        ),
        'teams' => meldetool_backup_collect_posts('team', array('rennklasse')),
        'riders' => meldetool_backup_collect_posts('fahrer', array('kategorie')),
    );

    $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        wp_die('Backup konnte nicht erstellt werden.');
    }

    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=meldetool-backup-' . gmdate('Y-m-d-His') . '.json');
    echo $json;
    exit;
});

add_action('admin_post_meldetool_backup_import', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung');
    }
    check_admin_referer('meldetool_backup_import');

    if (empty($_FILES['backup_file']) || !isset($_FILES['backup_file']['tmp_name'])) {
        meldetool_backup_redirect('error', 'Keine Backup-Datei ausgewählt.');
    }

    $tmp_name = $_FILES['backup_file']['tmp_name'];
    if (!is_uploaded_file($tmp_name)) {
        meldetool_backup_redirect('error', 'Ungültiger Datei-Upload.');
    }

    $raw = file_get_contents($tmp_name);
    if ($raw === false || $raw === '') {
        meldetool_backup_redirect('error', 'Backup-Datei konnte nicht gelesen werden.');
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        meldetool_backup_redirect('error', 'Backup-Datei ist kein gültiges JSON.');
    }

    if (empty($payload['schema_version']) || (int) $payload['schema_version'] !== 1) {
        meldetool_backup_redirect('error', 'Unbekannte Backup-Version.');
    }

    $purge_existing = !empty($_POST['purge_existing']);
    if ($purge_existing) {
        meldetool_backup_purge_post_type('fahrer');
        meldetool_backup_purge_post_type('team');
    }

    $term_maps = array(
        'rennklasse' => array(),
        'kategorie' => array(),
    );

    if (isset($payload['taxonomies']) && is_array($payload['taxonomies'])) {
        if (!empty($payload['taxonomies']['rennklasse']) && is_array($payload['taxonomies']['rennklasse'])) {
            $term_maps['rennklasse'] = meldetool_backup_ensure_terms('rennklasse', $payload['taxonomies']['rennklasse']);
        }
        if (!empty($payload['taxonomies']['kategorie']) && is_array($payload['taxonomies']['kategorie'])) {
            $term_maps['kategorie'] = meldetool_backup_ensure_terms('kategorie', $payload['taxonomies']['kategorie']);
        }
    }

    $team_map = array();
    $rider_count = 0;
    $team_count = 0;

    if (!empty($payload['teams']) && is_array($payload['teams'])) {
        foreach ($payload['teams'] as $team_data) {
            $new_team_id = meldetool_backup_import_post($team_data, 'team', $team_map, $term_maps);
            if ($new_team_id) {
                $team_count++;
            }
        }
    }

    if (!empty($payload['riders']) && is_array($payload['riders'])) {
        foreach ($payload['riders'] as $rider_data) {
            $new_rider_id = meldetool_backup_import_post($rider_data, 'fahrer', $team_map, $term_maps);
            if ($new_rider_id) {
                $rider_count++;
            }
        }
    }

    if (isset($payload['options']) && is_array($payload['options'])) {
        update_option('meldetool_options', $payload['options']);
    }

    meldetool_backup_redirect('ok', 'Import abgeschlossen: ' . $team_count . ' Teams und ' . $rider_count . ' Fahrer*innen importiert.');
});

function meldetool_backup_collect_terms($taxonomy) {
    $result = array();
    if (!taxonomy_exists($taxonomy)) {
        return $result;
    }

    $terms = get_terms(array(
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
    ));

    if (is_wp_error($terms) || empty($terms)) {
        return $result;
    }

    foreach ($terms as $term) {
        $result[] = array(
            'old_id' => (int) $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
        );
    }

    return $result;
}

function meldetool_backup_collect_posts($post_type, $taxonomies) {
    $result = array();

    $posts = get_posts(array(
        'post_type' => $post_type,
        'post_status' => 'any',
        'numberposts' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ));

    if (empty($posts)) {
        return $result;
    }

    foreach ($posts as $post) {
        $meta = get_post_meta($post->ID);
        $meta_filtered = array();

        foreach ($meta as $meta_key => $values) {
            if (in_array($meta_key, array('_edit_lock', '_edit_last', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time', '_uagb_previous_block_counts'), true)) {
                continue;
            }
            $meta_filtered[$meta_key] = array_map('maybe_unserialize', (array) $values);
        }

        $term_map = array();
        foreach ($taxonomies as $taxonomy) {
            $post_terms = get_the_terms($post->ID, $taxonomy);
            if (!empty($post_terms) && !is_wp_error($post_terms)) {
                $term_map[$taxonomy] = wp_list_pluck($post_terms, 'slug');
            } else {
                $term_map[$taxonomy] = array();
            }
        }

        $result[] = array(
            'old_id' => (int) $post->ID,
            'post_title' => $post->post_title,
            'post_status' => $post->post_status,
            'post_name' => $post->post_name,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_date' => $post->post_date,
            'meta' => $meta_filtered,
            'terms' => $term_map,
        );
    }

    return $result;
}

function meldetool_backup_ensure_terms($taxonomy, $terms) {
    $map = array();
    if (!taxonomy_exists($taxonomy)) {
        return $map;
    }

    foreach ($terms as $term_data) {
        if (empty($term_data['name']) || empty($term_data['slug'])) {
            continue;
        }

        $old_id = !empty($term_data['old_id']) ? (int) $term_data['old_id'] : 0;
        $existing = get_term_by('slug', $term_data['slug'], $taxonomy);

        if ($existing && !is_wp_error($existing)) {
            if ($old_id) {
                $map[$old_id] = (int) $existing->term_id;
            }
            continue;
        }

        $inserted = wp_insert_term(
            $term_data['name'],
            $taxonomy,
            array(
                'slug' => $term_data['slug'],
                'description' => isset($term_data['description']) ? (string) $term_data['description'] : '',
            )
        );

        if (!is_wp_error($inserted) && $old_id && !empty($inserted['term_id'])) {
            $map[$old_id] = (int) $inserted['term_id'];
        }
    }

    return $map;
}

function meldetool_backup_import_post($data, $post_type, &$team_map, $term_maps = array()) {
    if (empty($data['post_title'])) {
        return 0;
    }

    $postarr = array(
        'post_type' => $post_type,
        'post_title' => (string) $data['post_title'],
        'post_status' => !empty($data['post_status']) ? (string) $data['post_status'] : 'publish',
        'post_name' => !empty($data['post_name']) ? (string) $data['post_name'] : '',
        'post_content' => isset($data['post_content']) ? (string) $data['post_content'] : '',
        'post_excerpt' => isset($data['post_excerpt']) ? (string) $data['post_excerpt'] : '',
        'post_date' => !empty($data['post_date']) ? (string) $data['post_date'] : null,
    );

    $new_id = wp_insert_post($postarr, true);
    if (is_wp_error($new_id) || empty($new_id)) {
        return 0;
    }

    if (!empty($data['old_id']) && $post_type === 'team') {
        $team_map[(int) $data['old_id']] = (int) $new_id;
    }

    if (!empty($data['meta']) && is_array($data['meta'])) {
        foreach ($data['meta'] as $meta_key => $values) {
            delete_post_meta($new_id, $meta_key);
            foreach ((array) $values as $value) {
                if ($post_type === 'fahrer' && $meta_key === 'team') {
                    $old_team_id = (int) $value;
                    if ($old_team_id && isset($team_map[$old_team_id])) {
                        $value = (string) $team_map[$old_team_id];
                    }
                }

                if ($post_type === 'team' && $meta_key === 'team-rennklasse') {
                    $old_term_id = (int) $value;
                    if ($old_term_id && !empty($term_maps['rennklasse'][$old_term_id])) {
                        $value = (string) $term_maps['rennklasse'][$old_term_id];
                    }
                }

                if ($post_type === 'fahrer' && $meta_key === 'fahrer-kategorie') {
                    $old_term_id = (int) $value;
                    if ($old_term_id && !empty($term_maps['kategorie'][$old_term_id])) {
                        $value = (string) $term_maps['kategorie'][$old_term_id];
                    }
                }

                add_post_meta($new_id, $meta_key, $value);
            }
        }
    }

    if (!empty($data['terms']) && is_array($data['terms'])) {
        foreach ($data['terms'] as $taxonomy => $slugs) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $term_ids = array();
            foreach ((array) $slugs as $slug) {
                $term = get_term_by('slug', $slug, $taxonomy);
                if ($term && !is_wp_error($term)) {
                    $term_ids[] = (int) $term->term_id;
                }
            }
            wp_set_post_terms($new_id, $term_ids, $taxonomy, false);
        }
    }

    return (int) $new_id;
}

function meldetool_backup_purge_post_type($post_type) {
    $posts = get_posts(array(
        'post_type' => $post_type,
        'post_status' => 'any',
        'numberposts' => -1,
        'fields' => 'ids',
    ));

    foreach ($posts as $post_id) {
        wp_delete_post((int) $post_id, true);
    }
}

function meldetool_backup_redirect($status, $message) {
    $url = add_query_arg(
        array(
            'page' => 'meldetool-backup',
            'meldetool_backup_status' => $status,
            'meldetool_backup_msg' => $message,
        ),
        admin_url('tools.php')
    );

    wp_safe_redirect($url);
    exit;
}
