# ConnectLibrary local WordPress Docker environment

This stack mirrors the Bluehost staging environment closely enough for ConnectLibrary Phase 1 build verification without using any staging secrets.

## Staging facts this matches

- WordPress core: staging reports 7.0 via WP-CLI.
- PHP: staging uses Bluehost/cPanel EasyApache PHP 8.3.31; this stack uses `wordpress:php8.3-apache`.
- Web server: staging responds as Apache; this stack uses Apache.
- Database: staging reports MySQL 5.7.44-compatible server and `utf8` / `utf8_unicode_ci`; this stack uses `mysql:5.7` with matching charset/collation defaults.
- PHP limits: `memory_limit=512M`, `upload_max_filesize=512M`, `post_max_size=516M`, `max_input_vars=1000`, `max_file_uploads=20`.

## Files

- `compose.yaml` - Docker Compose stack with WordPress, MySQL, and WP-CLI services.
- `uploads.ini` - PHP limits matching staging.
- `.env.example` - local-only placeholder values. Copy to `.env` if you need to override values; never use staging credentials.
- `start-local.sh` - starts WordPress and installs local core if needed.
- `install-connectlibrary.sh` - installs and activates `dist/connectlibrary.zip` using WP-CLI.
- `smoke-test.sh` - validates Compose config, starts the stack, lints PHP files, installs/activates/deactivates the plugin ZIP, and checks REST health.

## Usage

From the repository root:

```sh
cp docker/.env.example docker/.env   # optional
bash docker/start-local.sh
bash docker/install-connectlibrary.sh
```

Open http://localhost:8080/wp-admin/ with the local placeholder admin credentials from `.env.example` unless overridden.

Run the full verification smoke test:

```sh
bash docker/smoke-test.sh
```

Stop the stack:

```sh
docker compose -f docker/compose.yaml down
```

Reset all local WordPress/database state:

```sh
docker compose -f docker/compose.yaml down -v
```
