# GitLab Issues – GLPI 11 plugin

[Français : README.fr.md](README.fr.md)

Create a GitLab issue from a GLPI ticket, then **check every 5 minutes** (GLPI automatic action) whether the issue was closed.
When it is, the plugin acts on the ticket **through GLPI's own objects** (followup, solution), so history, business rules,
notifications and other automatic actions (auto-close of solved tickets, satisfaction surveys…) run as for a manual action.

Tested on **GLPI 11.0.11** (PHP 8.3, MariaDB 10.11) against a mock of the GitLab API v4. License: AGPL-3.0-or-later.

## Install
1. Put the `gitlabissues` folder in GLPI's `plugins/` directory, then install and activate it in *Setup > Plugins*.
2. Make sure GLPI's system cron runs (`* * * * * php /path/to/glpi/front/cron.php`); the automatic action `CheckIssues` is in CLI mode.
3. *Setup > Plugins > GitLab Issues*: GitLab URL and access token (scope **`api`**, role **Reporter** or higher; stored encrypted),
   default project, behaviour when an issue is closed. "Save and test the connection" checks URL, token and scopes.

## Use
- Ticket tab **GitLab issues**: pick a project, optional extra labels, choose the followup (private / public / none), create the issue.
- The issue contains the ticket details, the description converted to Markdown, and the inline images and attachments uploaded to the project.
- When the issue is closed: followup (who closed it, when, closing merge requests) and/or ticket solution with a customisable text.

| Situation | Behaviour |
|---|---|
| Issue moved to another project | Ticket not solved; private informational followup; no longer followed |
| Issue not found (deleted, access removed) | Given up after 3 consecutive checks; private informational followup |
| Ticket already closed / in trash | Link marked closed, ticket untouched |
| GitLab unreachable, token refused, rate limit | Run stops cleanly, error shown in the tab, retried at the next run |
| Failure while acting on the ticket | Everything rolled back; issue stays "open" and is retried, no duplicates |

## Rights
*Administration > Profiles > (profile) > GitLab issues*: read, create, refresh, unlink. The configuration page requires GLPI's *Setup > Update* right.
Rights are checked server-side on every action; forms use GLPI's CSRF protection.

## Update
Replace the folder, clear GLPI's cache (`php bin/console cache:clear`), then in *Setup > Plugins* click *Update* and *Activate*
(or `php bin/console plugin:install gitlabissues --username=<admin>` then `plugin:activate gitlabissues`).
Settings, token, existing links, the automatic action and per-profile rights are preserved.

## Known limits
- Ticket → issue only; comments are not synchronised. An issue reopened after being closed is no longer followed.
- Attachments of followups are not copied (only the ticket's own and the images of its description).
- Detection delay is about 5 minutes (polling, no webhook).

## Tests (`tests/`)
`html_to_markdown_test.php` (unit), `mock_gitlab.py` (GitLab API mock), `integration.php` (real GLPI, CLI, real cron),
`http_test.py` (real web app: login, tab, configuration, creation, rights, CSRF), `upgrade_test.sh`, `run_all.sh`.
Not tested: a real GitLab server, and the project search widget (select2) in a real browser.
