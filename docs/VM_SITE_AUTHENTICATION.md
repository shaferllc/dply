# Site basic authentication

The **Authentication** section enables **HTTP basic auth** in front of the public site — useful for staging locks.

## Enable basic auth

Set:

- **Username**
- **Password** — stored securely; rotate periodically

When enabled, browsers prompt before any app login page loads.

## Scope

Applies at the **webserver vhost** layer for VM sites. Does not replace app-level auth (Laravel, etc.).

## Disable

Turn off the toggle and save to remove the auth gate on the next webserver apply.

## Related sections

- **Routing** — staging hostnames often paired with basic auth
- **Web server config** — see generated auth directives
- Edge **Preview protection** — different product (Edge previews)
