#!/bin/sh
# Runs INSIDE the Joomla test container: mock Google (HTTPS on 443 via Apache) + SMTP sink + Joomla mail settings.
set -e
mkdir -p /var/www/mock /tmp/gmock /tmp/mail /etc/ssl/wgbmock; chmod 777 /tmp/gmock /tmp/mail
cp /tmp/mock/mock.php /var/www/mock/mock.php
cd /etc/ssl/wgbmock
openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.crt -subj "/CN=wgb-test-ca" -days 30 2>/dev/null
openssl req -newkey rsa:2048 -nodes -keyout leaf.key -out leaf.csr -subj "/CN=www.googleapis.com" 2>/dev/null
printf "subjectAltName=DNS:www.googleapis.com,DNS:oauth2.googleapis.com,DNS:sheets.googleapis.com\n" > san.cnf
openssl x509 -req -in leaf.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out leaf.crt -days 30 -extfile san.cnf 2>/dev/null
# trust the mock CA in the bundle Joomla's curl transport uses (test container only)
BUNDLE=$(cd /var/www/html && php -r 'require "libraries/vendor/autoload.php"; echo Composer\CaBundle\CaBundle::getBundledCaBundlePath();')
cat ca.crt >> "$BUNDLE"
a2enmod ssl >/dev/null 2>&1 || true
cat > /etc/apache2/sites-available/mock-ssl.conf <<'CONF'
<VirtualHost *:443>
    ServerName www.googleapis.com
    ServerAlias oauth2.googleapis.com sheets.googleapis.com
    DocumentRoot /var/www/mock
    SSLEngine on
    SSLCertificateFile /etc/ssl/wgbmock/leaf.crt
    SSLCertificateKeyFile /etc/ssl/wgbmock/leaf.key
    FallbackResource /mock.php
    <Directory /var/www/mock>
        Require all granted
    </Directory>
</VirtualHost>
CONF
a2ensite mock-ssl >/dev/null 2>&1
apache2ctl graceful
# googleapis hosts resolve to this very container
grep -v googleapis /etc/hosts > /tmp/hosts.new || true
echo "127.0.0.1 www.googleapis.com oauth2.googleapis.com sheets.googleapis.com" >> /tmp/hosts.new
cat /tmp/hosts.new > /etc/hosts
# SMTP sink in the background
nohup php /tmp/mock/smtpsink.php > /tmp/smtpsink.log 2>&1 &
# Joomla mail -> sink
cd /var/www/html
sed -i "s/public \$mailer = '[a-z]*';/public \$mailer = 'smtp';/; s/public \$smtphost = '[^']*';/public \$smtphost = '127.0.0.1';/; s/public \$smtpport = [0-9]*;/public \$smtpport = 2525;/; s/public \$smtpsecure = '[a-z]*';/public \$smtpsecure = 'none';/; s/public \$smtpauth = [a-z0-9]*;/public \$smtpauth = false;/; s/public \$mailfrom = '[^']*';/public \$mailfrom = 'noreply@wgbtest.invalid';/; s/public \$mailonline = [a-z0-9]*;/public \$mailonline = true;/" configuration.php
echo setup-done
