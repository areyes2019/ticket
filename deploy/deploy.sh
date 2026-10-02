#!/usr/bin/env bash
#
# Despliega la rama main a producción (SITE_URL de deploy/config.sh).
#
#   bash deploy/deploy.sh               detecta solo si hay migraciones
#   bash deploy/deploy.sh --sin-migrar  no toca la base aunque haya migraciones
#   bash deploy/deploy.sh --verificar   solo comprueba el sitio publicado
#
# Se corre desde la máquina de desarrollo con Git Bash. Lo que hace:
#
#   1. Exige árbol limpio y main igual a origin/main.
#   2. Lee del servidor el commit desplegado (.desplegado) y calcula el diff.
#   3. Se detiene si cambió .env.example: el .env del servidor no tiene las
#      variables nuevas y hay que agregarlas a mano antes.
#   4. Sube main empaquetado con git archive (el servidor no tiene acceso a
#      GitHub) y lo sincroniza con rsync --delete sobre la instalación.
#   5. composer install --no-scripts + package:discover (Hostinger no tiene
#      proc_open).
#   6. Si hay migraciones nuevas: modo mantenimiento, respaldo con mysqldump
#      en ~/backups y migrate --force.
#   7. Recachea, sale de mantenimiento, comprueba el sitio y anota el commit.
#
# Nunca toca: .env, storage/, vendor/ (lo maneja composer), bootstrap/cache/
# ni el enlace public/storage.

set -euo pipefail

# Los datos del servidor viven en deploy/config.sh, que no se versiona. La
# plantilla es deploy/config.example.sh.
CONFIG="$(dirname "$0")/config.sh"
[ -f "$CONFIG" ] || { echo "falta $CONFIG (cópialo de deploy/config.example.sh y llénalo)" >&2; exit 1; }
# shellcheck source=/dev/null
. "$CONFIG"
: "${SSH_ALIAS:?falta SSH_ALIAS en deploy/config.sh}"
: "${REMOTE_APP:?falta REMOTE_APP en deploy/config.sh}"
: "${SITE_URL:?falta SITE_URL en deploy/config.sh}"
REMOTE_PHP="${REMOTE_PHP:-/usr/bin/php}"
RAMA="main"

MIGRAR="auto"
SOLO_VERIFICAR=0
for arg in "$@"; do
    case "$arg" in
        --sin-migrar) MIGRAR="no" ;;
        --verificar)  SOLO_VERIFICAR=1 ;;
        *) echo "argumento desconocido: $arg" >&2; exit 2 ;;
    esac
done

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32mok\033[0m %s\n' "$*"; }
warn() { printf '    \033[33m!!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# El servidor imprime un aviso de OpenSSH en cada conexión; se filtra para que
# no tape la salida útil.
remote() { ssh "$SSH_ALIAS" "$@" 2> >(grep -v -i 'post-quantum\|store now\|server may need' >&2); }

cd "$(git rev-parse --show-toplevel)"

verificar() {
    say "Comprobando $SITE_URL"
    local fallo=0 codigo
    for ruta in /up /login /css/app.css; do
        codigo="$(curl -s -o /dev/null -w '%{http_code}' "$SITE_URL$ruta")"
        if [ "$codigo" = "200" ]; then ok "$ruta -> $codigo"; else warn "$ruta -> $codigo"; fallo=1; fi
    done
    curl -s "$SITE_URL/login" | grep -q 'name="_token"' \
        && ok "el formulario de login trae token CSRF" \
        || { warn "el login no trae token CSRF"; fallo=1; }
    return $fallo
}

if [ "$SOLO_VERIFICAR" = "1" ]; then
    verificar && exit 0 || die "alguna comprobación falló"
fi

# --- 1. Estado local ---------------------------------------------------------
say "Revisando el repositorio"
[ -z "$(git status --porcelain)" ] || die "hay cambios sin commitear:
$(git status --short)"

git fetch -q origin "$RAMA"
LOCAL="$(git rev-parse "$RAMA")"
ORIGEN="$(git rev-parse "origin/$RAMA")"
[ "$LOCAL" = "$ORIGEN" ] || die "$RAMA local ($LOCAL) no es igual a origin/$RAMA ($ORIGEN).
       Haz push (o pull) primero: producción no debe adelantarse ni atrasarse respecto a GitHub."
ok "$RAMA = origin/$RAMA = ${LOCAL:0:7}"

# --- 2. Qué cambió -----------------------------------------------------------
say "Comparando con lo desplegado"
DESPLEGADO="$(remote "cat '$REMOTE_APP/.desplegado' 2>/dev/null || true" | tr -d '[:space:]')"

if [ -z "$DESPLEGADO" ]; then
    warn "el servidor no tiene .desplegado: se trata todo como cambiado"
    CAMBIOS="$(git ls-tree -r --name-only "$LOCAL")"
elif ! git cat-file -e "$DESPLEGADO^{commit}" 2>/dev/null; then
    die "el commit desplegado ($DESPLEGADO) no existe en este repositorio local"
elif [ "$DESPLEGADO" = "$LOCAL" ]; then
    ok "producción ya está en ${LOCAL:0:7}; no hay nada que desplegar"
    verificar || die "alguna comprobación falló"
    exit 0
else
    CAMBIOS="$(git diff --name-only "$DESPLEGADO" "$LOCAL")"
    ok "desplegado: ${DESPLEGADO:0:7}  ->  nuevo: ${LOCAL:0:7}  ($(echo "$CAMBIOS" | wc -l | tr -d ' ') archivos)"
    git --no-pager log --oneline "$DESPLEGADO..$LOCAL" | sed 's/^/      /'
fi

# --- 3. Variables de entorno nuevas -----------------------------------------
if [ -n "$DESPLEGADO" ] && echo "$CAMBIOS" | grep -qx '.env.example'; then
    echo
    git --no-pager diff "$DESPLEGADO" "$LOCAL" -- .env.example
    die "cambió .env.example. Agrega primero las variables nuevas al .env del servidor:
       ssh $SSH_ALIAS -t \"nano '$REMOTE_APP/.env'\"
       y vuelve a correr este script."
fi

HAY_MIGRACIONES=0
if echo "$CAMBIOS" | grep -q '^database/migrations/'; then HAY_MIGRACIONES=1; fi
if [ "$MIGRAR" = "no" ] && [ "$HAY_MIGRACIONES" = "1" ]; then
    warn "--sin-migrar con migraciones nuevas: el código puede esperar columnas que todavía no existen"
fi
[ "$MIGRAR" = "auto" ] && { [ "$HAY_MIGRACIONES" = "1" ] && MIGRAR="si" || MIGRAR="no"; }

# --- 4. Subir el código ------------------------------------------------------
say "Subiendo ${LOCAL:0:7}"
STAGING="$REMOTE_APP.subida"
git archive --format=tar.gz "$LOCAL" \
    | remote "rm -rf '$STAGING' && mkdir -p '$STAGING' && tar xzf - -C '$STAGING'" \
    || die "no se pudo subir el código"
ok "código descomprimido en $STAGING"

# rsync --delete borra del servidor lo que ya no está en el repo. Lo excluido
# no se borra (no se usa --delete-excluded).
remote "rsync -a --delete \
    --exclude='/.env' \
    --exclude='/.desplegado' \
    --exclude='/storage/' \
    --exclude='/vendor/' \
    --exclude='/bootstrap/cache/' \
    --exclude='/public/storage' \
    '$STAGING/' '$REMOTE_APP/' && rm -rf '$STAGING'" \
    || die "falló la sincronización; revisa $STAGING en el servidor"
ok "instalación sincronizada"

# --- 5. Dependencias ---------------------------------------------------------
say "Instalando dependencias"
remote "cd '$REMOTE_APP' && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts 2>&1 | tail -3 \
    && $REMOTE_PHP artisan package:discover --no-ansi >/dev/null" \
    || die "falló composer install"
ok "vendor al día"

# --- 6. Base de datos --------------------------------------------------------
EN_MANTENIMIENTO=0
salir_de_mantenimiento() {
    if [ "$EN_MANTENIMIENTO" = "1" ]; then
        warn "saliendo del modo mantenimiento tras un error"
        remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan up" || true
    fi
}
trap salir_de_mantenimiento EXIT

if [ "$MIGRAR" = "si" ]; then
    say "Migraciones"
    remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan down --retry=15 --no-ansi"
    EN_MANTENIMIENTO=1

    remote "APP_DIR='$REMOTE_APP' bash -s" <<'FIN_RESPALDO'
set -euo pipefail
cd "$APP_DIR"
leer_env() {
    sed -n "s/^$1=//p" .env | head -1 \
        | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}
DB_DATABASE="$(leer_env DB_DATABASE)"
DB_USERNAME="$(leer_env DB_USERNAME)"
DB_PASSWORD="$(leer_env DB_PASSWORD)"
[ -n "$DB_DATABASE" ] || { echo "no pude leer DB_DATABASE de .env" >&2; exit 1; }

mkdir -p ~/backups
DESTINO=~/backups/ticket-$(date +%Y%m%d-%H%M%S).sql.gz
# MYSQL_PWD en vez de -p: la contraseña no queda en la lista de procesos.
MYSQL_PWD="$DB_PASSWORD" mysqldump --single-transaction --quick --no-tablespaces \
    -u"$DB_USERNAME" "$DB_DATABASE" | gzip > "$DESTINO"
echo "    respaldo: $DESTINO ($(du -h "$DESTINO" | cut -f1))"
# Conserva los 10 más recientes.
ls -1t ~/backups/ticket-*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm --
FIN_RESPALDO

    # Sin configuración cacheada de la versión anterior durante la migración.
    remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan config:clear --no-ansi >/dev/null && $REMOTE_PHP artisan migrate --force --no-ansi"
    ok "migraciones aplicadas"
else
    ok "sin migraciones"
fi

# --- 7. Cachés, salida y verificación ----------------------------------------
say "Recacheando"
remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan optimize:clear --no-ansi >/dev/null && $REMOTE_PHP artisan optimize --no-ansi >/dev/null"
ok "config, rutas, eventos y vistas en caché"

if [ "$EN_MANTENIMIENTO" = "1" ]; then
    remote "cd '$REMOTE_APP' && $REMOTE_PHP artisan up --no-ansi" >/dev/null
    EN_MANTENIMIENTO=0
    ok "fuera de mantenimiento"
fi

verificar || die "el código ya está arriba pero alguna comprobación falló; no se anotó .desplegado"

remote "echo '$LOCAL' > '$REMOTE_APP/.desplegado'"
say "Listo: producción en ${LOCAL:0:7}"
