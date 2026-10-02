#!/usr/bin/env bash
# Plantilla de deploy/config.sh. Cópiala, llénala con los datos reales del
# servidor y no la versiones: deploy/config.sh está en .gitignore.
#
#   cp deploy/config.example.sh deploy/config.sh

# Alias de ~/.ssh/config con host, usuario, puerto y llave del servidor.
SSH_ALIAS="mi-servidor"

# Carpeta de la aplicación en el servidor. El docroot del dominio es un enlace
# simbólico a "$REMOTE_APP/public".
REMOTE_APP="/home/usuario/domains/ejemplo.com/ticket_factura"

# PHP de línea de comandos en el servidor.
REMOTE_PHP="/usr/bin/php"

# URL pública que verifica el script al terminar.
SITE_URL="https://ejemplo.com"
