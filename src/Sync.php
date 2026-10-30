<?php

namespace GlpiPlugin\Gitlabissues;

use CronTask;
use ITILFollowup;
use ITILSolution;
use Throwable;
use Ticket;
use Toolbox;

/**
 * Automatic action "CheckIssues": polls GitLab for the issues linked to tickets and,
 * when one is closed, runs the configured actions on the ticket (followup, solution).
 *
 * Tickets are always modified through GLPI's own objects (ITILFollowup, ITILSolution),
 * so history, business rules, notifications and the other automatic actions
 * (auto-close of solved tickets, satisfaction surveys...) behave as for a manual action.
 */
final class Sync
{
    public const DEFAULT_BATCH_SIZE = 200;

    /** Consecutive "not found" answers before an issue is declared missing. */
    private const MAX_NOT_FOUND = 3;

    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'CheckIssues' => [
                'description' => __('Check the GitLab issues linked to tickets', 'gitlabissues'),
                'parameter'   => __('Maximum number of issues checked per run', 'gitlabissues'),
            ],
            default => [],
        };
    }

    /**
     * Entry point called by GLPI's cron.
     *
     * @return int >0 something was processed, 0 nothing to do, <0 partially processed
     */
    public static function cronCheckIssues(CronTask $task): int
    {
        $limit = (int) ($task->fields['param'] ?? 0);
        $stats = self::run($limit > 0 ? $limit : self::DEFAULT_BATCH_SIZE, $task);

        $task->addVolume($stats['checked']);
        $task->log(sprintf(
            'checked=%d closed=%d moved=%d missing=%d errors=%d',
            $stats['checked'],
            $stats['closed'],
            $stats['moved'],
            $stats['missing'],
            $stats['errors']
        ));

        if ($stats['aborted']) {
            return -1;
        }

        return $stats['checked'] > 0 ? 1 : 0;
    }

    /**
     * @return array{checked: int, closed: int, moved: int, missing: int, errors: int, aborted: bool}
     */
    public static function run(int $limit = self::DEFAULT_BATCH_SIZE, ?CronTask $task = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $stats = ['checked' => 0, 'closed' => 0, 'moved' => 0, 'missing' => 0, 'errors' => 0, 'aborted' => false];

        if (!Settings::isConfigured()) {
            return $stats; // Nothing to do (and nothing to complain about) until the plugin is configured.
        }

        try {
            $client = GitlabClient::fromSettings();
        } catch (GitlabException $e) {
            self::logError($task, $e->getMessage());
            $stats['aborted'] = true;

            return $stats;
        }

        // Oldest checked first, never-checked first: every issue gets its turn even if
        // there are more open issues than the batch size.
        $rows = $DB->request([
            'FROM'  => Link::getTable(),
            'WHERE' => ['state' => Link::STATE_OPENED],
            'ORDER' => ['date_last_check ASC', 'id ASC'],
            'LIMIT' => max(1, $limit),
        ]);

        $by_project = [];
        foreach ($rows as $row) {
            $by_project[(int) $row['gitlab_project_id']][] = $row;
        }

        foreach ($by_project as $project_id => $links) {
            try {
                $issues = $client->getIssues($project_id, array_column($links, 'issue_iid'));
            } catch (GitlabException $e) {
                if ($e->isGlobal()) {
                    // GitLab unreachable, token refused, rate limited...: stop here and try again next run.
                    self::recordGlobalError($links, $e);
                    self::logError($task, $e->getMessage());
                    $stats['errors'] += count($links);
                    $stats['aborted'] = true;
                    break;
                }
                // Project not found / not accessible: treat each link as "not found".
                foreach ($links as $row) {
                    $stats['checked']++;
                    self::handleNotFound($row, $e->getMessage(), $stats);
                }
                continue;
            }

            foreach ($links as $row) {
                $stats['checked']++;
                $issue = $issues[(int) $row['issue_iid']] ?? null;

                try {
                    if ($issue === null) {
                        // Not in the batch answer: ask for it individually to tell "deleted" from "hidden".
                        $issue = $client->getIssue($project_id, (int) $row['issue_iid']);
                    }
                    self::handleIssue($row, $issue, $client, $stats);
                } catch (GitlabException $e) {
                    if ($e->getKind() === GitlabException::NOT_FOUND) {
                        self::handleNotFound($row, $e->getMessage(), $stats);
                        continue;
                    }
                    self::recordGlobalError([$row], $e);
                    self::logError($task, $e->getMessage());
                    $stats['errors']++;
                    $stats['aborted'] = $e->isGlobal();
                    if ($stats['aborted']) {
                        break 2;
                    }
                } catch (Throwable $e) {
                    // A problem with one ticket must not block the others.
                    self::recordFailure($row, $e->getMessage());
                    self::logError($task, sprintf('Link #%d: %s', $row['id'], $e->getMessage()));
                    $stats['errors']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Check a single link on demand (the "Refresh" button).
     *
     * @return string Human readable result
     */
    public static function refresh(Link $link): string
    {
        $client = GitlabClient::fromSettings();
        $row    = $link->fields;
        $stats  = ['checked' => 1, 'closed' => 0, 'moved' => 0, 'missing' => 0, 'errors' => 0, 'aborted' => false];

        if ($row['state'] !== Link::STATE_OPENED) {
            return Link::getStateLabel($row['state']);
        }

        try {
            $issue = $client->getIssue((int) $row['gitlab_project_id'], (int) $row['issue_iid']);
            self::handleIssue($row, $issue, $client, $stats);
        } catch (GitlabException $e) {
            if ($e->getKind() !== GitlabException::NOT_FOUND) {
                throw $e;
            }
            self::handleNotFound($row, $e->getMessage(), $stats);
        }

        $link->getFromDB((int) $row['id']);

        return Link::getStateLabel($link->fields['state']);
    }

    // ---------------------------------------------------------------- handlers

    /**
     * @param array<string, mixed> $row   glpi_plugin_gitlabissues_links row
     * @param array<string, mixed> $issue GitLab issue
     * @param array<string, int|bool> $stats
     */
    private static function handleIssue(array $row, array $issue, GitlabClient $client, array &$stats): void
    {
        $link = new Link();
        $link->getFromDB((int) $row['id']);

        $common = [
            'id'              => (int) $row['id'],
            'date_last_check' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'error_count'     => 0,
            'last_error'      => null,
        ];

        // Title kept in sync: it is displayed in the ticket tab.
        if (!empty($issue['title'])) {
            $common['issue_title'] = mb_substr((string) $issue['title'], 0, 255);
        }

        if (($issue['state'] ?? 'opened') !== 'closed') {
            $link->update($common);

            return;
        }

        // A closed issue with "moved_to_id" was moved to another project: it was not resolved.
        if (!empty($issue['moved_to_id'])) {
            $link->update($common + ['state' => Link::STATE_MOVED]);
            self::addFollowup((int) $row['tickets_id'], self::movedMessage($row), true, (int) $row['users_id']);
            $stats['moved']++;

            return;
        }

        self::processClosure($link, $issue, $client);
        $stats['closed']++;
    }

    /**
     * The issue is closed: run the configured actions on the ticket, atomically with the
     * state change, so a failure leaves the link "opened" and the whole thing is retried.
     */
    private static function processClosure(Link $link, array $issue, GitlabClient $client): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row        = $link->fields;
        $settings   = Settings::all();
        $merge_reqs = $client->getClosingMergeRequests((int) $row['gitlab_project_id'], (int) $row['issue_iid']);
        $closed_by  = self::closedByName($issue);
        $closed_at  = self::toLocalDateTime((string) ($issue['closed_at'] ?? ''));
        $actor      = (int) $settings['actor_users_id'] ?: (int) $row['users_id'];

        $DB->beginTransaction();
        try {
            $link->update([
                'id'              => (int) $row['id'],
                'state'           => Link::STATE_CLOSED,
                'date_closed'     => $closed_at,
                'closed_by'       => mb_substr($closed_by, 0, 255),
                'date_last_check' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                'error_count'     => 0,
                'last_error'      => null,
            ]);

            $ticket = new Ticket();
            if ($ticket->getFromDB((int) $row['tickets_id']) && !$ticket->fields['is_deleted']) {
                $status = (int) $ticket->fields['status'];

                if ($status !== Ticket::CLOSED) {
                    if ($settings['close_followup'] === '1') {
                        $private = $settings['close_followup_private'] === '1';
                        self::addFollowup(
                            $ticket->getID(),
                            self::closedMessage($row, $issue, $closed_by, $closed_at, $merge_reqs),
                            $private,
                            $actor,
                            true
                        );
                    }

                    if ($settings['close_solve_ticket'] === '1' && $status !== Ticket::SOLVED) {
                        self::solveTicket($ticket, $row, $issue, $closed_by, $closed_at, $merge_reqs, $settings, $actor);
                    }
                }
            }

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    private static function handleNotFound(array $row, string $message, array &$stats): void
    {
        $link = new Link();
        $link->getFromDB((int) $row['id']);

        $count  = (int) $row['error_count'] + 1;
        $update = [
            'id'              => (int) $row['id'],
            'date_last_check' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'error_count'     => $count,
            'last_error'      => mb_substr($message, 0, 1000),
        ];

        // A single 404 can be a permission glitch: only give up after several consecutive ones.
        if ($count >= self::MAX_NOT_FOUND) {
            $update['state'] = Link::STATE_MISSING;
            $link->update($update);
            self::addFollowup(
                (int) $row['tickets_id'],
                '<p>' . sprintf(
                    __('The GitLab issue %s can no longer be found (deleted, or access removed). It is not followed anymore.', 'gitlabissues'),
                    self::issueLink($row)
                ) . '</p>',
                true,
                (int) $row['users_id']
            );
            $stats['missing']++;

            return;
        }

        $link->update($update);
        $stats['errors']++;
    }

    // ----------------------------------------------------------- ticket actions

    /**
     * Add a followup to the ticket. Failures are logged and only rethrown when $strict.
     */
    private static function addFollowup(int $tickets_id, string $html, bool $private, int $users_id, bool $strict = false): void
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id) || $ticket->fields['is_deleted'] || (int) $ticket->fields['status'] === Ticket::CLOSED) {
            return;
        }

        $id = (new ITILFollowup())->add([
            'itemtype'   => 'Ticket',
            'items_id'   => $tickets_id,
            'content'    => $html,
            'is_private' => $private ? 1 : 0,
            'users_id'   => $users_id,
        ]);

        if ($id === false) {
            $msg = sprintf('Could not add followup to ticket %d', $tickets_id);
            Toolbox::logInFile('gitlabissues', $msg . "\n");
            if ($strict) {
                throw new \RuntimeException($msg);
            }
        }
    }

    private static function solveTicket(
        Ticket $ticket,
        array $row,
        array $issue,
        string $closed_by,
        string $closed_at,
        array $merge_reqs,
        array $settings,
        int $actor
    ): void {
        $template = trim($settings['solution_template']);
        if ($template === '') {
            $content = self::closedMessage($row, $issue, $closed_by, $closed_at, $merge_reqs);
        } else {
            // Placeholders are replaced with escaped values: the template itself is trusted HTML/text.
            $content = strtr($template, [
                '{issue_url}'   => htmlspecialchars((string) $row['issue_url'], ENT_QUOTES, 'UTF-8'),
                '{issue}'       => htmlspecialchars($row['project_path'] . '#' . $row['issue_iid'], ENT_QUOTES, 'UTF-8'),
                '{closed_by}'   => htmlspecialchars($closed_by, ENT_QUOTES, 'UTF-8'),
                '{closed_at}'   => htmlspecialchars(\Html::convDateTime($closed_at), ENT_QUOTES, 'UTF-8'),
                '{ticket_id}'   => (string) $ticket->getID(),
            ]);
            if (!\Glpi\RichText\RichText::isRichTextHtmlContent($content)) {
                $content = '<p>' . nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')) . '</p>';
            }
        }

        $input = [
            'itemtype' => 'Ticket',
            'items_id' => $ticket->getID(),
            'content'  => $content,
            'users_id' => $actor,
        ];
        if ((int) $settings['solutiontypes_id'] > 0) {
            $input['solutiontypes_id'] = (int) $settings['solutiontypes_id'];
        }

        if ((new ITILSolution())->add($input) === false) {
            throw new \RuntimeException(sprintf('Could not add the solution to ticket %d', $ticket->getID()));
        }
    }

    // ---------------------------------------------------------------- messages

    private static function issueLink(array $row): string
    {
        $label = htmlspecialchars($row['project_path'] . '#' . $row['issue_iid'], ENT_QUOTES, 'UTF-8');
        $url   = self::safeUrl((string) $row['issue_url']);

        return $url !== '' ? '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>' : $label;
    }

    private static function closedMessage(array $row, array $issue, string $closed_by, string $closed_at, array $merge_reqs): string
    {
        $when = $closed_at !== '' ? \Html::convDateTime($closed_at) : '';
        $who  = $closed_by !== '' ? htmlspecialchars($closed_by, ENT_QUOTES, 'UTF-8') : '';

        $text = sprintf(__('The GitLab issue %s has been closed', 'gitlabissues'), self::issueLink($row));
        if ($who !== '') {
            $text .= ' ' . sprintf(__('by %s', 'gitlabissues'), $who);
        }
        if ($when !== '') {
            $text .= ' ' . sprintf(__('on %s', 'gitlabissues'), htmlspecialchars($when, ENT_QUOTES, 'UTF-8'));
        }
        $html = '<p>' . $text . '.</p>';

        $links = [];
        foreach ($merge_reqs as $mr) {
            $url = self::safeUrl((string) ($mr['web_url'] ?? ''));
            if ($url !== '') {
                $title   = htmlspecialchars((string) ($mr['title'] ?? ('!' . ($mr['iid'] ?? ''))), ENT_QUOTES, 'UTF-8');
                $links[] = '<li><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">!' . (int) ($mr['iid'] ?? 0) . ' ' . $title . '</a></li>';
            }
        }
        if ($links !== []) {
            $html .= '<p>' . __('Closing merge request(s):', 'gitlabissues') . '</p><ul>' . implode('', $links) . '</ul>';
        }

        return $html;
    }

    private static function movedMessage(array $row): string
    {
        return '<p>' . sprintf(
            __('The GitLab issue %s was moved to another project. It is not followed anymore: the ticket is left unchanged.', 'gitlabissues'),
            self::issueLink($row)
        ) . '</p>';
    }

    // ----------------------------------------------------------------- helpers

    private static function closedByName(array $issue): string
    {
        $user = $issue['closed_by'] ?? null;
        if (is_array($user)) {
            return (string) ($user['name'] ?? $user['username'] ?? '');
        }

        return '';
    }

    /**
     * GitLab dates are ISO 8601 (UTC); GLPI stores local server time.
     */
    private static function toLocalDateTime(string $iso): string
    {
        $time = $iso !== '' ? strtotime($iso) : false;

        return $time !== false ? date('Y-m-d H:i:s', $time) : date('Y-m-d H:i:s');
    }

    private static function safeUrl(string $url): string
    {
        return preg_match('#^https?://#i', $url) ? $url : '';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function recordGlobalError(array $rows, GitlabException $e): void
    {
        foreach ($rows as $row) {
            (new Link())->update([
                'id'              => (int) $row['id'],
                'date_last_check' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                'last_error'      => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    }

    private static function recordFailure(array $row, string $message): void
    {
        (new Link())->update([
            'id'              => (int) $row['id'],
            'date_last_check' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            // error_count is reserved to consecutive "not found" answers (see handleNotFound()).
            'last_error'      => mb_substr($message, 0, 1000),
        ]);
    }

    private static function logError(?CronTask $task, string $message): void
    {
        Toolbox::logInFile('gitlabissues', $message . "\n");
        $task?->log($message);
    }
}
