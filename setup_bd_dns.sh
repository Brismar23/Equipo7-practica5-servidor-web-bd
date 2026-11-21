#!/usr/bin/env bash
# Configura la VM srv-bd-dns: MariaDB (con acceso remoto) + DNS interno BIND (empresa.local)
# Uso:  sudo bash setup_bd_dns.sh <IP_asiserver> <IP_srv-bd-dns>
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then echo "Córrelo con sudo."; exit 1; fi
if [ $# -ne 2 ]; then echo "Uso: sudo bash $0 <IP_asiserver> <IP_srv-bd-dns>"; exit 1; fi
IP_WEB="$1"; IP_BD="$2"
DIR="$(cd "$(dirname "$0")/.." && pwd)"

read -rsp "Contraseña para el usuario rh_app de la BD (solo letras y números): " DBPASS; echo
[ -n "$DBPASS" ] || { echo "La contraseña no puede ir vacía."; exit 1; }

echo "== 1/4 Instalando MariaDB y BIND =="
apt update
DEBIAN_FRONTEND=noninteractive apt install -y mariadb-server bind9 bind9utils

echo "== 2/4 Creando base de datos, usuario y tabla =="
sed "s/<contraseña_segura>/${DBPASS}/" "$DIR/sql/citas_medicas.sql" > /tmp/citas_setup.sql
# Si la tabla ya tiene datos, no vuelve a meter los de prueba
if mysql -N -e "SELECT COUNT(*) FROM citas_medicas.citas" 2>/dev/null | grep -qv '^0$'; then
  sed -i '/^-- Datos de prueba/,$d' /tmp/citas_setup.sql
fi
mysql < /tmp/citas_setup.sql
mysql -e "ALTER USER 'rh_app'@'%' IDENTIFIED BY '${DBPASS}'; FLUSH PRIVILEGES;"
rm -f /tmp/citas_setup.sql

echo "== 3/4 Abriendo MariaDB a conexiones remotas =="
CNF=/etc/mysql/mariadb.conf.d/50-server.cnf
sed -i 's/^\s*bind-address\s*=.*/bind-address = 0.0.0.0/' "$CNF"
systemctl restart mariadb
ufw allow 3306/tcp >/dev/null || true

echo "== 4/4 Configurando DNS interno empresa.local =="
if ! grep -q 'empresa.local' /etc/bind/named.conf.local; then
cat >> /etc/bind/named.conf.local <<EOF

zone "empresa.local" {
    type master;
    file "/etc/bind/db.empresa.local";
};
EOF
fi

cat > /etc/bind/db.empresa.local <<EOF
\$TTL 604800
@       IN  SOA ns.empresa.local. admin.empresa.local. (
            $(date +%Y%m%d)01 ; serial
            604800     ; refresh
            86400      ; retry
            2419200    ; expire
            604800 )   ; negative cache TTL
@       IN  NS  ns.empresa.local.
ns      IN  A   ${IP_BD}
db      IN  A   ${IP_BD}
citas   IN  A   ${IP_WEB}
EOF

named-checkconf
named-checkzone empresa.local /etc/bind/db.empresa.local
systemctl restart named 2>/dev/null || systemctl restart bind9
ufw allow 53 >/dev/null || true

echo
echo "== Verificación =="
systemctl is-active mariadb
systemctl is-active named 2>/dev/null || systemctl is-active bind9
dig +short @127.0.0.1 citas.empresa.local
dig +short @127.0.0.1 db.empresa.local
echo
echo "Listo. Ahora en la VM web corre:  sudo bash scripts/setup_web.sh ${IP_BD}"
echo "En las laptops de prueba, pon como DNS: ${IP_BD}"
