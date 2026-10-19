# Reverse Proxy Authentication (HC-143)

HomeCare can delegate authentication to a trusted reverse proxy. When
enabled, the proxy handles login, MFA, and session management; HomeCare
reads a header (default `X-Forwarded-User`) to identify the user and
skips its own login form, TOTP gate, and password-policy checks.

## Prerequisites

- The proxy **must** set the configured header on every request it
  forwards to HomeCare.
- By default each header value must match an existing `hc_user.login`
  in the database; create users before switching modes. Alternatively,
  turn on [auto-provisioning](#auto-provisioning-and-group-mapping-hc-243)
  so first-time users are created from the proxy's headers.
- The proxy must strip the trusted header from untrusted (external)
  requests to prevent header injection.

## Enabling

1. Go to **Settings** (gear icon) as an admin user.
2. Under **Authentication Mode**, select **Reverse Proxy**.
3. Set the **Proxy Header** to match your proxy's configuration
   (default: `X-Forwarded-User`).
4. Click **Save authentication settings**.

Once saved, `login.php` and `logout.php` redirect to `index.php` (the
proxy's own login/logout gates handle those flows).

To revert, set the mode back to **Native** via the database:

```sql
UPDATE hc_config SET value = 'native' WHERE setting = 'auth_mode';
```

## Auto-provisioning and group mapping (HC-243)

Off by default. When **Create HomeCare users automatically** is ticked
(Settings → Authentication Mode), a request whose proxy header names an
unknown login creates that user on the spot:

- **Role** comes from the groups header (default `Remote-Groups`).
  Configure comma-separated group names for each of admin, caregiver
  and viewer. Groups in the header may be separated by `|` (Authentik)
  or `,` (Authelia); surrounding whitespace is ignored and matching is
  case-insensitive. Group names containing `|` or `,` can't be mapped.
  If the user is in groups for several roles, the **highest role wins**
  (admin > caregiver > viewer).
- **No matching group** → the configured fallback: viewer (default),
  caregiver, or *Refuse sign-in* (401, no account created). Admin is
  never a fallback; it must come from an explicit group.
- **Login** must be 1–25 characters (the width of `hc_user.login`) of
  `A–Z a–z 0–9 . _ @ + -`, starting with a letter or digit. Anything
  else is refused with a 401 and nothing is created. (Existing users
  with other logins keep working; the check only gates creation.)
- **Name / email** are copied from the optional name and email headers
  when configured. The name is split on the first space into first and
  last name (each clipped to 25 characters); an email that isn't a valid
  address of ≤ 75 characters is dropped.
- The account is **enabled** and has **no password** (`passwd` is
  NULL, which the native login never accepts).
- Creation is recorded in the audit log as `user.auto_provisioned`
  (login, role, groups).

### Role sync

**Update every user's role from their groups on each request** is a
separate setting, also off by default.

- **Off**: the role is chosen once, when the account is created. An
  admin can change it afterwards and the change sticks.
- **On**: on every request the role of *every* proxy-authenticated user
  (including users created by hand) is re-derived from the groups
  header using the same mapping and fallback. Changes are audit-logged
  as `user.role_synced` (old and new role). With *Refuse sign-in*, a
  user whose groups no longer match anything is refused (their stored
  role is left as it was).

Sync works with or without auto-provisioning.

### Disabled users

A user with `hc_user.enabled = 'N'` is always refused, whatever the
groups say. Neither auto-provisioning nor role sync ever re-enables an
account, so disabling a user in HomeCare remains an effective block.

### Recovering from a lock-out

If a mapping mistake locks everyone out (e.g. sync on, wrong groups
header, *Refuse sign-in*), turn the features off in the database:

```sql
UPDATE hc_config SET value = 'N' WHERE setting = 'reverse_proxy_sync_role';
UPDATE hc_config SET value = 'viewer' WHERE setting = 'reverse_proxy_default_role';
UPDATE hc_user SET role = 'admin' WHERE login = 'your-login';
```

## Sample Configurations

### Authelia

```yaml
# authelia/configuration.yml
access_control:
  default_policy: deny
  rules:
    - domain: homecare.example.com
      policy: two_factor

# Authelia sends these headers by default:
#   Remote-User, Remote-Name, Remote-Email, Remote-Groups
# Set HomeCare's Proxy Header to: Remote-User
# For auto-provisioning: Groups Header Remote-Groups (comma-separated),
# Name Header Remote-Name, Email Header Remote-Email.
```

Traefik label (Docker Compose):

```yaml
services:
  homecare:
    labels:
      - "traefik.http.routers.homecare.middlewares=authelia@docker"
```

### Authentik

```yaml
# Authentik proxy provider configuration:
#   External host: https://homecare.example.com
#   Mode: Forward auth (single application)
#
# Authentik sets X-authentik-username by default.
# Set HomeCare's Proxy Header to: X-authentik-username
```

With auto-provisioning (see below), Authentik's other forward-auth
headers fill in the rest. Example HomeCare settings:

| Setting            | Value                       |
|--------------------|-----------------------------|
| Proxy Header       | `X-authentik-username`      |
| Auto-provisioning  | on                          |
| Groups Header      | `X-authentik-groups`        |
| Admin groups       | `homecare-admins`           |
| Caregiver groups   | `homecare-family, carers`   |
| Viewer groups      | `homecare-viewers`          |
| When no group matches | Refuse sign-in           |
| Name Header        | `X-authentik-name`          |
| Email Header       | `X-authentik-email`         |

Authentik sends groups `|`-separated, e.g.
`X-authentik-groups: authentik Admins|homecare-family`; that user is
created as a caregiver. Bind an Authentik policy to the HomeCare
application so only the intended groups can reach it at all.

Traefik label:

```yaml
services:
  homecare:
    labels:
      - "traefik.http.routers.homecare.middlewares=authentik@docker"
```

### Caddy (with caddy-security / forward_auth)

```caddyfile
homecare.example.com {
    forward_auth authelia:9091 {
        uri /api/verify?rd=https://auth.example.com
        copy_headers Remote-User Remote-Email
    }
    reverse_proxy homecare:80
}

# Set HomeCare's Proxy Header to: Remote-User
```

### Traefik (ForwardAuth middleware)

```yaml
# traefik dynamic config
http:
  middlewares:
    authelia:
      forwardAuth:
        address: "http://authelia:9091/api/verify?rd=https://auth.example.com"
        trustForwardHeader: true
        authResponseHeaders:
          - Remote-User
          - Remote-Email

  routers:
    homecare:
      rule: "Host(`homecare.example.com`)"
      middlewares:
        - authelia
      service: homecare

  services:
    homecare:
      loadBalancer:
        servers:
          - url: "http://homecare:80"

# Set HomeCare's Proxy Header to: Remote-User
```

### Nginx (with auth_request)

```nginx
server {
    listen 443 ssl;
    server_name homecare.example.com;

    location /authelia {
        internal;
        proxy_pass http://authelia:9091/api/verify;
        proxy_set_header X-Original-URL $scheme://$http_host$request_uri;
    }

    location / {
        auth_request /authelia;
        auth_request_set $user $upstream_http_remote_user;
        proxy_set_header Remote-User $user;
        proxy_pass http://homecare:80;
    }
}

# Set HomeCare's Proxy Header to: Remote-User
```

## Security Considerations

- **Header trust**: The proxy header is trusted unconditionally. If an
  attacker can reach HomeCare directly (bypassing the proxy), they can
  forge the header and impersonate any user. Ensure HomeCare only
  listens on a network the proxy controls (e.g., a Docker bridge
  network) or bind it to `127.0.0.1` and let the proxy connect locally.

- **Auto-provisioning is off by default**: users must exist in
  `hc_user` before they can authenticate via the proxy, so a
  misconfiguration can't create ghost accounts.

- **Auto-provisioning trusts the proxy completely**: with it on,
  *anyone the proxy lets through* gets a HomeCare account. Restrict
  who can reach HomeCare in the proxy itself (Authentik application
  policy / Authelia access-control rule), and prefer *Refuse sign-in*
  for unmatched users so an account only appears for someone in a
  mapped group.

- **The groups header is as trusted as the user header**: whoever can
  forge it can pick their own role. The proxy must strip
  client-supplied copies of *all* configured headers (user, groups,
  name, email), not just the user header.

- **Role sync makes the IdP authoritative**: with sync on, a group
  change in the IdP promotes or demotes the user on their next request,
  and role changes made in HomeCare are overwritten. A groups header
  that stops arriving (proxy config change) demotes everyone to the
  fallback role — or refuses them — so test sync with a non-admin
  account first.

- **Provisioned accounts have no password**, but the forgot-password
  page still works for any account with an email address. That only
  matters if you later switch back to native mode.

- **Disabled accounts**: If `hc_user.enabled = 'N'`, the proxy header
  is accepted but HomeCare returns a 401. The proxy's own account
  management and HomeCare's user table must stay in sync.

- **Session timeout**: In reverse-proxy mode, HomeCare's idle timeout
  (HC-012) is bypassed — the proxy owns session lifetime. Configure
  session TTL in your proxy instead.

- **TOTP / password policy**: Both are bypassed in reverse-proxy mode.
  Configure MFA in the proxy (Authelia/Authentik natively support TOTP,
  WebAuthn, and push).
