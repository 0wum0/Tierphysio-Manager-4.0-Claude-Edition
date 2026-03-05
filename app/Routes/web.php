<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\PatientController;
use App\Controllers\OwnerController;
use App\Controllers\InvoiceController;
use App\Controllers\SettingsController;
use App\Controllers\ProfileController;

/** @var \App\Core\Router $router */

$router->get('/', [DashboardController::class, 'index'], ['auth']);
$router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
$router->get('/dashboard/chart-data', [DashboardController::class, 'chartData'], ['auth']);
$router->post('/api/dashboard/layout', [DashboardController::class, 'saveLayout'], ['auth']);
$router->get('/api/dashboard/layout', [DashboardController::class, 'loadLayout'], ['auth']);

$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest']);
$router->post('/logout', [AuthController::class, 'logout'], ['auth']);

$router->get('/profil', [ProfileController::class, 'show'], ['auth']);
$router->post('/profil', [ProfileController::class, 'update'], ['auth']);
$router->post('/profil/password', [ProfileController::class, 'updatePassword'], ['auth']);

$router->get('/patienten', [PatientController::class, 'index'], ['auth']);
$router->get('/patienten/neu', [PatientController::class, 'wizard'], ['auth']);
$router->post('/patienten/wizard', [PatientController::class, 'wizardStore'], ['auth']);
$router->get('/api/tierhalter/suche', [PatientController::class, 'ownerSearch'], ['auth']);
$router->post('/patienten', [PatientController::class, 'store'], ['auth']);
$router->get('/patienten/{id}', [PatientController::class, 'show'], ['auth']);
$router->get('/patienten/{id}/json', [PatientController::class, 'showJson'], ['auth']);
$router->post('/patienten/{id}', [PatientController::class, 'update'], ['auth']);
$router->post('/patienten/{id}/loeschen', [PatientController::class, 'delete'], ['auth']);
$router->post('/patienten/{id}/foto', [PatientController::class, 'uploadPhoto'], ['auth']);
$router->post('/patienten/{id}/timeline', [PatientController::class, 'addTimelineEntry'], ['auth']);
$router->post('/patienten/{id}/timeline-json', [PatientController::class, 'addTimelineEntryJson'], ['auth']);
$router->post('/patienten/{id}/timeline/{entryId}/loeschen', [PatientController::class, 'deleteTimelineEntry'], ['auth']);
$router->post('/patienten/{id}/timeline/{entryId}/update-json', [PatientController::class, 'updateTimelineEntryJson'], ['auth']);
$router->post('/patienten/{id}/timeline/{entryId}/delete-json', [PatientController::class, 'deleteTimelineEntryJson'], ['auth']);
$router->get('/patienten/{id}/pdf', [PatientController::class, 'downloadPatientPdf'], ['auth']);
$router->get('/patienten/{id}/dokumente/{file}', [PatientController::class, 'downloadDocument'], ['auth']);
$router->get('/patienten/{id}/foto/{file}', [PatientController::class, 'servePhoto'], ['auth']);
$router->post('/patienten/{id}/dokumente', [PatientController::class, 'uploadDocument'], ['auth']);

$router->get('/tierhalter', [OwnerController::class, 'index'], ['auth']);
$router->post('/tierhalter', [OwnerController::class, 'store'], ['auth']);
$router->get('/tierhalter/{id}', [OwnerController::class, 'show'], ['auth']);
$router->post('/tierhalter/{id}', [OwnerController::class, 'update'], ['auth']);
$router->post('/tierhalter/{id}/loeschen', [OwnerController::class, 'delete'], ['auth']);

$router->get('/rechnungen', [InvoiceController::class, 'index'], ['auth']);
$router->get('/rechnungen/erstellen', [InvoiceController::class, 'create'], ['auth']);
$router->post('/rechnungen', [InvoiceController::class, 'store'], ['auth']);
$router->get('/rechnungen/{id}', [InvoiceController::class, 'show'], ['auth']);
$router->get('/rechnungen/{id}/bearbeiten', [InvoiceController::class, 'edit'], ['auth']);
$router->post('/rechnungen/{id}', [InvoiceController::class, 'update'], ['auth']);
$router->post('/rechnungen/{id}/loeschen', [InvoiceController::class, 'delete'], ['auth']);
$router->post('/rechnungen/{id}/status', [InvoiceController::class, 'updateStatus'], ['auth']);
$router->get('/rechnungen/{id}/pdf', [InvoiceController::class, 'downloadPdf'], ['auth']);
$router->get('/rechnungen/{id}/vorschau', [InvoiceController::class, 'preview'], ['auth']);
$router->get('/rechnungen/{id}/positionen-json', [InvoiceController::class, 'positionsJson'], ['auth']);
$router->post('/rechnungen/{id}/senden', [InvoiceController::class, 'sendEmail'], ['auth']);

$router->get('/einstellungen', [SettingsController::class, 'index'], ['admin']);
$router->post('/einstellungen', [SettingsController::class, 'update'], ['admin']);
$router->post('/einstellungen/logo', [SettingsController::class, 'uploadLogo'], ['admin']);
$router->post('/einstellungen/pdf-rechnung-bild', [SettingsController::class, 'uploadPdfRechnungBild'], ['admin']);
$router->post('/einstellungen/pdf-vielen-dank-bild', [SettingsController::class, 'uploadPdfVielenDankBild'], ['admin']);
$router->get('/einstellungen/plugins', [SettingsController::class, 'plugins'], ['admin']);
$router->post('/einstellungen/plugins/{name}/aktivieren', [SettingsController::class, 'enablePlugin'], ['admin']);
$router->post('/einstellungen/plugins/{name}/deaktivieren', [SettingsController::class, 'disablePlugin'], ['admin']);
$router->get('/einstellungen/updater', [SettingsController::class, 'updater'], ['admin']);
$router->post('/einstellungen/updater/run', [SettingsController::class, 'runMigrations'], ['admin']);
$router->post('/einstellungen/migrationen', [SettingsController::class, 'runMigrations'], ['admin']);
$router->get('/einstellungen/benutzer', [SettingsController::class, 'users'], ['admin']);
$router->post('/einstellungen/benutzer', [SettingsController::class, 'createUser'], ['admin']);
$router->post('/einstellungen/benutzer/{id}', [SettingsController::class, 'updateUser'], ['admin']);
$router->post('/einstellungen/benutzer/{id}/loeschen', [SettingsController::class, 'deleteUser'], ['admin']);

$router->post('/einstellungen/behandlungsarten', [SettingsController::class, 'createTreatmentType'], ['admin']);
$router->post('/einstellungen/behandlungsarten/{id}', [SettingsController::class, 'updateTreatmentType'], ['admin']);
$router->post('/einstellungen/behandlungsarten/{id}/loeschen', [SettingsController::class, 'deleteTreatmentType'], ['admin']);
$router->get('/api/behandlungsarten', [SettingsController::class, 'treatmentTypesJson'], ['auth']);
