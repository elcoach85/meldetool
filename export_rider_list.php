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
        'Team/Fahrer Export',
        'Team/Fahrer Export',
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
    $numbers_per_team = isset($_GET['nhr_npt']) ? (int) $_GET['nhr_npt'] : 8;
    $delimiter        = isset($_GET['nhr_delim']) ? $_GET['nhr_delim'] : ';';
    $einzel_keyword   = isset($_GET['nhr_einz']) ? sanitize_text_field($_GET['nhr_einz']) : 'einzelstarter';

    ?>
    <div class="wrap">
        <h1>Team/Fahrer Export</h1>
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
                        <span class="description">Standard: 8.</span>
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
                <button type="submit" name="nhr_do_export" value="1" class="button button-primary">CSV exportieren</button>
                <button type="submit" name="nhr_do_pdf" value="1" class="button button-secondary" style="margin-left:10px;">Starterliste (PDF-Druck)</button>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Sehr früh im Admin-Lebenszyklus prüfen wir, ob der PDF-Export aktiv ist.
 * Gibt eine druckfertige HTML-Seite aus (Browser → Drucken → Als PDF speichern).
 */
add_action('admin_init', function () {
    if (!is_admin() || !current_user_can('manage_options')) return;
    if (empty($_GET['page']) || $_GET['page'] !== 'team-fahrer-export') return;
    if (empty($_GET['nhr_do_pdf']) || $_GET['nhr_do_pdf'] !== '1') return;

    check_admin_referer('nhr_export_nonce2');

    $einzel_keyword = isset($_GET['nhr_einz']) ? trim(wp_unslash($_GET['nhr_einz'])) : 'einzelstarter';
    $is_einzel = function($team_title) use ($einzel_keyword) {
        if ($einzel_keyword === '') return false;
        return (stripos($team_title, $einzel_keyword) !== false);
    };

    $rennklassen = get_terms(array(
        'taxonomy'   => 'rennklasse',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ));
    if (is_wp_error($rennklassen)) $rennklassen = array();

    // Daten aufbauen
    $sections = array();
    foreach ($rennklassen as $rk_term) {
        $teams_in_rk = get_posts(array(
            'post_type'  => 'team',
            'post_status'=> 'any',
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

        $regular = array(); $einzel = array();
        foreach ($teams_in_rk as $t) {
            if ($is_einzel($t->post_title)) $einzel[] = $t; else $regular[] = $t;
        }
        usort($regular, function($a,$b){ return strcasecmp($a->post_title, $b->post_title); });
        usort($einzel,  function($a,$b){ return strcasecmp($a->post_title, $b->post_title); });

        $rows = array();
        foreach (array_merge($regular, $einzel) as $team) {
            $fahrer = get_posts(array(
                'post_type'  => 'fahrer',
                'post_status'=> 'any',
                'numberposts'=> -1,
                'meta_key'   => 'team',
                'meta_value' => $team->ID,
            ));
            if (empty($fahrer)) continue;

            usort($fahrer, function($a,$b){
                $ka = nhr_bool_meta($a->ID, 'ist_kapitaen') ? 1 : 0;
                $kb = nhr_bool_meta($b->ID, 'ist_kapitaen') ? 1 : 0;
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
                    'nachname'     => html_entity_decode((string)get_post_meta($f->ID, 'nachname', true),     ENT_QUOTES, 'UTF-8'),
                    'vorname'      => html_entity_decode((string)get_post_meta($f->ID, 'vorname', true),      ENT_QUOTES, 'UTF-8'),
                    'team'         => html_entity_decode(get_the_title($team->ID),                            ENT_QUOTES, 'UTF-8'),
                    'kategorie'    => html_entity_decode($kategorie,                                          ENT_QUOTES, 'UTF-8'),
                    'lizenznummer' => html_entity_decode((string)get_post_meta($f->ID, 'lizenznummer', true), ENT_QUOTES, 'UTF-8'),
                    'uci_id'       => html_entity_decode((string)get_post_meta($f->ID, 'uci_id', true),       ENT_QUOTES, 'UTF-8'),
                );
            }
        }
        if (!empty($rows)) {
            $sections[] = array(
                'name'  => html_entity_decode($rk_term->name, ENT_QUOTES, 'UTF-8'),
                'count' => count($rows),
                'rows'  => $rows,
            );
        }
    }

    // Output-Puffer leeren
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
    }
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">';
    echo '<title>Starterliste ' . esc_html(date('Y-m-d')) . '</title>';
    echo '<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: monospace; font-size: 10.5pt; padding: 1.5cm; color: #000; }
h2 { font-size: 11pt; font-weight: bold; margin-top: 1.6em; margin-bottom: 0.25em;
     border-bottom: 1px solid #000; padding-bottom: 2px; }
table { border-collapse: collapse; width: 100%; }
td { padding: 1px 0; vertical-align: top; }
td.col-name { width: 22%; white-space: nowrap; }
td.col-team { width: 28%; padding-left: 2em; }
td.col-rest { padding-left: 2em; }
.no-print { }
@media print {
  @page { margin: 1.5cm; size: A4 portrait; }
  body { padding: 0; }
  h2 { page-break-after: avoid; }
  .no-print { display: none; }
}
</style></head><body>';

    echo '<p class="no-print" style="margin-bottom:1em;">';
    echo '<button onclick="window.print()" style="padding:6px 14px;font-size:10.5pt;cursor:pointer;">&#128438;&nbsp;Drucken / Als PDF speichern</button>';
    echo '</p>';

    foreach ($sections as $section) {
        $count = (int) $section['count'];
        $count_label = ($count === 1) ? '1 Fahrer*in' : $count . ' Fahrer*innen';
        echo '<h2>' . esc_html($section['name'] . ' (' . $count_label . ')') . '</h2>';
        echo '<table>';
        foreach ($section['rows'] as $row) {
            $rest = implode(', ', array_filter(array(
                $row['kategorie'],
                $row['lizenznummer'],
                $row['uci_id'],
            )));
            echo '<tr>';
            echo '<td class="col-name">' . esc_html($row['nachname'] . ', ' . $row['vorname']) . '</td>';
            echo '<td class="col-team">' . esc_html($row['team']) . '</td>';
            echo '<td class="col-rest">' . esc_html($rest) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    echo '</body></html>';
    exit;
});

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
    $numbers_per_team = isset($_GET['nhr_npt']) ? max(1, (int) $_GET['nhr_npt']) : 8;
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
    fputcsv($out, array('Rennklasse','Team','Startnummer','Kapitän','Nachname','Vorname','UCI-ID','Lizenznummer','Kategorie','Etappe','Bezahlt (€)'), $delimiter);

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
        $class_rider_count = 0;
        $class_rows = array();
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
                $bezahlt  = (string) get_post_meta($f->ID, 'bezahlt', true);
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

                $class_rows[] = array(
                    html_entity_decode($team_title, ENT_QUOTES, 'UTF-8'),
                    (string)$nr,
                    $is_cap,
                    html_entity_decode($nachname, ENT_QUOTES, 'UTF-8'),
                    html_entity_decode($vorname, ENT_QUOTES, 'UTF-8'),
                    html_entity_decode($uci, ENT_QUOTES, 'UTF-8'),
                    html_entity_decode($liz, ENT_QUOTES, 'UTF-8'),
					html_entity_decode($kategorie, ENT_QUOTES, 'UTF-8'),
                    html_entity_decode($etappe, ENT_QUOTES, 'UTF-8'),
                    html_entity_decode($bezahlt, ENT_QUOTES, 'UTF-8')
                );
                $class_has_rows = true;
                $class_rider_count++;
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
            $rk_name = html_entity_decode($rk_term->name, ENT_QUOTES, 'UTF-8');
            $rk_label = $rk_name;
            foreach ($class_rows as $csv_row) {
                array_unshift($csv_row, $rk_label);
                fputcsv($out, $csv_row, $delimiter);
            }
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