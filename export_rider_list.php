<?php
/**
 * Exporting the rider list e.g. as csv file
 *
 */


/**
 * Tools-Seite + CSV-Export (Variante B: direkt auf derselben Seite, ohne admin-post.php)
 * - Sortierung: Rennklasse -> Team (A–Z, "Einzelstarter" ans Ende) -> Kapitän -> Nachname
 * - Nummern je Rennklasse: 1–9, 11–19, 21–29, ...
 *   * Normale Teams: max N Nummern (Standard 6, niemals >9 pro Block)
 *   * "Einzelstarter"-Teams: unbegrenzt, können mehrere Blöcke konsumieren
 * - Leerzeile zwischen Rennklassen
 */

add_action('admin_menu', function () {
    add_management_page(
        'Team/Fahrer Export (csv)',
        'Team/Fahrer Export (csv)',
        'manage_options',
        'team-fahrer-export',
        'nhr_export_tools_page_render'
    );
});

function nhr_export_tools_page_render() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung');
    }

    // Build Export-URL: gleicher Screen + Trigger-Parameter + Nonce
    $base  = menu_page_url('team-fahrer-export', false);
    $url   = add_query_arg('nhr_do_export', '1', $base);

    // default values in UI
    $numbers_per_team = isset($_GET['nhr_npt']) ? (int) $_GET['nhr_npt'] : 6;
    $delimiter        = isset($_GET['nhr_delim']) ? $_GET['nhr_delim'] : ';';
    $einzel_keyword   = isset($_GET['nhr_einz']) ? sanitize_text_field($_GET['nhr_einz']) : 'einzelstarter';

    ?>
    <div class="wrap">
        <h1>Team/Fahrer Export (CSV)</h1>
        <p>Sortierung: Rennklasse → Team (A–Z, „Einzelstarter“ am Ende) → Kapitän → Nachname. Nummern je Rennklasse: 1–9, 11–19, 21–29 …</p>

        <form method="get" action="">
            <!-- Wichtig: page muss gesetzt bleiben, damit wir auf dieser Tools-Seite bleiben -->
            <input type="hidden" name="page" value="team-fahrer-export">
            <input type="hidden" name="nhr_do_export" value="1">
            <?php wp_nonce_field('nhr_export_nonce2'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="nhr_npt">Startnummern pro normalem Team</label></th>
                    <td>
                        <input type="number" name="nhr_npt" id="nhr_npt" value="<?php echo esc_attr($numbers_per_team); ?>" min="1" step="1">
                        <span class="description">Standard: 6.</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nhr_einz">Schlüsselwort für Einzelstarter-Teams</label></th>
                    <td>
                        <input type="text" name="nhr_einz" id="nhr_einz" value="<?php echo esc_attr($einzel_keyword); ?>">
                        <span class="description">Teamnamen, die dieses Wort enthalten (ohne Beachtung der Groß-/Kleinschreibung), werden am Ende der Rennklasse gelistet und sind nicht auf 9 Nummern beschränkt.</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nhr_delim">CSV-Trennzeichen</label></th>
                    <td>
                        <select name="nhr_delim" id="nhr_delim">
                            <option value=";" <?php selected($delimiter, ';'); ?>>Semikolon (;)</option>
                            <option value="," <?php selected($delimiter, ','); ?>>Komma (,)</option>
                            <option value="\t" <?php selected($delimiter, '\t'); ?>>Tabulator</option>
                        </select>
                        <span class="description">Für deutsches Excel meist Semikolon.</span>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">CSV exportieren</button>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Sehr früh im Admin-Lebenszyklus prüfen wir, ob die Export-Query aktiv ist.
 * Wenn ja, wird der CSV-Stream ausgegeben und der Request beendet.
 */
add_action('admin_init', function () {
    if (!is_admin() || !current_user_can('manage_options')) return;
    if (empty($_GET['page']) || $_GET['page'] !== 'team-fahrer-export') return;
    if (empty($_GET['nhr_do_export']) || $_GET['nhr_do_export'] !== '1') return;

    // Nonce prüfen (muss in der Tools-Seite gesetzt sein)
    check_admin_referer('nhr_export_nonce2');

    // Output-Puffer leeren, damit kein HTML/JS vor der CSV landet
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
    }

    // Parameter
    $numbers_per_team = isset($_GET['nhr_npt']) ? max(1, (int) $_GET['nhr_npt']) : 6;
    $delimiter_in     = isset($_GET['nhr_delim']) ? wp_unslash($_GET['nhr_delim']) : ';';
    $delimiter        = ($delimiter_in === '\t') ? "\t" : $delimiter_in;
    $einzel_keyword   = isset($_GET['nhr_einz']) ? trim(wp_unslash($_GET['nhr_einz'])) : 'einzelstarter';

    // CSV-Header
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=team_fahrer_export_' . date('Y-m-d') . '.csv');

    // UTF-8 BOM (Excel)
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    // Kopfzeile
    fputcsv($out, array('Rennklasse','Team','Startnummer','Kapitän','Nachname','Vorname','UCI-ID','Lizenznummer','Kategorie','Etappe'), $delimiter);

    // Rennklassen alphabetisch
    $rennklassen = get_terms(array(
        'taxonomy'   => 'rennklasse',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ));
    if (is_wp_error($rennklassen)) $rennklassen = array();

    // Helper: Keyword-Match für "Einzelstarter"
    $is_einzel = function($team_title) use ($einzel_keyword) {
        if ($einzel_keyword === '') return false;
        return (stripos($team_title, $einzel_keyword) !== false);
    };

    // Startnummern-Logik: Startnummer für jede Rennklasse im nächsten 50er-Block beginnen lassen
    $next_start_number = 1;
    foreach ($rennklassen as $rk_term) {
        // Teams je Rennklasse
        $teams_in_rk = get_posts(array(
            'post_type'      => 'team',
            'post_status'    => 'any',
            'numberposts'    => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'tax_query'      => array(
                array(
                    'taxonomy' => 'rennklasse',
                    'field'    => 'term_id',
                    'terms'    => $rk_term->term_id,
                )
            ),
        ));
        if (empty($teams_in_rk)) {
            // Keine Teams in der Rennklasse -> Überspringen (keine Leerzeile)
            continue;
        }

        $is_u17_class = (stripos($rk_term->name, 'U17') !== false);

        // Teams trennen: regulär vs. Einzelstarter
        $regular = array();
        $einzel  = array();
        foreach ($teams_in_rk as $t) {
            if ($is_einzel($t->post_title)) $einzel[] = $t; else $regular[] = $t;
        }
        // A–Z sortieren
        usort($regular, function($a,$b){ return strcasecmp($a->post_title, $b->post_title); });
        usort($einzel,  function($a,$b){ return strcasecmp($a->post_title, $b->post_title); });
        $ordered_teams = array_merge($regular, $einzel);

        // Startnummer für diese Rennklasse bestimmen (nächster 50er-Block)
        $class_start_number = $next_start_number;
        $block_index    = 0;
        $class_has_rows = false;
        $max_number_in_class = 0;

        foreach ($ordered_teams as $team) {
            // Fahrer des Teams
            $fahrer = get_posts(array(
                'post_type'   => 'fahrer',
                'post_status' => 'any',
                'numberposts' => -1,
                'meta_key'    => 'team',
                'meta_value'  => $team->ID,
            ));
            if (empty($fahrer)) continue;

            // Fahrer sortieren: Kapitän → Nachname → Vorname
            usort($fahrer, function($a,$b){
                $ka = nhr_bool_meta($a->ID, 'ist_kapitaen') ? 1 : 0;
                $kb = nhr_bool_meta($b->ID, 'ist_kapitaen') ? 1 : 0;
                if ($ka !== $kb) return ($kb - $ka);
                $na = strtolower((string) get_post_meta($a->ID, 'nachname', true));
                $nb = strtolower((string) get_post_meta($b->ID, 'nachname', true));
                if ($na !== $nb) return strcmp($na, $nb);
                $va = strtolower((string) get_post_meta($a->ID, 'vorname', true));
                $vb = strtolower((string) get_post_meta($b->ID, 'vorname', true));
                return strcmp($va, $vb);
            });

            $team_title = get_the_title($team->ID);
            $einzelFlg  = $is_einzel($team_title);
            $base       = $class_start_number + ($block_index * 10); // z.B. 1, 11, 21, ...

            $assigned = 0;
            foreach ($fahrer as $f) {
                $vorname  = (string) get_post_meta($f->ID, 'vorname', true);
                $nachname = (string) get_post_meta($f->ID, 'nachname', true);
                $uci      = (string) get_post_meta($f->ID, 'uci_id', true);
                $liz      = (string) get_post_meta($f->ID, 'lizenznummer', true);
                $is_cap   = nhr_bool_meta($f->ID, 'ist_kapitaen') ? 'Ja' : 'Nein';
				$terms = get_the_terms($f->ID, 'kategorie');
				if (!empty($terms) && !is_wp_error($terms)) {
					$kategorie = (string) implode(', ', wp_list_pluck($terms, 'name'));
				} else {
					$kategorie = '—';
				}

                $etappe = $is_u17_class ? (string) get_post_meta($f->ID, 'etappen_auswahl', true) : '';

                // Nummernvergabe
                if ($einzelFlg) {
                    // unbegrenzt über Blöcke
                    $nr = $base + ($assigned % 9) + (10 * floor($assigned / 9));
                    $assigned++;
                } else {
                    $cap = min($numbers_per_team, 9);
                    if ($assigned < $cap) {
                        $nr = $base + $assigned; // 1..9
                        $assigned++;
                    } else {
                        $nr = ''; // Teamlimit erreicht -> keine Nummer
                    }
                }

                if ($nr !== '') {
                    $max_number_in_class = max($max_number_in_class, (int)$nr);
                }

                fputcsv($out, array(
                    $rk_term->name,
                    $team_title,
                    (string)$nr,
                    $is_cap,
                    $nachname,
                    $vorname,
                    $uci,
                    $liz,
					$kategorie,
                    $etappe
                ), $delimiter);
                $class_has_rows = true;
            }

            // Blöcke „verbrauchen“
            if ($einzelFlg) {
                $blocks_used = (int) ceil($assigned / 9);
                if ($blocks_used < 1 && $assigned > 0) $blocks_used = 1;
                $block_index += $blocks_used;
            } else {
                if ($assigned > 0) $block_index += 1;
            }
        }

        // Leerzeile zwischen Rennklassen, wenn in dieser Rennklasse etwas ausgegeben wurde
        if ($class_has_rows) {
            fputcsv($out, array(), $delimiter);
        }

        // Nächsten Startnummernblock für folgende Rennklasse bestimmen
        if ($max_number_in_class > 0) {
            $next_start_number = (int)(floor(($max_number_in_class + 49) / 50) * 50) + 1;
        }
    }

    fclose($out);
    exit;
});

/** Hilfsfunktion: interpretiert Meta als bool */
function nhr_bool_meta($post_id, $key) {
    $v = get_post_meta($post_id, $key, true);
    if (is_bool($v)) return $v;
    $v = strtolower(trim((string)$v));
    return in_array($v, array('1','true','yes','ja','on'), true);
}