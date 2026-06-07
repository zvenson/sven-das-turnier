# Sven Das Turnier

Tischtennis-Turniersoftware als WordPress-Plugin. Gruppen mit Drag-and-Drop, Round-Robin-Spielplan, Sieger-Eintragung per Klick, Live-Tabellen im Backend und auf der Webseite via Shortcode.

Entwickelt für die **Weller Open** (Open-Air Tischtennisturnier des TV Welle).

---

## Features

### Setup-Phase
- Turnier mit frei wählbarem Namen, Gruppenanzahl (1–12) und Tischanzahl (1–20) anlegen
- Spieler manuell hinzufügen (Inline-Form, AJAX)
- **Drag-and-Drop** zwischen „Unzugeordnet" und allen Gruppen (jQuery UI Sortable, kein externer Dep)
- Spieler-Reihenfolge innerhalb einer Gruppe per Drag sortierbar (relevant für Setzlisten-Pärchen)

### Running-Phase
- **Round-Robin per Circle-Method** — bei 4 Spielern 3 Runden mit gleichmäßigen Pausen
- **Smart Next-Match-Logik:** zeigt genau so viele parallele Spiele wie Tische konfiguriert sind, niedrige Runden zuerst, kein Spieler in zwei Spielen gleichzeitig
- Sieg eintragen mit **einem Klick** auf den Namen
- Ergebnisse rückgängig machbar (Reset-Button pro Spiel)
- **Live-Tabellen** pro Gruppe: Siege absteigend, bei Gleichstand direkter Vergleich
- Wenn alle Spiele einer Gruppe abgeschlossen sind: 🥇 / 🥈 für Platz 1 + 2

### Frontend
- Shortcode `[sdt_turnier]` (zeigt aktuell laufendes/letztes Turnier)
- `[sdt_turnier id="3"]` für ein bestimmtes Turnier
- Read-only: Nächste Spiele + alle Tabellen, keine Admin-Buttons

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

### Turnier-Setup
1. `/wp-admin/ → Turnier → Neues Turnier`
2. Spieler unten in „Unzugeordnet" eintragen
3. Per Drag-and-Drop in Gruppen ziehen (Empfehlung: max. 4 pro Gruppe)
4. „Plan generieren & Turnier starten" klickt — alle Spiele werden erzeugt, Status wechselt auf `running`

### Während des Turniers
- „Nächste Spiele"-Box zeigt die jetzt spielbaren Matches
- Sieger anklicken → Tabelle aktualisiert sich automatisch
- Bei Bedarf: „Ergebnis zurücksetzen" in der „Alle Spiele"-Liste
- „Zurück zum Setup" als Notausgang (löscht alle Spiele!)

### Frontend-Einbindung
Auf einer beliebigen Seite den Shortcode platzieren:

```
[sdt_turnier]
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
| `created_at` | DATETIME | |

### `wp_sdt_players`
| Feld | Typ | Bedeutung |
|------|-----|-----------|
| `id` | BIGINT PK | |
| `tournament_id` | BIGINT | FK |
| `name` | VARCHAR(190) | |
| `group_label` | VARCHAR(5) NULL | `A`, `B`, … oder NULL=unzugeordnet |
| `position` | SMALLINT | Sortierung innerhalb Gruppe |

### `wp_sdt_matches`
| Feld | Typ | Bedeutung |
|------|-----|-----------|
| `id` | BIGINT PK | |
| `tournament_id` | BIGINT | FK |
| `group_label` | VARCHAR(5) | |
| `round` | TINYINT | 1, 2, 3 … |
| `position` | SMALLINT | innerhalb Runde |
| `player1_id`, `player2_id` | BIGINT | |
| `winner_id` | BIGINT NULL | |
| `status` | VARCHAR(20) | `pending` / `done` |
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
│   ├── class-scheduler.php    Round-Robin (Circle-Method), Next-Match, Standings
│   ├── class-ajax.php         AJAX-Endpoints (add/delete/assign/result/reset)
│   ├── class-admin.php        Backend-Pages (Liste/Setup/Running)
│   └── class-frontend.php     Shortcode + Public-Rendering
└── assets/
    ├── admin.js               Drag-Drop (jQuery UI Sortable), AJAX-Wrapper
    ├── admin.css
    └── frontend.css
```

---

## Algorithmen

### Round-Robin (Circle-Method)
Bei `n` Spielern in einer Gruppe (für `n=4`):

```
Round 1: P1-P4  P2-P3
Round 2: P1-P3  P4-P2
Round 3: P1-P2  P3-P4
```

Bei ungerader Spielerzahl wird intern ein Bye-Slot eingefügt — der jeweilige Spieler pausiert in dieser Runde. Implementiert in [`SDT_Scheduler::round_robin()`](includes/class-scheduler.php).

### Next-Match-Auswahl
1. Alle `pending` Matches holen, sortieren nach `round ASC, group_label ASC, position ASC`
2. Greedy bis `tables_count` Matches gesammelt sind, dabei: ein Spieler darf nicht in zwei der ausgewählten Matches vorkommen

→ Niedrigere Runden werden zuerst abgearbeitet, jeder Spieler hat mindestens 1 Match gespielt, bevor irgendwer die nächste Runde beginnt.

### Tabellen-Sortierung
1. Siege absteigend
2. Bei Gleichstand zwischen genau 2: direkter Vergleich (Wer hat das Match gewonnen)
3. Bei größeren Patt-Situationen: alphabetisch (manuelles Stechen nötig)

---

## Roadmap

Geplant, aber noch nicht umgesetzt:

- [ ] **KO-Runde** nach Vorrunde (Top 2 pro Gruppe ins Achtel-/Viertelfinale)
- [ ] **Import** von Spielern aus dem `sven-das-anmeldung`-Plugin (bestätigte Anmeldungen)
- [ ] **Live-Auto-Refresh** der Frontend-Tabelle (für Beamer / TV im Vereinsheim)
- [ ] **Satzergebnisse** statt nur Sieger (3:1 / 11:7, 11:9, …)
- [ ] **3-Wege-Stechen-Workflow**, wenn Tabellen-Sortierung nicht deterministisch
- [ ] **CSV-Export** des Endergebnisses
- [ ] **Spieler-Bezeichner / Vereinsspieler-Flag** für die Welle-internen Wertungen

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
- AJAX-Endpoints mit Nonce + Capability-Check
- Alle DB-Queries via `$wpdb->prepare()` oder mit format-Arrays

---

## Lizenz

GPL-2.0-or-later — siehe [LICENSE](LICENSE).

## Autor

Sven Trogus · [weller-open.de](https://weller-open.de/) · [designburg.net](https://designburg.net/)
