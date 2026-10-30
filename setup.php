<?php

/**
 * GitLab Issues – GLPI plugin
 *
 * Create a GitLab issue from a GLPI ticket, then periodically check (GLPI
 * automatic action) whether the issue has been closed and run the configured
 * actions on the ticket.
 *
 * @license AGPL-3.0-or-later
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Gitlabissues\Link;
use GlpiPlugin\Gitlabissues\Profile as PluginProfile;

define('PLUGIN_GITLABISSUES_VERSION', '1.0.4');

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

    // Language fallback: English when the plugin has no catalogue for the user's language (see below).
    $PLUGIN_HOOKS[Hooks::POST_INIT]['gitlabissues'] = 'plugin_gitlabissues_post_init';

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
        'license'      => 'AGPL-3.0-or-later',
        'homepage'     => 'https://github.com/boscorelly/gitlabissues',
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

/**
 * Language fallback.
 *
 * GLPI loads the plugin catalogue of the user's language, else the one of the instance's DEFAULT language,
 * and only then English: on a French instance, a user whose language the plugin does not translate (English
 * included) would get French. Here the rule is: the user's language if the plugin has it, otherwise English.
 * The English catalogue is registered last for the user's locale, so it wins over what GLPI loaded.
 */
function plugin_gitlabissues_post_init(): void
{
    /**
     * @var array $CFG_GLPI
     * @var \Laminas\I18n\Translator\Translator $TRANSLATE
     */
    global $CFG_GLPI, $TRANSLATE;

    $lang = $_SESSION['glpilanguage'] ?? ($CFG_GLPI['language'] ?? 'en_GB');
    $dir  = Plugin::getPhpDir('gitlabissues') . '/locales/';
    $file = $CFG_GLPI['languages'][$lang][1] ?? ($lang . '.mo');

    if (!is_file($dir . $file) && is_file($dir . 'en_GB.mo')) {
        $TRANSLATE->addTranslationFile('gettext', $dir . 'en_GB.mo', 'gitlabissues', $lang);
    }
}
