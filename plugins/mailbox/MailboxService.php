<?php

declare(strict_types=1);

namespace Plugins\Mailbox;

use App\Repositories\SettingsRepository;

class MailboxService
{
    private array $cfg;

    public function __construct(private readonly SettingsRepository $settings) {}

    /* ── Config ─────────────────────────────────────────────── */

    public function getConfig(): array
    {
        if (!isset($this->cfg)) {
            $this->cfg = [
                'imap_host'    => $this->settings->get('mail_imap_host', ''),
                'imap_port'    => (int)$this->settings->get('mail_imap_port', 993),
                'imap_encrypt' => $this->settings->get('mail_imap_encrypt', 'ssl'),
                'imap_user'    => $this->settings->get('mail_imap_user', '') ?: $this->settings->get('mail_from_address', ''),
                'imap_pass'    => $this->settings->get('smtp_password', ''),
                'smtp_host'    => $this->settings->get('smtp_host', ''),
                'smtp_port'    => (int)$this->settings->get('smtp_port', 587),
                'smtp_user'    => $this->settings->get('smtp_username', ''),
                'smtp_pass'    => $this->settings->get('smtp_password', ''),
                'smtp_from'    => $this->settings->get('mail_from_address', ''),
                'smtp_name'    => $this->settings->get('mail_from_name', ''),
                'smtp_encrypt' => $this->settings->get('smtp_encryption', 'tls'),
            ];
        }
        return $this->cfg;
    }

    public function isConfigured(): bool
    {
        $c = $this->getConfig();
        return !empty($c['imap_host']) && !empty($c['imap_user']) && !empty($c['imap_pass']);
    }

    /* ── IMAP connection string ─────────────────────────────── */

    private function imapString(string $folder = 'INBOX'): string
    {
        $c   = $this->getConfig();
        $enc = strtolower($c['imap_encrypt']);
        $flags = '/imap';
        if ($enc === 'ssl')      $flags .= '/ssl';
        elseif ($enc === 'tls')  $flags .= '/tls';
        else                     $flags .= '/novalidate-cert';
        return '{' . $c['imap_host'] . ':' . $c['imap_port'] . $flags . '}' . $folder;
    }

    private function openImap(string $folder = 'INBOX'): mixed
    {
        if (!function_exists('imap_open')) {
            throw new \RuntimeException('PHP IMAP-Erweiterung ist nicht installiert. Bitte php-imap aktivieren.');
        }
        $c = $this->getConfig();
        $conn = @imap_open($this->imapString($folder), $c['imap_user'], $c['imap_pass'], 0, 1);
        if (!$conn) {
            $err = imap_last_error();
            throw new \RuntimeException('IMAP-Verbindung fehlgeschlagen: ' . ($err ?: 'Unbekannter Fehler'));
        }
        return $conn;
    }

    /* ── Folders ────────────────────────────────────────────── */

    public function getFolders(): array
    {
        try {
            $c    = $this->getConfig();
            $conn = $this->openImap();
            $enc  = strtolower($c['imap_encrypt']);
            $flags = '/imap' . ($enc === 'ssl' ? '/ssl' : ($enc === 'tls' ? '/tls' : '/novalidate-cert'));
            $ref  = '{' . $c['imap_host'] . ':' . $c['imap_port'] . $flags . '}';
            $list = imap_list($conn, $ref, '*');
            imap_close($conn);
            if (!$list) return [];
            return array_map(fn($f) => str_replace($ref, '', $f), $list);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* ── List messages ──────────────────────────────────────── */

    public function getMessages(string $folder = 'INBOX', int $page = 1, int $perPage = 20): array
    {
        $conn    = $this->openImap($folder);
        $total   = imap_num_msg($conn);
        $start   = max(1, $total - ($page * $perPage) + 1);
        $end     = max(1, $total - (($page - 1) * $perPage));

        $messages = [];
        for ($i = $end; $i >= $start; $i--) {
            $header = imap_headerinfo($conn, $i);
            if (!$header) continue;

            $uid     = imap_uid($conn, $i);
            $flags   = $header->Unseen === 'U' || $header->Recent === 'N';

            $from = '';
            $fromName = '';
            if (!empty($header->from)) {
                $f = $header->from[0];
                $fromName = isset($f->personal) ? $this->decodeMime($f->personal) : '';
                $from     = isset($f->mailbox, $f->host) ? $f->mailbox . '@' . $f->host : '';
            }

            $messages[] = [
                'uid'       => $uid,
                'seq'       => $i,
                'subject'   => $this->decodeMime($header->subject ?? '(Kein Betreff)'),
                'from'      => $from,
                'from_name' => $fromName ?: $from,
                'date'      => $header->date ?? '',
                'date_ts'   => isset($header->udate) ? (int)$header->udate : 0,
                'unread'    => $flags,
                'flagged'   => $header->Flagged === 'F',
            ];
        }

        imap_close($conn);

        return [
            'messages'   => $messages,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'total_pages'=> (int)ceil($total / $perPage),
            'folder'     => $folder,
        ];
    }

    /* ── Single message ─────────────────────────────────────── */

    public function getMessage(int $uid, string $folder = 'INBOX'): ?array
    {
        $conn = $this->openImap($folder);
        $seq  = imap_msgno($conn, $uid);
        if (!$seq) { imap_close($conn); return null; }

        $header   = imap_headerinfo($conn, $seq);
        $structure= imap_fetchstructure($conn, $seq);

        $from = '';
        $fromName = '';
        if (!empty($header->from)) {
            $f = $header->from[0];
            $fromName = isset($f->personal) ? $this->decodeMime($f->personal) : '';
            $from     = isset($f->mailbox, $f->host) ? $f->mailbox . '@' . $f->host : '';
        }

        $to = [];
        foreach ($header->to ?? [] as $t) {
            $to[] = (isset($t->personal) ? $this->decodeMime($t->personal) . ' ' : '') . '<' . ($t->mailbox ?? '') . '@' . ($t->host ?? '') . '>';
        }

        $body        = $this->getBodyPart($conn, $seq, $structure, 'html');
        $bodyText    = $this->getBodyPart($conn, $seq, $structure, 'plain');
        $attachments = $this->getAttachments($conn, $seq, $structure);

        /* Mark as read */
        imap_setflag_full($conn, (string)$seq, '\\Seen');

        imap_close($conn);

        return [
            'uid'         => $uid,
            'subject'     => $this->decodeMime($header->subject ?? '(Kein Betreff)'),
            'from'        => $from,
            'from_name'   => $fromName ?: $from,
            'to'          => implode(', ', $to),
            'date'        => $header->date ?? '',
            'date_ts'     => isset($header->udate) ? (int)$header->udate : 0,
            'body_html'   => $body,
            'body_text'   => $bodyText,
            'attachments' => $attachments,
            'folder'      => $folder,
        ];
    }

    /* ── Delete / move to trash ─────────────────────────────── */

    public function deleteMessage(int $uid, string $folder = 'INBOX'): bool
    {
        try {
            $conn = $this->openImap($folder);
            $seq  = imap_msgno($conn, $uid);
            if ($seq) {
                imap_delete($conn, (string)$seq);
                imap_expunge($conn);
            }
            imap_close($conn);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /* ── Send via SMTP (native sockets) ─────────────────────── */

    public function sendMail(string $to, string $subject, string $bodyHtml, string $bodyText = '', array $cc = [], array $bcc = [], ?string $replyToMsgId = null): bool
    {
        $c = $this->getConfig();

        if (empty($c['smtp_host']) || empty($c['smtp_user'])) {
            throw new \RuntimeException('SMTP ist nicht konfiguriert. Bitte in Einstellungen hinterlegen.');
        }

        $boundary = '=_' . md5(uniqid('', true));
        $msgId    = '<' . uniqid('tp', true) . '@' . ($c['smtp_host']) . '>';
        $fromFull = $c['smtp_name'] ? '"' . $c['smtp_name'] . '" <' . $c['smtp_from'] . '>' : $c['smtp_from'];

        $headers  = "From: {$fromFull}\r\n";
        $headers .= "To: {$to}\r\n";
        if ($cc) $headers .= "Cc: " . implode(', ', $cc) . "\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "Message-ID: {$msgId}\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        if ($replyToMsgId) $headers .= "In-Reply-To: {$replyToMsgId}\r\nReferences: {$replyToMsgId}\r\n";

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($bodyText ?: strip_tags($bodyHtml))) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($bodyHtml)) . "\r\n";
        $body .= "--{$boundary}--\r\n";

        /* Use php mail() as fallback, prefer PHPMailer if available */
        if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            return $this->sendViaPhpMailer($to, $subject, $bodyHtml, $bodyText, $cc, $bcc);
        }

        /* Native PHP mail() — works if sendmail/postfix configured */
        return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    }

    private function sendViaPhpMailer(string $to, string $subject, string $bodyHtml, string $bodyText, array $cc, array $bcc): bool
    {
        $c  = $this->getConfig();
        $pm = new \PHPMailer\PHPMailer\PHPMailer(true);
        $pm->isSMTP();
        $pm->Host       = $c['smtp_host'];
        $pm->Port       = $c['smtp_port'];
        $pm->Username   = $c['smtp_user'];
        $pm->Password   = $c['smtp_pass'];
        $pm->SMTPAuth   = true;
        $pm->SMTPSecure = strtolower($c['smtp_encrypt']) === 'ssl'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $pm->CharSet    = 'UTF-8';
        $pm->setFrom($c['smtp_from'], $c['smtp_name']);
        $pm->addAddress($to);
        foreach ($cc  as $a) $pm->addCC($a);
        foreach ($bcc as $a) $pm->addBCC($a);
        $pm->Subject  = $subject;
        $pm->Body     = $bodyHtml;
        $pm->AltBody  = $bodyText ?: strip_tags($bodyHtml);
        $pm->isHTML(true);
        return $pm->send();
    }

    /* ── Unread count (fast) ────────────────────────────────── */

    public function getUnreadCount(string $folder = 'INBOX'): int
    {
        try {
            $conn   = $this->openImap($folder);
            $status = imap_status($conn, $this->imapString($folder), SA_UNSEEN);
            imap_close($conn);
            return $status ? (int)$status->unseen : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /* ── Helpers ────────────────────────────────────────────── */

    private function decodeMime(string $str): string
    {
        if (empty($str)) return '';
        $decoded = imap_mime_header_decode($str);
        $result  = '';
        foreach ($decoded as $part) {
            $charset = strtolower($part->charset ?? 'utf-8');
            $text    = $part->text ?? '';
            if ($charset !== 'default' && $charset !== 'utf-8') {
                $text = mb_convert_encoding($text, 'UTF-8', $charset);
            }
            $result .= $text;
        }
        return $result;
    }

    private function getBodyPart($conn, int $seq, object $structure, string $type): string
    {
        $mimeType = $type === 'html' ? 'TEXT/HTML' : 'TEXT/PLAIN';

        if ($structure->type === 0) {
            /* Single-part */
            $subtype = strtoupper($structure->subtype ?? 'PLAIN');
            if (($type === 'html' && $subtype === 'HTML') || ($type === 'plain' && $subtype === 'PLAIN')) {
                $body = imap_fetchbody($conn, $seq, '1');
                return $this->decodeBody($body, $structure->encoding ?? 0, $structure->parameters ?? []);
            }
            return '';
        }

        /* Multipart — search recursively */
        return $this->findBodyPart($conn, $seq, $structure->parts ?? [], $type, '');
    }

    private function findBodyPart($conn, int $seq, array $parts, string $type, string $prefix): string
    {
        foreach ($parts as $i => $part) {
            $partNum = $prefix ? $prefix . '.' . ($i + 1) : (string)($i + 1);
            $subtype = strtoupper($part->subtype ?? '');

            if ($part->type === 0) {
                if (($type === 'html' && $subtype === 'HTML') || ($type === 'plain' && $subtype === 'PLAIN')) {
                    $body = imap_fetchbody($conn, $seq, $partNum);
                    return $this->decodeBody($body, $part->encoding ?? 0, $part->parameters ?? []);
                }
            }

            if (!empty($part->parts)) {
                $found = $this->findBodyPart($conn, $seq, $part->parts, $type, $partNum);
                if ($found !== '') return $found;
            }
        }
        return '';
    }

    private function decodeBody(string $body, int $encoding, array $params): string
    {
        $body = match ($encoding) {
            1       => imap_utf8($body),
            2       => base64_decode($body),
            3       => base64_decode($body),
            4       => quoted_printable_decode($body),
            default => $body,
        };

        $charset = 'UTF-8';
        foreach ($params as $p) {
            if (strtolower($p->attribute ?? '') === 'charset') {
                $charset = strtoupper($p->value);
                break;
            }
        }

        if ($charset !== 'UTF-8') {
            $body = mb_convert_encoding($body, 'UTF-8', $charset);
        }

        return $body;
    }

    private function getAttachments($conn, int $seq, object $structure): array
    {
        $attachments = [];
        if (empty($structure->parts)) return $attachments;

        foreach ($structure->parts as $i => $part) {
            $partNum = (string)($i + 1);
            if ($part->ifdparameters) {
                foreach ($part->dparameters as $dp) {
                    if (strtolower($dp->attribute) === 'filename') {
                        $attachments[] = [
                            'part'     => $partNum,
                            'name'     => $this->decodeMime($dp->value),
                            'encoding' => $part->encoding,
                            'size'     => $part->bytes ?? 0,
                        ];
                    }
                }
            }
            if ($part->ifparameters) {
                foreach ($part->parameters as $p) {
                    if (strtolower($p->attribute) === 'name') {
                        $exists = array_filter($attachments, fn($a) => $a['part'] === $partNum);
                        if (!$exists) {
                            $attachments[] = [
                                'part'     => $partNum,
                                'name'     => $this->decodeMime($p->value),
                                'encoding' => $part->encoding,
                                'size'     => $part->bytes ?? 0,
                            ];
                        }
                    }
                }
            }
        }

        return $attachments;
    }
}
