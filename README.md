# FF PDF Attachment

Ein leichtgewichtiges WordPress-Plugin, das automatisch ein übersichtliches PDF mit allen übermittelten Daten an die E-Mail-Benachrichtigungen von **Fluent Forms** anhängt. 

Ideal für Buchhaltungsbelege, Anmeldebestätigungen oder interne Archivierung – direkt als Anhang in der Inbox.

## 🚀 Highlights

- **Keine Abhängigkeiten:** Benötigt keine schweren Bibliotheken wie mPDF oder dompdf. Das Plugin nutzt einen minimalen, integrierten PDF-Generator (SimplePDF).
- **Plug & Play:** Einfach installieren und Formular-IDs festlegen.
- **Smartes Design:** Automatische Tabellengenerierung, Zeilenumbrüche und Unterstützung für Repeater-Felder (Wiederholungsfelder).
- **Branding:** Die Akzentfarbe des PDFs lässt sich bequem in den Einstellungen anpassen.
- **Datenschutzkonform:** PDF-Dateien werden temporär generiert, nach dem Versand versiegelt und automatisch nach 2 Stunden vom Server gelöscht.

## ✨ Features

- **Automatischer Anhang:** Hängt das PDF an alle aktiven Fluent Forms E-Mail-Benachrichtigungen an.
- **Repeater-Support:** Erkennt komplexe Felder wie "Wiederholungsfelder" und stellt diese strukturiert dar.
- **UTF-8 Support:** Korrekte Darstellung von Umlauten (ä, ö, ü) und Sonderzeichen (€).
- **Selektive Aktivierung:** Wähle gezielt aus, für welche Formular-IDs PDFs generiert werden sollen (oder lass das Feld leer für alle).
- **Cleanup-Funktion:** Automatisches Löschen alter PDF-Dateien aus dem Temp-Verzeichnis zur Vermeidung von Datenmüll.

## 🛠 Installation

1. Lade den Ordner `ff-pdf-attachment` in das Verzeichnis `/wp-content/plugins/` hoch.
2. Aktiviere das Plugin im WordPress-Dashboard unter **Plugins**.
3. Gehe zu **Einstellungen → FF PDF Attachment**, um die Formular-IDs und deine Wunschfarbe zu konfigurieren.

## ⚙️ Einstellungen

Unter `Einstellungen > FF PDF Attachment` findest du:
- **Formular-IDs:** Gib hier die IDs der Formulare ein (kommagetrennt), die einen PDF-Anhang erhalten sollen.
- **Akzentfarbe:** Wähle eine Hex-Farbe (z.B. `#2563eb`) für die Header-Linien und Titel im PDF.

## 📄 Lizenz

Dieses Projekt steht unter der [GPLv2](https://www.gnu.org/licenses/gpl-2.0.html) oder einer späteren Version.

---
*Entwickelt für maximale Performance und minimale Serverlast.*
