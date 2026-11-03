<?php

namespace GlpiPlugin\Gitlabissues\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Gitlabissues\ConfigPage;
use GlpiPlugin\Gitlabissues\ConfigTabs;
use GlpiPlugin\Gitlabissues\GitlabClient;
use GlpiPlugin\Gitlabissues\GitlabException;
use GlpiPlugin\Gitlabissues\Link;
use GlpiPlugin\Gitlabissues\Settings;
use Html;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Configuration page: GLPI's standard page with the tabs on the left (ConfigPage / ConfigTabs).
 * Each tab is a form of its own and only saves the settings of its zone.
 */
final class ConfigController extends AbstractController
{
    /** Settings of each tab: what a form may change (anything else posted is ignored). */
    private const FIELDS = [
        'connection' => ['gitlab_url', 'gitlab_token'],
        'creation'   => ['default_project_id', 'default_project_path', 'title_template', 'default_labels', 'confidential',
            'include_people', 'upload_attachments', 'max_attachment_mb', 'creation_followup', 'creation_followup_private',
            'notify_requester', 'notify_requester_text'],
        'closure'    => ['close_followup', 'close_followup_private', 'notify_requester_close_text', 'solutiontypes_id',
            'solution_template', 'actor_users_id'],
    ];

    /** Checkboxes (absent from the POST when unticked) and numbers of each tab. */
    private const FLAGS = [
        'creation' => ['confidential', 'include_people', 'upload_attachments', 'creation_followup', 'creation_followup_private', 'notify_requester'],
        'closure'  => ['close_followup', 'close_followup_private'],
    ];
    private const NUMBERS = [
        'creation' => ['default_project_id', 'max_attachment_mb'],
        'closure'  => ['solutiontypes_id', 'actor_users_id'],
    ];

    #[Route('/Config', name: 'gitlabissues_config', methods: ['GET'])]
    public function show(): Response
    {
        $this->checkRight();

        // Same rendering as GLPI's own pages with tabs.
        ob_start();
        Html::header(ConfigPage::getTypeName(), '', 'config', 'plugin');
        (new ConfigPage())->display(['id' => 0]);
        Html::footer();

        return new Response((string) ob_get_clean());
    }

    #[Route('/Config', name: 'gitlabissues_config_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->checkRight();

        $tab = (string) $request->request->get('_tab', '');
        if (!isset(self::FIELDS[$tab])) {
            return $this->backTo('connection');
        }
        // Only the settings of this tab, whatever else was posted; and all of them valid, or nothing is saved.
        $posted = $request->request->all();
        $input  = [];
        foreach (self::FIELDS[$tab] as $name) {
            if (!array_key_exists($name, $posted)) {
                continue;
            }
            if (!is_scalar($posted[$name])) {
                return $this->invalid($tab);
            }
            $input[$name] = (string) $posted[$name];
        }
        foreach (self::NUMBERS[$tab] ?? [] as $number) {
            if (isset($input[$number]) && !preg_match('/^\d{0,9}$/', trim($input[$number]))) {
                return $this->invalid($tab);
            }
        }

        // Checkboxes are absent from the POST when unchecked.
        foreach (self::FLAGS[$tab] ?? [] as $flag) {
            $input[$flag] = !empty($input[$flag]) ? '1' : '0';
        }
        foreach (self::NUMBERS[$tab] ?? [] as $number) {
            $input[$number] = (string) max(0, (int) ($input[$number] ?? 0));
        }

        if ($tab === 'creation') {
            if ((int) $input['max_attachment_mb'] < 1) {
                $input['max_attachment_mb'] = '10';
            }
            if ($input['default_project_id'] === '0') {
                $input['default_project_path'] = '';
            }
        }

        if ($tab === 'closure') {
            // One choice for the requester at closure; stored in the two existing settings (nothing to migrate).
            $mode = $posted['requester_close_mode'] ?? null;
            if (!in_array($mode, ['none', 'followup', 'solve'], true)) {
                return $this->invalid($tab);
            }
            $input['close_solve_ticket']     = $mode === 'solve' ? '1' : '0';
            $input['notify_requester_close'] = $mode === 'followup' ? '1' : '0';
        }

        if ($tab === 'connection') {
            if (!empty($input['gitlab_url']) && !preg_match('#^https?://#i', (string) $input['gitlab_url'])) {
                Session::addMessageAfterRedirect(__('The GitLab URL must start with http:// or https://', 'gitlabissues'), false, ERROR);

                return $this->backTo($tab);
            }
            // The stored token must never be sent to another server than the one it was entered for: someone
            // with the "config" right but who does not know the token could otherwise point the URL to his own
            // server and click "test". Changing the URL therefore requires typing the token again.
            $stored = Settings::all();
            if (
                isset($input['gitlab_url'])
                && rtrim(trim((string) $input['gitlab_url']), '/') !== $stored['gitlab_url']
                && $stored['gitlab_token'] !== ''
                && trim((string) ($input['gitlab_token'] ?? '')) === ''
            ) {
                Session::addMessageAfterRedirect(__('The GitLab URL has changed: enter the access token again. Nothing was saved.', 'gitlabissues'), false, ERROR);

                return $this->backTo($tab);
            }
        }

        Settings::save($input);

        if ($tab === 'connection' && $request->request->has('_test')) {
            $this->testConnection();
        } else {
            Session::addMessageAfterRedirect(__('Settings saved.', 'gitlabissues'));
        }

        return $this->backTo($tab);
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
        $central = array_column(ConfigTabs::profiles(), 'id');
        foreach (array_map('intval', $request->request->all('profiles')) as $profiles_id) {
            // Only the profiles that were displayed (central interface) can be changed.
            if (in_array($profiles_id, $central, true)) {
                Link::saveProfileRights($profiles_id, (array) ($ticked[$profiles_id] ?? []));
            }
        }
        Session::addMessageAfterRedirect(__('Rights updated.', 'gitlabissues'));

        return $this->backTo('rights');
    }

    /**
     * Refuse the whole form: a partly applied invalid form would silently overwrite good settings.
     */
    private function invalid(string $tab): Response
    {
        Session::addMessageAfterRedirect(__('Invalid form data. Nothing was saved.', 'gitlabissues'), false, ERROR);

        return $this->backTo($tab);
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
        if (!ConfigPage::canManage()) {
            throw new AccessDeniedHttpException();
        }
    }

    /**
     * Back to the page, on the tab that was just saved: GLPI reopens the tab remembered in the session.
     */
    private function backTo(string $tab): Response
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $number = ConfigTabs::TAB_NAMES[$tab] ?? ConfigTabs::CONNECTION;
        Session::setActiveTab(ConfigPage::class, ConfigTabs::class . '$' . $number);

        return new RedirectResponse(($CFG_GLPI['root_doc'] ?? '') . '/plugins/gitlabissues/Config');
    }
}
