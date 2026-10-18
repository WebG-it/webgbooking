#!/bin/bash
# up.sh <5|6> <port> : throwaway Joomla + MariaDB (Docker) for the WebG Booking end-to-end tests.
# Images: JOOMLA_IMAGE / MARIADB_IMAGE if set (e.g. a digest), otherwise the public tags.
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL="*"
MAJ=${1:?usage: up.sh 5|6 port}; PORT=${2:?usage: up.sh 5|6 port}
IMG=${JOOMLA_IMAGE:-joomla:$MAJ-apache}
MDB=${MARIADB_IMAGE:-mariadb:11.4}
ADMINPW=${WGB_E2E_ADMIN_PASS:?set WGB_E2E_ADMIN_PASS (any throwaway password for the test admin)}
NET=wgbtest-$MAJ-net; DBC=wgbtest-$MAJ-db; JC=wgbtest-$MAJ-j
docker network create $NET >/dev/null 2>&1
docker run -d --name $DBC --network $NET -e MARIADB_ROOT_PASSWORD=rootpw -e MARIADB_DATABASE=joomla -e MARIADB_USER=joomla -e MARIADB_PASSWORD=joomlapw --memory 512m "$MDB" >/dev/null
sleep 12
docker run -d --name $JC --network $NET -p $PORT:80 \
  -e JOOMLA_DB_HOST=$DBC -e JOOMLA_DB_USER=joomla -e JOOMLA_DB_PASSWORD=joomlapw -e JOOMLA_DB_NAME=joomla -e JOOMLA_SITE_NAME=wgbtest \
  -e JOOMLA_ADMIN_USER=admin -e JOOMLA_ADMIN_USERNAME=admin -e JOOMLA_ADMIN_PASSWORD="$ADMINPW" -e JOOMLA_ADMIN_EMAIL=a@example.invalid \
  --memory 1g "$IMG" >/dev/null
for i in $(seq 1 90); do sleep 4; if docker logs $JC 2>&1 | grep -q "resuming normal operations"; then echo ready; break; fi; done
# mock Google + SMTP sink + site timezone, then the plugin options
HERE="$(cd "$(dirname "$0")" && pwd)"; HEREW="$(cygpath -m "$HERE" 2>/dev/null || echo "$HERE")"
docker cp "$HEREW/mock" $JC:/tmp/mock
docker exec $JC sh /tmp/mock/setup.sh | tail -1
docker exec $JC sh /tmp/mock/tz.sh
