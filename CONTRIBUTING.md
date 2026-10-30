# Contributing

Bug reports and pull requests are welcome.

## Before opening an issue
Give the GLPI version, the plugin version, the GitLab version (or gitlab.com), and the relevant lines of
`files/_log/gitlabissues.log` and `files/_log/php-errors.log`. Remove tokens and personal data first.

## Pull requests
- Target GLPI 11.0.x. Keep the code PSR-12 style, with PHP 8.2+ features allowed.
- User-visible strings go through `__('…', 'gitlabissues')`; add the French translation in `locales/fr_FR.po` and recompile `fr_FR.mo` (`msgfmt`).
- Add or update tests in `tests/` (`run_all.sh` needs a GLPI 11 test instance, MariaDB and the GitLab API mock `tests/mock_gitlab.py`).
- Installation code in `hook.php` runs again on every update: keep it idempotent and never overwrite administrators' settings or rights.

## Releasing (maintainers)
Bump `PLUGIN_GITLABISSUES_VERSION` in `setup.php`, add the version (compatibility and `download_url`) to `gitlabissues.xml`,
update `CHANGELOG.md`, then publish a GitHub release whose tag equals the version. The release workflow builds and attaches
`glpi-gitlabissues-<version>.tar.bz2` and `.zip`.

## License
By contributing you agree that your contribution is licensed under AGPL-3.0-or-later.
