# Security

The plugin is for trusted, authenticated Kirby administrators. It does not grant
impersonation access to other roles and never accepts Kirby's virtual identities.

Application actions are enabled by default. Configure restrictions for each site,
especially authentication actions and mutating GET routes. Read-only mode applies
to requests carrying `as`; it does not sandbox the admin's entire login session.

The plugin does not replace endpoint-level authorization or CSRF protection.
Sensitive data must be protected in the application, not only hidden in templates.

Please report suspected vulnerabilities privately to **ciao@iperstudio.net**.
Include the plugin and Kirby versions and a minimal reproduction without real
credentials, session cookies or customer data.
