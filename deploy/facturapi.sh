#!/usr/bin/env bash
#
# Ambiente de facturapi.io en producción (servidor de deploy/config.sh).
#
#   bash deploy/facturapi.sh               muestra el estado (igual que "estado")
#   bash deploy/facturapi.sh estado        ambiente activo y si cada llave existe,
#                                          coincide con el .env local y la acepta facturapi.io
#   bash deploy/facturapi.sh subir-llaves  copia FACTURAPI_TEST_KEY y FACTURAPI_LIVE_KEY
#                                          del .env local al del servidor (solo las que no
#                                          estén vacías); no cambia el ambiente
#   bash deploy/facturapi.sh live          cambia a live: las facturas tienen validez ante el SAT
#   bash deploy/facturapi.sh test          cambia a test: las facturas son de prueba
#
# "live" pide escribir LIVE para confirmar; --si lo omite.
#
# Las llaves nunca se imprimen ni van en la línea de comandos: viajan por la
# entrada de ssh y se comparan por huella (primeros caracteres del SHA-256).
# Antes de modificar el .env del servidor se guarda una copia .env.bak-<fecha>.

set -euo pipefail

CONFIG="$(dirname "$0")/config.sh"
[ -f "$CONFIG" ] || { echo "falta $CONFIG (cópialo de deploy/config.example.sh y llénalo)" >&2; exit 1; }
# shellcheck source=/dev/null
. "$CONFIG"
: "${SSH_ALIAS:?falta SSH_ALIAS en deploy/config.sh}"
: "${REMOTE_APP:?falta REMOTE_APP en deploy/config.sh}"
REMOTE_PHP="${REMOTE_PHP:-/usr/bin/php}"

ACCION="estado"
CONFIRMADO=0
for arg in "$@"; do
    case "$arg" in
        estado|subir-llaves|live|test) ACCION="$arg" ;;
        --si) CONFIRMADO=1 ;;
        *) echo "argumento desconocido: $arg" >&2; exit 2 ;;
    esac
done

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32mok\033[0m %s\n' "$*"; }
warn() { printf '    \033[33m!!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

remote() { ssh "$SSH_ALIAS" "$@" 2> >(grep -v -i 'post-quantum\|store now\|server may need' >&2); }

cd "$(git rev-parse --show-toplevel)"

leer_local() {
    sed -n "s/^$1=//p" .env | head -1 | tr -d '\r' \
        | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
        | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

huella() {
    if [ -z "$1" ]; then echo "vacía"; else printf '%s' "$1" | sha256sum | cut -c1-10; fi
}

# Funciones que se anteponen a cada script que corre en el servidor.
REMOTO_COMUN='
set -euo pipefail
cd "$APP_DIR"
leer_env() {
    sed -n "s/^$1=//p" .env | head -1 | tr -d "\r" \
        | sed -e "s/^[[:space:]]*//" -e "s/[[:space:]]*$//" \
        | sed -e "s/^\"//" -e "s/\"$//" -e "s/^'"'"'//" -e "s/'"'"'$//"
}
huella() {
    if [ -z "$1" ]; then echo "vacía"; else printf "%s" "$1" | sha256sum | cut -c1-10; fi
}
poner_env() {
    if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else printf "%s=%s\n" "$1" "$2" >> .env; fi
}
respaldar_env() {
    cp .env ".env.bak-$(date +%Y%m%d-%H%M%S)"
    # Conserva los 5 respaldos más recientes.
    ls -1t .env.bak-* 2>/dev/null | tail -n +6 | xargs -r rm --
}
# 200 si facturapi.io acepta la llave. La llave va por -K (entrada estándar),
# no como argumento, para que no aparezca en la lista de procesos.
probar_llave() {
    [ -n "$1" ] || { echo "-"; return; }
    printf "header = \"Authorization: Bearer %s\"\n" "$1" \
        | curl -s -K - -o /dev/null -w "%{http_code}" --max-time 15 "https://www.facturapi.io/v2/customers?limit=1" || echo "sin conexión"
}
'

estado() {
    say "facturapi.io en producción"
    remote "APP_DIR='$REMOTE_APP' HUELLA_LOCAL_TEST='$(huella "$(leer_local FACTURAPI_TEST_KEY)")' HUELLA_LOCAL_LIVE='$(huella "$(leer_local FACTURAPI_LIVE_KEY)")' bash -s" <<FIN
$REMOTO_COMUN
AMBIENTE="\$(leer_env FACTURAPI_ENV)"
echo "    ambiente activo: \${AMBIENTE:-test (por omisión)}"
for modo in test live; do
    llave="\$(leer_env "FACTURAPI_\${modo^^}_KEY")"
    local_var="HUELLA_LOCAL_\${modo^^}"
    remota="\$(huella "\$llave")"
    if [ "\$remota" = "vacía" ]; then
        comparacion="vacía en el servidor"
    elif [ "\$remota" = "\${!local_var}" ]; then
        comparacion="igual a la del .env local"
    else
        comparacion="distinta de la del .env local"
    fi
    echo "    llave \$modo: \$comparacion · facturapi.io responde \$(probar_llave "\$llave")"
done
FIN
    echo "    (200 = llave válida; 401 = llave rechazada; - = sin llave)"
}

recachear() {
    remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan config:clear --no-ansi >/dev/null && $REMOTE_PHP artisan config:cache --no-ansi >/dev/null"
    ok "configuración recacheada"
}

subir_llaves() {
    say "Subiendo llaves del .env local"
    local test live
    test="$(leer_local FACTURAPI_TEST_KEY)"
    live="$(leer_local FACTURAPI_LIVE_KEY)"
    [ -n "$test$live" ] || die "el .env local no tiene FACTURAPI_TEST_KEY ni FACTURAPI_LIVE_KEY"
    for valor in "$test" "$live"; do
        [[ -z "$valor" || "$valor" =~ ^[A-Za-z0-9_]+$ ]] || die "una llave del .env local tiene caracteres inesperados"
    done

    # Las llaves viajan en las dos primeras líneas de la entrada, no en la línea de comandos.
    { printf '%s\n%s\n' "$test" "$live"; cat <<FIN
$REMOTO_COMUN
respaldar_env
if [ -n "\$TEST" ]; then poner_env FACTURAPI_TEST_KEY "\$TEST"; echo "    ok FACTURAPI_TEST_KEY actualizada"; fi
if [ -n "\$LIVE" ]; then poner_env FACTURAPI_LIVE_KEY "\$LIVE"; echo "    ok FACTURAPI_LIVE_KEY actualizada"; fi
FIN
    } | remote "read -r TEST; read -r LIVE; export TEST LIVE; APP_DIR='$REMOTE_APP' bash -s"

    recachear
}

cambiar_ambiente() {
    local modo="$1"
    say "Cambiando producción a $modo"

    if [ "$modo" = "live" ] && [ "$CONFIRMADO" != "1" ]; then
        echo "    En live cada factura timbrada es real: tiene validez ante el SAT y consume folios."
        read -r -p "    Escribe LIVE para confirmar: " respuesta
        [ "$respuesta" = "LIVE" ] || die "cancelado"
    fi

    remote "APP_DIR='$REMOTE_APP' MODO='$modo' bash -s" <<FIN
$REMOTO_COMUN
llave="\$(leer_env "FACTURAPI_\${MODO^^}_KEY")"
[ -n "\$llave" ] || { echo "FACTURAPI_\${MODO^^}_KEY está vacía en el servidor; corre primero: bash deploy/facturapi.sh subir-llaves" >&2; exit 1; }
codigo="\$(probar_llave "\$llave")"
[ "\$codigo" = "200" ] || { echo "facturapi.io rechaza FACTURAPI_\${MODO^^}_KEY (respondió \$codigo); no se cambió nada" >&2; exit 1; }
respaldar_env
poner_env FACTURAPI_ENV "\$MODO"
echo "    ok FACTURAPI_ENV=\$MODO"
FIN

    recachear
}

case "$ACCION" in
    estado) ;;
    subir-llaves) subir_llaves ;;
    live|test) cambiar_ambiente "$ACCION" ;;
esac

estado
