<?php

/**
 * GitLab Issues – GLPI plugin
 *
 * Create a GitLab issue from a GLPI ticket, then periodically check (GLPI
 * automatic action) whether the issue has been closed and run the configured
 * actions on the ticket.
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Gitlabissues\Link;
use GlpiPlugin\Gitlabissues\Profile as PluginProfile;

define('PLUGIN_GITLABISSUES_VERSION', '1.0.0');

// Minimal GLPI version, inclusive
define('PLUGIN_GITLABISSUES_MIN_GLPI', '11.0.0');
// Maximum GLPI version, exclusive
define('PLUGIN_GITLABISSUES_MAX_GLPI', '11.1.0');

/**
 * Plugin initialisation: register classes and hooks.
 */
function plugin_init_gitlabissues(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['gitlabissues'] = true;

    // The GitLab token is stored encrypted (GLPIKey) in the GLPI configuration table.
    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['gitlabissues'] = ['gitlab_token'];

    if (!Plugin::isPluginActive('gitlabissues')) {
        return;
    }

    // "GitLab" tab on tickets, and "GitLab issues" rights tab on profiles.
    Plugin::registerClass(Link::class, ['addtabon' => ['Ticket']]);
    Plugin::registerClass(PluginProfile::class, ['addtabon' => ['Profile']]);

    // Setup > Plugins > GitLab Issues > configuration (route /plugins/gitlabissues/Config,
    // access is checked by the controller).
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['gitlabissues'] = 'Config';

    // Remove links when a ticket is purged.
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['gitlabissues'] = [
        'Ticket' => [Link::class, 'cleanForTicket'],
    ];
}

/**
 * Plugin metadata and requirements.
 */
function plugin_version_gitlabissues(): array
{
    return [
        'name'         => 'GitLab Issues',
        'version'      => PLUGIN_GITLABISSUES_VERSION,
        'author'       => 'GitLab Issues contributors',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_GITLABISSUES_MIN_GLPI,
                'max' => PLUGIN_GITLABISSUES_MAX_GLPI,
                'dev' => false,
            ],
            'php'  => [
                'min' => '8.2',
                'exts' => [
                    'curl'     => ['required' => true],
                    'dom'      => ['required' => true],
                    'mbstring' => ['required' => true],
                ],
            ],
        ],
    ];
}
