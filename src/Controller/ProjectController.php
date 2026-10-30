<?php

namespace GlpiPlugin\Gitlabissues\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Gitlabissues\GitlabClient;
use GlpiPlugin\Gitlabissues\GitlabException;
use GlpiPlugin\Gitlabissues\Link;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectController extends AbstractController
{
    /**
     * Project search for the select2 of the ticket tab (only for users allowed to create issues).
     */
    #[Route('/Projects', name: 'gitlabissues_projects', methods: ['GET'])]
    public function search(Request $request): Response
    {
        if (!Link::canCreate()) {
            throw new AccessDeniedHttpException();
        }

        try {
            $res = GitlabClient::fromSettings()->searchProjects(
                trim((string) $request->query->get('term', '')),
                (int) $request->query->get('page', 1)
            );
        } catch (GitlabException $e) {
            return new JsonResponse(['results' => [], 'error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['results' => $res['results'], 'pagination' => ['more' => $res['more']]]);
    }
}
