#!/bin/bash
# redeploy.sh [container] : rebuild the package from this clone, install it into the test Joomla, reset the
# plugin options + fake Google connection and re-render the test pages.
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL="*"
J=${1:-wgbtest-6-j}
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
ROOTW="$(cygpath -m "$ROOT" 2>/dev/null || echo "$ROOT")"   # docker.exe wants a Windows path for the host side on Windows
cd "$ROOT" && python tools/build.py | tail -1
VER=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' src/pkg_webgbooking/pkg_webgbooking.xml | head -1)
docker cp "$ROOTW/dist/pkg_webgbooking-$VER.zip" $J:/tmp/new.zip
docker exec -w /var/www/html $J php cli/joomla.php extension:install --path=/tmp/new.zip 2>&1 | tail -2
docker cp "$ROOTW/tools/e2e/mock/." $J:/tmp/mock/
docker exec $J php /tmp/mock/init.php
docker exec $J php /tmp/mock/render_el.php /tmp/mock/configs.json | tr '\n' ' '
docker exec $J sh -c 'chmod -R a+rX /var/www/html/wgbtest; echo "<!doctype html><title>thanks</title><h1>thanks</h1>" > /var/www/html/wgbtest/thanks.html; chmod a+r /var/www/html/wgbtest/thanks.html'
