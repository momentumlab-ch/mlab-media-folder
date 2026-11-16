<img src="assets/images/icon-128x128.png" width="64" height="64" alt="" align="right">

# MomentumLab Media Folder

Ordner für die WordPress-Mediathek: Ordnerbaum, Drag & Drop, Upload direkt in den geöffneten Ordner und ein Ordner-Filter in jedem Medien-Dialog.

| | |
|---|---|
| Aktuelle Version | 3.0.0 |
| WordPress | ab 6.5, getestet bis 7.1.3 |
| PHP | ab 8.0 |
| Lizenz | GPL-2.0-or-later |
| Sprachen | Englisch, Deutsch (de_DE, de_DE_formal, de_CH, de_CH_informal) |

## 3.0 Workspace

Version 3.0 erweitert die bestehende Mediathek, ohne die 2.x-Ordner- und Media-Health-Workflows zu ersetzen.

- **Workspace:** globale Suche über Dateien und Ordner, optionaler Inspector rechts, Mobile-Drawer für Ordner und Inspector als Bottom Sheet.
- **Smart Views:** «Neue Uploads», «Potenziell ungenutzt», «Ohne Ordner», «Ohne Alternativtext», «Nicht in einer Seite», «Doppelt» und «Datei fehlt».
- **Free bleibt stark:** alle bestehenden 2.x-Funktionen, Global Search, Inspector, Migration, Mobile Workspace und Basis-WebP-Optimierung bleiben ohne Pro-Lizenz nutzbar.
- **Pro:** AI-Metadaten, Glossar, Naming Rules, Ordnerrechte, Ordnerfarben/-duplizieren, gespeicherte Dynamic Views, AVIF/Privacy, Bulk-AI und erweiterte Automation.
- **BYOK:** jeder Kunde nutzt seinen eigenen OpenAI-API-Key. Der Key wird lokal verschlüsselt gespeichert oder kann über `MLAB_MEDIA_FOLDER_OPENAI_API_KEY` in `wp-config.php` gesetzt werden.
- **Sichere Bildoptimierung:** neue WordPress-Derivate können WebP/AVIF verwenden; hochgeladene Originale werden in 3.0 nie automatisch ersetzt oder gelöscht.
- **Migration:** HappyFiles und FileBird werden nicht-destruktiv in die Media-Folder-Taxonomie kopiert; Quelldaten bleiben bestehen.

### Free / Pro und Lizenzserver

MomentumLab Core entsperrt Pro über den License-Server-Entitlement-Slug `mlab-media-folder-pro`. Auf dem Lizenzserver wird dieser als Unlock ohne eigenes Plugin-Main-File geführt. Ohne diesen Unlock läuft Media Folder vollständig im Free-Modus; bestehende 2.x-Funktionen werden nicht abgeschaltet.

## Funktionen

**Ordnerbaum.** Neben der Mediathek (Raster- und Listenansicht) erscheint eine Ordner-Leiste mit verschachtelten Ordnern und der Anzahl Dateien pro Ordner. Dazu kommen die Ansichten «Alle Dateien» und «Ohne Ordner».

**Smart Views.** «Neue Uploads» zeigt die letzten sieben Tage. «Potenziell ungenutzt» erscheint erst nach einer vollständigen konservativen Hintergrundprüfung und ist ausdrücklich nur ein Prüfhinweis. Die bestehenden Ansichten für Ordner, Alternativtext, Verwendung, Duplikate und fehlende Dateien bleiben erhalten.

**Workflow «Zu erledigen».** Die Leiste führt durch sortieren, beschreiben und prüfen. «Nächster Schritt» zeigt die erste offene Aufgabe. «Doppelt» erkennt sowohl mehrere Einträge mit derselben physischen Datei als auch Kopien mit identischem SHA-256-Inhalt. «Datei fehlt» basiert nach dem ersten Hintergrundlauf auf einer vollständigen Prüfung der Mediathek. Die normale Mediathek blendet nur zusätzliche Einträge aus, die wirklich dieselbe physische Datei teilen; eigenständige Dateien mit identischem Inhalt bleiben sichtbar, bis sie bewusst bereinigt werden. Gemeinsame Dateien sind beim Löschen eines Attachment-Eintrags geschützt.

**Herkunft.** In den Anhang-Details zeigt das Feld «Herkunft», wer die Datei wann hochgeladen hat, in welche Seite oder welchen Beitrag sie hochgeladen wurde oder wo sie Beitragsbild ist, in welchem Ordner sie liegt und was noch offen ist.

**Verschieben ohne Ziehen.** Bei Mehrfachauswahl verschiebt «Verschieben nach …» in der Leiste unten die Auswahl in einen Ordner (auch auf dem Touch-Bildschirm). Nach jedem Verschieben bietet die Leiste «Rückgängig».

**Ordner verwalten.** Ordner lassen sich anlegen, umbenennen, verschieben (einen Ordner auf einen anderen ziehen) und löschen. Beim Löschen wird nie eine Datei gelöscht: Dateien und Unterordner wandern eine Ebene nach oben.

**Drag & Drop.** Dateien auf einen Ordner ziehen, um sie zu verschieben. Im Mehrfachauswahl-Modus wandern alle ausgewählten Dateien gemeinsam. Als tastaturfreundliche Alternative gibt es in den Anhang-Details ein Feld «Ordner».

**Mehrere Dateien auswählen.** In der Rasteransicht die Maustaste gedrückt halten und über die Dateien ziehen: alle Dateien im Rahmen werden ausgewählt. Bei eingeschalteter Mehrfachauswahl («Mehrere auswählen») darf das Ziehen auf einer Datei beginnen. Ist sie aus, verschiebt das Ziehen einer Datei diese in einen Ordner, und ein Ziehen zwischen den Dateien schaltet die Mehrfachauswahl ein. Am oberen und unteren Rand scrollt die Seite mit, mit Esc bricht man ab. Unten erscheint eine Leiste mit der Anzahl, «Alle auswählen» (alle Dateien des Ordners), «Endgültig löschen» bzw. «In den Papierkorb» für die ganze Auswahl und «Abbrechen». Die Auswahl lässt sich auch gemeinsam in einen Ordner ziehen.

**Upload in den geöffneten Ordner.** Neue Uploads landen im Ordner, der gerade offen ist, und erscheinen sofort (mit Fortschritt) im Raster.

**Ordner-Filter in Medien-Dialogen.** Jeder Medien-Dialog (Block-Editor, Galerie, Beitragsbild) bekommt einen Filter «Ordner».

**Anpassbare Leiste.** Die Ordner-Leiste lässt sich auf einen schmalen Streifen einklappen und am rechten Rand in der Breite ziehen (180 bis 480 px, auch mit den Pfeiltasten; Doppelklick setzt zurück). Breite und Zustand merkt sich der Browser.

**Medienstatus.** Zwei resumierbare Hintergrundprüfungen kontrollieren fehlende Dateien und identische Dateiinhalte. Der Status erscheint direkt in der Mediathek; berechtigte Benutzer können beide Prüfungen mit «Erneut prüfen» neu starten. Grosse Dateien werden in kleinen Batches verarbeitet, damit normale Admin-Aufrufe nicht blockiert werden.

**Automatische Updates.** Standalone-Installationen prüfen die GitHub-Releases dieses Repos. Wenn MomentumLab Core die Update-Verwaltung für `mlab-media-folder` übernimmt, deaktiviert sich der GitHub-Fallback automatisch; dadurch gibt es nie zwei konkurrierende Updatequellen.

Ordner ordnen nur die Verwaltung. Die URLs der Dateien auf der Website ändern sich nicht.

## Installation

1. Die neueste `mlab-media-folder-x.y.z.zip` herunterladen aus den [Releases](https://github.com/momentumlab-ch/mlab-media-folder/releases).
2. In WordPress unter **Plugins › Installieren › Plugin hochladen** die ZIP-Datei wählen und installieren.
3. Das Plugin aktivieren. Die Ordner-Leiste erscheint unter **Medien › Mediathek**.

Das ZIP aus den Releases ist genau das Plugin und lässt sich direkt hochladen. Auch **Code › Download ZIP** auf GitHub funktioniert, WordPress legt das Plugin dann aber im Ordner `mlab-media-folder-main` ab. Deshalb besser das ZIP aus den Releases nehmen.

## Updates

Ab Version 1.1.0 holt sich das Plugin Updates selbst aus diesem Repo:

- Der Plugin-Header enthält `Update URI: https://github.com/momentumlab-ch/mlab-media-folder`. Dadurch fragt WordPress nicht WordPress.org, sondern das Plugin selbst nach Updates.
- Das Plugin liest das neueste veröffentlichte Release über die GitHub-API (`/releases/latest`). Entwürfe und Pre-Releases werden ignoriert.
- Als Standalone-Update gilt nur die angehängte Datei `mlab-media-folder-<version>.zip`, nie das automatisch erzeugte Quellcode-ZIP von GitHub.
- Der Release-Workflow erzeugt zusätzlich `mlab-media-folder.zip` für MomentumLab License Server/Core. Produkt-Slug: `mlab-media-folder`, Repo: `momentumlab-ch/mlab-media-folder`, Main File: `mlab-media-folder/mlab-media-folder.php`.
- Sobald MomentumLab Core über den Filter `mlab_core_manages_plugin` meldet, dass es dieses Plugin verwaltet, registriert der Standalone-Updater keine eigene Updatequelle mehr.
- WordPress sucht von sich aus alle 12 Stunden nach Updates. Das Plugin merkt sich die Antwort von GitHub 1 Stunde lang.
- Sofort prüfen: **Dashboard › Aktualisierungen › Erneut prüfen**. Das fragt GitHub neu (WordPress lässt das höchstens einmal pro Minute zu).
- Neue Versionen erscheinen wie gewohnt unter **Dashboard › Aktualisierungen** und auf der Plugin-Seite, mit den Release-Notes unter «Details ansehen». Auto-Updates lassen sich pro Plugin einschalten.
- Es werden keine Daten der Website übertragen. Das Repo ist öffentlich, ein Token ist nicht nötig.

### Neue Version veröffentlichen

1. Version in `mlab-media-folder.php` erhöhen (Header `Version` und Konstante `MLAB_MEDIA_FOLDER_VERSION`), dazu `Stable tag` und Changelog in `readme.txt` sowie einen Abschnitt in `CHANGELOG.md`.
2. Änderungen in `main` mergen.

Mehr braucht es nicht. Die GitHub Action [`release.yml`](.github/workflows/release.yml) läuft bei jedem Push auf `main`. Gibt es für die Plugin-Version noch kein Release, baut sie das Plugin-ZIP, erstellt Tag und Release `v<version>` und nimmt die Notizen aus dem passenden Abschnitt in `CHANGELOG.md`. Ab dann bieten alle installierten Websites das Update an. Die Action lässt sich auch von Hand starten (**Actions › Release › Run workflow**).

## Aufbau des Repos

Das Repo ist das Plugin: alles im Hauptordner landet im ZIP, ausser was `.gitattributes` ausschliesst (`.github/`, `README.md`, `phpcs.xml.dist`).

```
mlab-media-folder.php      Plugin-Datei mit Header und Version
includes/                  PHP-Klassen (Taxonomie, Ordner, REST, Abfragen, Admin, Updater)
assets/                    JavaScript, CSS und Plugin-Icon (images/)
languages/                 Deutsche Übersetzungen (.l10n.php)
readme.txt                 Readme im WordPress-Format
CHANGELOG.md               Änderungen pro Version
uninstall.php              Aufräumen beim Löschen
.github/workflows/         Release-Action
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
| Filter `mlab_media_folder_audit_capability` | `manage_options` | Wer vollständige Media-Health-Audits manuell neu starten darf |
| Filter `mlab_media_folder_update_repository` | `momentumlab-ch/mlab-media-folder` | GitHub-Repo für die Standalone-Update-Prüfung |
| Filter `mlab_media_folder_settings_capability` | `manage_options` | Wer pluginweite Automation-/Pro-Einstellungen ändern darf |
| Filter `mlab_media_folder_pro_unlocked` | Lizenzstatus | Pro-Entitlement für Tests oder eigene Lizenzintegration überschreiben |
| Filter `mlab_media_folder_feature_available` | Feature-Matrix | Einzelne Free/Pro-Features gezielt überschreiben |
| Filter `mlab_media_folder_migration_sources` | bekannte Quellen | Weitere Taxonomie-basierte Folder-Migrationen registrieren |
| Filter `mlab_media_folder_check_missing_files` | `true` | Lokale Missing-File-Prüfung deaktivieren, z. B. bei Offload-Speicher |
| Filter `mlab_media_folder_check_content_duplicates` | `true` | SHA-256-Inhaltsprüfung deaktivieren |
| Filter `mlab_core_manages_plugin` | `false` | Von MomentumLab Core gesetzter Integrationsvertrag; bei `true` übernimmt Core/Lizenzserver die Updates |
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
| 2.1.1 | 2026-10-17 | Auswahl-Leiste verschwindet wieder mit der Mehrfachauswahl; «Alle auswählen» schaltet sie ein; kein «Dateien zum Hochladen ablegen» beim Verschieben |
| 2.1.0 | 2026-10-17 | Mehrfachauswahl durch Ziehen mit der Maus; Leiste mit «Alle auswählen» und Löschen der ganzen Auswahl; Umstiegs-Code vom alten Plugin entfernt |
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
