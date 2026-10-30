# Changelog

Release checklist: bump `PLUGIN_GITLABISSUES_VERSION` in `setup.php`, add the version (compatibility + `download_url`) to `gitlabissues.xml`, update this file, tag and publish a release with the `glpi-gitlabissues-<version>.tar.bz2` archive.

## 1.0.2
- Fix: the update (re-run of the installation) no longer fails on profile rights and no longer resets the rights chosen by administrators.
- Document the update procedure; add `tests/upgrade_test.sh`.

## 1.0.1
- Creation followup: choose private, public or none for each issue; default set in the configuration.

## 1.0.0
- First release: create an issue from a ticket, check linked issues every 5 minutes, followup/solution when closed.
