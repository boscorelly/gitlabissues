#!/bin/sh
# Simulates an update from an older installed version and checks nothing is lost. Run from the GLPI root.
set -e
Q="mysql -uroot glpi -N -e"
$Q "update glpi_profilerights set rights=1 where name='plugin_gitlabissues_issue' and profiles_id=4; delete from glpi_configs where context='plugin:gitlabissues' and name='creation_followup_private'; update glpi_plugins set version='0.9.0' where directory='gitlabissues';"
rm -rf files/_cache/*
php bin/console plugin:install gitlabissues --username=glpi --allow-superuser -n >/dev/null 2>&1 || true   # first call only marks it "to update"
php bin/console plugin:install gitlabissues --username=glpi --allow-superuser -n 2>&1 | grep -c "installé" >/dev/null
php bin/console plugin:activate gitlabissues --allow-superuser -n >/dev/null 2>&1
echo "state/version : $($Q "select state,version from glpi_plugins where directory='gitlabissues'")  (expected: 1 and the current version)"
echo "custom rights kept : $($Q "select rights from glpi_profilerights where name='plugin_gitlabissues_issue' and profiles_id=4")  (expected 1)"
echo "cron tasks : $($Q "select count(*) from glpi_crontasks where itemtype like '%Gitlabissues%'")  (expected 1)"
echo "new setting added : $($Q "select count(*) from glpi_configs where context='plugin:gitlabissues' and name='creation_followup_private'")  (expected 1)"
echo "links kept : $($Q "select count(*) from glpi_plugin_gitlabissues_links")"
