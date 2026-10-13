#!/usr/bin/env bash
# Instalação direta no Debian/Ubuntu (sem Docker): nginx + php-fpm + postgresql.
# Pode ser executado novamente: preserva .env (ou config.php), banco e conteúdo.
set -euo pipefail

[ "${EUID:-$(id -u)}" -eq 0 ] || { echo "Rode como root: sudo ./install.sh" >&2; exit 1; }
command -v apt-get >/dev/null || { echo "Este instalador é para Debian/Ubuntu." >&2; exit 1; }

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIR="${APP_DIR:-/var/www/caderninho}"
ENVFILE="$DIR/.env"
CONF="$DIR/config.php"   # formato antigo, ainda aceito pelo app
DB_NAME="${DB_NAME:-caderninho}"
DB_USER="${DB_USER:-caderninho}"

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
die() { printf 'erro: %s\n' "$*" >&2; exit 1; }
warn() { printf '\033[33maviso: %s\033[0m\n' "$*"; }

[ -f "$SRC/schema.sql" ] || die "schema.sql não encontrado em $SRC"
[ -f "$SRC/public/api.php" ] || die "public/api.php não encontrado em $SRC"

say "Dependências (nginx, postgresql, php-fpm, php-pgsql, php-xml)"
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq nginx postgresql php-fpm php-pgsql php-xml >/dev/null
command -v php >/dev/null || die "php não instalado"
php -r 'exit(class_exists("DOMDocument") ? 0 : 1);' || die "php-xml ausente: a sanitização do editor precisa de DOMDocument"

FPM_SVC="$(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null | awk '{print $1}' | head -n1 || true)"
FPM_SVC="${FPM_SVC%.service}"
if command -v systemctl >/dev/null && systemctl is-system-running >/dev/null 2>&1; then
  systemctl enable --now nginx postgresql ${FPM_SVC:+$FPM_SVC} >/dev/null 2>&1 || warn "não foi possível habilitar algum serviço"
else
  warn "systemd indisponível: suba nginx, postgresql e php-fpm manualmente"
fi

if [ -f "$ENVFILE" ] || [ -f "$CONF" ]; then
  say "Configuração existente encontrada — mantendo $([ -f "$ENVFILE" ] && echo "$ENVFILE" || echo "$CONF")"
  say "Arquivos e nginx serão atualizados; banco e senha ficam como estão"
  cp -r "$SRC/public/." "$DIR/public/"
  mkdir -p "$DIR/public/uploads"
  warn "se o schema.sql mudou, aplique: PGPASSWORD=... psql -h localhost -U $DB_USER -d $DB_NAME -f $SRC/schema.sql"
else
  say "Configuração do aplicativo"
  read -rsp "Senha de acesso do app (mínimo 8 caracteres): " SENHA; echo
  [ "${#SENHA}" -ge 8 ] || die "senha curta demais (mínimo 8 caracteres)"

  read -rp "Domínio ou IP para o nginx (ex: wiki.local): " HOST
  [[ "$HOST" =~ ^[A-Za-z0-9._:-]+$ ]] || die "host inválido: use apenas letras, números, ponto, hífen ou dois-pontos"

  say "Banco de dados"
  PGPASS="$(openssl rand -hex 24)"
  TMP_SQL="$(mktemp)"
  chmod 600 "$TMP_SQL"
  # Senha vai em arquivo temporário (nunca em argv do psql, visível no ps).
  cat >"$TMP_SQL" <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$DB_USER') THEN
    CREATE ROLE $DB_USER LOGIN PASSWORD '$PGPASS';
  ELSE
    ALTER ROLE $DB_USER PASSWORD '$PGPASS';
  END IF;
END
\$\$;
SQL
  chown postgres:postgres "$TMP_SQL"
  su postgres -c "psql -q -v ON_ERROR_STOP=1 -f '$TMP_SQL'"
  rm -f "$TMP_SQL"

  su postgres -c "psql -tAc \"SELECT 1 FROM pg_database WHERE datname = '$DB_NAME'\"" | grep -q 1 \
    || su postgres -c "createdb -O '$DB_USER' '$DB_NAME'"
  su postgres -c "psql -q -d '$DB_NAME' -c 'CREATE EXTENSION IF NOT EXISTS unaccent;'"

  say "Schema (idempotente)"
  PGPASSWORD="$PGPASS" psql -q -v ON_ERROR_STOP=1 -h localhost -U "$DB_USER" -d "$DB_NAME" -f "$SRC/schema.sql"

  say "Arquivos"
  mkdir -p "$DIR/public/uploads"
  cp -r "$SRC/public/." "$DIR/public/"

  # Senha via variável de ambiente: não aparece em `ps`.
  HASH="$(CAD_PASS="$SENHA" php -r 'echo password_hash(getenv("CAD_PASS"), PASSWORD_DEFAULT);')"
  umask 077
  cat >"$ENVFILE" <<ENVF
# Configuração do Caderninho (gerada por install.sh).
# NÃO versionar: ver .gitignore. Permissões: 600, dono www-data.
DB_HOST=localhost
DB_PORT=5432
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$PGPASS
APP_PASSWORD_HASH=$HASH
ENVF
  unset SENHA HASH
fi

# Limites de upload vêm de public/.user.ini, copiado junto com o app
# (a fonte de verdade é o repositório: edite lá e rode o install.sh de novo).

SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1 || true)"
[ -n "$SOCK" ] || die "socket do php-fpm não encontrado em /run/php/"

chown -R www-data:www-data "$DIR"
for f in "$ENVFILE" "$CONF"; do
  [ -f "$f" ] && chmod 600 "$f"
done
chmod 640 "$DIR/public/.user.ini"

if { [ -f "$ENVFILE" ] || [ -f "$CONF" ]; } && [ -z "${HOST:-}" ]; then
  # Reexecução: reaproveita o server_name já configurado.
  HOST="$(grep -oP 'server_name \K[^;]+' /etc/nginx/sites-available/caderninho 2>/dev/null | head -n1 || true)"
  [ -n "$HOST" ] || HOST="_"
fi

say "nginx"
cat >/etc/nginx/sites-available/caderninho <<NGX
server {
  listen 80;
  server_name $HOST;
  root $DIR/public;
  index index.html;
  client_max_body_size 12M;

  add_header X-Content-Type-Options nosniff always;
  add_header Referrer-Policy no-referrer always;
  add_header X-Frame-Options DENY always;

  location ~ \.php\$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:$SOCK;
  }

  # .user.ini, .git e afins nunca são servidos (mas o ACME continua acessível)
  location ^~ /.well-known/ { allow all; }
  location ~ /\. { deny all; }

  # upload nunca é executado
  location /uploads/ {
    location ~ \.php\$ { return 403; }
  }
}
NGX
ln -sf /etc/nginx/sites-available/caderninho /etc/nginx/sites-enabled/caderninho
nginx -t
systemctl reload nginx 2>/dev/null || warn "recarregue o nginx manualmente (systemctl reload nginx)"

if command -v systemctl >/dev/null && [ -n "${FPM_SVC:-}" ]; then
  systemctl reload "$FPM_SVC" 2>/dev/null || warn "recarregue o php-fpm manualmente"
fi

if [[ "${HOST:-}" =~ ^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] && ! [ -e /etc/letsencrypt/live/"$HOST" ]; then
  read -rp "Configurar HTTPS agora com Certbot para $HOST? [s/N] " resp || true
  if [[ "${resp:-}" =~ ^[sSyY]$ ]]; then
    read -rp "E-mail para o Let's Encrypt: " EMAIL
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq certbot python3-certbot-nginx >/dev/null
    certbot --nginx -d "$HOST" --non-interactive --agree-tos --email "$EMAIL" --redirect \
      && say "HTTPS ativo em https://$HOST"
  fi
fi

say "Pronto"
echo "Acesse http://$HOST e entre com a senha informada."
echo "Configuração: $ENVFILE (não versionar, permissão 600)"
echo "Backup: veja a seção Backup do README.md"
