# OpenMES deployment on Portainer

This directory contains the production-oriented Portainer stack for the SoftMedia MES deployment.

## Deployment model

- Portainer deploys `deploy/portainer/docker-compose.yml` from the Git repository.
- The application image is built from repository source, because the upstream GHCR image may not be publicly pullable.
- Nginx Proxy Manager terminates HTTPS. The internal Caddy service routes HTTP traffic to Laravel Octane and WebSocket traffic at `/app/*` to Laravel Reverb.
- PostgreSQL data, Laravel storage, cache, Caddy state and database backups are stored in named Docker volumes.

## Required Portainer settings

Create a new stack from Git:

- Repository URL: `https://softmediapl@dev.azure.com/softmediapl/MES/_git/MES`
- Compose path: `deploy/portainer/docker-compose.yml`
- Environment variables: copy keys from `portainer.env.example` and replace every `CHANGE_ME...` value.
- `NPM_NETWORK` must be the existing Docker network used by the Nginx Proxy Manager container. On `ded1`, NPM is attached to `devops_net`, so use `NPM_NETWORK=devops_net`.

Do not paste secrets into this repository. Keep production values only in Portainer environment variables or a Portainer secret mechanism.

## Secret generation

Generate `APP_KEY` once and keep it stable across redeployments:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Generate passwords and `REVERB_APP_SECRET` with a password manager or:

```bash
openssl rand -base64 48
```

## DNS and ports

Before first deploy:

- Point `DOMAIN` to the server public IP.
- Ensure inbound TCP `80` and `443` reach this Docker host.
- Set `APP_URL=https://your-domain`.

Nginx Proxy Manager should request and renew the TLS certificate automatically.

## Nginx Proxy Manager

Create a Proxy Host:

- Domain Names: `mes.softmedia.com.pl`
- Scheme: `http`
- Forward Hostname / IP: `openmes-web`
- Forward Port: `80`
- Websockets Support: enabled
- Block Common Exploits: enabled

On the SSL tab:

- Request a new Let's Encrypt certificate
- Force SSL: enabled
- HTTP/2 Support: enabled
- HSTS: enable only after confirming the site works over HTTPS

## First start

On first backend start, the entrypoint runs migrations, seeds required roles/reference data and creates the configured admin account if no users exist.

After deployment, check:

```bash
curl -fsS https://your-domain/api/health
```

Expected response contains `"status":"ok"`.

## Backups

The `postgres-backup` service writes daily PostgreSQL custom-format dumps to the `postgres_backups` volume and removes files older than `BACKUP_RETENTION_DAYS`.

Restore example from inside the Portainer host:

```bash
docker run --rm --network openmes_default \
  -v openmes_postgres_backups:/backups:ro \
  -e PGPASSWORD='production-db-password' \
  postgres:17-alpine \
  pg_restore --host=postgres --username=openmes_user --dbname=openmes --clean --if-exists /backups/openmes-YYYYMMDDTHHMMSSZ.dump
```

Adapt the Docker network and volume names to the actual Portainer stack name.

## Operational notes

- Keep `APP_KEY` unchanged after data exists; changing it can invalidate encrypted application data.
- Keep `REVERB_APP_KEY=openmeskey` unless the frontend build is updated to receive a different Vite value at build time.
- Use the `queue-worker` service for background jobs; the backend also starts a default worker, so this stack is conservative for production workloads.
- For machine connectivity services such as MQTT, Modbus or OPC UA, add a separate reviewed compose override after the base app is stable.
