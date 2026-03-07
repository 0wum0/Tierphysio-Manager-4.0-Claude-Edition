<?php

declare(strict_types=1);

namespace Plugins\Mailbox;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Repositories\SettingsRepository;

class MailboxController extends Controller
{
    private MailboxService $mailbox;

    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        SettingsRepository $settings
    ) {
        parent::__construct($view, $session, $config, $translator);
        $this->mailbox = new MailboxService($settings);
    }

    /* ── Index → redirect to inbox ─────────────────────────── */

    public function index(array $params = []): void
    {
        $this->redirect('/mailbox/posteingang');
    }

    /* ── Inbox ──────────────────────────────────────────────── */

    public function inbox(array $params = []): void
    {
        $this->renderFolder('INBOX', 'Posteingang', 'inbox');
    }

    /* ── Sent ───────────────────────────────────────────────── */

    public function sent(array $params = []): void
    {
        $folder = $this->detectFolder(['Sent', 'Sent Items', 'INBOX.Sent', 'Gesendet']);
        $this->renderFolder($folder, 'Gesendet', 'sent');
    }

    /* ── Drafts ─────────────────────────────────────────────── */

    public function drafts(array $params = []): void
    {
        $folder = $this->detectFolder(['Drafts', 'INBOX.Drafts', 'Entwürfe']);
        $this->renderFolder($folder, 'Entwürfe', 'drafts');
    }

    /* ── Show single message ────────────────────────────────── */

    public function show(array $params = []): void
    {
        if (!$this->mailbox->isConfigured()) {
            $this->renderNotConfigured();
            return;
        }

        $uid    = (int)($params['uid'] ?? 0);
        $folder = $this->get('folder', 'INBOX');

        try {
            $message = $this->mailbox->getMessage($uid, $folder);
            if (!$message) {
                $this->session->flash('error', 'Nachricht nicht gefunden.');
                $this->redirect('/mailbox/posteingang');
                return;
            }
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Fehler: ' . $e->getMessage());
            $this->redirect('/mailbox/posteingang');
            return;
        }

        $this->render('@mailbox/mailbox/show.twig', [
            'page_title'   => $message['subject'],
            'message'      => $message,
            'folder'       => $folder,
            'active_folder'=> $folder,
            'unread_count' => $this->safeUnreadCount(),
            'cfg_ok'       => true,
        ]);
    }

    /* ── Compose new message ────────────────────────────────── */

    public function compose(array $params = []): void
    {
        if (!$this->mailbox->isConfigured()) {
            $this->renderNotConfigured();
            return;
        }

        $to      = $this->get('to', '');
        $subject = $this->get('subject', '');
        $replyUid= (int)$this->get('reply', 0);
        $folder  = $this->get('folder', 'INBOX');

        $replyMessage = null;
        if ($replyUid > 0) {
            try {
                $replyMessage = $this->mailbox->getMessage($replyUid, $folder);
                if ($replyMessage && empty($subject)) {
                    $s = $replyMessage['subject'];
                    $subject = str_starts_with(strtolower($s), 're:') ? $s : 'Re: ' . $s;
                }
                if ($replyMessage && empty($to)) {
                    $to = $replyMessage['from'];
                }
            } catch (\Throwable) {}
        }

        $this->render('@mailbox/mailbox/compose.twig', [
            'page_title'    => 'Neue E-Mail',
            'to'            => $to,
            'subject'       => $subject,
            'reply_message' => $replyMessage,
            'reply_uid'     => $replyUid,
            'reply_folder'  => $folder,
            'active_folder' => 'compose',
            'unread_count'  => $this->safeUnreadCount(),
            'cfg_ok'        => true,
        ]);
    }

    /* ── Send ───────────────────────────────────────────────── */

    public function send(array $params = []): void
    {
        if (!$this->mailbox->isConfigured()) {
            $this->json(['ok' => false, 'error' => 'Nicht konfiguriert.']);
            return;
        }

        $to      = trim($this->post('to', ''));
        $subject = trim($this->post('subject', '(Kein Betreff)'));
        $body    = $this->post('body', '');
        $cc      = array_filter(array_map('trim', explode(',', $this->post('cc', ''))));
        $bcc     = array_filter(array_map('trim', explode(',', $this->post('bcc', ''))));

        if (empty($to)) {
            $this->session->flash('error', 'Bitte einen Empfänger angeben.');
            $this->redirectBack();
            return;
        }

        try {
            $this->mailbox->sendMail($to, $subject, $body, '', $cc, $bcc);
            $this->session->flash('success', 'E-Mail erfolgreich gesendet.');
            $this->redirect('/mailbox/posteingang');
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Fehler beim Senden: ' . $e->getMessage());
            $this->redirectBack();
        }
    }

    /* ── Delete ─────────────────────────────────────────────── */

    public function delete(array $params = []): void
    {
        $uid    = (int)($params['uid'] ?? 0);
        $folder = $this->post('folder', 'INBOX');

        if ($this->mailbox->deleteMessage($uid, $folder)) {
            $this->session->flash('success', 'Nachricht gelöscht.');
        } else {
            $this->session->flash('error', 'Löschen fehlgeschlagen.');
        }
        $this->redirect('/mailbox/posteingang');
    }

    /* ── Save draft (placeholder) ───────────────────────────── */

    public function saveDraft(array $params = []): void
    {
        $this->json(['ok' => true, 'message' => 'Entwurf gespeichert.']);
    }

    /* ── API: unread count ──────────────────────────────────── */

    public function apiCheck(array $params = []): void
    {
        $count = $this->safeUnreadCount();
        $this->json(['unread' => $count]);
    }

    /* ── Helpers ────────────────────────────────────────────── */

    private function renderFolder(string $folder, string $title, string $activeFolder): void
    {
        if (!$this->mailbox->isConfigured()) {
            $this->renderNotConfigured();
            return;
        }

        $page = max(1, (int)$this->get('seite', 1));

        try {
            $data = $this->mailbox->getMessages($folder, $page);
        } catch (\Throwable $e) {
            $this->render('@mailbox/mailbox/index.twig', [
                'page_title'    => $title,
                'error'         => $e->getMessage(),
                'messages'      => [],
                'total'         => 0,
                'page'          => 1,
                'total_pages'   => 1,
                'active_folder' => $activeFolder,
                'unread_count'  => 0,
                'cfg_ok'        => true,
                'folder'        => $folder,
            ]);
            return;
        }

        $this->render('@mailbox/mailbox/index.twig', array_merge($data, [
            'page_title'    => $title,
            'active_folder' => $activeFolder,
            'unread_count'  => $this->safeUnreadCount(),
            'cfg_ok'        => true,
        ]));
    }

    private function renderNotConfigured(): void
    {
        $this->render('@mailbox/mailbox/index.twig', [
            'page_title'    => 'Mailbox',
            'cfg_ok'        => false,
            'messages'      => [],
            'total'         => 0,
            'page'          => 1,
            'total_pages'   => 1,
            'active_folder' => 'inbox',
            'unread_count'  => 0,
            'folder'        => 'INBOX',
        ]);
    }

    private function detectFolder(array $candidates): string
    {
        $folders = $this->mailbox->getFolders();
        foreach ($candidates as $c) {
            foreach ($folders as $f) {
                if (stripos($f, $c) !== false) return $f;
            }
        }
        return $candidates[0];
    }

    private function safeUnreadCount(): int
    {
        try {
            return $this->mailbox->isConfigured() ? $this->mailbox->getUnreadCount() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
