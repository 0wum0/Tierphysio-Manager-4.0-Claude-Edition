<?php

declare(strict_types=1);

namespace Plugins\Calendar;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Core\Database;
use App\Repositories\PatientRepository;
use App\Repositories\OwnerRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TreatmentTypeRepository;
use App\Repositories\UserRepository;
use App\Services\MailService;

class CalendarController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        private readonly AppointmentRepository $appointmentRepository,
        private readonly PatientRepository $patientRepository,
        private readonly OwnerRepository $ownerRepository,
        private readonly UserRepository $userRepository,
        private readonly MailService $mailService,
        private readonly SettingsRepository $settingsRepository,
        private readonly TreatmentTypeRepository $treatmentTypeRepository
    ) {
        parent::__construct($view, $session, $config, $translator);
    }

    /* ── Main calendar view ── */
    public function index(array $params = []): void
    {
        $treatmentTypes = [];
        try { $treatmentTypes = $this->treatmentTypeRepository->findActive(); } catch (\Throwable) {}

        $patients = $this->patientRepository->findAll();
        $owners   = $this->ownerRepository->findAll();
        $users    = $this->userRepository->findAll();
        $stats    = $this->appointmentRepository->getStats();

        $this->renderPlugin('calendar/index.twig', [
            'page_title'      => 'Terminkalender',
            'patients'        => $patients,
            'owners'          => $owners,
            'users'           => $users,
            'treatment_types' => $treatmentTypes,
            'stats'           => $stats,
        ]);
    }

    /* ── Waitlist view ── */
    public function waitlist(array $params = []): void
    {
        $waitlist = $this->appointmentRepository->getWaitlist();
        $patients = $this->patientRepository->findAll();
        $owners   = $this->ownerRepository->findAll();

        $treatmentTypes = [];
        try { $treatmentTypes = $this->treatmentTypeRepository->findActive(); } catch (\Throwable) {}

        $this->renderPlugin('calendar/waitlist.twig', [
            'page_title'      => 'Warteliste',
            'waitlist'        => $waitlist,
            'patients'        => $patients,
            'owners'          => $owners,
            'treatment_types' => $treatmentTypes,
        ]);
    }

    /* ── Stats view ── */
    public function stats(array $params = []): void
    {
        $stats = $this->appointmentRepository->getStats();
        $this->renderPlugin('calendar/stats.twig', [
            'page_title' => 'Kalender Statistiken',
            'stats'      => $stats,
        ]);
    }

    /* ── API: events for calendar range ── */
    public function apiEvents(array $params = []): void
    {
        $start = $this->get('start', date('Y-m-01'));
        $end   = $this->get('end',   date('Y-m-t'));
        $appointments = $this->appointmentRepository->findByRange($start, $end);

        $events = array_map(fn($a) => $this->toCalendarEvent($a), $appointments);

        header('Content-Type: application/json');
        echo json_encode($events);
        exit;
    }

    /* ── API: single appointment ── */
    public function apiShow(array $params = []): void
    {
        $a = $this->appointmentRepository->findById((int)$params['id']);
        if (!$a) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
        header('Content-Type: application/json');
        echo json_encode($a);
        exit;
    }

    /* ── API: create ── */
    public function apiStore(array $params = []): void
    {
        $this->validateCsrf();
        $data = $this->parseAppointmentData();

        if (empty($data['title']) || empty($data['start_at']) || empty($data['end_at'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Titel, Start und Ende sind Pflichtfelder.']);
            exit;
        }

        /* Handle recurrence expansion */
        $ids = [];
        if (!empty($data['recurrence_rule'])) {
            $ids = $this->expandRecurrence($data);
        } else {
            $ids[] = $this->appointmentRepository->create($data);
        }

        header('Content-Type: application/json');
        $event = $this->appointmentRepository->findById($ids[0]);
        echo json_encode(['success' => true, 'id' => $ids[0], 'event' => $this->toCalendarEvent($event)]);
        exit;
    }

    /* ── API: update ── */
    public function apiUpdate(array $params = []): void
    {
        $this->validateCsrf();
        $id = (int)$params['id'];
        $a  = $this->appointmentRepository->findById($id);
        if (!$a) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

        $data = $this->parseAppointmentData();
        $this->appointmentRepository->update($id, $data);

        $updated = $this->appointmentRepository->findById($id);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'event' => $this->toCalendarEvent($updated)]);
        exit;
    }

    /* ── API: quick drag&drop reschedule ── */
    public function apiReschedule(array $params = []): void
    {
        $this->validateCsrf();
        $id = (int)$params['id'];
        $a  = $this->appointmentRepository->findById($id);
        if (!$a) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $startAt  = $body['start_at'] ?? null;
        $endAt    = $body['end_at']   ?? null;
        if (!$startAt || !$endAt) { http_response_code(422); echo json_encode(['error' => 'Missing dates']); exit; }

        $this->appointmentRepository->update($id, array_merge($a, [
            'start_at' => $startAt,
            'end_at'   => $endAt,
        ]));

        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    /* ── API: delete ── */
    public function apiDelete(array $params = []): void
    {
        $this->validateCsrf();
        $id   = (int)$params['id'];
        $mode = $this->post('mode', 'single'); // single | all

        if ($mode === 'all') {
            $this->appointmentRepository->delete($id);
        } else {
            $this->appointmentRepository->deleteSingle($id);
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    /* ── API: iCal export ── */
    public function icalExport(array $params = []): void
    {
        $start = $this->get('start', date('Y-m-01'));
        $end   = $this->get('end',   date('Y-m-t', strtotime('+3 months')));
        $appointments = $this->appointmentRepository->findByRange($start, $end);

        $ical = $this->buildIcal($appointments);

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="termine.ics"');
        echo $ical;
        exit;
    }

    /* ── API: iCal import ── */
    public function icalImport(array $params = []): void
    {
        $this->validateCsrf();
        $file = $_FILES['ical_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->session->flash('error', 'Datei-Upload fehlgeschlagen.');
            $this->redirect('/kalender');
            return;
        }

        $content = file_get_contents($file['tmp_name']);
        $count   = $this->importIcal($content);

        $this->session->flash('success', "{$count} Termine importiert.");
        $this->redirect('/kalender');
    }

    /* ── Waitlist: add ── */
    public function waitlistStore(array $params = []): void
    {
        $this->validateCsrf();
        $this->appointmentRepository->addToWaitlist([
            'patient_id'        => (int)$this->post('patient_id', 0) ?: null,
            'owner_id'          => (int)$this->post('owner_id', 0) ?: null,
            'treatment_type_id' => (int)$this->post('treatment_type_id', 0) ?: null,
            'preferred_date'    => $this->post('preferred_date') ?: null,
            'notes'             => $this->post('notes', ''),
        ]);
        $this->session->flash('success', 'Zur Warteliste hinzugefügt.');
        $this->redirect('/kalender/warteliste');
    }

    /* ── Waitlist: delete ── */
    public function waitlistDelete(array $params = []): void
    {
        $this->validateCsrf();
        $this->appointmentRepository->deleteWaitlist((int)$params['id']);
        $this->session->flash('success', 'Aus Warteliste entfernt.');
        $this->redirect('/kalender/warteliste');
    }

    /* ── Waitlist: schedule (convert to appointment) ── */
    public function waitlistSchedule(array $params = []): void
    {
        $this->validateCsrf();
        $entry = $this->appointmentRepository->getWaitlist();
        $entry = array_filter($entry, fn($e) => (int)$e['id'] === (int)$params['id']);
        $entry = reset($entry);

        if ($entry) {
            $start = $this->post('start_at');
            $end   = $this->post('end_at');
            if ($start && $end) {
                $id = $this->appointmentRepository->create([
                    'title'             => $entry['patient_name'] ?? ($entry['first_name'] . ' ' . $entry['last_name']),
                    'start_at'          => $start,
                    'end_at'            => $end,
                    'patient_id'        => $entry['patient_id'],
                    'owner_id'          => $entry['owner_id'],
                    'treatment_type_id' => $entry['treatment_type_id'],
                    'status'            => 'scheduled',
                ]);
                $this->appointmentRepository->updateWaitlistStatus((int)$params['id'], 'scheduled');
                $this->session->flash('success', 'Termin erstellt.');
                $this->redirect("/kalender");
                return;
            }
        }

        $this->session->flash('error', 'Fehler beim Erstellen des Termins.');
        $this->redirect('/kalender/warteliste');
    }

    /* ── Cron: send pending reminders ── */
    public function cronReminders(array $params = []): void
    {
        $secret = $this->settingsRepository->get('calendar_cron_secret', '');
        $token  = $_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');

        if (empty($secret) || !hash_equals($secret, $token)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $reminderService = new ReminderService(
            $this->appointmentRepository,
            $this->mailService,
            $this->settingsRepository
        );

        $result = $reminderService->processPending();

        header('Content-Type: application/json');
        echo json_encode(array_merge($result, ['ok' => true, 'time' => date('c')]));
        exit;
    }

    /* ── Create invoice from appointment ── */
    public function createInvoice(array $params = []): void
    {
        $a = $this->appointmentRepository->findById((int)$params['id']);
        if (!$a) { $this->abort(404); }

        $query = http_build_query(array_filter([
            'patient_id' => $a['patient_id'],
            'owner_id'   => $a['owner_id'],
        ]));
        $this->redirect('/rechnungen/erstellen?' . $query);
    }

    /* ── Helpers ── */
    private function toCalendarEvent(array $a): array
    {
        $color = $a['color'] ?? $a['treatment_type_color'] ?? '#4f7cff';
        $ownerName = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
        return [
            'id'          => $a['id'],
            'title'       => $a['title'],
            'start'       => $a['start_at'],
            'end'         => $a['end_at'],
            'allDay'      => (bool)$a['all_day'],
            'color'       => $color,
            'borderColor' => $color,
            'textColor'   => '#ffffff',
            'extendedProps' => [
                'status'               => $a['status'],
                'description'          => $a['description'],
                'notes'                => $a['notes'],
                'patient_id'           => $a['patient_id'],
                'patient_name'         => $a['patient_name'] ?? null,
                'owner_id'             => $a['owner_id'],
                'owner_name'           => $ownerName ?: null,
                'treatment_type_id'    => $a['treatment_type_id'],
                'treatment_type_name'  => $a['treatment_type_name'] ?? null,
                'user_name'            => $a['user_name'] ?? null,
                'recurrence_rule'      => $a['recurrence_rule'],
                'recurrence_parent'    => $a['recurrence_parent'],
                'reminder_minutes'     => $a['reminder_minutes'],
                'invoice_id'           => $a['invoice_id'],
            ],
        ];
    }

    private function parseAppointmentData(): array
    {
        $user = $this->session->get('user');
        return [
            'title'             => $this->sanitize($this->post('title', '')),
            'description'       => $this->post('description', ''),
            'start_at'          => $this->post('start_at', ''),
            'end_at'            => $this->post('end_at', ''),
            'all_day'           => (bool)$this->post('all_day', 0),
            'status'            => $this->sanitize($this->post('status', 'scheduled')),
            'color'             => $this->post('color') ?: null,
            'patient_id'        => (int)$this->post('patient_id', 0) ?: null,
            'owner_id'          => (int)$this->post('owner_id', 0) ?: null,
            'treatment_type_id' => (int)$this->post('treatment_type_id', 0) ?: null,
            'user_id'           => $user ? (int)$user['id'] : null,
            'recurrence_rule'   => $this->post('recurrence_rule') ?: null,
            'notes'             => $this->post('notes', ''),
            'reminder_minutes'  => (int)$this->post('reminder_minutes', 60),
        ];
    }

    private function expandRecurrence(array $data): array
    {
        $rule   = $data['recurrence_rule'];
        $start  = new \DateTime($data['start_at']);
        $end    = new \DateTime($data['end_at']);
        $diff   = $start->diff($end);
        $ids    = [];
        $count  = 0;
        $maxOccurrences = 52;

        preg_match('/FREQ=(\w+)/', $rule, $freqMatch);
        preg_match('/COUNT=(\d+)/', $rule, $countMatch);
        preg_match('/UNTIL=([^;]+)/', $rule, $untilMatch);

        $freq     = $freqMatch[1] ?? 'WEEKLY';
        $maxCount = isset($countMatch[1]) ? (int)$countMatch[1] : $maxOccurrences;
        $until    = isset($untilMatch[1]) ? new \DateTime($untilMatch[1]) : null;

        $parentId = $this->appointmentRepository->create(array_merge($data, ['recurrence_parent' => null]));
        $ids[]    = $parentId;
        $count    = 1;

        $current = clone $start;
        while ($count < $maxCount) {
            match ($freq) {
                'DAILY'   => $current->modify('+1 day'),
                'WEEKLY'  => $current->modify('+1 week'),
                'MONTHLY' => $current->modify('+1 month'),
                default   => $current->modify('+1 week'),
            };

            if ($until && $current > $until) break;

            $childEnd = clone $current;
            $childEnd->add($diff);

            $childData = array_merge($data, [
                'start_at'          => $current->format('Y-m-d H:i:s'),
                'end_at'            => $childEnd->format('Y-m-d H:i:s'),
                'recurrence_parent' => $parentId,
                'recurrence_rule'   => null,
            ]);
            $ids[] = $this->appointmentRepository->create($childData);
            $count++;
        }

        return $ids;
    }

    private function buildIcal(array $appointments): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Tierphysio Manager//Calendar//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($appointments as $a) {
            $uid   = 'apt-' . $a['id'] . '@tierphysio';
            $dtstart = (new \DateTime($a['start_at']))->format('Ymd\THis');
            $dtend   = (new \DateTime($a['end_at']))->format('Ymd\THis');
            $summary = $this->icalEscape($a['title']);
            $desc    = $this->icalEscape($a['description'] ?? '');

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . $dtstart;
            $lines[] = 'DTEND:' . $dtend;
            $lines[] = 'SUMMARY:' . $summary;
            if ($desc) $lines[] = 'DESCRIPTION:' . $desc;
            if (!empty($a['recurrence_rule'])) $lines[] = 'RRULE:' . $a['recurrence_rule'];
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    private function icalEscape(string $str): string
    {
        return str_replace(["\n", "\r", ',', ';'], ['\\n', '', '\\,', '\\;'], $str);
    }

    private function importIcal(string $content): int
    {
        $count  = 0;
        $events = [];
        $current = null;

        foreach (explode("\n", str_replace("\r\n", "\n", $content)) as $line) {
            $line = rtrim($line);
            if ($line === 'BEGIN:VEVENT') {
                $current = [];
            } elseif ($line === 'END:VEVENT' && $current !== null) {
                $events[] = $current;
                $current  = null;
            } elseif ($current !== null && str_contains($line, ':')) {
                [$key, $val] = explode(':', $line, 2);
                $current[strtoupper($key)] = $val;
            }
        }

        foreach ($events as $ev) {
            $title = $ev['SUMMARY'] ?? 'Importierter Termin';
            $startRaw = $ev['DTSTART'] ?? null;
            $endRaw   = $ev['DTEND']   ?? null;
            if (!$startRaw || !$endRaw) continue;

            try {
                $start = (new \DateTime($startRaw))->format('Y-m-d H:i:s');
                $end   = (new \DateTime($endRaw))->format('Y-m-d H:i:s');
            } catch (\Throwable) { continue; }

            $this->appointmentRepository->create([
                'title'           => str_replace(['\\n', '\\,', '\\;'], ["\n", ',', ';'], $title),
                'description'     => str_replace(['\\n', '\\,', '\\;'], ["\n", ',', ';'], $ev['DESCRIPTION'] ?? ''),
                'start_at'        => $start,
                'end_at'          => $end,
                'recurrence_rule' => $ev['RRULE'] ?? null,
            ]);
            $count++;
        }

        return $count;
    }

    private function renderPlugin(string $template, array $data = []): void
    {
        $this->render('@calendar/' . $template, $data);
    }
}
