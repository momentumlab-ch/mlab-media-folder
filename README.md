<img src="assets/images/icon-128x128.png" width="64" height="64" alt="" align="right">

# MomentumLab Media Folder

Ordner für die WordPress-Mediathek: Ordnerbaum, Drag & Drop, Upload direkt in den geöffneten Ordner und ein Ordner-Filter in jedem Medien-Dialog.

| | |
|---|---|
| Aktuelle Version | 2.0.0 |
| WordPress | ab 6.5, getestet bis 7.1.3 |
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

1. Die neueste `mlab-media-folder-x.y.z.zip` herunterladen aus den [Releases](https://github.com/momentumlab-ch/mlab-media-folder/releases).
2. In WordPress unter **Plugins › Installieren › Plugin hochladen** die ZIP-Datei wählen und installieren.
3. Das Plugin aktivieren. Die Ordner-Leiste erscheint unter **Medien › Mediathek**.

Das ZIP aus den Releases ist genau das Plugin und lässt sich direkt hochladen. Auch **Code › Download ZIP** auf GitHub funktioniert, WordPress legt das Plugin dann aber im Ordner `mlab-media-folder-main` ab. Deshalb besser das ZIP aus den Releases nehmen.

### Umstieg vom alten Plugin (mlab-media-folders, bis 1.1.6)

Bis Version 1.1.6 hiess das Plugin «MomentumLab Media Folders» und lag im Ordner `mlab-media-folders`. Der Umstieg läuft von selbst:

- Websites mit 1.1.6 bekommen das Update wie gewohnt. Jedes Release enthält dafür zusätzlich `mlab-media-folders-x.y.z.zip`, eine kleine Brücke unter dem alten Namen.
- Beim nächsten Aufruf des Admin-Bereichs installiert die Brücke das neue Plugin `mlab-media-folder`, aktiviert es und löscht den alten Ordner.
- Wird das neue Plugin stattdessen von Hand hochgeladen und aktiviert, deaktiviert und löscht es das alte selbst.
- Ordner und Zuordnungen bleiben erhalten (gleiche Taxonomie). Die Lösch-Routine des alten Plugins läuft nie. War die automatische Aktualisierung eingeschaltet, bleibt sie es.
- Kann WordPress nicht direkt ins Dateisystem schreiben (FTP-Zugang nötig), zeigt die Brücke einen Hinweis. Dann das neue ZIP von Hand hochladen.

## Updates

Ab Version 1.1.0 holt sich das Plugin Updates selbst aus diesem Repo:

- Der Plugin-Header enthält `Update URI: https://github.com/momentumlab-ch/mlab-media-folder`. Dadurch fragt WordPress nicht WordPress.org, sondern das Plugin selbst nach Updates.
- Das Plugin liest das neueste veröffentlichte Release über die GitHub-API (`/releases/latest`). Entwürfe und Pre-Releases werden ignoriert.
- Als Update gilt nur die angehängte Datei `mlab-media-folder-<version>.zip`, nie das automatisch erzeugte Quellcode-ZIP von GitHub.
- WordPress sucht von sich aus alle 12 Stunden nach Updates. Das Plugin merkt sich die Antwort von GitHub 1 Stunde lang.
- Sofort prüfen: **Dashboard › Aktualisierungen › Erneut prüfen**. Das fragt GitHub neu (WordPress lässt das höchstens einmal pro Minute zu).
- Neue Versionen erscheinen wie gewohnt unter **Dashboard › Aktualisierungen** und auf der Plugin-Seite, mit den Release-Notes unter «Details ansehen». Auto-Updates lassen sich pro Plugin einschalten.
- Es werden keine Daten der Website übertragen. Das Repo ist öffentlich, ein Token ist nicht nötig.

Wer noch 1.0.x installiert hat, muss 1.1.0 einmal von Hand hochladen. Danach laufen Updates über WordPress.

### Neue Version veröffentlichen

1. Version in `mlab-media-folder.php` erhöhen (Header `Version` und Konstante `MLAB_MEDIA_FOLDER_VERSION`), dazu `Stable tag` und Changelog in `readme.txt` sowie einen Abschnitt in `CHANGELOG.md`.
2. Änderungen in `main` mergen.

Mehr braucht es nicht. Die GitHub Action [`release.yml`](.github/workflows/release.yml) läuft bei jedem Push auf `main`. Gibt es für die Plugin-Version noch kein Release, baut sie das Plugin-ZIP und das Brücken-ZIP für alte Installationen, erstellt Tag und Release `v<version>` und nimmt die Notizen aus dem passenden Abschnitt in `CHANGELOG.md`. Ab dann bieten alle installierten Websites das Update an. Die Action lässt sich auch von Hand starten (**Actions › Release › Run workflow**).

## Aufbau des Repos

Das Repo ist das Plugin: alles im Hauptordner landet im ZIP, ausser was `.gitattributes` ausschliesst (`.github/`, `README.md`, `phpcs.xml.dist`).

```
mlab-media-folder.php      Plugin-Datei mit Header und Version
includes/                  PHP-Klassen (Taxonomie, Ordner, REST, Abfragen, Admin, Updater, Umstieg)
assets/                    JavaScript, CSS und Plugin-Icon (images/)
languages/                 Deutsche Übersetzungen (.l10n.php)
readme.txt                 Readme im WordPress-Format
CHANGELOG.md               Änderungen pro Version
uninstall.php              Aufräumen beim Löschen
.github/workflows/         Release-Action
.github/bridge/            Brücke für Websites mit dem alten Plugin
```

## Technik

- Ordner sind eine private, hierarchische Taxonomie `mlab_media_folder` für Anhänge. Es gibt keine eigenen Datenbanktabellen.
- REST-API unter `mlab-media-folder/v1`: `GET`/`POST /folders`, `PATCH`/`DELETE /folders/{id}`, `POST /assign`.

### Sicherheit

- Jede REST-Route prüft Berechtigungen (Standard `upload_files`) und nutzt die REST-Nonce von WordPress.
- Jede verschobene Datei wird gegen `edit_post` des aktuellen Benutzers geprüft.
- Eingaben werden validiert (Typen, Bereiche, existierende Ordner, keine Zyklen); Ordnernamen werden bereinigt und bei der Ausgabe escaped.
- Kein Tracking. Die einzige externe Anfrage ist die Update-Prüfung bei GitHub.

### Hooks und Konstanten für Entwickler

| Name | Standard | Zweck |
|---|---|---|
| Filter `mlab_media_folder_use_capability` | `upload_files` | Wer Ordner sieht und Dateien verschiebt |
| Filter `mlab_media_folder_manage_capability` | `upload_files` | Wer Ordner anlegt, umbenennt, verschiebt und löscht |
| Filter `mlab_media_folder_update_repository` | `momentumlab-ch/mlab-media-folder` | GitHub-Repo für die Update-Prüfung |
| Konstante `MLAB_MEDIA_FOLDER_KEEP_DATA` | nicht gesetzt | Auf `true` setzen, um die Ordner beim Löschen des Plugins zu behalten |

### Deinstallation

Beim Löschen des Plugins werden alle Ordner und die gespeicherte Version entfernt. Mediendateien werden nie angefasst. Mit `define( 'MLAB_MEDIA_FOLDER_KEEP_DATA', true );` in `wp-config.php` bleiben die Ordner erhalten.

### Code-Standard

Der Code folgt den WordPress Coding Standards (`phpcs.xml.dist`):

```sh
composer global require wp-coding-standards/wpcs phpcompatibility/phpcompatibility-wp
phpcs --standard=phpcs.xml.dist .
```

## Versionen

| Version | Datum | Änderungen |
|---|---|---|
| 2.0.0 | 2026-10-17 | Neuer Name «MomentumLab Media Folder» (`mlab-media-folder`), Plugin liegt im Hauptordner des Repos, automatischer Umstieg vom alten Plugin |
| 1.1.6 | 2026-10-17 | Ordner-Zähler stimmen nach dem Löschen; Link zu MomentumLab in der Ordner-Leiste |
| 1.1.5 | 2026-10-10 | Getestet mit WordPress 7.1.3; Kompatibilität wird in der Update-Liste angezeigt |
| 1.1.4 | 2026-10-10 | Plugin-Icon |
| 1.1.3 | 2026-10-10 | Update-Prüfung reagiert schneller; Releases entstehen automatisch beim Merge |
| 1.1.2 | 2026-10-10 | «Details anzeigen» funktioniert auch ohne neueres Release |
| 1.1.1 | 2026-10-10 | Link «Automatische Aktualisierung aktivieren» erscheint immer, auch ohne neueres Release |
| 1.1.0 | 2026-10-10 | Automatische Updates aus den GitHub-Releases |
| 1.0.2 | 2026-10-10 | Ordner-Leiste einklappbar und in der Breite verstellbar; schmalere Standardbreite |
| 1.0.1 | 2026-10-10 | Uploads erscheinen sofort im geöffneten Ordner; «Alle Dateien» sendet keinen leeren Ordner-Parameter mehr |
| 1.0.0 | 2026-10-10 | Erste Version |

Details stehen in [`CHANGELOG.md`](CHANGELOG.md).

## Lizenz

GPL-2.0-or-later, siehe [LICENSE](LICENSE).
