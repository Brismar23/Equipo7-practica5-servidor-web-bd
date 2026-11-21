# Sistema de Citas Médicas — ASI Práctica 5, Equipo 7

**Administración de Servicios de Internet · Grupo 2 · Semestre 2027-1 · FI UNAM**
Integrantes: Gómez Luna Brisa Mariana · Navarro Nuño Juan Pablo

Aplicación web CRUD de citas médicas con el flujo **Cliente → DNS → Apache/PHP (HTTPS) → MariaDB**, montada en dos máquinas virtuales de VirtualBox.

📄 Documentación completa (arquitectura, flujo de datos, código, manual de usuario, pruebas): [`docs/ASI_Practica5_Equipo7_Documentacion.pdf`](docs/ASI_Practica5_Equipo7_Documentacion.pdf)

## Arquitectura

| VM (VirtualBox) | Rol | Adaptador 1 | Adaptador 2 (Red interna `asi7`) | Nombre DNS |
|---|---|---|---|---|
| **ASI** (juanpablo-VirtualBox) | MariaDB 10.11 + BIND 9.18 | NAT | `192.168.1.1/24` | `db.empresa.local` |
| **cliente** (jp-VirtualBox) | Apache 2.4 + PHP 8.3 + HTTPS, y cliente de prueba | NAT | `192.168.1.10/24` | `citas.empresa.local` |

## Instalación rápida

**1. Red** (en VirtualBox, con cada VM apagada): Adaptador 1 = NAT; Adaptador 2 = Red interna, nombre `asi7`, cable conectado.

**2. IP fija** dentro de cada VM:
```bash
# VM ASI
sudo nmcli con add type ethernet ifname enp0s8 con-name asi7 ipv4.method manual ipv4.addresses 192.168.1.1/24
# VM cliente
sudo nmcli con add type ethernet ifname enp0s8 con-name asi7 ipv4.method manual ipv4.addresses 192.168.1.10/24

sudo nmcli con up asi7
```

**3. BD + DNS** (VM ASI):
```bash
sudo bash scripts/setup_bd_dns.sh 192.168.1.10 192.168.1.1
```

**4. Web + HTTPS** (VM cliente), con la misma contraseña del paso anterior:
```bash
sudo bash scripts/setup_web.sh 192.168.1.1
```

**5. DNS en el cliente:**
```bash
sudo nmcli con mod asi7 ipv4.dns 192.168.1.1 ipv4.dns-search "~empresa.local"
sudo nmcli con up asi7
sudo sed -i 's/mdns4_minimal \[NOTFOUND=return\] //' /etc/nsswitch.conf
```

**6. Probar:** abrir `https://citas.empresa.local` y aceptar el certificado autofirmado.

## Estructura

```
app/index.php             Aplicación CRUD (alta, consulta con filtro, edición/estado, baja)
app/config.example.php    Plantilla de conexión (el config.php real NO se sube)
sql/citas_medicas.sql     Base de datos, usuario, tabla y datos de prueba
scripts/setup_bd_dns.sh   Instala MariaDB + BIND (zona empresa.local)
scripts/setup_web.sh      Instala Apache + PHP + certificado + redirección HTTPS
docs/                     Documentación en PDF
```

## Problemas comunes

| Síntoma | Solución |
|---|---|
| `Destination Host Unreachable` entre VMs | Mismo nombre `asi7` en ambas, cable conectado, IP fija con `nmcli` |
| Sin internet (enp0s3 sin IP) | `sudo nmcli con add type ethernet ifname enp0s3 con-name nat ipv4.method auto && sudo nmcli con up nat` |
| "No se pudo conectar a la base de datos" | VM ASI encendida; `nc -zv 192.168.1.1 3306` |
| `citas.empresa.local` no resuelve | Paso 5; probar `nslookup citas.empresa.local` |
