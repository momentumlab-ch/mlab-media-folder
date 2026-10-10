# MomentumLab Media Folders

Ordner für die WordPress-Mediathek: Ordnerbaum, Drag & Drop, Upload direkt in den geöffneten Ordner und ein Ordner-Filter in jedem Medien-Dialog.

| | |
|---|---|
| Aktuelle Version | 1.1.0 |
| WordPress | ab 6.5 |
| PHP | ab 8.0 |
| Lizenz | GPL-2.0-or-later |
| Sprachen | Englisch, Deutsch (de_DE, de_DE_formal, de_CH, de_CH_informal) |

## Funktionen

**Ordnerbaum.** Neben der Mediathek (Raster- und Listenansicht) erscheint eine Ordner-Leiste mit verschachtelten Ordnern und der Anzahl Dateien pro Ordner. Dazu kommen die Ansichten «Alle Dateien» und «Ohne Ordner».

**Ordner verwalten.** Ordner lassen sich anlegen, umbenennen, verschieben (einen Ordner auf einen anderen ziehen) und löschen. Beim Löschen wird nie eine Datei gelöscht: Dateien und Unterordner wandern eine Ebene nach oben.

**Drag & Drop.** Dateien auf einen Ordner ziehen, um sie zu verschieben. Im Mehrfachauswahl-Modus wandern alle ausgewählten Dateien gemeinsam. Als tastaturfreundliche Alternative gibt es in den Anhang-Details ein Feld «Ordner».

**Upload in den geöffneten Ordner.** Neue Uploads landen im Ordner, der gerade offen ist, und erscheinen sofort (mit Fortschritt) im Raster.

**Ordner-Filter in Medien-Dialogen.** Jeder Medien-Dialog (Block-Editor, Galerie, Beitragsbild) bekommt einen Filter «Ordner».

**Anpassbare Leiste.** Die Ordner-Leiste lässt sich auf einen schmalen Streifen einklappen und am rechten Rand in der Breite ziehen (180 bis 480 px, auch mit den Pfeiltasten; Doppelklick setzt zurück). Breite und Zustand merkt sich der Browser.

**Automatische Updates.** WordPress prüft die GitHub-Releases dieses Repos auf neue Versionen (siehe [Updates](#updates)).

Ordner ordnen nur die Verwaltung. Die URLs der Dateien auf der Website ändern sich nicht.

## Installation

1. Die neueste `mlab-media-folders-x.y.z.zip` herunterladen, entweder aus den [Releases](https://github.com/momentumlab-ch/wp-media-folder/releases) oder aus dem Ordner [`releases/`](releases/).
2. In WordPress unter **Plugins › Installieren › Plugin hochladen** die ZIP-Datei wählen und installieren.
3. Das Plugin aktivieren. Die Ordner-Leiste erscheint unter **Medien › Mediathek**.

## Updates

Ab Version 1.1.0 holt sich das Plugin Updates selbst aus diesem Repo:

- Der Plugin-Header enthält `Update URI: https://github.com/momentumlab-ch/wp-media-folder`. Dadurch fragt WordPress nicht WordPress.org, sondern das Plugin selbst nach Updates.
- Das Plugin liest das neueste veröffentlichte Release über die GitHub-API (`/releases/latest`). Entwürfe und Pre-Releases werden ignoriert.
- Als Update gilt nur die angehängte Datei `mlab-media-folders-<version>.zip`, nie das automatisch erzeugte Quellcode-ZIP von GitHub.
- Das Ergebnis wird 12 Stunden zwischengespeichert, nach einem Fehler 1 Stunde. Ein Klick auf **Dashboard › Aktualisierungen › Erneut prüfen** fragt GitHub sofort neu.
- Neue Versionen erscheinen wie gewohnt unter **Dashboard › Aktualisierungen** und auf der Plugin-Seite, mit den Release-Notes unter «Details ansehen». Auto-Updates lassen sich pro Plugin einschalten.
- Es werden keine Daten der Website übertragen. Das Repo ist öffentlich, ein Token ist nicht nötig.

Wer noch 1.0.x installiert hat, muss 1.1.0 einmal von Hand hochladen. Danach laufen Updates über WordPress.

### Neue Version veröffentlichen

1. Version in `mlab-media-folders/mlab-media-folders.php` erhöhen (Header `Version` und Konstante `MLAB_MF_VERSION`), dazu `Stable tag` und Changelog in `readme.txt` sowie einen Abschnitt in `CHANGELOG.md`.
2. Änderungen in `main` mergen.
3. Ein Tag `v<version>` auf `main` setzen, entweder per `git tag v1.2.0 && git push origin v1.2.0` oder auf GitHub unter **Releases › Draft a new release** mit neuem Tag und **Publish release**.

Die GitHub Action [`release.yml`](.github/workflows/release.yml) prüft, ob Tag und Plugin-Version übereinstimmen, baut das ZIP aus `mlab-media-folders/` und hängt es an das Release. Die Release-Notes kommen aus dem passenden Abschnitt in `CHANGELOG.md`. Ab dann bieten alle installierten Websites das Update an.

## Aufbau des Repos

```
mlab-media-folders/        Quellcode des Plugins (Inhalt des ZIPs)
  mlab-media-folders.php   Plugin-Datei mit Header und Version
  includes/                PHP-Klassen (Taxonomie, Ordner, REST, Abfragen, Admin, Updater)
  assets/                  JavaScript und CSS für Mediathek und Medien-Dialoge
  languages/               Deutsche Übersetzungen (.l10n.php)
  readme.txt               Readme im WordPress-Format
  CHANGELOG.md             Änderungen pro Version
releases/                  Unveränderte ZIPs der Versionen 1.0.0 bis 1.0.2
.github/workflows/         Release-Action
```

## Technik

- Ordner sind eine private, hierarchische Taxonomie `mlab_media_folder` für Anhänge. Es gibt keine eigenen Datenbanktabellen.
- REST-API unter `mlab-media-folders/v1`: `GET`/`POST /folders`, `PATCH`/`DELETE /folders/{id}`, `POST /assign`.

### Sicherheit

- Jede REST-Route prüft Berechtigungen (Standard `upload_files`) und nutzt die REST-Nonce von WordPress.
- Jede verschobene Datei wird gegen `edit_post` des aktuellen Benutzers geprüft.
- Eingaben werden validiert (Typen, Bereiche, existierende Ordner, keine Zyklen); Ordnernamen werden bereinigt und bei der Ausgabe escaped.
- Kein Tracking. Die einzige externe Anfrage ist die Update-Prüfung bei GitHub.

### Hooks und Konstanten für Entwickler

| Name | Standard | Zweck |
|---|---|---|
| Filter `mlab_media_folders_use_capability` | `upload_files` | Wer Ordner sieht und Dateien verschiebt |
| Filter `mlab_media_folders_manage_capability` | `upload_files` | Wer Ordner anlegt, umbenennt, verschiebt und löscht |
| Filter `mlab_media_folders_update_repository` | `momentumlab-ch/wp-media-folder` | GitHub-Repo für die Update-Prüfung |
| Konstante `MLAB_MF_KEEP_DATA` | nicht gesetzt | Auf `true` setzen, um die Ordner beim Löschen des Plugins zu behalten |

### Deinstallation

Beim Löschen des Plugins werden alle Ordner und die gespeicherte Version entfernt. Mediendateien werden nie angefasst. Mit `define( 'MLAB_MF_KEEP_DATA', true );` in `wp-config.php` bleiben die Ordner erhalten.

### Code-Standard

Der Code folgt den WordPress Coding Standards (`mlab-media-folders/phpcs.xml.dist`):

```sh
composer global require wp-coding-standards/wpcs phpcompatibility/phpcompatibility-wp
phpcs --standard=mlab-media-folders/phpcs.xml.dist mlab-media-folders
```

## Versionen

| Version | Datum | Änderungen |
|---|---|---|
| 1.1.0 | 2026-10-10 | Automatische Updates aus den GitHub-Releases |
| 1.0.2 | 2026-10-10 | Ordner-Leiste einklappbar und in der Breite verstellbar; schmalere Standardbreite |
| 1.0.1 | 2026-10-10 | Uploads erscheinen sofort im geöffneten Ordner; «Alle Dateien» sendet keinen leeren Ordner-Parameter mehr |
| 1.0.0 | 2026-10-10 | Erste Version |

Details stehen in [`CHANGELOG.md`](mlab-media-folders/CHANGELOG.md).

## Lizenz

GPL-2.0-or-later, siehe [LICENSE](LICENSE).
