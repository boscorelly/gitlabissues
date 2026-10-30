#!/bin/sh
# Full test run: unit tests, integration (CLI, real GLPI), HTTP (real GLPI web app). Mock GitLab must be started fresh.
set -e
D=$(dirname "$0")
php "$D/html_to_markdown_test.php" | tail -1
mysql -uroot glpi -e "update glpi_profilerights set rights=15 where name='plugin_gitlabissues_issue' and profiles_id=4; delete from glpi_plugin_gitlabissues_links; delete from glpi_itilfollowups; delete from glpi_itilsolutions;"
php "$D/integration.php" | grep -v '^  ok' | tail -12
python3 "$D/http_test.py" "$(cat /tmp/last_ticket_id)" | grep -v '^  ok' | tail -14
python3 "$D/rights_test.py" "$(cat /tmp/last_ticket_id)" | grep -v "^  ok" | tail -14
python3 "$D/visibility_test.py" | grep -v "^  ok" | tail -14
