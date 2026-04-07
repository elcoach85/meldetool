<?php

/**
 * Frontend-Formular-Logik: Dynamische Feldanzeige basierend auf Team-Typ
 * 
 * Diese Action fügt JavaScript im Footer ein, das Fahrerformulare dynamisch anpasst:
 * - Hobbyteams: Lizenznummer und UCI-ID verstecken, als optional markieren
 * - Einzelstarter-Teams: IBAN/BIC/Kontoinhaber anzeigen
 * - Normale Teams: Kapitän-Checkbox sichtbar
 * 
 * Das Script findet das Team-Dropdown-Feld und reagiert auf Änderungen.
 */
add_action('wp_footer', function() {

    $optional_team_ids = meldetool_get_license_optional_team_ids();
    $iban_bic_team_ids = meldetool_get_iban_bic_visible_team_ids();
    $u17_team_ids      = meldetool_get_u17_team_ids();
    $logging_enabled = meldetool_is_logging_enabled();
    
    // Debug: sammelt alle Team-IDs und -Namen für Logging (nur wenn Logging aktiv)
    $all_teams_debug = array();
    if ($logging_enabled) {
        $all_posts = get_posts(array('post_type' => 'team', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids'));
        foreach ($all_posts as $tid) {
            $all_teams_debug[(int)$tid] = get_the_title((int)$tid);
        }
    }
    ?>
    <?php if ($logging_enabled): ?>
    <!-- meldetool debug: optional_team_ids=<?php echo esc_html(wp_json_encode($optional_team_ids)); ?> all_teams=<?php echo esc_html(wp_json_encode($all_teams_debug)); ?> -->
    <?php endif; ?>
    <script>
    /**
     * IIFE (Immediately Invoked Function Expression) für Feldanzeige-Logik
     * Scope-Isolation verhindert Konflikte mit anderen Scripts
     */
    (function() {
        var loggingEnabled = <?php echo wp_json_encode($logging_enabled); ?>;
        
        // Hilfsfunktion: bedingte `console.log()` basierend auf Logging-Settings
        function meldLog(message) {
            if (loggingEnabled) {
                console.log('[meldetool] ' + message);
            }
        }
        
        // Team-Arrays aus PHP übernehmen
        var optionalTeamIds = <?php echo wp_json_encode(array_values($optional_team_ids)); ?>;
        var ibanBicTeamIds = <?php echo wp_json_encode(array_values($iban_bic_team_ids)); ?>;
        var u17TeamIds = <?php echo wp_json_encode(array_values($u17_team_ids)); ?>;
        meldLog('[meldetool] optional team IDs: ' + JSON.stringify(optionalTeamIds));
        meldLog('[meldetool] iban/bic team IDs: ' + JSON.stringify(ibanBicTeamIds));
        meldLog('[meldetool] u17 team IDs: ' + JSON.stringify(u17TeamIds));

        // Sichere Integer-Konvertierung mit NaN-Handling
        function asInt(value) {
            var parsed = parseInt(value, 10);
            return isNaN(parsed) ? 0 : parsed;
        }

        /**
         * Generiert verschiedene Schreibweisen eines Feldnamens (mit/ohne Bindestrich)
         * Pods verwendet konsistent unterschiedliche Naming-Konventionen:
         * - mit Unterstrichen (pods_field_lizenznummer)
         * - mit Bindestrichen (pods-field-lizenznummer)
         * Diese Funktion erzeugt alle Varianten aus einem kanonischen Namen
         */
        function fieldNameVariants(fieldName) {
            var dash = fieldName.replace(/_/g, '-');
            var underscore = fieldName.replace(/-/g, '_');
            return Array.from(new Set([fieldName, dash, underscore]));
        }

        /**
         * Findet das äußere Wrapper-Element eines Pods-Formularfeldes
         * Wrapper-Element ist notwendig zum Verstecken/Anzeigen des ganzen Feldes inkl. Label
         * 
         * @param {string} fieldName - Feldname (z.B. "lizenznummer")
         * @param {Element} root - Suchbereich (Standard: ganzes Dokument)
         * @return {Element|null} Gefundenes Wrapper-Element oder null
         */
        function findFieldWrap(fieldName, root) {
            var scope = root || document;
            var names = fieldNameVariants(fieldName);
            for (var i = 0; i < names.length; i++) {
                var name = names[i];
                var wrap = scope.querySelector('.pods-form-ui-row-name-pods-field-' + name)
                    || scope.querySelector('.pods-form-ui-row-name-' + name)
                    || scope.querySelector('.pods-form-ui-field-name-' + name);
                if (wrap) {
                    return wrap;
                }
            }
            return null;
        }

        /**
         * Findet das Input-Element eines Pods-Formularfeldes
         * Input-Element wird direkt manipuliert (value, required-Attribut)
         * 
         * @param {string} fieldName - Feldname (z.B. "lizenznummer")
         * @param {Element} root - Suchbereich (Standard: ganzes Dokument)
         * @return {Element|null} Gefundenes Input-Element oder null
         */
        function findFieldInput(fieldName, root) {
            var scope = root || document;
            var names = fieldNameVariants(fieldName);
            for (var i = 0; i < names.length; i++) {
                var name = names[i];
                var input = scope.querySelector('#pods-form-ui-pods-field-' + name)
                    || scope.querySelector('#pods-form-ui-' + name)
                    || scope.querySelector('input[name="pods_field_' + name + '"]')
                    || scope.querySelector('input[name="' + name + '"]')
                    || scope.querySelector('textarea[name="pods_field_' + name + '"]')
                    || scope.querySelector('textarea[name="' + name + '"]');
                if (input) {
                    return input;
                }
            }
            return null;
        }

        /**
         * Findet das Team-Dropdown-Select-Element im Formular
         * Trigger für die gesamte Feldanzeige-Logik
         * Verwendet genaue Namen-Matching, um ähnliche Felder nicht zu treffen
         */
        function findTeamSelect() {
            return document.querySelector('select[name="pods_field_team"]')
                || document.querySelector('select[name="team"]')
                || document.getElementById('pods-form-ui-pods-field-team')
                || document.getElementById('pods-form-ui-team');
        }

        /**
         * Findet ein Formular in der Naehe einer Ueberschrift mit bestimmtem Text
         * (z.B. "Anmeldung Teams" oder "Anmeldung Fahrer").
         */
        function findFormNearHeading(headingText) {
            var normalizedNeedle = String(headingText || '').toLowerCase().trim();
            if (!normalizedNeedle) {
                return null;
            }

            var headings = document.querySelectorAll('h1, h2, h3, h4, h5, h6');
            for (var i = 0; i < headings.length; i++) {
                var heading = headings[i];
                var normalizedHeading = String(heading.textContent || '').toLowerCase().trim();
                if (normalizedHeading.indexOf(normalizedNeedle) === -1) {
                    continue;
                }

                var node = heading.nextElementSibling;
                var maxSteps = 12;
                while (node && maxSteps > 0) {
                    if (node.tagName && node.tagName.toLowerCase() === 'form') {
                        return node;
                    }
                    var nestedForm = node.querySelector ? node.querySelector('form') : null;
                    if (nestedForm) {
                        return nestedForm;
                    }
                    node = node.nextElementSibling;
                    maxSteps--;
                }
            }

            return null;
        }

        /**
         * Findet eine Ueberschrift ueber mehrere moegliche Texte.
         */
        function findHeadingByTexts(possibleTexts) {
            var headings = document.querySelectorAll('h1, h2, h3, h4, h5, h6');
            for (var i = 0; i < headings.length; i++) {
                var heading = headings[i];
                var normalizedHeading = String(heading.textContent || '').toLowerCase().trim();
                for (var j = 0; j < possibleTexts.length; j++) {
                    var needle = String(possibleTexts[j] || '').toLowerCase().trim();
                    if (needle && normalizedHeading.indexOf(needle) !== -1) {
                        return heading;
                    }
                }
            }
            return null;
        }

        /**
         * Liefert den im DOM zuerst vorkommenden Knoten aus einer Liste.
         */
        function getEarliestNode(nodes) {
            var validNodes = nodes.filter(function(node) {
                return !!node;
            });
            if (!validNodes.length) {
                return null;
            }

            var earliest = validNodes[0];
            for (var i = 1; i < validNodes.length; i++) {
                var current = validNodes[i];
                if (earliest.compareDocumentPosition(current) & Node.DOCUMENT_POSITION_PRECEDING) {
                    earliest = current;
                }
            }
            return earliest;
        }

        /**
         * Fallback: Findet ein Formular anhand typischer Feldselektoren.
         */
        function findFormByFieldSelectors(selectors) {
            var forms = document.querySelectorAll('form');
            for (var i = 0; i < forms.length; i++) {
                var form = forms[i];
                for (var j = 0; j < selectors.length; j++) {
                    if (form.querySelector(selectors[j])) {
                        return form;
                    }
                }
            }
            return null;
        }

        /**
         * Erstellt Buttons zur Auswahl zwischen Team- und Fahrermeldung.
         */
        function initFrontendFormSwitcher() {
            if (document.getElementById('meldetool-form-switcher')) {
                return true;
            }

            var teamHeading = findHeadingByTexts(['Anmeldung Teams']);
            var riderHeading = findHeadingByTexts(['Anmeldung Fahrer*innen', 'Anmeldung Fahrer']);

            var teamForm = findFormNearHeading('Anmeldung Teams')
                || findFormByFieldSelectors([
                    'input[name="pods_field_teamname"]',
                    'input[name="teamname"]',
                    'input[name="pods_field_email_manager"]',
                    'input[name="email_manager"]'
                ]);

            var riderForm = findFormNearHeading('Anmeldung Fahrer*innen')
                || findFormByFieldSelectors([
                    'input[name="pods_field_vorname"]',
                    'input[name="vorname"]',
                    'select[name="pods_field_team"]',
                    'select[name="team"]'
                ]);

            if (!teamForm || !riderForm || teamForm === riderForm) {
                return false;
            }

            var switcher = document.createElement('div');
            switcher.id = 'meldetool-form-switcher';
            switcher.style.display = 'flex';
            switcher.style.flexWrap = 'wrap';
            switcher.style.gap = '10px';
            switcher.style.margin = '0 0 16px 0';

            var teamButton = document.createElement('button');
            teamButton.type = 'button';
            teamButton.textContent = 'Anmeldung Teams';

            var riderButton = document.createElement('button');
            riderButton.type = 'button';
            riderButton.textContent = 'Anmeldung Fahrer';

            [teamButton, riderButton].forEach(function(btn) {
                btn.style.border = '1px solid #1f2937';
                btn.style.background = '#ffffff';
                btn.style.color = '#4a006d';
                btn.style.padding = '10px 16px';
                btn.style.borderRadius = '6px';
                btn.style.cursor = 'pointer';
                btn.style.fontWeight = '600';
            });

            function setMode(mode) {
                var showTeam = (mode === 'team');
                if (teamHeading) {
                    teamHeading.style.display = showTeam ? '' : 'none';
                }
                if (riderHeading) {
                    riderHeading.style.display = showTeam ? 'none' : '';
                }
                teamForm.style.display = showTeam ? '' : 'none';
                riderForm.style.display = showTeam ? 'none' : '';

                teamButton.style.background = showTeam ? '#4a006d' : '#ffffff';
                teamButton.style.color = showTeam ? '#ffffff' : '#4a006d';
                riderButton.style.background = showTeam ? '#ffffff' : '#4a006d';
                riderButton.style.color = showTeam ? '#4a006d' : '#ffffff';

                teamButton.setAttribute('aria-pressed', showTeam ? 'true' : 'false');
                riderButton.setAttribute('aria-pressed', showTeam ? 'false' : 'true');

                if (!showTeam) {
                    meldLog('[meldetool] setMode: switching to rider, calling applyVisibility immediately');
                    applyVisibility();
                    // Retry nach kurzem Delay fuer asynchron geladene Elemente
                    setTimeout(function() {
                        meldLog('[meldetool] setMode: retry applyVisibility after delay');
                        applyVisibility();
                    }, 100);
                }
            }

            teamButton.addEventListener('click', function() {
                setMode('team');
            });
            riderButton.addEventListener('click', function() {
                setMode('rider');
            });

            switcher.appendChild(teamButton);
            switcher.appendChild(riderButton);

            var anchorNode = getEarliestNode([teamHeading, riderHeading, teamForm, riderForm]);
            if (!anchorNode || !anchorNode.parentNode) {
                return false;
            }
            anchorNode.parentNode.insertBefore(switcher, anchorNode);

            setMode('team');
            meldLog('[meldetool] frontend form switcher initialized.');
            return true;
        }

        /**
         * Debug-Funktion: Loggt alle Select-Elemente und Feldstatus im DOM
         * Hilft beim Troubleshooting bei Formularen, die nicht richtig angepasst werden
         */
        function logAllSelects() {
            var selects = document.querySelectorAll('select');
            meldLog('[meldetool] all <select> elements found (' + selects.length + '):');
            selects.forEach(function(s) {
                meldLog('  id="' + s.id + '" name="' + s.name + '" class="' + s.className + '"');
            });
            var teamSelect = findTeamSelect();
            var riderForm = teamSelect ? (teamSelect.closest('form') || document) : document;
            ['lizenznummer', 'uci_id', 'iban', 'bic', 'kontoinhaber', 'ist_kapitaen'].forEach(function(fieldName) {
                var wrap = findFieldWrap(fieldName, riderForm);
                var input = findFieldInput(fieldName, riderForm);
                meldLog('[meldetool] field "' + fieldName + '": wrap=' + (wrap ? 'found' : 'not found') + ', input=' + (input ? 'found' : 'not found'));
            });
        }

        /**
         * Erstellt oder verwaltet die Haftungsausschluss-Checkbox fuer Hobbyteams.
         * 
         * Bei Hobbyteams muss der Fahrer den Haftungsausschluss akzeptieren.
         * Die Checkbox wird direkt vor dem Submit-Button eingefuegt.
         * 
         * @param {Element} riderForm - Das Fahrerformular
         * @param {boolean} isHobbyTeam - Ob es sich um ein Hobbyteam handelt
         */
        function ensureLiabilityCheckbox(riderForm, isHobbyTeam) {
            var checkboxId = 'meldetool_hobby_liability_checkbox';
            var wrapperId = 'meldetool-liability-wrapper';
            var existingCheckbox = riderForm.querySelector('#' + checkboxId);

            function getSubmitControls() {
                var candidates = riderForm.querySelectorAll('button[type="submit"], input[type="submit"], .pods-form-ui-submit, a.button, a[role="button"]');
                if (!candidates || !candidates.length) {
                    candidates = riderForm.querySelectorAll('button, input[type="button"], input[type="submit"], a.button, a[role="button"]');
                }
                return Array.from(candidates);
            }

            function setSubmitControlsEnabled(enabled) {
                var controls = getSubmitControls();
                controls.forEach(function(control) {
                    if (!control || (control.id === checkboxId) || (control.closest && control.closest('#' + wrapperId))) {
                        return;
                    }

                    if (control.tagName && control.tagName.toLowerCase() === 'a') {
                        control.style.pointerEvents = enabled ? '' : 'none';
                        control.style.opacity = enabled ? '' : '0.5';
                        control.setAttribute('aria-disabled', enabled ? 'false' : 'true');
                    } else {
                        control.disabled = !enabled;
                    }
                });
            }

            function bindCheckboxSync(checkbox) {
                if (!checkbox || checkbox.dataset.meldetoolBound === '1') {
                    return;
                }
                checkbox.addEventListener('change', function() {
                    setSubmitControlsEnabled(checkbox.checked);
                });
                checkbox.dataset.meldetoolBound = '1';
            }

            if (isHobbyTeam) {
                if (existingCheckbox) {
                    existingCheckbox.style.display = '';
                    existingCheckbox.required = true;
                    var parentWrapper = riderForm.querySelector('#' + wrapperId);
                    if (parentWrapper) {
                        parentWrapper.style.display = '';
                    }
                    bindCheckboxSync(existingCheckbox);
                    setSubmitControlsEnabled(!!existingCheckbox.checked);
                    return;
                }

                // Versuche Submit-Button zu finden mit mehreren Fallbacks
                var submitButton = riderForm.querySelector('button[type="submit"]')
                    || riderForm.querySelector('input[type="submit"]')
                    || riderForm.querySelector('.pods-form-ui-submit')
                    || riderForm.querySelector('[data-test*="submit"]')
                    || riderForm.querySelector('button.submit, button.btn-primary, button[name*="submit"]')
                    || Array.from(riderForm.querySelectorAll('button, input[type="button"], a.button, a[role="button"]')).find(function(btn) {
                        var text = (btn.textContent || btn.value || '').toLowerCase();
                        return text.indexOf('fahrer') !== -1 || text.indexOf('anmelden') !== -1 || text.indexOf('senden') !== -1 || text.indexOf('submit') !== -1 || text.indexOf('abschicken') !== -1 || text.indexOf('speichern') !== -1;
                    });

                if (!submitButton) {
                    meldLog('[meldetool] WARNING: submit button not found, checkbox will be appended to form end.');
                } else {
                    meldLog('[meldetool] liability checkbox: submit button found: ' + (submitButton.tagName || 'unknown') + ' / ' + (submitButton.type || 'no type'));
                }

                var wrapper = document.createElement('div');
                wrapper.id = wrapperId;
                wrapper.style.marginBottom = '16px';
                wrapper.style.padding = '12px';
                wrapper.style.backgroundColor = '#fff8e6';
                wrapper.style.borderLeft = '4px solid #ff9800';
                wrapper.style.borderRadius = '4px';

                var label = document.createElement('label');
                label.style.display = 'flex';
                label.style.alignItems = 'flex-start';
                label.style.gap = '8px';
                label.style.cursor = 'pointer';

                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.id = checkboxId;
                checkbox.name = checkboxId;
                checkbox.style.marginTop = '4px';
                checkbox.style.cursor = 'pointer';
                checkbox.required = true;

                var labelText = document.createElement('span');
                labelText.style.fontSize = '14px';
                labelText.style.lineHeight = '1.5';
                labelText.style.color = '#1f2937';

                var siteUrl = window.location.origin;
                var liabilityUrl = siteUrl + '/haftungsausschluss-hobby-rennen/';

                labelText.innerHTML = 'Ich habe die <a href="' + liabilityUrl + '" target="_blank" style="color: #4a006d; text-decoration: underline;">Teilnahmebedingungen und den Haftungsausschluss</a> gelesen und akzeptiert.';

                label.appendChild(checkbox);
                label.appendChild(labelText);
                wrapper.appendChild(label);

                if (submitButton && submitButton.parentNode) {
                    submitButton.parentNode.insertBefore(wrapper, submitButton);
                } else {
                    riderForm.appendChild(wrapper);
                }
                bindCheckboxSync(checkbox);
                setSubmitControlsEnabled(false);
                meldLog('[meldetool] liability checkbox created and inserted');
            } else {
                if (existingCheckbox) {
                    existingCheckbox.style.display = 'none';
                    existingCheckbox.checked = false;
                    existingCheckbox.required = false;
                    var parentWrapper = riderForm.querySelector('#' + wrapperId);
                    if (parentWrapper) {
                        parentWrapper.style.display = 'none';
                    }
                }
                setSubmitControlsEnabled(true);
            }
        }

        /**
         * Wendet Feldanzeige-Regeln basierend auf ausgewähltem Team-Typ an
         * 
         * Logik:
         * 1. Bei Hobby-Teams: Lizenznummer/UCI-ID verstecken, Wert auf "n/a" setzen
         * 2. Bei Einzelstarter: IBAN/BIC/Kontoinhaber anzeigen
         * 3. Kapitän-Checkbox nur bei normalen Teams zeigen
         * 4. Haftungsausschluss-Checkbox bei Hobby-Teams zeigen
         * 5. Required-Attribute dynamisch aktualisieren
         */
        function applyVisibility() {
            var teamSelect = findTeamSelect();
            if (!teamSelect) {
                return;
            }
            var riderForm = teamSelect.closest('form') || document;

            var selectedTeamId = asInt(teamSelect.value);
            var isOptional = optionalTeamIds.indexOf(selectedTeamId) !== -1;
            var isEinzelstarter = ibanBicTeamIds.indexOf(selectedTeamId) !== -1;
            var isU17Team = u17TeamIds.indexOf(selectedTeamId) !== -1;

            ['lizenznummer', 'uci_id'].forEach(function(fieldName) {
                var wrap = findFieldWrap(fieldName, riderForm);
                var input = findFieldInput(fieldName, riderForm);
                if (!wrap || !input) {
                    return;
                }

                wrap.style.display = isOptional ? 'none' : '';
                input.required = !isOptional;

                if (isOptional && !input.value) {
                    input.value = 'n/a';
                }
                if (!isOptional && input.value === 'n/a') {
                    input.value = '';
                }
            });

            ['iban', 'bic', 'kontoinhaber'].forEach(function(fieldName) {
                var wrap = findFieldWrap(fieldName, riderForm);
                var input = findFieldInput(fieldName, riderForm);
                if (!wrap || !input) {
                    return;
                }

                wrap.style.display = (isEinzelstarter && !isOptional) ? '' : 'none';
                input.required = false;
            });

            ['ist_kapitaen'].forEach(function(fieldName) {
                var wrap = findFieldWrap(fieldName, riderForm);
                var input = findFieldInput(fieldName, riderForm);
                if (!wrap || !input) {
                    return;
                }

                wrap.style.display = (isOptional || isEinzelstarter) ? 'none' : '';
                input.required = false;
            });

            // Etappenauswahl nur fuer U17-Teams
            var etappenWrap = findFieldWrap('etappen_auswahl', riderForm);
            if (etappenWrap) {
                // Wert vor Display-Toggle sichern – Tom Select / Select2 setzt select
                // beim Sichtbarwechsel manchmal auf den ersten Eintrag zurueck.
                var etappenSelect = riderForm.querySelector(
                    'select[name="pods_field_etappen_auswahl"], select[name="pods_field_etappen-auswahl"], select[name="etappen_auswahl"]'
                );
                var savedEtappenValue = etappenSelect ? etappenSelect.value : null;

                etappenWrap.style.display = isU17Team ? '' : 'none';

                // Select (Dropdown-Fall) behandeln
                if (etappenSelect) {
                    etappenSelect.required = isU17Team;
                    if (isU17Team && savedEtappenValue) {
                        // Gespeicherten Wert nach Display-Aenderung wiederherstellen
                        etappenSelect.value = savedEtappenValue;
                    } else if (!isU17Team) {
                        etappenSelect.value = '';
                    }
                }

                // Radio-Inputs (aktiv sobald Pods-Feld auf Radio umgestellt ist)
                var etappenRadios = riderForm.querySelectorAll(
                    'input[type="radio"][name="pods_field_etappen_auswahl"], ' +
                    'input[type="radio"][name="pods_field_etappen-auswahl"], ' +
                    'input[type="radio"][name="etappen_auswahl"]'
                );
                Array.prototype.forEach.call(etappenRadios, function(input) {
                    input.required = isU17Team;
                    if (!isU17Team) {
                        input.checked = false;
                    }
                });
            }

            // Haftungsausschluss-Checkbox fuer Hobbyteams
            meldLog('[meldetool] applyVisibility: teamId=' + selectedTeamId + ', isOptional=' + isOptional + ', calling ensureLiabilityCheckbox');
            ensureLiabilityCheckbox(riderForm, isOptional);
        }

        /**
         * UCI-ID Validierung: Muss aus genau 11 Ziffern bestehen
         */
        function setupUciIdValidation() {
            var teamSel = findTeamSelect();
            if (!teamSel) return;
            var riderForm = teamSel.closest('form') || document;
            var uciInput = findFieldInput('uci_id', riderForm);
            if (!uciInput || uciInput.dataset.meldetoolUciValidation === '1') return;
            uciInput.dataset.meldetoolUciValidation = '1';

            var errorEl = document.createElement('span');
            errorEl.style.color = '#dc2626';
            errorEl.style.fontSize = '13px';
            errorEl.style.marginTop = '4px';
            errorEl.style.display = 'none';
            errorEl.textContent = 'Die UCI-ID muss aus genau 11 Ziffern bestehen.';
            if (uciInput.parentNode) {
                uciInput.parentNode.insertBefore(errorEl, uciInput.nextSibling);
            }

            function validateUci() {
                var selId = asInt(teamSel.value);
                var isHobby = optionalTeamIds.indexOf(selId) !== -1;
                var val = uciInput.value;
                if (isHobby || val === '' || val === 'n/a') {
                    uciInput.setCustomValidity('');
                    errorEl.style.display = 'none';
                    return;
                }
                if (/^\d{11}$/.test(val)) {
                    uciInput.setCustomValidity('');
                    errorEl.style.display = 'none';
                } else {
                    uciInput.setCustomValidity('Die UCI-ID muss aus genau 11 Ziffern bestehen.');
                    errorEl.style.display = '';
                }
            }

            uciInput.addEventListener('input', validateUci);
            uciInput.addEventListener('blur', validateUci);
            teamSel.addEventListener('change', validateUci);
        }

        /**
         * IBAN-Prüfsummen-Algorithmus (ISO 13616, Modulo 97)
         * Gibt true zurück wenn IBAN formal gültig ist.
         */
        function isValidIban(raw) {
            var iban = raw.replace(/\s/g, '').toUpperCase();
            if (!/^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$/.test(iban)) {
                return false;
            }
            var rearranged = iban.slice(4) + iban.slice(0, 4);
            var numeric = rearranged.replace(/[A-Z]/g, function(c) {
                return String(c.charCodeAt(0) - 55);
            });
            var remainder = 0;
            for (var i = 0; i < numeric.length; i++) {
                remainder = (remainder * 10 + parseInt(numeric[i], 10)) % 97;
            }
            return remainder === 1;
        }

        /**
         * IBAN-Validierung: Format und Prüfsumme prüfen
         * Nur sichtbar bei Einzelstarter-Teams (ibanBicTeamIds)
         */
        function setupIbanValidation() {
            var teamSel = findTeamSelect();
            if (!teamSel) return;
            var riderForm = teamSel.closest('form') || document;
            var ibanInput = findFieldInput('iban', riderForm);
            if (!ibanInput || ibanInput.dataset.meldetoolIbanValidation === '1') return;
            ibanInput.dataset.meldetoolIbanValidation = '1';

            var errorEl = document.createElement('span');
            errorEl.style.color = '#dc2626';
            errorEl.style.fontSize = '13px';
            errorEl.style.marginTop = '4px';
            errorEl.style.display = 'none';
            errorEl.textContent = 'Die IBAN ist ungültig. Bitte eine gültige IBAN eingeben.';
            if (ibanInput.parentNode) {
                ibanInput.parentNode.insertBefore(errorEl, ibanInput.nextSibling);
            }

            function validateIban() {
                var selId = asInt(teamSel.value);
                var isEinzelstarter = ibanBicTeamIds.indexOf(selId) !== -1;
                var val = ibanInput.value.trim();
                if (!isEinzelstarter || val === '') {
                    ibanInput.setCustomValidity('');
                    errorEl.style.display = 'none';
                    return;
                }
                if (isValidIban(val)) {
                    ibanInput.setCustomValidity('');
                    errorEl.style.display = 'none';
                } else {
                    ibanInput.setCustomValidity('Die IBAN ist ungültig.');
                    errorEl.style.display = '';
                }
            }

            ibanInput.addEventListener('input', validateIban);
            ibanInput.addEventListener('blur', validateIban);
            teamSel.addEventListener('change', validateIban);
        }

        /**
         * IBAN-Validierung im Teamformular (Anmeldung Teams)
         * Das IBAN-Feld ist dort immer sichtbar und wird unabhaengig vom Team-Typ geprueft.
         */
        function setupTeamFormIbanValidation() {
            var teamForm = findFormNearHeading('Anmeldung Teams')
                || findFormByFieldSelectors([
                    'input[name="pods_field_teamname"]',
                    'input[name="teamname"]',
                    'input[name="pods_field_email_manager"]',
                    'input[name="email_manager"]'
                ]);
            if (!teamForm) return;

            var ibanInput = teamForm.querySelector(
                'input[name="pods_field_iban"], input[name="iban"], #pods-form-ui-pods-field-iban, #pods-form-ui-iban'
            );
            if (!ibanInput || ibanInput.dataset.meldetoolIbanValidation === '1') return;
            ibanInput.dataset.meldetoolIbanValidation = '1';

            var errorEl = document.createElement('span');
            errorEl.style.color = '#dc2626';
            errorEl.style.fontSize = '13px';
            errorEl.style.marginTop = '4px';
            errorEl.style.display = 'none';
            errorEl.textContent = 'Die IBAN ist ungültig. Bitte eine gültige IBAN eingeben.';
            if (ibanInput.parentNode) {
                ibanInput.parentNode.insertBefore(errorEl, ibanInput.nextSibling);
            }

            function validateTeamIban() {
                var val = ibanInput.value.trim();
                if (val === '') {
                    ibanInput.setCustomValidity('');
                    errorEl.style.display = 'none';
                    return;
                }
                if (isValidIban(val)) {
                    ibanInput.setCustomValidity('');
                    errorEl.style.display = 'none';
                } else {
                    ibanInput.setCustomValidity('Die IBAN ist ungültig.');
                    errorEl.style.display = '';
                }
            }

            ibanInput.addEventListener('input', validateTeamIban);
            ibanInput.addEventListener('blur', validateTeamIban);
        }

        var bootCompleted = false;

        /**
         * Initialisiert die Feldanzeige-Logik
         * 
         * 1. Sucht Team-Select-Element
         * 2. Wendet Sichtbarkeitsregeln sofort an
         * 3. Registriert Change-Event-Listener
         * 4. Registriert Form-Submit-Validierung fuer Hobbyteams
         * 
         * @return {boolean} true wenn erfolgreich initialisiert, false wenn Team-Select nicht gefunden
         */
        function boot() {
            if (bootCompleted) {
                return true;
            }

            initFrontendFormSwitcher();

            var teamSelect = findTeamSelect();
            if (!teamSelect) {
                return false;
            }
            meldLog('[meldetool] team select found, optional IDs: ' + JSON.stringify(optionalTeamIds));
            meldLog('[meldetool] iban/bic IDs: ' + JSON.stringify(ibanBicTeamIds));
            logAllSelects();
            applyVisibility();
            setupUciIdValidation();
            setupIbanValidation();
            setupTeamFormIbanValidation();
            teamSelect.addEventListener('change', function() {
                meldLog('[meldetool] team select change event triggered');
                applyVisibility();
            });

            // Registriere Form-Submit-Handler fuer Hobbyteam-Validierung
            var riderForm = teamSelect.closest('form') || document;
            if (riderForm && riderForm.addEventListener) {
                riderForm.addEventListener('submit', function(e) {
                    var selectedTeamId = asInt(teamSelect.value);
                    var isHobbyTeam = optionalTeamIds.indexOf(selectedTeamId) !== -1;
                    if (!isHobbyTeam) {
                        return;
                    }

                    var checkbox = riderForm.querySelector('#meldetool_hobby_liability_checkbox');
                    if (!checkbox || !checkbox.checked) {
                        e.preventDefault();
                        if (checkbox) {
                            checkbox.focus();
                        }
                    }
                });
            }

            bootCompleted = true;
            return true;
        }

        function tryBoot() {
            boot();
        }

        // Sofort versuchen
        tryBoot();

        // Nach spaeteren Lifecycle-Events erneut versuchen
        document.addEventListener('DOMContentLoaded', tryBoot);
        window.addEventListener('load', tryBoot);

        // Beobachte asynchron nachgeladene DOM-Elemente (Caching/Optimierung/Builder)
        var bootObserver = new MutationObserver(function() {
            if (bootCompleted) {
                return;
            }
            tryBoot();
        });
        if (document.body) {
            bootObserver.observe(document.body, { childList: true, subtree: true });
        }

        // Zusätzlicher Polling-Fallback mit laengerem Fenster
        var tries = 0;
        var timer = setInterval(function() {
            tries++;
            tryBoot();
            if (bootCompleted || tries > 240) {
                if (!bootCompleted && tries > 240) {
                    meldLog('[meldetool] WARNING: team select not found after ' + tries + ' attempts.');
                    logAllSelects();
                }
                clearInterval(timer);
                bootObserver.disconnect();
            }
        }, 250);
    })();
    </script>
    <?php
});