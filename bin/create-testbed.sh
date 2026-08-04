#!/usr/bin/env bash
#
# Create a disposable Craft 5 site for developing this plugin.
#
# The testbed itself is deliberately not version controlled - it is a throwaway Craft install, and
# checking one in would mean maintaining it. This script is the reproducible part: run it and you
# get the same environment back.
#
# What it sets up, and why each piece matters:
#
#   - The plugin is installed as a *symlinked* composer path repository, so editing src/ takes
#     effect on the next request. No reinstall, no rebuild, no ddev restart.
#   - Mutagen is off. Mutagen plus symlinks that escape the project root is the fragile
#     combination, and this site is small enough that the default mount costs nothing noticeable.
#   - The platform hostname is aliased to ddev-router. Inside a ddev container *.ddev.site resolves
#     to 127.0.0.1, which is the container itself, so without this the connector would quietly talk
#     to the testbed's own web server instead of the platform.
#
# Usage:  ./bin/create-testbed.sh [testbed-directory]

set -euo pipefail

CONNECTOR_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKSPACE_DIR="$(dirname "$CONNECTOR_DIR")"
TESTBED_DIR="${1:-$WORKSPACE_DIR/testbed}"
PLATFORM_HOST="${PLATFORM_HOST:-manager.ddev.site}"

if [ -d "$TESTBED_DIR/.ddev" ]; then
    echo "A testbed already exists at $TESTBED_DIR."
    echo "Remove it first:  cd $TESTBED_DIR && ddev delete -Oy && cd .. && rm -rf testbed"
    exit 1
fi

echo "==> Creating $TESTBED_DIR"
mkdir -p "$TESTBED_DIR/.ddev"
cd "$TESTBED_DIR"

cat > .ddev/config.yaml <<YAML
name: manager-testbed
type: craftcms
docroot: web
php_version: "8.4"
webserver_type: nginx-fpm
xdebug_enabled: false
nodejs_version: "22"
composer_version: "2"

database:
  type: mysql
  version: "8.0"

# Off deliberately: the plugin is symlinked in from outside this project root.
mutagen_enabled: false

use_dns_when_possible: true

upload_dirs:
  - ../web/assets
  - ../storage
YAML

cat > .ddev/docker-compose.workspace.yaml <<YAML
services:
  web:
    volumes:
      - ../../connector:/var/www/connector

    # Without this, *.ddev.site resolves to this container rather than the platform's.
    external_links:
      - "ddev-router:${PLATFORM_HOST}"
YAML

echo "==> Starting ddev"
ddev start -y

echo "==> Installing Craft"
ddev composer create -y "craftcms/craft" || true
ddev craft install \
    --interactive=0 \
    --username=admin \
    --email=admin@example.test \
    --password=testbed-password \
    --site-name="Manager Testbed" \
    --site-url='https://manager-testbed.ddev.site' \
    --language=en-GB

echo "==> Linking the connector from source"
# The connector is symlinked so editing src/*.php takes effect on the next request. A
# container-absolute path, which is why ddev composer must be used in this project rather than host
# composer.
#
# The protocol package is deliberately NOT linked. It comes from Packagist, the same version a real
# Craft site would resolve, so the testbed exercises the published contract rather than whatever is
# in a sibling working copy. Testing against local protocol source is how the two sides drift apart
# without either suite noticing.
ddev composer config repositories.manager-connector '{"type":"path","url":"/var/www/connector","options":{"symlink":true}}'
ddev composer config minimum-stability dev
ddev composer config prefer-stable true
ddev composer require coysh-digital/craft-manager-connector:@dev -W

ddev craft plugin/install manager-connector

cat > config/manager-connector.php <<PHP
<?php

return [
    'platformUrl' => 'https://${PLATFORM_HOST}',
    'timeout' => 10,
];
PHP

cat <<EOF

Testbed ready at $TESTBED_DIR

  ddev craft manager-connector/preview     see exactly what this site would report
  ddev craft manager-connector/status      show the current connection

To pair, issue a code on the platform and run:

  ddev craft manager-connector/pair mgr_enrol_...

Editing $CONNECTOR_DIR/src takes effect on the next request. Only changes to the plugin's own
composer.json need 'ddev composer update'; after touching templates or Plugin.php run
'ddev craft clear-caches/compiled-templates'.
EOF
