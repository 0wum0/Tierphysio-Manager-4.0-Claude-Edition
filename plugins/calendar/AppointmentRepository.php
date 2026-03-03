<?php

declare(strict_types=1);

namespace Plugins\Calendar;

use App\Core\Database;
use PDO;

class AppointmentRepository
{
    public function __construct(private readonly Database $db) {}

    public function findById(int $id): ?array
    {
        $stmt = $this->db->query(
            'SELECT a.*, p.name AS patient_name, o.first_name, o.last_name,
                    tt.name AS treatment_type_name, tt.color AS treatment_type_color,
                    u.name AS user_name
             FROM appointments a
             LEFT JOIN patients p ON p.id = a.patient_id
             LEFT JOIN owners o ON o.id = a.owner_id
             LEFT JOIN treatment_types tt ON tt.id = a.treatment_type_id
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.id = ?',
            [$id]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByRange(string $start, string $end): array
    {
        $stmt = $this->db->query(
            'SELECT a.*, p.name AS patient_name, o.first_name, o.last_name,
                    tt.name AS treatment_type_name, tt.color AS treatment_type_color,
                    u.name AS user_name
             FROM appointments a
             LEFT JOIN patients p ON p.id = a.patient_id
             LEFT JOIN owners o ON o.id = a.owner_id
             LEFT JOIN treatment_types tt ON tt.id = a.treatment_type_id
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.start_at < ? AND a.end_at > ?
             ORDER BY a.start_at ASC',
            [$end, $start]
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findUpcoming(int $limit = 5): array
    {
        $stmt = $this->db->query(
            'SELECT a.*, p.name AS patient_name, o.first_name, o.last_name,
                    tt.name AS treatment_type_name, tt.color AS treatment_type_color
             FROM appointments a
             LEFT JOIN patients p ON p.id = a.patient_id
             LEFT JOIN owners o ON o.id = a.owner_id
             LEFT JOIN treatment_types tt ON tt.id = a.treatment_type_id
             WHERE a.start_at >= NOW() AND a.status NOT IN (\'cancelled\',\'noshow\')
             ORDER BY a.start_at ASC
             LIMIT ?',
            [$limit]
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findPendingReminders(): array
    {
        $stmt = $this->db->query(
            'SELECT a.*, o.email AS owner_email, o.first_name, o.last_name,
                    p.name AS patient_name
             FROM appointments a
             LEFT JOIN owners o ON o.id = a.owner_id
             LEFT JOIN patients p ON p.id = a.patient_id
             WHERE a.reminder_sent = 0
               AND a.status IN (\'scheduled\',\'confirmed\')
               AND a.start_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL a.reminder_minutes MINUTE)
               AND o.email IS NOT NULL AND o.email != \'\'',
            []
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $this->db->query(
            'INSERT INTO appointments
             (title, description, start_at, end_at, all_day, status, color,
              patient_id, owner_id, treatment_type_id, user_id,
              recurrence_rule, recurrence_parent, notes, reminder_minutes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $data['title'],
                $data['description'] ?? null,
                $data['start_at'],
                $data['end_at'],
                $data['all_day'] ? 1 : 0,
                $data['status'] ?? 'scheduled',
                $data['color'] ?? null,
                $data['patient_id'] ?? null,
                $data['owner_id'] ?? null,
                $data['treatment_type_id'] ?? null,
                $data['user_id'] ?? null,
                $data['recurrence_rule'] ?? null,
                $data['recurrence_parent'] ?? null,
                $data['notes'] ?? null,
                $data['reminder_minutes'] ?? 60,
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $this->db->query(
            'UPDATE appointments SET
             title=?, description=?, start_at=?, end_at=?, all_day=?, status=?, color=?,
             patient_id=?, owner_id=?, treatment_type_id=?, user_id=?,
             recurrence_rule=?, notes=?, reminder_minutes=?
             WHERE id=?',
            [
                $data['title'],
                $data['description'] ?? null,
                $data['start_at'],
                $data['end_at'],
                $data['all_day'] ? 1 : 0,
                $data['status'] ?? 'scheduled',
                $data['color'] ?? null,
                $data['patient_id'] ?? null,
                $data['owner_id'] ?? null,
                $data['treatment_type_id'] ?? null,
                $data['user_id'] ?? null,
                $data['recurrence_rule'] ?? null,
                $data['notes'] ?? null,
                $data['reminder_minutes'] ?? 60,
                $id,
            ]
        );
    }

    public function markReminderSent(int $id): void
    {
        $this->db->query('UPDATE appointments SET reminder_sent=1 WHERE id=?', [$id]);
    }

    public function linkInvoice(int $id, int $invoiceId): void
    {
        $this->db->query('UPDATE appointments SET invoice_id=? WHERE id=?', [$invoiceId, $id]);
    }

    public function delete(int $id): void
    {
        $this->db->query('DELETE FROM appointments WHERE id=? OR recurrence_parent=?', [$id, $id]);
    }

    public function deleteSingle(int $id): void
    {
        $this->db->query('DELETE FROM appointments WHERE id=?', [$id]);
    }

    public function getStats(): array
    {
        $stmt = $this->db->query(
            'SELECT
               COUNT(*) AS total,
               SUM(CASE WHEN status="scheduled" OR status="confirmed" THEN 1 ELSE 0 END) AS upcoming,
               SUM(CASE WHEN status="cancelled" THEN 1 ELSE 0 END) AS cancelled,
               SUM(CASE WHEN status="completed" THEN 1 ELSE 0 END) AS completed,
               SUM(CASE WHEN DATE(start_at)=CURDATE() THEN 1 ELSE 0 END) AS today
             FROM appointments',
            []
        );
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /* ── Waitlist ── */
    public function getWaitlist(): array
    {
        $stmt = $this->db->query(
            'SELECT w.*, p.name AS patient_name, o.first_name, o.last_name,
                    tt.name AS treatment_type_name
             FROM appointment_waitlist w
             LEFT JOIN patients p ON p.id = w.patient_id
             LEFT JOIN owners o ON o.id = w.owner_id
             LEFT JOIN treatment_types tt ON tt.id = w.treatment_type_id
             WHERE w.status="waiting"
             ORDER BY w.created_at ASC',
            []
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addToWaitlist(array $data): int
    {
        $this->db->query(
            'INSERT INTO appointment_waitlist (patient_id, owner_id, treatment_type_id, preferred_date, notes)
             VALUES (?,?,?,?,?)',
            [
                $data['patient_id'] ?? null,
                $data['owner_id'] ?? null,
                $data['treatment_type_id'] ?? null,
                $data['preferred_date'] ?? null,
                $data['notes'] ?? null,
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    public function updateWaitlistStatus(int $id, string $status): void
    {
        $this->db->query('UPDATE appointment_waitlist SET status=? WHERE id=?', [$status, $id]);
    }

    public function deleteWaitlist(int $id): void
    {
        $this->db->query('DELETE FROM appointment_waitlist WHERE id=?', [$id]);
    }
}
