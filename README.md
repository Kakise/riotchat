# Element for Nextcloud 35

This fork of [gary-kim/riotchat](https://github.com/gary-kim/riotchat) bundles **Element Web 1.12.29** for **Nextcloud 35**. The Nextcloud app version is **0.22.0**. It keeps the existing `riotchat` app ID and administrator settings.

Element is a Matrix client. You still need a Matrix account and homeserver; your Nextcloud account is not automatically a Matrix account. This project is not affiliated with Nextcloud or Element.

## Install the release

Download **`riotchat-0.22.0.tar.gz`** and its **`.sha256`** file from [this fork's releases](https://github.com/Kakise/riotchat/releases/latest). Use the release asset, not GitHub's automatically generated source archive: the asset includes the compiled JavaScript and Element client. No Node.js or build tools are needed on your server.

You need administrator access and access to the server's app files (SSH, Docker, or a hosting file manager). Nextcloud's web admin page does not install arbitrary uploaded app archives. This fork is installed manually; the original App Store listing does not serve this build.

### Standard server

Adjust `/var/www/nextcloud` and `www-data` to your installation. Confirm `custom_apps` is listed as a writable app directory in Nextcloud's `config/config.php` (`apps_paths`). Alternatively use your existing writable app directory.

```sh
# Run in the directory containing the downloaded release assets.
sha256sum -c riotchat-0.22.0.tar.gz.sha256

# For an existing installation, first disable it and back up its directory.
# sudo -u www-data php /var/www/nextcloud/occ app:disable riotchat
# sudo mv /var/www/nextcloud/custom_apps/riotchat /safe/backup/riotchat-before-upgrade

sudo mkdir -p /var/www/nextcloud/custom_apps
sudo tar -xzf riotchat-0.22.0.tar.gz -C /var/www/nextcloud/custom_apps
sudo chown -R www-data:www-data /var/www/nextcloud/custom_apps/riotchat
sudo -u www-data php /var/www/nextcloud/occ app:enable riotchat
sudo -u www-data php /var/www/nextcloud/occ upgrade
sudo -u www-data php /var/www/nextcloud/occ app:list
```

For an update, move the old app directory aside before extracting to avoid mixing stale assets. Keep the backup outside the active app directories. Back up the Nextcloud database/configuration as usual. Disabling or replacing this app does not delete chats on your Matrix homeserver.

### Official Nextcloud Docker image

Replace `nextcloud` with your container name. The official image normally has a persistent `/var/www/html/custom_apps` directory. Verify your volume configuration so the app survives container replacement.

```sh
sha256sum -c riotchat-0.22.0.tar.gz.sha256
docker cp riotchat-0.22.0.tar.gz nextcloud:/tmp/riotchat-0.22.0.tar.gz
# For an update, disable the existing app and back up/move its folder first.
docker exec -u root nextcloud mkdir -p /var/www/html/custom_apps
docker exec -u root nextcloud tar -xzf /tmp/riotchat-0.22.0.tar.gz -C /var/www/html/custom_apps
docker exec -u root nextcloud chown -R www-data:www-data /var/www/html/custom_apps/riotchat
docker exec -u www-data nextcloud php occ app:enable riotchat
docker exec -u www-data nextcloud php occ upgrade
docker exec -u www-data nextcloud php occ app:list
```

For Nextcloud AIO, the application container is commonly `nextcloud-aio-nextcloud`; use its actual name and paths. With a hosting file manager, extract the archive so the final path is `custom_apps/riotchat/appinfo/info.xml`, then enable **Element for Nextcloud** under Apps. A provider may need to run the upgrade command.

### Configure and open

1. Open **Administration settings → Element** and set the Matrix homeserver URL and server name, or supply your custom Element configuration JSON.
2. Open **Element** in the Nextcloud navigation. The app route is `https://your-nextcloud.example/index.php/apps/riotchat/` (adjust the base path for subdirectory installs).
3. Sign in with your Matrix account. A pre-existing Matrix session may be reused by the browser.

HTTPS is required for browser encryption and media features. Camera/microphone permissions, calls, and SSO also depend on your homeserver, identity provider, and reverse proxy configuration. The release does not configure a homeserver or calling backend.

This is an unsigned custom fork: it cannot use the original maintainer's app-signing key and may appear as unverified in Nextcloud. Do not disable Nextcloud's global integrity checks. Keep using this fork's release assets when updating; an upstream App Store update may replace your custom build. To roll back, disable `riotchat`, restore the previous app directory (and database backup if needed), and re-enable the compatible version.

## Build and verify

Use Node.js 20 with npm, Python 3, Make, and network access. PHP 8.3+ is used for Nextcloud 35 runtime checks.

```sh
npm ci
npm test
make test-packaging
make appstore
```

To run PHP regression tests against an extracted official Nextcloud 35 release, install PHPUnit 11 and run:

```sh
NEXTCLOUD_ROOT=/absolute/path/to/nextcloud phpunit tests/runtime
```

GitHub Actions also builds the release and installs it into a clean Nextcloud 35 instance on Linux.

The ready-to-install archive is written to `build/artifacts/appstore/riotchat-0.22.0.tar.gz`. The build downloads the official Element release pinned in `element-release.json` and checks its SHA-256 before extraction. The adapter is built from `src/`. No Element source checkout or pnpm build is required.

To update Element again, review the new stable release, update the manifest's version, archive URL, digest and license/source references, bump the app version, and rebuild and retest. Do not point a released build at an unpinned `latest` URL.

## License and source

The Nextcloud integration is [AGPL-3.0-or-later](LICENSE), originally by Gary Kim and contributors. This fork preserves their notices.

The bundled Element Web release is from [element-hq/element-web v1.12.29](https://github.com/element-hq/element-web/tree/v1.12.29). Its [corresponding source archive](https://github.com/element-hq/element-web/archive/refs/tags/v1.12.29.tar.gz) and upstream license are available at that pinned tag. Element's license and bundled third-party notices are included with the client. Element names and marks belong to their respective owners.
