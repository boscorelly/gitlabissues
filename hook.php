<?php

/**
 * Install / uninstall hooks of the GitLab Issues plugin.
 *
 * @license AGPL-3.0-or-later
 */

use GlpiPlugin\Gitlabissues\Link;
use GlpiPlugin\Gitlabissues\Settings;
use GlpiPlugin\Gitlabissues\Sync;

/**
 * Install the plugin: table, automatic action, rights, default settings.
 */
function plugin_gitlabissues_install(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    $migration = new Migration(PLUGIN_GITLABISSUES_VERSION);

    $charset  = DBConnection::getDefaultCharset();
    $collate  = DBConnection::getDefaultCollation();
    $key_sign = DBConnection::getDefaultPrimaryKeySignOption();

    // ---- Table of links between tickets and GitLab issues -------------------
    $table = Link::getTable();
    // This function also runs on every plugin update (GLPI calls it again when the version
    // changes): everything below must be idempotent and must never overwrite admin choices.
    $first_install = !$DB->tableExists($table);
    if ($first_install) {
        $migration->displayMessage("Installing $table");
        $DB->doQuery(
            "CREATE TABLE `$table` (
                `id`                 INT {$key_sign} NOT NULL AUTO_INCREMENT,
                `tickets_id`         INT {$key_sign} NOT NULL DEFAULT '0',
                `users_id`           INT {$key_sign} NOT NULL DEFAULT '0',
                `gitlab_project_id`  BIGINT UNSIGNED NOT NULL DEFAULT '0',
                `project_path`       VARCHAR(255) DEFAULT NULL,
                `issue_iid`          BIGINT UNSIGNED NOT NULL DEFAULT '0',
                `issue_id`           BIGINT UNSIGNED NOT NULL DEFAULT '0',
                `issue_url`          VARCHAR(1024) DEFAULT NULL,
                `issue_title`        VARCHAR(255) DEFAULT NULL,
                `state`              VARCHAR(16) NOT NULL DEFAULT 'opened',
                `date_closed`        TIMESTAMP NULL DEFAULT NULL,
                `closed_by`          VARCHAR(255) DEFAULT NULL,
                `date_last_check`    TIMESTAMP NULL DEFAULT NULL,
                `error_count`        INT {$key_sign} NOT NULL DEFAULT '0',
                `last_error`         TEXT DEFAULT NULL,
                `date_creation`      TIMESTAMP NULL DEFAULT NULL,
                `date_mod`           TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`gitlab_project_id`, `issue_iid`),
                KEY `tickets_id` (`tickets_id`),
                KEY `state_check` (`state`, `date_last_check`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate} ROW_FORMAT=DYNAMIC"
        );
    }

    // Future schema changes go here, written as "if the column/table is missing, add it"
    // (use $DB->fieldExists() / $migration->addField()): GLPI gives no "previous version" to the
    // install function, so each step must detect by itself whether it is needed.

    // ---- Settings (the token is encrypted thanks to the secured_configs hook) ----
    // Declare the secured config key right now: hooks of the plugin are not
    // necessarily loaded when the installation runs from the CLI.
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS[Glpi\Plugin\Hooks::SECURED_CONFIGS]['gitlabissues'] = ['gitlab_token'];
    $existing = Config::getConfigurationValues(Settings::CONTEXT);
    $missing  = array_diff_key(Settings::DEFAULTS, $existing);
    if ($missing !== []) {
        Config::setConfigurationValues(Settings::CONTEXT, $missing);
    }

    // ---- Rights --------------------------------------------------------------
    // ProfileRight::addProfileRights() inserts blindly (it fails when rerun on an update):
    // only create the rows that are missing.
    $existing_profiles = array_column(
        iterator_to_array($DB->request(['SELECT' => ['profiles_id'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => Link::$rightname]])),
        'profiles_id'
    );
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable()]) as $profile) {
        if (!in_array($profile['id'], $existing_profiles)) {
            $DB->insert('glpi_profilerights', ['profiles_id' => $profile['id'], 'name' => Link::$rightname]);
        }
    }
    // First installation only: profiles that can already update tickets get the plugin rights by
    // default. On an update the rights chosen by administrators in Administration > Profiles are kept.
    if ($first_install) {
        foreach ($DB->request([
            'SELECT' => ['profiles_id'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'ticket', 'rights' => ['&', UPDATE]],
        ]) as $row) {
            ProfileRight::updateProfileRights(
                (int) $row['profiles_id'],
                [Link::$rightname => READ | CREATE | UPDATE | DELETE]
            );
        }
    }

    // ---- Automatic action: check the issues every 5 minutes -------------------
    CronTask::register(
        Sync::class,
        'CheckIssues',
        5 * MINUTE_TIMESTAMP,
        [
            'comment' => 'Check GitLab issues linked to tickets and run the actions when they are closed',
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
            'param'   => Sync::DEFAULT_BATCH_SIZE,
        ]
    );

    $migration->executeMigration();

    return true;
}

/**
 * Uninstall the plugin and remove all its data.
 */
function plugin_gitlabissues_uninstall(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    $migration = new Migration(PLUGIN_GITLABISSUES_VERSION);
    $migration->displayMessage('Uninstalling GitLab Issues');

    $migration->dropTable(Link::getTable());
    $migration->executeMigration();

    $config = new Config();
    $config->deleteByCriteria(['context' => Settings::CONTEXT]);

    ProfileRight::deleteProfileRights([Link::$rightname]);

    // CronTask::unregister('gitlabissues') is called by GLPI after this function.

    return true;
}
