<?php

namespace GlpiPlugin\Gitlabissues\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Gitlabissues\GitlabClient;
use GlpiPlugin\Gitlabissues\GitlabException;
use GlpiPlugin\Gitlabissues\Link;
use GlpiPlugin\Gitlabissues\RequesterNotice;
use ProfileRight;
use GlpiPlugin\Gitlabissues\Settings;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigController extends AbstractController
{
    #[Route('/Config', name: 'gitlabissues_config', methods: ['GET'])]
    public function show(): Response
    {
        $this->checkRight();
        $settings = Settings::all();
        $has_token = $settings['gitlab_token'] !== '';
        unset($settings['gitlab_token']); // never sent back to the browser

        return $this->render('@gitlabissues/config.html.twig', [
            'settings'  => $settings,
            'has_token' => $has_token,
            'form_url'  => $this->url('/Config'),
            'projects_url' => $this->url('/Projects'),
            'solution_types' => $this->solutionTypes(),
            'users'     => $this->users($settings['actor_users_id']),
            'default_notice_creation' => RequesterNotice::defaultText(RequesterNotice::CREATION),
            'default_notice_close' => RequesterNotice::defaultText(RequesterNotice::CLOSURE),
            'profiles'  => $this->profiles(),
            'right_labels' => (new Link())->getRights(),
            'can_edit_rights' => Session::haveRight('profile', UPDATE),
            'rights_url' => $this->url('/Config/Rights'),
        ]);
    }

    #[Route('/Config', name: 'gitlabissues_config_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->checkRight();
        $input = $request->request->all();

        // Checkboxes are absent from the POST when unchecked.
        foreach (['confidential', 'include_people', 'upload_attachments', 'creation_followup', 'creation_followup_private', 'notify_requester', 'notify_requester_close', 'close_followup', 'close_followup_private', 'close_solve_ticket'] as $flag) {
            $input[$flag] = !empty($input[$flag]) ? '1' : '0';
        }
        foreach (['max_attachment_mb', 'solutiontypes_id', 'actor_users_id', 'default_project_id'] as $number) {
            $input[$number] = (string) max(0, (int) ($input[$number] ?? 0));
        }
        if ((int) $input['max_attachment_mb'] < 1) {
            $input['max_attachment_mb'] = '10';
        }
        if (!empty($input['gitlab_url']) && !preg_match('#^https?://#i', (string) $input['gitlab_url'])) {
            Session::addMessageAfterRedirect(__('The GitLab URL must start with http:// or https://', 'gitlabissues'), false, ERROR);

            return new RedirectResponse($this->url('/Config'));
        }
        if (($input['default_project_id'] ?? '0') === '0') {
            $input['default_project_path'] = '';
        }

        Settings::save($input);

        if (isset($input['_test'])) {
            $this->testConnection();
        } else {
            Session::addMessageAfterRedirect(__('Settings saved.', 'gitlabissues'));
        }

        return new RedirectResponse($this->url('/Config'));
    }

    /**
     * Rights per profile, edited from the configuration page (same data as the "GitLab issues"
     * tab of each profile). Needs both the "config" and the "profile" update rights.
     */
    #[Route('/Config/Rights', name: 'gitlabissues_config_rights', methods: ['POST'])]
    public function saveRights(Request $request): Response
    {
        $this->checkRight();
        if (!Session::haveRight('profile', UPDATE)) {
            throw new AccessDeniedHttpException();
        }

        $ticked  = $request->request->all('rights');   // [profiles_id => [right, ...]]
        $central = array_column($this->profiles(), 'id');
        foreach (array_map('intval', $request->request->all('profiles')) as $profiles_id) {
            // Only the profiles that were displayed (central interface) can be changed.
            if (in_array($profiles_id, $central, true)) {
                Link::saveProfileRights($profiles_id, (array) ($ticked[$profiles_id] ?? []));
            }
        }
        Session::addMessageAfterRedirect(__('Rights updated.', 'gitlabissues'));

        return new RedirectResponse($this->url('/Config'));
    }

    /**
     * Profiles of the central (technician) interface with their current plugin rights.
     *
     * @return array<int, array{id: int, name: string, value: int}>
     */
    private function profiles(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $profiles = [];
        foreach ($DB->request(['FROM' => 'glpi_profiles', 'WHERE' => ['interface' => 'central'], 'ORDER' => 'name']) as $row) {
            $rights     = ProfileRight::getProfileRights((int) $row['id'], [Link::$rightname]);
            $profiles[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'value' => (int) ($rights[Link::$rightname] ?? 0)];
        }

        return $profiles;
    }

    private function testConnection(): void
    {
        try {
            $res = GitlabClient::fromSettings()->testConnection();
            Session::addMessageAfterRedirect(
                htmlspecialchars(sprintf(
                    __('Connection successful: GitLab %1$s, authenticated as %2$s.', 'gitlabissues'),
                    $res['version'],
                    $res['user']
                )),
                false,
                INFO
            );
            if ($res['scopes'] !== null && !in_array('api', $res['scopes'], true)) {
                Session::addMessageAfterRedirect(
                    __('This token has no "api" scope: it can read issues but not create them.', 'gitlabissues'),
                    false,
                    WARNING
                );
            }
        } catch (GitlabException $e) {
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
        }
    }

    private function checkRight(): void
    {
        if (!Session::haveRight('config', UPDATE)) {
            throw new AccessDeniedHttpException();
        }
    }

    private function url(string $path): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/gitlabissues' . $path;
    }

    /**
     * @return array<int, string>
     */
    private function solutionTypes(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $types = [];
        foreach ($DB->request(['FROM' => 'glpi_solutiontypes', 'ORDER' => 'name']) as $row) {
            $types[(int) $row['id']] = $row['name'];
        }

        return $types;
    }

    /**
     * Active users able to work on tickets (candidates for "author of the automatic actions").
     *
     * @return array<int, string>
     */
    private function users(string $selected): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $users = [];
        foreach ($DB->request(['FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0], 'ORDER' => 'name', 'LIMIT' => 500]) as $row) {
            $users[(int) $row['id']] = getUserName((int) $row['id']);
        }

        return $users;
    }
}
