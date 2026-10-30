# Security policy

## Supported versions
Only the latest release is supported.

## Reporting a vulnerability
Please **do not open a public issue** for a security problem. Use GitHub's private reporting:
the **Security** tab of this repository > **Report a vulnerability**.

Include the plugin and GLPI versions, and the steps to reproduce. You will get an answer as soon as possible.

Never post a GitLab access token or a GLPI session in an issue, a log or a screenshot. If you did, revoke the token immediately.

## Scope notes
- The GitLab token is stored encrypted with GLPI's key and is never sent back to the browser.
- Rights are checked server-side on every action; forms use GLPI's CSRF protection.
