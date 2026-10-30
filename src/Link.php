<?php

namespace GlpiPlugin\Gitlabissues;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Html;
use ITILFollowup;
use RuntimeException;
use Session;
use Ticket;
use Toolbox;

/**
 * Link between a GLPI ticket and a GitLab issue.
 *
 * One row per issue created from a ticket. The row also carries what the automatic
 * action needs to follow the issue (state, last check, error counter).
 */
class Link extends CommonDBTM
{
    public static $rightname = 'plugin_gitlabissues_issue';

    public $dohistory = false;

    public const STATE_OPENED  = 'opened';
    public const STATE_CLOSED  = 'closed';
    public const STATE_MISSING = 'missing'; // deleted or no longer reachable on GitLab
    public const STATE_MOVED   = 'moved';   // moved to another project: cannot be followed

    public static function getTypeName($nb = 0): string
    {
        return _n('GitLab issue', 'GitLab issues', $nb, 'gitlabissues');
    }

    public static function getIcon(): string
    {
        return 'ti ti-brand-gitlab';
    }

    public function getRights($interface = 'central'): array
    {
        return [
            READ   => __('Read'),
            CREATE => __('Create an issue', 'gitlabissues'),
            UPDATE => __('Refresh the status', 'gitlabissues'),
            DELETE => __('Unlink', 'gitlabissues'),
        ];
    }

    public static function getStateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_OPENED  => __('Open', 'gitlabissues'),
            self::STATE_CLOSED  => __('Closed', 'gitlabissues'),
            self::STATE_MISSING => __('Not found on GitLab', 'gitlabissues'),
            self::STATE_MOVED   => __('Moved to another project', 'gitlabissues'),
            default             => $state,
        };
    }

    // --------------------------------------------------------------------- tab

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        if ($item instanceof Ticket && self::canView()) {
            $count = 0;
            if (!empty($_SESSION['glpishow_count_on_tabs'])) {
                $count = countElementsInTable(self::getTable(), ['tickets_id' => $item->getID()]);
            }

            return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $count, $item::class);
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Ticket) {
            self::showForTicket($item);

            return true;
        }

        return false;
    }

    public static function showForTicket(Ticket $ticket): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (!self::canView() || !$ticket->canViewItem()) {
            return;
        }

        $links = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $ticket->getID()], 'ORDER' => 'id ASC']) as $row) {
            $row['state_label'] = self::getStateLabel($row['state']);
            $row['date_closed'] = $row['date_closed'] ? Html::convDateTime($row['date_closed']) : '';
            $row['date_last_check'] = $row['date_last_check'] ? Html::convDateTime($row['date_last_check']) : '';
            $links[] = $row;
        }

        $configured = Settings::isConfigured();
        $default_id = (int) Settings::get('default_project_id');

        TemplateRenderer::getInstance()->display('@gitlabissues/ticket_tab.html.twig', [
            'ticket_id'    => $ticket->getID(),
            'links'        => $links,
            'configured'   => $configured,
            'can_create'   => $configured && self::canCreate(),
            'can_update'   => self::canUpdate(),
            'can_delete'   => self::canDelete(),
            'can_config'   => Session::haveRight('config', UPDATE),
            'default_project' => $default_id > 0
                ? ['id' => $default_id, 'text' => Settings::get('default_project_path') ?: (string) $default_id]
                : null,
            'default_labels' => Settings::get('default_labels'),
            'base'         => ($CFG_GLPI['root_doc'] ?? '') . '/plugins/gitlabissues',
        ]);
    }

    // ---------------------------------------------------------------- creation

    /**
     * Create the GitLab issue for a ticket and store the link.
     *
     * @return array{link: Link, warnings: string[]}
     *
     * @throws GitlabException|RuntimeException
     */
    public static function createFromTicket(
        Ticket $ticket,
        int $project_id,
        string $extra_labels = '',
        int $users_id = 0,
        bool $allow_duplicate = false,
    ): array {
        /** @var \DBmysql $DB */
        global $DB;

        if ($project_id <= 0) {
            throw new RuntimeException(__('Select a GitLab project.', 'gitlabissues'));
        }

        // Serialise concurrent creations for the same ticket (double click, two technicians).
        $lock = 'gitlabissues_ticket_' . $ticket->getID();
        $got  = $DB->doQuery("SELECT GET_LOCK('" . $DB->escape($lock) . "', 10) AS acquired")->fetch_assoc();
        if (!$got || (int) $got['acquired'] !== 1) {
            throw new RuntimeException(__('Another issue creation is in progress for this ticket, try again in a moment.', 'gitlabissues'));
        }

        try {
            if (
                !$allow_duplicate
                && countElementsInTable(self::getTable(), [
                    'tickets_id'        => $ticket->getID(),
                    'gitlab_project_id' => $project_id,
                    'state'             => self::STATE_OPENED,
                ]) > 0
            ) {
                throw new RuntimeException(__('This ticket already has an open issue in this project.', 'gitlabissues'));
            }

            $client  = GitlabClient::fromSettings();
            // Validates that the project exists and is reachable before uploading anything.
            $project = $client->getProject($project_id);
            $path    = (string) ($project['path_with_namespace'] ?? $project_id);

            $payload = (new IssueBuilder($client))->build($ticket, $project_id, $extra_labels, $users_id);
            $issue   = $client->createIssue($project_id, $payload);

            $link = new self();
            try {
                $id = $link->add([
                    'tickets_id'        => $ticket->getID(),
                    'users_id'          => $users_id,
                    'gitlab_project_id' => $project_id,
                    'project_path'      => $path,
                    'issue_iid'         => (int) $issue['iid'],
                    'issue_id'          => (int) $issue['id'],
                    'issue_url'         => (string) ($issue['web_url'] ?? ''),
                    'issue_title'       => mb_substr((string) ($issue['title'] ?? $payload['title']), 0, 255),
                    'state'             => self::STATE_OPENED,
                ]);
            } catch (\Throwable $e) {
                // GLPI 11 throws on SQL errors. Whatever happens, the user must learn where the issue is.
                Toolbox::logInFile('gitlabissues', 'Link not saved: ' . $e->getMessage() . "\n");
                $id = false;
            }
            if ($id === false) {
                // The issue exists on GitLab but could not be recorded: tell exactly where it is.
                Toolbox::logInFile('gitlabissues', sprintf(
                    "Issue created but link not saved (ticket %d): %s\n",
                    $ticket->getID(),
                    $issue['web_url'] ?? ''
                ));
                throw new RuntimeException(sprintf(
                    __('The issue was created on GitLab (%s) but could not be linked to the ticket.', 'gitlabissues'),
                    $issue['web_url'] ?? ''
                ));
            }
        } finally {
            $DB->doQuery("SELECT RELEASE_LOCK('" . $DB->escape($lock) . "')");
        }

        if (Settings::get('creation_followup') === '1') {
            self::addCreationFollowup($ticket, $link, $users_id);
        }

        return ['link' => $link, 'warnings' => $payload['warnings']];
    }

    private static function addCreationFollowup(Ticket $ticket, self $link, int $users_id): void
    {
        $url   = htmlspecialchars((string) $link->fields['issue_url'], ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($link->fields['project_path'] . '#' . $link->fields['issue_iid'], ENT_QUOTES, 'UTF-8');

        try {
            (new ITILFollowup())->add([
                'itemtype'   => 'Ticket',
                'items_id'   => $ticket->getID(),
                'content'    => '<p>' . sprintf(__('GitLab issue created: %s', 'gitlabissues'), '<a href="' . $url . '">' . $label . '</a>') . '</p>',
                'is_private' => 1,
                'users_id'   => $users_id,
            ]);
        } catch (\Throwable $e) {
            // Traceability only: never fail the creation because of it.
            Toolbox::logInFile('gitlabissues', 'Creation followup failed: ' . $e->getMessage() . "\n");
        }
    }

    // ------------------------------------------------------------------- misc

    /**
     * item_purge hook: remove the links of a purged ticket.
     */
    public static function cleanForTicket(Ticket $ticket): void
    {
        (new self())->deleteByCriteria(['tickets_id' => $ticket->getID()], true);
    }
}
