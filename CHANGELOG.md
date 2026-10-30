# Changelog

Release checklist: bump `PLUGIN_GITLABISSUES_VERSION` in `setup.php`, add the version (compatibility + `download_url`) to `gitlabissues.xml`, update this file, tag and publish a release with the `glpi-gitlabissues-<version>.tar.bz2` archive.

## 1.0.4
- **Languages**: English (`en_GB`, `en_US`) is now shipped next to French, with a translation template (`locales/gitlabissues.pot`) and a maintenance script (`tools/locales.py`). Language rule: **the user's language if the plugin has it, otherwise English**. Before, GLPI fell back on the instance's *default* language: on a French instance every user, English speakers included, got French.
- The neutral messages for the requester follow the same rule: the language of the interface (the technician's when he creates the issue; the instance's default language for the automatic action), English if the plugin has no catalogue for it.
- **Configuration page reorganised**: the "Inform the requester" section is gone, because it duplicated the "When the issue is closed" section. Each requester message now sits with the event it belongs to: the creation message in *Issue creation*, the closure message in *When the issue is closed on GitLab*.
- At closure the requester gets **one choice** instead of interacting options: *nothing*, *a public followup with the message*, or *the ticket is solved with the message as the solution*. The technicians' followup (GitLab details) is a separate, clearly labelled option. The custom solution text, used by older versions, moves under "Advanced".
- Nothing to migrate: the choice is stored in the existing settings, so installed configurations keep their behaviour (if both a public followup and the solution were enabled, the page shows "solved", which is what happened).

## 1.0.3
- **Inform the requester** (new section in the configuration, and a box when creating an issue): neutral public followups telling a non-technician requester that his request was forwarded to the developers, and that the technical work is complete. No GitLab detail is ever included (project, link, developer, merge request); the technical details stay in the private followups. Texts are customisable; the technician can untick the box for a given issue.
- The default text of the **solution** added when the issue is closed is now neutral (it used to name the GitLab project, the developer and the merge requests, which the requester could read). The details go to the private followup.
- The "GitLab issues" tab of profiles now shows the same GitLab icon as the ticket tab.
- Fix: the actions on the ticket are always run in the automatic action's context, also when the check is triggered by the **Refresh** button. Before, they used the rights of the technician who clicked, so a closed issue could be recorded without the ticket being solved. A failure now shows an error message instead of an error page.
- Rights hardening: every permission now requires the **Read** right, and profiles of the self-service interface are refused even if a plugin right was stored for them. Before, a profile with *Create* but without *Read* could create issues and search projects.
- Ticking a right in the profile tab now also grants **Read**.
- New table **Rights by profile** on the plugin's configuration page (*Setup > Plugins > GitLab Issues*): the rights can be managed there as well as in the "GitLab issues" tab of each profile.
- Tests: `tests/rights_test.py` checks the whole matrix (no right, read, create, update, delete, create-without-read, self-service) on the web application, including the rights shown in the tab and the refusal of forged requests.

## 1.0.2
- Fix: the update (re-run of the installation) no longer fails on profile rights and no longer resets the rights chosen by administrators.
- Document the update procedure; add `tests/upgrade_test.sh`.

## 1.0.1
- Creation followup: choose private, public or none for each issue; default set in the configuration.

## 1.0.0
- First release: create an issue from a ticket, check linked issues every 5 minutes, followup/solution when closed.
