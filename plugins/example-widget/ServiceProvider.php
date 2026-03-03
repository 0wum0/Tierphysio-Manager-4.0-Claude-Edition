<?php

declare(strict_types=1);

namespace Plugins\ExampleWidget;

use App\Core\PluginManager;

class ServiceProvider
{
    public function register(PluginManager $pluginManager): void
    {
        $pluginManager->hook('dashboardWidgets', [$this, 'renderWidget']);
        $pluginManager->hook('beforeRender', [$this, 'onBeforeRender']);
    }

    public function renderWidget(array $context): string
    {
        $user = $context['user'] ?? null;
        $name = $user ? htmlspecialchars($user['name'] ?? 'Unbekannt') : 'Gast';

        return '
            <div style="padding:1rem;">
                <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);margin-bottom:0.75rem;">
                    Beispiel-Widget
                </div>
                <div style="font-size:1rem;color:var(--text-primary);">
                    👋 Hallo, <strong>' . $name . '</strong>!
                </div>
                <div style="font-size:0.8rem;color:var(--text-muted);margin-top:0.5rem;">
                    Dies ist ein Plugin-Beispiel-Widget.
                </div>
            </div>
        ';
    }

    public function onBeforeRender(array &$data): void
    {
        // Add global data before any template renders.
        // $data['my_plugin_var'] = 'hello';
    }
}
