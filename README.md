# Sven Das Turnier

> Eine sehr sehr coole Software für Spaßturniere — fertig für den Vereinsabend.

WordPress-Plugin für Tischtennis-, Tennis- und alle anderen Turniere, wo zwei gegeneinander spielen. Gruppen mit Drag-and-Drop, Round-Robin-Vorrunde, Doppel-KO-Endrunde (Gold + Silber) inkl. Trostrunde und Spiel um Platz 3, wahlweise **Tennis-Modus mit Satz-Ergebnissen** und **„Nur Gruppenphase"-Format**, Live-Seite mit Auto-Refresh für den Beamer im Vereinsheim, Olympia-Treppchen am Ende.

Entwickelt für die **Weller Open** (Open-Air Tischtennisturnier des TV Welle).

![Endergebnis-Podium mit Gold/Silber/Bronze](docs/screenshots/podium.png)

---

## Features

### Modi & Formate (neu in 2.0)
- **Standard-Modus**: Sieg/Niederlage mit einem Klick (wie bisher)
- **🎾 Tennis-Modus**: Ergebnisse mit Satz-Scores eintragen (z. B. `6:4, 3:6, 7:5`)
  - Anzahl Sätze wählbar: 1 Satz, Best of 3, Best of 5, Best of 7
  - Ergebnis-Dialog mit Satz-Eingaben — der Sieger wird automatisch aus den Sätzen ermittelt (serverseitig validiert)
  - **w.o.-Haken** („Nicht angetreten"): kampfloser Sieg ohne Satz-Ergebnis
  - Satz-Statistik in den Tabellen (Sätze gewonnen:verloren), **Satzdifferenz als Tiebreak** nach Siegen und direktem Vergleich
  - Scores überall sichtbar: Spiele-Liste, Brackets (Admin + Frontend)
- **Turnierformat wählbar**:
  - *Gruppenphase + Endrunde (KO)* — wie bisher Gold-/Silberrunde
  - *Nur Gruppenphase* — Jeder gegen Jeden, die Tabelle ist das Endergebnis; funktioniert auch mit **einer einzigen großen Gruppe**. Podium kommt direkt aus der Abschluss-Tabelle.
- Modus, Sätze und Format werden pro Turnier beim Anlegen gewählt (später nur im Setup änderbar)

### Setup
- Turnier mit frei wählbarem Namen, Gruppenanzahl (1–12) und Tisch-/Platzanzahl (1–20)
- Spieler manuell oder per **Drag-and-Drop-Import** aus dem zugehörigen Anmeldungs-Plugin (`sven-das-anmeldung`)
- Drag-and-Drop zwischen Unzugeordnet und Gruppen, Sortier-Reihenfolge pro Gruppe (für Setzlisten)
- Demo-Spieler-Knopf zum schnellen Testen mit 30 fertigen Namen

### Vorrunde (Round-Robin)
- **Round-Robin per Circle-Method** — z. B. bei 4 Spielern 3 Runden mit gleichmäßigen Pausen
- **Smart Next-Match-Logik:** zeigt so viele parallele Spiele wie Tische konfiguriert sind, niedrige Runden zuerst, kein Spieler in zwei Spielen gleichzeitig
- Sieg eintragen mit einem Klick, Ergebnisse rückgängig machbar
- Live-Tabellen pro Gruppe (Siege absteigend, direkter Vergleich bei Gleichstand)
- 🥇 / 🥈 für die ersten beiden ab Gruppenende → wandern in die Gold- bzw. Silberrunde

### Endrunde (Doppel-KO)
- **Goldrunde** für die Top-2 jeder Gruppe → spielt Plätze 1, 2 und 3 aus
- **Silberrunde** für alle ab Platz 3 → spielt parallel ihre eigenen Plätze 1, 2 und 3 aus
- **Doppel-KO** in jeder Runde: wer einmal verliert, kommt nicht raus sondern in die **Trostrunde**
- Hauptrunden-Finale entscheidet 1. + 2., letztes Trostrunden-Match entscheidet Platz 3 — **kein Grand Final, kein Bracket-Reset** (alles bleibt endgültig)
- **Smart Scheduling:** Hauptrunde und Trostrunde laufen parallel, damit am Ende alles ungefähr gleichzeitig fertig wird
- **Bye-Propagation:** ungerade Teilnehmerzahlen werden automatisch durch Freilose ausgeglichen
- **Reset-Cascade:** ein zurückgesetztes Bracket-Match räumt die nachgelagerten Slots sauber auf

### Live-Seite (Frontend-Shortcode)
- `[sdt_turnier]` zeigt das aktuell laufende oder zuletzt aktive Turnier
- `[sdt_turnier id="3" refresh="15"]` für ein bestimmtes Turnier mit individuellem Auto-Refresh-Intervall
- **Auto-Refresh** alle 30 s (per Attribut anpassbar, `refresh="0"` = aus)
- **Schwebende Sprungmarken-Nav** rechts mit Smooth-Scroll — Nächste Spiele, Gold-/Silberrunde, Trostrunden, Vorrunden, Endergebnis
- **Olympia-Podium** am Ende: Gold/Silber/Bronze auf drei Stufen, sobald Finals durch sind
- Mobile-Friendly (Nav wandert auf schmalen Screens nach oben)
- Reine Anzeige, keine Bearbeitung — gefahrlos auf öffentlichen Seiten einbindbar

### Admin-Komfort
- **Backend-Navigation** mit Sprungmarken zu allen Sektionen (sticky oben)
- **Bracket-Visualisierung** im Admin für Gold + Silber jeweils mit Hauptrunde, Trostrunde und Spiel-um-Platz-3
- **Vorrunden-Simulator** + **Bracket-Simulator** mit Zufallsergebnissen — perfekt für Demos und Tests
- **JSON-Backup-Export** des kompletten Turniers (Spieler + Matches + Stand)
- AJAX-Endpoints mit Nonce + `manage_options`-Capability-Check

![Admin-Ansicht "Nächste Spiele" mit Trostrunden-Mix und Simulator-Button](docs/screenshots/admin-naechste-spiele.png)

---

## Installation

1. Plugin-ZIP über WP-Admin → Plugins → Installieren → Plugin hochladen
2. Aktivieren — DB-Tabellen werden automatisch erstellt
3. Im Menü „Turnier" → Neues Turnier anlegen

Alternativ als Git-Clone direkt in `wp-content/plugins/`:

```bash
cd wp-content/plugins/
git clone git@github.com:zvenson/sven-das-turnier.git
```

---

## Verwendung

### 1. Turnier-Setup
1. `/wp-admin/ → Turnier → Neues Turnier`
2. Spieler in „Unzugeordnet" eintragen oder aus Anmeldungen reinziehen
3. Per Drag-and-Drop in Gruppen ziehen (Empfehlung: 3–4 pro Gruppe)
4. „Plan generieren & Turnier starten" — alle Vorrundenspiele werden erzeugt

### 2. Vorrunde spielen
- Box „Nächste Spiele" zeigt was gerade auf welchem Tisch läuft
- Sieger anklicken → Tabelle aktualisiert sich, nächste Spiele rücken nach
- Mit dem Test-Button kannst du die ganze Vorrunde zufällig simulieren

### 3. Endrunde
- Sobald die Vorrunde durch ist: „🏆 Gold- und Silberrunde generieren"
- Spiel die Brackets durch — Hauptrunde + Trostrunde laufen parallel
- Sieger des Hauptrunden-Finales = 🥇, Verlierer = 🥈, Sieger letztes Trostrunden-Match = 🥉

### 4. Frontend einbinden
Auf einer beliebigen Seite den Shortcode platzieren:

```
[sdt_turnier]
```

Auf einer Beamer-Seite/Vereinsheim-TV mit häufigem Refresh:

```
[sdt_turnier refresh="10"]
```

---

## Datenmodell

Drei DB-Tabellen mit Präfix `wp_sdt_`:

### `wp_sdt_tournaments`
| Feld | Typ | Bedeutung |
|------|-----|-----------|
| `id` | BIGINT PK | |
| `name` | VARCHAR(190) | Anzeigename |
| `status` | VARCHAR(20) | `setup` / `running` / `finished` |
| `num_groups` | TINYINT | gewünschte Gruppenanzahl |
| `tables_count` | TINYINT | parallele Spiele möglich |
| `mode` | VARCHAR(20) | `simple` (Sieg/Niederlage) / `tennis` (Satz-Ergebnisse) |
| `best_of` | TINYINT | Anzahl Sätze im Tennis-Modus (1/3/5/7) |
| `format` | VARCHAR(20) | `group_ko` (Gruppen + Endrunde) / `group_only` (nur Gruppenphase) |
| `created_at` | DATETIME | |

### `wp_sdt_players`
| Feld | Typ | Bedeutung |
|------|-----|-----------|
| `id` | BIGINT PK | |
| `tournament_id` | BIGINT | FK |
| `name` | VARCHAR(190) | |
| `group_label` | VARCHAR(5) NULL | `A`, `B`, … oder NULL=unzugeordnet |
| `position` | SMALLINT | Sortierung innerhalb Gruppe |
| `registration_id` | BIGINT NULL | FK auf `sven-das-anmeldung`-Plugin |

### `wp_sdt_matches`
| Feld | Typ | Bedeutung |
|------|-----|-----------|
| `id` | BIGINT PK | |
| `tournament_id` | BIGINT | FK |
| `group_label` | VARCHAR(5) | Vorrunde |
| `round` | TINYINT | Vorrundenrunde |
| `position` | SMALLINT | innerhalb Runde |
| `player1_id`, `player2_id` | BIGINT | |
| `winner_id` | BIGINT NULL | |
| `status` | VARCHAR(20) | `pending` / `done` |
| `score` | VARCHAR(100) NULL | Satz-Ergebnis aus Sicht von Spieler 1, z. B. `6:4, 3:6, 7:5` (nur Tennis-Modus) |
| `walkover` | TINYINT | 1 = kampfloser Sieg (w.o. / nicht angetreten) |
| `phase` | VARCHAR(20) | `group` / `gold` / `silber` |
| `bracket_round` | TINYINT NULL | Runde innerhalb des Brackets |
| `bracket_position` | SMALLINT NULL | Position innerhalb der Runde |
| `bracket_side` | VARCHAR(10) NULL | `winner` (Hauptrunde) / `loser` (Trostrunde) / `final` |
| `feeds_match_id`, `feeds_slot` | BIGINT/TINYINT NULL | wohin der Sieger weitergegeben wird |
| `loser_feeds_match_id`, `loser_feeds_slot` | BIGINT/TINYINT NULL | wohin der Verlierer ins Trostrunden-Bracket geht |
| `finished_at` | DATETIME NULL | |

---

## Dateistruktur

```
sven-das-turnier/
├── sven-das-turnier.php       Plugin-Header, Bootstrap, Hooks
├── README.md
├── LICENSE
├── includes/
│   ├── class-db.php           Schema (dbDelta) + alle DB-Queries
│   ├── class-scheduler.php    Round-Robin, Bracket-Build, Next-Match, Bye-Auflösung, Reset-Cascade
│   ├── class-ajax.php         AJAX-Endpoints
│   ├── class-admin.php        Backend-Pages + Bracket-Render
│   └── class-frontend.php     Shortcode + Live-Seite + Podium
├── assets/
│   ├── admin.js               Drag-Drop + AJAX-Wrapper + Sim-Handler
│   ├── admin.css
│   └── frontend.css
└── docs/
    └── screenshots/           PNGs für README
```

---

## Algorithmen

### Round-Robin (Circle-Method, Vorrunde)
Bei `n` Spielern in einer Gruppe (für `n=4`):

```
Round 1: P1-P4  P2-P3
Round 2: P1-P3  P4-P2
Round 3: P1-P2  P3-P4
```

Bei ungerader Spielerzahl wird intern ein Bye eingefügt — Spieler pausiert in dieser Runde. → [`SDT_Scheduler::round_robin()`](includes/class-scheduler.php)

### Doppel-KO Bracket-Build
- **Hauptrunde:** klassisches Single-Elimination-Bracket auf nächster 2er-Potenz mit Standard-Seeding-Reihenfolge (z. B. 1 vs 8, 4 vs 5 für `n=8`)
- **Trostrunde:** alterniert zwischen „Minor" (paart Trostrunden-Sieger untereinander) und „Major" (paart Trostrunden-Sieger gegen frischen Hauptrunden-Verlierer)
- Hauptrunden-Verlierer aus R1 werden direkt-benachbart in der Trostrunde gepaart
- Hauptrunden-Verlierer ab R2 werden gespiegelt in die Trostrunde gespeist (verhindert sofortige Rematches)
- Letztes Trostrunden-Match = Spiel um Platz 3, kein Grand Final
- → [`SDT_Scheduler::build_bracket()`](includes/class-scheduler.php)

### Bye-Auflösung (`resolve_byes`)
Iterativer Algorithmus, der Matches mit nur einem echten Spieler automatisch weiterleitet, sobald klar ist, dass der andere Slot permanent leer bleibt (Auto-Sieger). Tote Matches (beide Slots permanent leer durch verkettete Byes) werden als `done`/`winner=NULL` markiert und ihre 0-Propagation nach downstream durchgereicht.

### Next-Match-Reihenfolge (während Bracket-Phase)
Priorität: 
1. `bracket_round ASC` — niedrigste Runde überall zuerst (Trostrunde & Hauptrunde parallel)
2. `bracket_side`: Hauptrunde vor Trostrunde vor Finale
3. `phase`: Goldrunde vor Silberrunde
4. `bracket_position ASC`

Damit laufen Gold- und Silberrunde sowie Haupt- und Trostrunde möglichst gleichzeitig fertig.

### Reset-Cascade
Beim Zurücksetzen eines Bracket-Matches werden die nachgelagerten Slots (Sieger-Feed und Verlierer-Feed) leer geräumt. Wenn der nachgelagerte Match selbst schon weitergespielt war (etwa durch Auto-Bye-Advance), wird rekursiv aufgeräumt. Nach dem Cascade läuft `resolve_byes` neu durch, damit Bye-Auto-Advances konsistent neu berechnet werden.

---

## Versionshistorie

- **2.0.0** — 🎾 Tennis-Modus (Satz-Ergebnisse, Best of 1/3/5/7, w.o.-Haken, Satzdifferenz-Tiebreak), Turnierformat wählbar (Gruppen + KO oder nur Gruppenphase, auch mit einer großen Gruppe), Podium aus der Abschluss-Tabelle, Score-Anzeige in allen Ansichten
- **1.2.5** — Bracket-Simulator + finale Polish-Runde (Podium nach oben, Reihenfolge nach Runden, Sprungmarken)
- **1.2.0** — Doppel-KO mit Trostrunde, Spiel um Platz 3, Live-Podium, Auto-Refresh, schwebende Navigation
- **1.1.0** — Doppel-KO (initial)
- **1.0.4** — Gold/Silber Single-Elimination
- **1.0.3** — KO-Brackets, Registrierungs-Import, Vorschau-Queue, Backup
- **1.0.0** — Initial: Round-Robin Vorrunde, Live-Tabellen, Shortcode

---

## Entwicklung

Voraussetzungen:
- WordPress 6.0+
- PHP 8.0+
- MySQL/MariaDB

Lokale Entwicklung läuft in einem Docker-WP-Setup unter `wp-content/plugins/sven-das-turnier/`.

Code-Style:
- Tabs (WordPress-Style)
- `SDT_`-Prefix für alle Klassen, Konstanten, Hooks, DB-Tabellen
- AJAX-Endpoints mit Nonce + `manage_options`-Capability-Check
- DB-Queries via `$wpdb->prepare()` oder Format-Arrays

---

## Lizenz

GPL-2.0-or-later — siehe [LICENSE](LICENSE).

## Autor

Sven Trogus · [weller-open.de](https://weller-open.de/) · [designburg.net](https://designburg.net/)
