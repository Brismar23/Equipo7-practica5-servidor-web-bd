#!/usr/bin/env bash
# Configura la VM asiserver: Apache + PHP + la app de citas + HTTPS (certificado autofirmado)
# Uso:  sudo bash setup_web.sh <IP_srv-bd-dns>
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then echo "Córrelo con sudo."; exit 1; fi
if [ $# -ne 1 ]; then echo "Uso: sudo bash $0 <IP_srv-bd-dns>"; exit 1; fi
IP_BD="$1"
DIR="$(cd "$(dirname "$0")/.." && pwd)"
WEB=/var/www/html

read -rsp "Contraseña del usuario rh_app (la MISMA que pusieron en srv-bd-dns): " DBPASS; echo

echo "== 1/4 Instalando Apache, PHP y herramientas =="
apt update
DEBIAN_FRONTEND=noninteractive apt install -y apache2 php php-mysqli libapache2-mod-php \
  mariadb-client netcat-openbsd openssl
systemctl enable --now apache2

echo "== 2/4 Copiando la aplicación =="
[ -f "$WEB/index.html" ] && mv "$WEB/index.html" "$WEB/index.html.default"
cp "$DIR/app/index.php" "$WEB/index.php"
cat > "$WEB/config.php" <<EOF
<?php
\$host = "${IP_BD}";
\$user = "rh_app";
\$pass = "${DBPASS}";
\$db   = "citas_medicas";
EOF
chown root:www-data "$WEB/config.php" "$WEB/index.php"
chmod 640 "$WEB/config.php"

echo "== 3/4 Configurando HTTPS =="
if [ ! -f /etc/ssl/certs/citas.crt ]; then
  openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/ssl/private/citas.key -out /etc/ssl/certs/citas.crt \
    -subj "/C=MX/ST=CDMX/O=Equipo 7 ASI/CN=citas.empresa.local" \
    -addext "subjectAltName=DNS:citas.empresa.local"
fi
SSLCONF=/etc/apache2/sites-available/default-ssl.conf
sed -i 's#^\(\s*SSLCertificateFile\s\+\).*#\1/etc/ssl/certs/citas.crt#'          "$SSLCONF"
sed -i 's#^\(\s*SSLCertificateKeyFile\s\+\).*#\1/etc/ssl/private/citas.key#'     "$SSLCONF"
a2enmod ssl rewrite >/dev/null
a2ensite default-ssl >/dev/null

# Redirección HTTP -> HTTPS
HTTPCONF=/etc/apache2/sites-available/000-default.conf
if ! grep -q 'RewriteEngine On' "$HTTPCONF"; then
  sed -i 's#<VirtualHost \*:80>#<VirtualHost *:80>\n\tRewriteEngine On\n\tRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]#' "$HTTPCONF"
fi
apache2ctl configtest
systemctl reload apache2
ufw allow 80/tcp >/dev/null || true
ufw allow 443/tcp >/dev/null || true

echo "== 4/4 Probando conexión con la BD =="
if nc -zv -w 5 "$IP_BD" 3306; then
  MYSQL_PWD="$DBPASS" mysql -h "$IP_BD" -u rh_app citas_medicas -e "SELECT COUNT(*) AS citas FROM citas;"
else
  echo "!! No llega al puerto 3306 de $IP_BD. Revisen: ping, bind-address=0.0.0.0, firewall."
fi

echo
echo "Listo. Prueben desde otra máquina:"
echo "  https://$(hostname -I | awk '{print $NF}')     (por IP)"
echo "  https://citas.empresa.local      (con el DNS de la laptop apuntando a ${IP_BD})"
