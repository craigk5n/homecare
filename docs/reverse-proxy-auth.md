# Reverse Proxy Authentication (HC-143)

HomeCare can delegate authentication to a trusted reverse proxy. When
enabled, the proxy handles login, MFA, and session management; HomeCare
reads a header (default `X-Forwarded-User`) to identify the user and
skips its own login form, TOTP gate, and password-policy checks.

## Prerequisites

- The proxy **must** set the configured header on every request it
  forwards to HomeCare.
- Each header value must match an existing `hc_user.login` in the
  database. HomeCare does **not** auto-provision accounts; create users
  via the admin UI before switching modes.
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

- **No auto-provision**: Users must exist in `hc_user` before they can
  authenticate via the proxy. This prevents an accidental
  misconfiguration from creating ghost accounts.

- **Disabled accounts**: If `hc_user.enabled = 'N'`, the proxy header
  is accepted but HomeCare returns a 401. The proxy's own account
  management and HomeCare's user table must stay in sync.

- **Session timeout**: In reverse-proxy mode, HomeCare's idle timeout
  (HC-012) is bypassed — the proxy owns session lifetime. Configure
  session TTL in your proxy instead.

- **TOTP / password policy**: Both are bypassed in reverse-proxy mode.
  Configure MFA in the proxy (Authelia/Authentik natively support TOTP,
  WebAuthn, and push).
