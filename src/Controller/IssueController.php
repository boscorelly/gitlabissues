<?php

namespace GlpiPlugin\Gitlabissues\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Gitlabissues\GitlabException;
use GlpiPlugin\Gitlabissues\Link;
use GlpiPlugin\Gitlabissues\Sync;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ticket;

final class IssueController extends AbstractController
{
    #[Route('/Ticket/{tickets_id}/Issue', name: 'gitlabissues_create', requirements: ['tickets_id' => '\d+'], methods: ['POST'])]
    public function create(Request $request, int $tickets_id): Response
    {
        $ticket = $this->getReadableTicket($tickets_id);
        if (!Link::canCreate()) {
            throw new AccessDeniedHttpException();
        }

        try {
            $res = Link::createFromTicket(
                $ticket,
                (int) $request->request->get('project_id', 0),
                (string) $request->request->get('labels', ''),
                (int) Session::getLoginUserID(),
                $request->request->getBoolean('allow_duplicate')
            );
            $url = htmlspecialchars((string) $res['link']->fields['issue_url'], ENT_QUOTES, 'UTF-8');
            Session::addMessageAfterRedirect(
                sprintf(__('GitLab issue created: %s', 'gitlabissues'), '<a href="' . $url . '" target="_blank" rel="noopener">'
                    . htmlspecialchars($res['link']->fields['project_path'] . '#' . $res['link']->fields['issue_iid'], ENT_QUOTES, 'UTF-8') . '</a>'),
                false,
                INFO
            );
            foreach ($res['warnings'] as $warning) {
                Session::addMessageAfterRedirect(htmlspecialchars($warning), false, WARNING);
            }
        } catch (GitlabException | \RuntimeException $e) {
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
        }

        return $this->backToTicket($tickets_id);
    }

    #[Route('/Link/{id}/Refresh', name: 'gitlabissues_refresh', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refresh(int $id): Response
    {
        $link = $this->getLink($id);
        if (!Link::canUpdate()) {
            throw new AccessDeniedHttpException();
        }
        try {
            Session::addMessageAfterRedirect(
                htmlspecialchars(sprintf(__('Status: %s', 'gitlabissues'), Sync::refresh($link))),
                false,
                INFO
            );
        } catch (GitlabException $e) {
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
        }

        return $this->backToTicket((int) $link->fields['tickets_id']);
    }

    #[Route('/Link/{id}/Unlink', name: 'gitlabissues_unlink', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unlink(int $id): Response
    {
        $link = $this->getLink($id);
        if (!Link::canDelete()) {
            throw new AccessDeniedHttpException();
        }
        $tickets_id = (int) $link->fields['tickets_id'];
        $link->delete(['id' => $id], true);
        Session::addMessageAfterRedirect(__('The issue is no longer followed. It was not changed on GitLab.', 'gitlabissues'));

        return $this->backToTicket($tickets_id);
    }

    private function getReadableTicket(int $id): Ticket
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($id)) {
            throw new NotFoundHttpException();
        }
        if (!$ticket->canViewItem()) {
            throw new AccessDeniedHttpException();
        }

        return $ticket;
    }

    /**
     * A link is only reachable by someone who can see its ticket.
     */
    private function getLink(int $id): Link
    {
        $link = new Link();
        if (!$link->getFromDB($id)) {
            throw new NotFoundHttpException();
        }
        $this->getReadableTicket((int) $link->fields['tickets_id']);

        return $link;
    }

    private function backToTicket(int $tickets_id): Response
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return new RedirectResponse(
            ($CFG_GLPI['root_doc'] ?? '') . '/front/ticket.form.php?id=' . $tickets_id
            . '&forcetab=' . rawurlencode(Link::class . '$1')
        );
    }
}
