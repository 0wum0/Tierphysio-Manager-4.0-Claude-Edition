<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Repositories\UserRepository;

class ProfileController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        private readonly UserRepository $userRepository
    ) {
        parent::__construct($view, $session, $config, $translator);
    }

    public function show(array $params = []): void
    {
        $user = $this->userRepository->findById((int)$this->session->get('user_id'));

        $this->render('profile/index.twig', [
            'page_title'     => $this->translator->trans('profile.title'),
            'user'           => $user,
            'theme'          => $this->session->get('theme', 'dark'),
            'current_locale' => $this->translator->getLocale(),
        ]);
    }

    public function update(array $params = []): void
    {
        $this->validateCsrf();

        $name  = $this->sanitize($this->post('name', ''));
        $email = $this->sanitize($this->post('email', ''));

        if (empty($name) || empty($email)) {
            $this->session->flash('error', $this->translator->trans('profile.fill_required'));
            $this->redirect('/profil');
            return;
        }

        $userId = (int)$this->session->get('user_id');
        $this->userRepository->update($userId, ['name' => $name, 'email' => $email]);

        $this->session->set('user_name', $name);
        $this->session->set('user_email', $email);

        if ($this->post('theme')) {
            $theme = in_array($this->post('theme'), ['dark', 'light']) ? $this->post('theme') : 'dark';
            $this->session->set('theme', $theme);
        }

        if ($this->post('locale')) {
            $locale = in_array($this->post('locale'), ['de', 'en']) ? $this->post('locale') : 'de';
            $this->session->set('locale', $locale);
        }

        $this->session->flash('success', $this->translator->trans('profile.updated'));
        $this->redirect('/profil');
    }

    public function updatePassword(array $params = []): void
    {
        $this->validateCsrf();

        $current  = $this->post('current_password', '');
        $new      = $this->post('new_password', '');
        $confirm  = $this->post('confirm_password', '');

        $userId = (int)$this->session->get('user_id');
        $user   = $this->userRepository->findById($userId);

        if (!password_verify($current, $user['password'])) {
            $this->session->flash('error', $this->translator->trans('profile.wrong_password'));
            $this->redirect('/profil');
            return;
        }

        if ($new !== $confirm || strlen($new) < 8) {
            $this->session->flash('error', $this->translator->trans('profile.password_mismatch'));
            $this->redirect('/profil');
            return;
        }

        $this->userRepository->update($userId, [
            'password' => password_hash($new, PASSWORD_BCRYPT, ['cost' => 12])
        ]);

        $this->session->flash('success', $this->translator->trans('profile.password_updated'));
        $this->redirect('/profil');
    }
}
