<?php

namespace GlpiPlugin\Gitlabissues\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Gitlabissues\GitlabClient;
use GlpiPlugin\Gitlabissues\GitlabException;
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
        ]);
    }

    #[Route('/Config', name: 'gitlabissues_config_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->checkRight();
        $input = $request->request->all();

        // Checkboxes are absent from the POST when unchecked.
        foreach (['confidential', 'include_people', 'upload_attachments', 'creation_followup', 'creation_followup_private', 'close_followup', 'close_followup_private', 'close_solve_ticket'] as $flag) {
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
