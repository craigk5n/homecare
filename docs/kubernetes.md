# Running HomeCare on Kubernetes

This page is a complete, copy-pasteable example of running the
published HomeCare image on Kubernetes: a namespace, a MySQL 8
StatefulSet, the HomeCare web Deployment, an Ingress, CronJobs for
the CLI scripts, and an optional reverse-proxy-auth setup with a
NetworkPolicy.

It uses the same image and the same environment variables as
`docker-compose.yml` — nothing here is Kubernetes-specific inside
the container.

## The image

Release tags (`v*`) are built by `.github/workflows/docker-publish.yml`
for `linux/amd64` and `linux/arm64` and pushed to GitHub Container
Registry:

| Tag | Meaning |
| --- | --- |
| `ghcr.io/craigk5n/homecare:0.2.0` | Exact release (git tag `v0.2.0`) |
| `ghcr.io/craigk5n/homecare:0.2` | Latest patch of a minor line |
| `ghcr.io/craigk5n/homecare:latest` | Latest non-pre-release tag |

Pin an exact version in production. The examples below use
`ghcr.io/craigk5n/homecare:0.2.0` — replace it with the release you
want.

### What the container does on start

`docker/docker-entrypoint.sh` runs on every start, then hands off to
Apache (or to whatever command you pass as `args`):

1. Renders `includes/settings.php` from the `HC_DB_*` variables.
2. Waits up to 120 s for MySQL to accept the app user.
3. If the database has no `hc_config` table (a **fresh** database),
   loads `tables-mysql.sql`, marks every file in `migrations/` as
   applied, and seeds the starter `admin` / `admin` user. **Rotate
   that password on first login** (Settings → Your Settings).
4. On an existing database it does nothing — **upgrades are not
   applied automatically**. The Deployment below runs
   `php bin/migrate.php` in an init container to cover that.

Environment variables the image reads:

| Variable | Default | Purpose |
| --- | --- | --- |
| `HC_DB_HOST` | `db` | MySQL host |
| `HC_DB_PORT` | `3306` | Port used by the entrypoint's wait/init step only (see [Limitations](#limitations-and-follow-ups)) |
| `HC_DB_NAME` | `homecare` | Database name |
| `HC_DB_USER` | `homecare` | DB user |
| `HC_DB_PASSWORD` | required | DB password |
| `HC_DB_TYPE` | `mysqli` | `dbi4php` driver name |

Persistent, writable paths inside the container:

| Path | What lives there |
| --- | --- |
| `/var/www/html/data/attachments` | Uploaded attachments + thumbnails (HC-130) |

Everything else (patients, schedules, settings, the URL-signing
secret) is in MySQL.

## 1. Namespace

The image starts as root (the entrypoint writes `settings.php`, then
Apache drops to `www-data`), so it fits the Pod Security `baseline`
profile but not `restricted`.

```yaml
apiVersion: v1
kind: Namespace
metadata:
  name: homecare
  labels:
    pod-security.kubernetes.io/enforce: baseline
    pod-security.kubernetes.io/warn: restricted
```

## 2. Secret (database credentials)

Generate random hex passwords — hex keeps them safe for the
key/value `settings.php` file the entrypoint renders (no newlines,
quotes or `*/`):

```bash
kubectl create namespace homecare   # if you didn't apply the YAML above

kubectl -n homecare create secret generic homecare-db \
  --from-literal=HC_DB_PASSWORD="$(openssl rand -hex 24)" \
  --from-literal=MYSQL_ROOT_PASSWORD="$(openssl rand -hex 24)"
```

Non-secret settings go in a ConfigMap shared by the web Deployment
and the CronJobs:

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: homecare-env
  namespace: homecare
data:
  HC_DB_HOST: mysql
  HC_DB_PORT: "3306"
  HC_DB_NAME: homecare
  HC_DB_USER: homecare
  HC_DB_TYPE: mysqli
```

## 3. MySQL 8

Skip this section if you already have a MySQL 8 server (in-cluster or
managed): create a database and user for HomeCare there, point
`HC_DB_HOST` at it, and put the user's password in `HC_DB_PASSWORD`.
The entrypoint's wait/init step connects **without TLS**
(`--skip-ssl`), so a server that *requires* TLS
(`require_secure_transport=ON`) won't work with the image as-is.

`mysql:8.0` matches `docker-compose.yml` and the CI stack. MySQL 8.0
is end-of-life upstream; `mysql:8.4` (LTS) uses the same default
authentication plugin and should work, but it is not what CI tests.

```yaml
apiVersion: v1
kind: Service
metadata:
  name: mysql
  namespace: homecare
  labels:
    app.kubernetes.io/name: mysql
    app.kubernetes.io/part-of: homecare
spec:
  clusterIP: None
  selector:
    app.kubernetes.io/name: mysql
  ports:
    - name: mysql
      port: 3306
---
apiVersion: apps/v1
kind: StatefulSet
metadata:
  name: mysql
  namespace: homecare
  labels:
    app.kubernetes.io/name: mysql
    app.kubernetes.io/part-of: homecare
spec:
  serviceName: mysql
  replicas: 1
  selector:
    matchLabels:
      app.kubernetes.io/name: mysql
  template:
    metadata:
      labels:
        app.kubernetes.io/name: mysql
        app.kubernetes.io/part-of: homecare
    spec:
      securityContext:
        fsGroup: 999            # mysql group in the official image
        seccompProfile:
          type: RuntimeDefault
      containers:
        - name: mysql
          image: mysql:8.0
          ports:
            - name: mysql
              containerPort: 3306
          env:
            - name: MYSQL_DATABASE
              value: homecare
            - name: MYSQL_USER
              value: homecare
            - name: MYSQL_PASSWORD
              valueFrom:
                secretKeyRef: { name: homecare-db, key: HC_DB_PASSWORD }
            - name: MYSQL_ROOT_PASSWORD
              valueFrom:
                secretKeyRef: { name: homecare-db, key: MYSQL_ROOT_PASSWORD }
          volumeMounts:
            - name: data
              mountPath: /var/lib/mysql
          readinessProbe:
            exec:
              command:
                - sh
                - -c
                - MYSQL_PWD="$MYSQL_PASSWORD" mysql -h 127.0.0.1 -u "$MYSQL_USER" -e 'SELECT 1' "$MYSQL_DATABASE"
            initialDelaySeconds: 10
            periodSeconds: 10
            timeoutSeconds: 5
          livenessProbe:
            exec:
              command:
                - sh
                - -c
                - MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping -h 127.0.0.1 -u root
            initialDelaySeconds: 60
            periodSeconds: 20
            timeoutSeconds: 5
          resources:
            requests: { cpu: 100m, memory: 512Mi }
            limits:   { memory: 1Gi }
          securityContext:
            allowPrivilegeEscalation: false
  volumeClaimTemplates:
    - metadata:
        name: data
      spec:
        accessModes: [ReadWriteOnce]
        resources:
          requests:
            storage: 5Gi
```

Back it up like any MySQL: `kubectl -n homecare exec mysql-0 -- sh -c
'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -u root homecare' >
homecare-$(date +%F).sql` (or `dump.sh`-style, HomeCare tables only).

## 4. HomeCare web

One replica with the `Recreate` strategy: attachments live on a
`ReadWriteOnce` volume and PHP sessions are kept in the container's
local `/tmp`, so running more than one replica would need shared
storage and sticky sessions.

The `migrate` init container runs the same entrypoint (render
settings, wait for MySQL, first-boot init) and then
`php bin/migrate.php`, which applies any `migrations/*.sql` not yet
recorded in `hc_migrations`. On a fresh database it finds nothing to
do; after an image upgrade it applies the new migrations before the
new web container starts. **Take a database backup before upgrading.**

Probes:

- `startupProbe` / `livenessProbe` fetch `/manifest.json`, a static
  file Apache serves without touching PHP or the database — "is
  Apache up".
- `readinessProbe` checks that the app's DB credentials still work,
  using the MySQL client already in the image. An HTTP probe on
  `/login.php` would also exercise PHP + the DB, but in reverse-proxy
  auth mode it redirects to `index.php`, which answers 401 to a
  request without the proxy header, so it is only usable in native
  auth mode.

```yaml
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: homecare-attachments
  namespace: homecare
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 2Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: homecare
  namespace: homecare
  labels:
    app.kubernetes.io/name: homecare
    app.kubernetes.io/component: web
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app.kubernetes.io/name: homecare
      app.kubernetes.io/component: web
  template:
    metadata:
      labels:
        app.kubernetes.io/name: homecare
        app.kubernetes.io/component: web
    spec:
      securityContext:
        fsGroup: 33               # www-data: makes the attachments PVC writable by Apache
        seccompProfile:
          type: RuntimeDefault
      initContainers:
        - name: migrate
          image: ghcr.io/craigk5n/homecare:0.2.0
          args: ["php", "bin/migrate.php"]
          envFrom:
            - configMapRef: { name: homecare-env }
          env:
            - name: HC_DB_PASSWORD
              valueFrom:
                secretKeyRef: { name: homecare-db, key: HC_DB_PASSWORD }
          resources:
            requests: { cpu: 50m, memory: 64Mi }
            limits:   { memory: 256Mi }
          securityContext:
            allowPrivilegeEscalation: false
      containers:
        - name: web
          image: ghcr.io/craigk5n/homecare:0.2.0
          ports:
            - name: http
              containerPort: 80
          envFrom:
            - configMapRef: { name: homecare-env }
          env:
            - name: HC_DB_PASSWORD
              valueFrom:
                secretKeyRef: { name: homecare-db, key: HC_DB_PASSWORD }
          volumeMounts:
            - name: attachments
              mountPath: /var/www/html/data/attachments
          startupProbe:
            httpGet: { path: /manifest.json, port: http }
            periodSeconds: 5
            failureThreshold: 36    # up to 3 min: entrypoint waits up to 120 s for MySQL
          livenessProbe:
            httpGet: { path: /manifest.json, port: http }
            periodSeconds: 20
            timeoutSeconds: 5
            failureThreshold: 3
          readinessProbe:
            exec:
              command:
                - sh
                - -c
                - >-
                  MYSQL_PWD="$HC_DB_PASSWORD" mysql --skip-ssl --connect-timeout=3
                  -h "$HC_DB_HOST" -P "$HC_DB_PORT" -u "$HC_DB_USER"
                  -e 'SELECT 1' "$HC_DB_NAME" >/dev/null
            periodSeconds: 15
            timeoutSeconds: 5
            failureThreshold: 3
          resources:
            requests: { cpu: 50m, memory: 128Mi }
            limits:   { memory: 512Mi }   # PDF export (dompdf) is the memory-hungry path
          securityContext:
            # The entrypoint runs as root and Apache drops to www-data,
            # so runAsNonRoot / readOnlyRootFilesystem are not supported
            # by the current image.
            allowPrivilegeEscalation: false
      volumes:
        - name: attachments
          persistentVolumeClaim:
            claimName: homecare-attachments
---
apiVersion: v1
kind: Service
metadata:
  name: homecare
  namespace: homecare
  labels:
    app.kubernetes.io/name: homecare
    app.kubernetes.io/component: web
spec:
  type: ClusterIP
  selector:
    app.kubernetes.io/name: homecare
    app.kubernetes.io/component: web
  ports:
    - name: http
      port: 80
      targetPort: http
```

## 5. Ingress

TLS terminates at the ingress controller; HomeCare listens on plain
HTTP inside the cluster. The session cookie honours
`X-Forwarded-Proto`, which ingress controllers set by default.

Example for ingress-nginx (adjust `ingressClassName`, host and TLS
secret; cert-manager users add their usual `cert-manager.io/...`
annotation). Attachments are capped at 10 MB by the app, so raise the
proxy's default 1 MB body limit:

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: homecare
  namespace: homecare
  annotations:
    nginx.ingress.kubernetes.io/proxy-body-size: "12m"
spec:
  ingressClassName: nginx
  tls:
    - hosts: [homecare.example.com]
      secretName: homecare-tls
  rules:
    - host: homecare.example.com
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: homecare
                port:
                  name: http
```

Note: the upstream ingress-nginx project was retired in March 2026.
The annotations here still apply to existing installs; on a new
cluster use whatever controller you run (Traefik, a Gateway API
implementation, ...) — HomeCare only needs a plain HTTP backend.

## 6. Scheduled jobs (CronJobs)

The Docker image runs **no cron daemon** — `docker-compose.yml`
doesn't schedule anything either. On Kubernetes, run the CLI scripts
as CronJobs using the same image: keep the image's entrypoint (it
renders `settings.php` first) and pass the script as `args`.

`send_reminders.php` pushes due-dose and low-supply alerts through
the channels configured in Settings (ntfy, email, webhooks). The
README's cron line runs it every 5 minutes with the default 5-minute
window. `backoffLimit: 0` avoids a retried Job sending the same
notification twice.

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: homecare-reminders
  namespace: homecare
  labels:
    app.kubernetes.io/name: homecare
    app.kubernetes.io/component: cron
spec:
  schedule: "*/5 * * * *"
  concurrencyPolicy: Forbid
  startingDeadlineSeconds: 120
  successfulJobsHistoryLimit: 1
  failedJobsHistoryLimit: 3
  jobTemplate:
    spec:
      backoffLimit: 0
      activeDeadlineSeconds: 240
      template:
        metadata:
          labels:
            app.kubernetes.io/name: homecare
            app.kubernetes.io/component: cron
        spec:
          restartPolicy: Never
          securityContext:
            seccompProfile:
              type: RuntimeDefault
          containers:
            - name: send-reminders
              image: ghcr.io/craigk5n/homecare:0.2.0
              args: ["php", "send_reminders.php"]
              envFrom:
                - configMapRef: { name: homecare-env }
              env:
                - name: HC_DB_PASSWORD
                  valueFrom:
                    secretKeyRef: { name: homecare-db, key: HC_DB_PASSWORD }
              resources:
                requests: { cpu: 20m, memory: 64Mi }
                limits:   { memory: 256Mi }
              securityContext:
                allowPrivilegeEscalation: false
```

Test it once without sending anything:

```bash
kubectl -n homecare create job reminders-test --from=cronjob/homecare-reminders
# or, against the running web pod:
kubectl -n homecare exec deploy/homecare -c web -- php send_reminders.php --dry-run
```

Other cron-friendly scripts follow the same pattern — copy the
CronJob, change `name`, `schedule` and `args`:

| Script | Suggested schedule | Notes |
| --- | --- | --- |
| `bin/send_adherence_digest.php` | `0 8 * * 1` | Weekly email digest (HC-107). Set `spec.timeZone` (e.g. `America/New_York`) so "08:00" is local. |
| `generate_monthly_pdf.php` | `5 0 1 * *` | Writes PDFs to `pdf_archive_dir` / `--output-dir`, so the Job needs its own writable volume. The attachments PVC is `ReadWriteOnce` and mounted by the web pod, so give the archive a separate PVC (or a `ReadWriteMany` one). |
| `export_intake.php` | as needed | CLI intake export (`--out-dir`); same volume consideration as the PDF archive. |

## 7. Reverse-proxy authentication

HomeCare can delegate login to Authelia, Authentik or another
forward-auth proxy — see [reverse-proxy-auth.md](reverse-proxy-auth.md)
for how the mode works, how to enable it (Settings → Authentication
Mode) and its security considerations. Users must already exist in
HomeCare with a login matching the header value.

On Kubernetes the proxy is usually the ingress controller calling
the IdP's forward-auth endpoint. Set HomeCare's **Proxy Header** to
the header your IdP returns.

### ingress-nginx + Authelia

Authelia 4.38+ (`/api/authz/auth-request` endpoint). HomeCare Proxy
Header: `Remote-User`.

```yaml
metadata:
  annotations:
    nginx.ingress.kubernetes.io/proxy-body-size: "12m"
    nginx.ingress.kubernetes.io/auth-method: GET
    nginx.ingress.kubernetes.io/auth-url: http://authelia.authelia.svc.cluster.local/api/authz/auth-request
    nginx.ingress.kubernetes.io/auth-signin: https://auth.example.com?rm=$request_method
    nginx.ingress.kubernetes.io/auth-response-headers: Remote-User,Remote-Name,Remote-Groups,Remote-Email
```

### ingress-nginx + Authentik

Proxy provider in "Forward auth (single application)" mode, using the
embedded or a dedicated outpost. HomeCare Proxy Header:
`X-authentik-username`.

```yaml
metadata:
  annotations:
    nginx.ingress.kubernetes.io/proxy-body-size: "12m"
    nginx.ingress.kubernetes.io/auth-url: http://ak-outpost-authentik-embedded-outpost.authentik.svc.cluster.local:9000/outpost.goauthentik.io/auth/nginx
    nginx.ingress.kubernetes.io/auth-signin: https://homecare.example.com/outpost.goauthentik.io/start?rd=$scheme://$http_host$escaped_request_uri
    nginx.ingress.kubernetes.io/auth-response-headers: Set-Cookie,X-authentik-username,X-authentik-groups,X-authentik-email,X-authentik-name,X-authentik-uid
```

Authentik's docs also add an `auth-snippet` annotation
(`proxy_set_header X-Forwarded-Host $http_host;`); snippet
annotations are disabled by default in recent ingress-nginx releases,
so enable them deliberately or follow the outpost docs for your
version.

With `auth-response-headers`, ingress-nginx overwrites the listed
headers with the IdP's values, so a client cannot inject its own
`Remote-User` through the ingress.

### Traefik

Create a `Middleware` with `forwardAuth` and attach it to the Ingress
with an annotation (namespace-name@kubernetescrd):

```yaml
apiVersion: traefik.io/v1alpha1
kind: Middleware
metadata:
  name: authelia
  namespace: homecare
spec:
  forwardAuth:
    address: http://authelia.authelia.svc.cluster.local/api/authz/forward-auth
    trustForwardHeader: true
    authResponseHeaders:
      - Remote-User
      - Remote-Name
      - Remote-Groups
      - Remote-Email
```

```yaml
metadata:
  annotations:
    traefik.ingress.kubernetes.io/router.middlewares: homecare-authelia@kubernetescrd
```

For Authentik, point `address` at
`.../outpost.goauthentik.io/auth/traefik` and list the
`X-authentik-*` headers instead.

### Lock the Service down (NetworkPolicy)

In reverse-proxy mode HomeCare trusts the header **unconditionally**.
Anything that can reach the `homecare` Service directly — another
pod, a `NodePort`, a `kubectl port-forward` — can send
`Remote-User: admin` and be logged in as admin. Keep the Service
`ClusterIP`, never expose it any other way, and only let the ingress
controller reach it.

These policies allow traffic to the web pods only from the ingress
controller's namespace, and to MySQL only from HomeCare's own pods
(web + CronJobs). They need a CNI that enforces NetworkPolicy
(Calico, Cilium, ...). Adjust the namespace label to wherever your
controller runs (`ingress-nginx`, `traefik`, `kube-system`, ...).
Kubelet probes are node-originated and are allowed by common CNIs.

```yaml
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: homecare-web-from-ingress-only
  namespace: homecare
spec:
  podSelector:
    matchLabels:
      app.kubernetes.io/name: homecare
      app.kubernetes.io/component: web
  policyTypes: [Ingress]
  ingress:
    - from:
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: ingress-nginx
      ports:
        - protocol: TCP
          port: 80
---
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: mysql-from-homecare-only
  namespace: homecare
spec:
  podSelector:
    matchLabels:
      app.kubernetes.io/name: mysql
  policyTypes: [Ingress]
  ingress:
    - from:
        - podSelector:
            matchLabels:
              app.kubernetes.io/name: homecare
      ports:
        - protocol: TCP
          port: 3306
```

The NetworkPolicy is worth having in native auth mode too — it keeps
the database private to HomeCare.

## Day-2 operations

```bash
# Logs (entrypoint output shows first-boot init / migrations)
kubectl -n homecare logs deploy/homecare -c migrate
kubectl -n homecare logs deploy/homecare -c web

# Upgrade: back up, bump the image tag in the Deployment and CronJobs,
# re-apply. The migrate init container applies new migrations.
kubectl -n homecare set image deploy/homecare migrate=ghcr.io/craigk5n/homecare:0.2.1 web=ghcr.io/craigk5n/homecare:0.2.1

# Reset auth mode to native if the proxy is misconfigured
kubectl -n homecare exec mysql-0 -- sh -c \
  "MYSQL_PWD=\"\$MYSQL_PASSWORD\" mysql -u homecare homecare -e \"UPDATE hc_config SET value='native' WHERE setting='auth_mode'\""
```

## Limitations and follow-ups

Things the current image doesn't support, noted rather than worked
around here:

- **Non-root / read-only root filesystem**: the entrypoint writes
  `includes/settings.php` into the image as root. Supporting
  `runAsNonRoot` would need Apache on an unprivileged port and
  `settings.php` rendered somewhere writable (or the existing
  `HOMECARE_USE_ENV=true` code path in `includes/config.php` used
  instead of the file).
- **Database port**: `HC_DB_PORT` is used only by the entrypoint's
  wait/init step; `settings.php` has no port field and
  `bin/migrate.php` connects on the default port, so use 3306.
- **Upgrades**: the entrypoint only initialises empty databases;
  migrations on existing databases need `bin/migrate.php` (the init
  container above). Running it from the entrypoint would make
  Compose behave the same way.
- **TLS to MySQL**: the entrypoint's client uses `--skip-ssl`; there
  is no option for servers that require TLS.
- **Timezone**: `HC_TIMEZONE` is defaulted by the entrypoint and
  listed in the README, but nothing reads it — the app timezone comes
  from HomeCare's own settings.
- **Upload size**: the app allows 10 MB attachments, but the image
  ships PHP's defaults (`upload_max_filesize=2M`, `post_max_size=8M`),
  so larger uploads fail inside PHP regardless of the ingress limit.
- **HTTPS-generated URLs**: some absolute URLs (password-reset links,
  links in login security-notification emails) look only at
  `$_SERVER['HTTPS']`, so behind
  a TLS-terminating proxy they come out as `http://`. An Apache
  `SetEnvIf X-Forwarded-Proto https HTTPS=on` in the image would fix
  that.
- **Health endpoint**: there is no dedicated `/healthz`; the probes
  above use a static file plus a DB check.
