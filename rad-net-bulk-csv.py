import argparse
import csv
import re
import sys
import time
from html import unescape
from typing import Dict, List, Tuple
from urllib.parse import urljoin

import requests

URL = "https://www.rad-net.de/rad-net-sportlerportrait.htm"
DEFAULT_SLEEP_SECONDS = 0.35

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "de-DE,de;q=0.9,en;q=0.8",
    "Referer": URL,
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Ergaenzt Geburtsjahre in einer CSV anhand rad-net Sportlerportrait"
    )
    parser.add_argument("--input", required=True, help="Eingabe-CSV")
    parser.add_argument("--output", required=True, help="Ausgabe-CSV")
    parser.add_argument("--first-col", default="vorname", help="Spaltenname Vorname")
    parser.add_argument("--last-col", default="nachname", help="Spaltenname Nachname")
    parser.add_argument("--year-col", default="geburtsjahr", help="Zielspalte Geburtsjahr")
    parser.add_argument("--status-col", default="radnet_status", help="Statusspalte")
    parser.add_argument("--match-col", default="radnet_match", help="Gematchter Name")
    parser.add_argument("--url-col", default="radnet_url", help="Verwendeter Profil-Link")
    parser.add_argument("--sleep", type=float, default=DEFAULT_SLEEP_SECONDS, help="Pause zwischen Requests in Sekunden")
    parser.add_argument("--season", default="2026", help="Saisonwert fuer Suchformular")
    parser.add_argument("--delimiter", default=";", help="CSV Delimiter, z.B. ';' oder ','")
    parser.add_argument("--encoding", default="utf-8-sig", help="CSV Encoding")
    parser.add_argument("--overwrite-year", action="store_true", help="Vorhandene Geburtsjahre ueberschreiben")
    return parser.parse_args()


def parse_input_fields(html: str) -> Dict[str, str]:
    fields: Dict[str, str] = {}
    for m in re.finditer(r"<input\b[^>]*>", html, flags=re.IGNORECASE):
        tag = m.group(0)
        name_m = re.search(r"\bname\s*=\s*['\"]([^'\"]+)['\"]", tag, flags=re.IGNORECASE)
        if not name_m:
            continue
        name = unescape(name_m.group(1))
        value_m = re.search(r"\bvalue\s*=\s*['\"]([^'\"]*)['\"]", tag, flags=re.IGNORECASE)
        value = unescape(value_m.group(1)) if value_m else ""
        fields[name] = value
    return fields


def extract_candidate_links(search_html: str) -> List[Dict[str, str]]:
    pattern = re.compile(
        r'<a[^>]+title="Zum Sportlerportrait von ([^"]+)"[^>]+href="([^"]+)"',
        flags=re.IGNORECASE,
    )
    candidates: List[Dict[str, str]] = []
    seen = set()

    for m in pattern.finditer(search_html):
        full_name = unescape(m.group(1)).strip()
        href = unescape(m.group(2)).strip()
        key = (full_name.lower(), href)
        if key in seen:
            continue
        seen.add(key)
        candidates.append({"full_name": full_name, "href": href})

    return candidates


def find_next_page_url(search_html: str, current_page: int = 1) -> str:
    """
    Sucht nach dem Link zur naechsten Seite basierend auf der aktuellen Seite.
    current_page: aktuelle Seitennummer (z.B. 1 bedeutet wir sind auf Seite 1, suchen Seite 2)
    """
    next_page_num = current_page + 1
    
    # Suche nach "Seite ausw" Bereich
    seite_section = re.search(
        r'Seite\s+ausw[^<]*.*?</(?:td|div)>',
        search_html,
        re.IGNORECASE | re.DOTALL,
    )
    
    if not seite_section:
        return ""
    
    section_html = seite_section.group(0)
    
    # Suche nach dem Link mit pgID_BDRMitglied=N oder pgID_BDRMitglied%3DN (HTML-kodiert)
    link_pattern = rf'href="([^"]*(?:pgID_BDRMitglied(?:%3D|=)){next_page_num}[^"]*)"'
    link_match = re.search(link_pattern, section_html)
    
    if link_match:
        href = unescape(link_match.group(1))
        # Nur relative URLs sind in der HTML, wir muessen diese komplett machen
        if href.startswith("/"):
            href = "https://www.rad-net.de" + href
        return href
    
    return ""


def choose_candidate(candidates: List[Dict[str, str]], vorname: str, nachname: str) -> Tuple[Dict[str, str], int, str]:
    vn = (vorname or "").strip().lower()
    nn = (nachname or "").strip().lower()

    exact_first: List[Dict[str, str]] = []
    loose_first: List[Dict[str, str]] = []
    surname_only: List[Dict[str, str]] = []

    for c in candidates:
        full = c["full_name"].lower()
        if nn not in full:
            continue

        if vn and re.search(rf"\b{re.escape(vn)}\b", full):
            exact_first.append(c)
        elif vn and vn in full:
            loose_first.append(c)
        else:
            surname_only.append(c)

    if exact_first:
        return exact_first[0], len(exact_first), "exact"
    if loose_first:
        return loose_first[0], len(loose_first), "loose"
    if surname_only:
        return surname_only[0], len(surname_only), "surname_only"
    return {}, 0, "none"


def extract_birth_year_from_profile(profile_html: str) -> str:
    m = re.search(
        r'Jahrgang:\s*</font>\s*</td>\s*<td[^>]*>\s*<font[^>]*>\s*(\d{4})\s*</font>',
        profile_html,
        flags=re.IGNORECASE,
    )
    if m:
        return m.group(1)
    return ""


def read_rows(input_path: str, delimiter: str, encoding: str) -> Tuple[List[Dict[str, str]], List[str]]:
    with open(input_path, "r", newline="", encoding=encoding) as f:
        reader = csv.DictReader(f, delimiter=delimiter)
        if not reader.fieldnames:
            raise ValueError("CSV hat keine Kopfzeile")
        rows = list(reader)
        return rows, list(reader.fieldnames)


def write_rows(output_path: str, rows: List[Dict[str, str]], fieldnames: List[str], delimiter: str, encoding: str) -> None:
    with open(output_path, "w", newline="", encoding=encoding) as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames, delimiter=delimiter)
        writer.writeheader()
        writer.writerows(rows)


def main() -> int:
    args = parse_args()

    rows, fieldnames = read_rows(args.input, args.delimiter, args.encoding)

    required = [args.first_col, args.last_col]
    for c in required:
        if c not in fieldnames:
            print(f"[FEHLER] Spalte nicht gefunden: {c}")
            print(f"Vorhandene Spalten: {', '.join(fieldnames)}")
            return 2

    for c in [args.year_col, args.status_col, args.match_col, args.url_col]:
        if c not in fieldnames:
            fieldnames.append(c)

    session = requests.Session()
    session.headers.update(HEADERS)

    base = session.get(URL, timeout=20)
    base.raise_for_status()
    base_fields = parse_input_fields(base.text)
    if "fldFahrerStichwort" not in base_fields or "pbFahrerSuchen" not in base_fields:
        print("[FEHLER] Erwartete Suchfelder nicht gefunden")
        return 3

    surname_cache: Dict[str, List[Dict[str, str]]] = {}
    profile_cache: Dict[str, str] = {}

    total = len(rows)
    ok = 0
    skipped = 0
    miss = 0
    ambiguous = 0
    errors = 0

    for idx, row in enumerate(rows, start=1):
        vorname = (row.get(args.first_col) or "").strip()
        nachname = (row.get(args.last_col) or "").strip()
        current_year = (row.get(args.year_col) or "").strip()

        if not vorname or not nachname:
            row[args.status_col] = "SKIP_NAME_MISSING"
            skipped += 1
            print(f"[{idx}/{total}] SKIP fehlender Name")
            continue

        if current_year and not args.overwrite_year:
            row[args.status_col] = "SKIP_HAS_YEAR"
            skipped += 1
            print(f"[{idx}/{total}] {vorname} {nachname}: SKIP_HAS_YEAR")
            continue

        try:
            if nachname not in surname_cache:
                # Erste Suche durchfuehren
                r0 = session.get(URL, timeout=20)
                r0.raise_for_status()
                payload = parse_input_fields(r0.text)
                payload["fldFahrerStichwort"] = nachname
                payload["pbFahrerSuchen"] = "Suchen"
                payload["fldFahrerSaison"] = payload.get("fldFahrerSaison", args.season) or args.season

                all_candidates = []
                page = 1
                current_url = URL
                
                while True:
                    # Bei Seite 1 POST, danach GET mit den Seiten-Links folgen
                    if page == 1:
                        r1 = session.post(current_url, data=payload, timeout=20)
                    else:
                        r1 = session.get(current_url, timeout=20)
                    
                    r1.raise_for_status()
                    
                    candidates_on_page = extract_candidate_links(r1.text)
                    all_candidates.extend(candidates_on_page)
                    print(f"  Seite {page}: {len(candidates_on_page)} Kandidat(en)")
                    
                    # Suche nach Link zur naechsten Seite (uebergebe aktuelle Seitennummer)
                    next_url = find_next_page_url(r1.text, current_page=page)
                    if not next_url:
                        # Keine weitere Seite gefunden
                        break
                    
                    current_url = next_url
                    page += 1
                    time.sleep(max(0.0, args.sleep))
                
                surname_cache[nachname] = all_candidates
                if all_candidates:
                    print(f"  -> {len(all_candidates)} Kandidat(en) auf {page} Seite(n)")

                time.sleep(max(0.0, args.sleep))

            candidates = surname_cache.get(nachname, [])
            chosen, match_count, match_quality = choose_candidate(candidates, vorname, nachname)

            if not chosen:
                row[args.status_col] = "MISS_NO_CANDIDATE"
                row[args.match_col] = ""
                row[args.url_col] = ""
                row[args.year_col] = ""
                miss += 1
                print(f"[{idx}/{total}] {vorname} {nachname}: MISS_NO_CANDIDATE")
                continue

            profile_url = urljoin(URL, chosen["href"])

            if profile_url not in profile_cache:
                pr = session.get(profile_url, timeout=20)
                pr.raise_for_status()
                profile_cache[profile_url] = pr.text
                time.sleep(max(0.0, args.sleep))

            year = extract_birth_year_from_profile(profile_cache[profile_url])

            row[args.match_col] = chosen["full_name"]
            row[args.url_col] = profile_url

            if year:
                row[args.year_col] = year
                if match_count > 1:
                    row[args.status_col] = f"OK_MULTI_{match_quality.upper()}"
                    ambiguous += 1
                else:
                    row[args.status_col] = f"OK_{match_quality.upper()}"
                ok += 1
                print(f"[{idx}/{total}] {vorname} {nachname}: {row[args.status_col]} -> {year}")
            else:
                row[args.year_col] = ""
                row[args.status_col] = "MISS_NO_YEAR_ON_PROFILE"
                miss += 1
                print(f"[{idx}/{total}] {vorname} {nachname}: MISS_NO_YEAR_ON_PROFILE")

        except Exception as ex:
            row[args.status_col] = f"ERROR_{type(ex).__name__}"
            errors += 1
            print(f"[{idx}/{total}] {vorname} {nachname}: ERROR {ex}")

    write_rows(args.output, rows, fieldnames, args.delimiter, args.encoding)

    print("\n=== Statistik ===")
    print(f"Gesamt: {total}")
    print(f"OK: {ok}")
    print(f"Mehrdeutig (unter OK): {ambiguous}")
    print(f"SKIP: {skipped}")
    print(f"MISS: {miss}")
    print(f"ERROR: {errors}")
    print(f"Ausgabe: {args.output}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
