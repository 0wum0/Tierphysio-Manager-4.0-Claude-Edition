-- Migration 010: Add Terminerinnerung + Einladung email templates
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
('email_reminder_subject', 'Terminerinnerung: {{appointment_title}} am {{appointment_date}}'),
('email_reminder_body',    'Hallo {{owner_name}},\n\nwir möchten Sie an Ihren bevorstehenden Termin erinnern:\n\n📅 {{appointment_title}}\nDatum: {{appointment_date}}\nUhrzeit: {{appointment_time}}\n{{appointment_patient}}\n\nFalls Sie den Termin absagen oder verschieben möchten, kontaktieren Sie uns bitte rechtzeitig.\n\nMit freundlichen Grüßen\n{{company_name}}'),
('email_invite_subject',   'Ihre Einladung zur Anmeldung — {{company_name}}'),
('email_invite_body',      'Sie wurden eingeladen!\n\n{{from_name}} lädt Sie ein, Ihr Tier und sich als Besitzer direkt in unserem System zu registrieren.\n\n{{note}}\n\nJetzt registrieren:\n{{invite_url}}\n\nDieser Link ist 7 Tage gültig.\n\nMit freundlichen Grüßen\n{{company_name}}');
