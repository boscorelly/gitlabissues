<?php

namespace GlpiPlugin\Gitlabissues;

use Config;
use GLPIKey;

/**
 * Plugin settings, stored in glpi_configs (context "plugin:gitlabissues").
 * The token is encrypted with GLPIKey (see the secured_configs hook in setup.php).
 */
final class Settings
{
    public const CONTEXT = 'plugin:gitlabissues';

    public const DEFAULTS = [
        // Connection
        'gitlab_url'             => '',
        'gitlab_token'           => '',
        // Issue creation
        'default_project_id'     => '',
        'default_project_path'   => '',
        'title_template'         => '[GLPI #{id}] {title}',
        'default_labels'         => 'glpi',
        'confidential'           => '0',
        'include_people'         => '1',
        'upload_attachments'     => '1',
        'max_attachment_mb'      => '10',
        'creation_followup'      => '1',
        // When the issue is closed
        'close_followup'         => '1',
        'close_followup_private' => '1',
        'close_solve_ticket'     => '0',
        'solutiontypes_id'       => '0',
        'solution_template'      => '',
        'actor_users_id'         => '0',
    ];

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $values = Config::getConfigurationValues(self::CONTEXT) + self::DEFAULTS;
        // Config::getConfigurationValues() does not decrypt: it is done here.
        $values['gitlab_token'] = self::decryptToken((string) $values['gitlab_token']);

        return $values;
    }

    public static function get(string $name): string
    {
        return (string) (self::all()[$name] ?? '');
    }

    /**
     * Save settings. An empty token keeps the stored one (the form never displays it).
     *
     * @param array<string, mixed> $input
     */
    public static function save(array $input): void
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $name) {
            if (!array_key_exists($name, $input)) {
                continue;
            }
            $value = is_scalar($input[$name]) ? trim((string) $input[$name]) : '';
            if ($name === 'gitlab_token' && $value === '') {
                continue;
            }
            if ($name === 'gitlab_url') {
                $value = rtrim($value, '/');
            }
            $values[$name] = $value;
        }
        Config::setConfigurationValues(self::CONTEXT, $values);
    }

    public static function hasToken(): bool
    {
        return self::get('gitlab_token') !== '';
    }

    public static function isConfigured(): bool
    {
        $all = self::all();

        return $all['gitlab_url'] !== '' && $all['gitlab_token'] !== '';
    }

    private static function decryptToken(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        // decrypt() returns an empty string when the value cannot be decrypted
        // (e.g. glpicrypt.key changed): the admin then has to enter the token again.
        return (new GLPIKey())->decrypt($stored);
    }
}
