<?php

namespace GlpiPlugin\Gitlabissues\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Gitlabissues\Link;
use Profile;
use ProfileRight;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    #[Route('/Profile/{profiles_id}/Rights', name: 'gitlabissues_profile_rights', requirements: ['profiles_id' => '\d+'], methods: ['POST'])]
    public function save(Request $request, int $profiles_id): Response
    {
        // Same permission as editing the profile in GLPI itself.
        if (!Session::haveRight('profile', UPDATE)) {
            throw new AccessDeniedHttpException();
        }
        $profile = new Profile();
        if (!$profile->getFromDB($profiles_id)) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }

        $allowed = array_keys((new Link())->getRights());
        $value   = 0;
        foreach ((array) $request->request->all('rights') as $right) {
            if (in_array((int) $right, $allowed, true)) {
                $value |= (int) $right;
            }
        }
        ProfileRight::updateProfileRights($profiles_id, [Link::$rightname => $value]);
        Session::addMessageAfterRedirect(__('Rights updated.', 'gitlabissues'));

        return new RedirectResponse($request->headers->get('referer') ?: Profile::getFormURLWithID($profiles_id));
    }
}
