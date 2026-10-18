#!/bin/sh
cd /var/www/html
sed -i "s/public \$offset = '[^']*';/public \$offset = 'Europe\/Rome';/" configuration.php
grep -c "Europe/Rome" configuration.php
