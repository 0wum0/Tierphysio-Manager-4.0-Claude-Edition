-- Migration: 003_plugin_seeds
-- Seeds all available Tierphysio Manager plugins into the marketplace

-- Seed all known plugins
INSERT IGNORE INTO `marketplace_plugins`
  (`slug`, `name`, `description`, `long_desc`, `category`, `icon`, `price`, `price_type`, `is_active`, `is_featured`, `version`, `sort_order`)
VALUES
(
  'calendar',
  'Terminkalender',
  'Vollständiger Terminkalender mit Monats-, Wochen- und Tagesansicht, Wiederkehrenden Terminen, iCal-Export/Import und Warteliste.',
  'Der Terminkalender bietet eine vollständige Kalenderansicht direkt in der Praxissoftware. Funktionen:\n\n- Monats-, Wochen- und Tagesansicht\n- Wiederkehrende Termine\n- iCal-Export und -Import\n- Warteliste für Patienten\n- Terminstatistiken\n- Farbkodierung nach Tierart oder Mitarbeiter',
  'Terminverwaltung',
  'bi-calendar3',
  0.00,
  'free',
  1, 1, '1.0.0', 1
),
(
  'patient-intake',
  'Patientenanmeldung',
  'Öffentliches Anmeldeformular für neue Patienten mit Multi-Step Wizard, Foto-Upload und E-Mail-Benachrichtigung.',
  'Das Patientenanmeldungs-Plugin stellt ein öffentlich zugängliches Formular bereit, über das Tierbesitzer ihre Tiere anmelden können. Funktionen:\n\n- Multi-Step Anmeldeassistent\n- Foto-Upload für das Tier\n- Automatische E-Mail-Benachrichtigung\n- Admin-Eingangspostfach für neue Anmeldungen\n- Direktes Anlegen als Patient nach Prüfung',
  'Patienten',
  'bi-clipboard2-pulse',
  0.00,
  'free',
  1, 1, '1.0.0', 2
),
(
  'patient-invite',
  'Einladungslinks',
  'Sendet Einladungslinks per E-Mail oder WhatsApp. Besitzer füllen das Anmeldeformular selbst aus und werden automatisch angelegt.',
  'Mit dem Einladungslinks-Plugin können Sie Tierbesitzer direkt einladen, ihre Daten selbst einzutragen. Funktionen:\n\n- Einladungslink per E-Mail versenden\n- Link per WhatsApp teilen\n- Besitzer trägt Daten selbst ein\n- Automatische Anlage als aktiver Besitzer mit Patient\n- Keine manuelle Nachbearbeitung nötig',
  'Kommunikation',
  'bi-envelope-paper',
  0.00,
  'free',
  1, 0, '1.0.0', 3
),
(
  'mailbox',
  'Mailbox',
  'E-Mail App: Posteingang lesen (IMAP/POP3) und E-Mails schreiben (SMTP) direkt in der Praxissoftware.',
  'Die Mailbox integriert eine vollständige E-Mail-Verwaltung in die Praxissoftware. Funktionen:\n\n- Posteingang über IMAP oder POP3 lesen\n- E-Mails direkt aus der Software versenden\n- Konfiguration über Einstellungen\n- Direkter Bezug zu Patienten oder Besitzern möglich',
  'Kommunikation',
  'bi-envelope',
  9.00,
  'monthly',
  1, 0, '1.0.0', 4
),
(
  'tax-export-pro',
  'TaxExportPro',
  'Steuerrelevante Export-, Archivierungs- und Finanzfunktionen: ZIP-Archiv, CSV-Export, PDF-Steuerbericht, GoBD-Buchführungshilfe.',
  'TaxExportPro erweitert die Rechnungsverwaltung um steuerrelevante Exportfunktionen. Funktionen:\n\n- ZIP-Archiv aller Rechnungen\n- CSV-Export für Steuerberater\n- PDF-Steuerbericht\n- GoBD-orientierte Buchführungshilfe\n- Jahresabschluss-Übersicht\n- Kompatibel mit DATEV-Format',
  'Abrechnung',
  'bi-file-earmark-spreadsheet',
  19.00,
  'monthly',
  1, 1, '1.0.0', 5
),
(
  'theme-manager',
  'Theme Manager',
  'Vollständiges Theme-System: Themes als ZIP hochladen, aktivieren und verwalten. Ändert das gesamte Design der Anwendung.',
  'Der Theme Manager erlaubt es, das komplette Erscheinungsbild der Praxissoftware anzupassen. Funktionen:\n\n- Themes als ZIP-Datei hochladen\n- Themes aktivieren und deaktivieren\n- Vorschau der verfügbaren Themes\n- Eigene CSS-Anpassungen\n- Mehrere Designs für verschiedene Jahreszeiten oder Anlässe',
  'Allgemein',
  'bi-palette',
  0.00,
  'free',
  1, 0, '1.0.0', 6
),
(
  'license-guard',
  'Lizenzprüfung',
  'Prüft regelmäßig die SaaS-Lizenz und schaltet Funktionen je nach Abo frei. Offline-Betrieb bis 30 Tage.',
  'Die Lizenzprüfung ist ein Kernsystem-Plugin, das die Verbindung zwischen der Praxissoftware und der SaaS-Plattform sicherstellt. Funktionen:\n\n- Automatische Lizenzprüfung\n- Funktionen gemäß Abo-Plan freischalten\n- Offline-Betrieb bis zu 30 Tagen\n- Sicherheits-Token-Verwaltung',
  'System',
  'bi-shield-check',
  0.00,
  'free',
  1, 0, '1.0.0', 7
);
