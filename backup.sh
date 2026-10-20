#!/usr/bin/env bash
# Backup do Caderninho: banco (pg_dump) + imagens num único .tar.gz por dia.
#
# Rodado pelo timer systemd `caderninho-backup.timer` (ver README), mas também
# serve para uso manual:
#   sudo /usr/local/bin/caderninho-backup
#
# Variáveis de ambiente (os padrões vêm do install.sh):
#   APP_DIR     instalação do app            (padrão /var/www/caderninho)
#   BACKUP_DIR  onde os arquivos são salvos  (padrão /var/backups/caderninho)
#   BACKUP_KEEP quantos arquivos manter      (padrão 7)
#
# O que fica de fora de propósito: o .env/config.php, que guarda a senha do
# banco e o hash de acesso. Sem ele a instalação não sobe, então ele pede
# guarda separada (ver README, seção Backup).
set -euo pipefail
umask 077

APP_DIR="${APP_DIR:-/var/www/caderninho}"
DEST="${BACKUP_DIR:-/var/backups/caderninho}"
KEEP="${BACKUP_KEEP:-7}"
PREFIX="caderninho-"

log() { printf '%s %s\n' "$(date '+%F %T')" "$*"; }
die() { log "ERRO: $*" >&2; exit 1; }

[[ "$KEEP" =~ ^[0-9]+$ ]] && [ "$KEEP" -ge 1 ] || die "BACKUP_KEEP inválido: $KEEP"
[ -d "$APP_DIR/public" ] || die "APP_DIR inválido (sem public/): $APP_DIR"
for bin in php pg_dump pg_restore tar; do
  command -v "$bin" >/dev/null 2>&1 || die "$bin não encontrado no PATH"
done

# Lê a configuração do banco do mesmo jeito que o api.php: .env primeiro,
# config.php (formato antigo) como alternativa. Imprime DSN, usuário e senha,
# um por linha — nada além disso vai para a saída.
ler_config() {
  php -r '
    $root = $argv[1];
    $env  = [];
    if (is_readable($root . "/.env")) {
        foreach (file($root . "/.env", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $l = trim($l);
            if ($l === "" || $l[0] === "#") continue;
            if (str_starts_with($l, "export ")) $l = trim(substr($l, 7));
            $p = strpos($l, "=");
            if ($p === false) continue;
            $k = trim(substr($l, 0, $p));
            $v = trim(substr($l, $p + 1));
            if (strlen($v) >= 2 && ($v[0] === "\"" || $v[0] === "\x27") && $v[-1] === $v[0]) $v = substr($v, 1, -1);
            $env[$k] = $v;
        }
    }
    $dsn  = $env["DB_DSN"] ?? "";
    $user = $env["DB_USER"] ?? "";
    $pass = $env["DB_PASS"] ?? "";
    if ($dsn === "" && ($env["DB_NAME"] ?? "") !== "") {
        $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s",
            $env["DB_HOST"] ?? "localhost", $env["DB_PORT"] ?? "5432", $env["DB_NAME"]);
    }
    if ($user === "" && is_readable($root . "/config.php")) {
        require $root . "/config.php";
        $dsn  = defined("DSN")     ? (string) DSN     : $dsn;
        $user = defined("DB_USER") ? (string) DB_USER : $user;
        $pass = defined("DB_PASS") ? (string) DB_PASS : $pass;
    }
    if ($dsn === "" || $user === "") {
        fwrite(STDERR, "configuração do banco não encontrada em .env/config.php\n");
        exit(1);   // PHP 8.4 recusa `exit 1` sem parênteses
    }
    echo $dsn, "\n", $user, "\n", $pass, "\n";
  ' -- "$APP_DIR"
}

mapfile -t CFG < <(ler_config) || true
[ "${#CFG[@]}" -eq 3 ] || die "não foi possível ler a configuração do banco em $APP_DIR"

DSN="${CFG[0]}"
DB_USER="${CFG[1]}"
DB_PASS="${CFG[2]}"

# DSN do PDO: pgsql:host=...;port=...;dbname=...
DB_HOST=localhost
DB_PORT=5432
DB_NAME=""
IFS=';' read -r -a DSN_PARTS <<<"${DSN#pgsql:}"
for kv in "${DSN_PARTS[@]}"; do
  case "$kv" in
    host=*) DB_HOST="${kv#host=}" ;;
    port=*) DB_PORT="${kv#port=}" ;;
    dbname=*) DB_NAME="${kv#dbname=}" ;;
  esac
done
[ -n "$DB_NAME" ] || die "dbname ausente na DSN"
[ -n "$DB_HOST" ] || die "host ausente na DSN"

mkdir -p "$DEST"
chmod 700 "$DEST"

# Duas execuções ao mesmo tempo (timer + manual) escreveriam o mesmo arquivo.
exec 9>"$DEST/.lock"
flock -n 9 || die "outra execução do backup ainda está em andamento"

# Área de trabalho no mesmo sistema de arquivos do destino: o arquivo final
# aparece por rename, nunca pela metade.
WORK="$(mktemp -d "$DEST/.work.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

log "banco: $DB_NAME em $DB_HOST:$DB_PORT (usuário $DB_USER)"
PGPASSWORD="$DB_PASS" pg_dump \
  --no-owner --no-privileges \
  -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
  -Fc -f "$WORK/db.dump" || die "pg_dump falhou"

# Confere se o dump é legível antes de dar o backup como bom.
pg_restore -l "$WORK/db.dump" >/dev/null 2>&1 || die "dump gerado está inválido"

log "imagens: $APP_DIR/public/uploads"
tar -czf "$WORK/backup.tar.gz" \
  -C "$WORK" db.dump \
  -C "$APP_DIR/public" uploads || die "falha ao empacotar uploads"

FINAL="$DEST/${PREFIX}$(date +%F).tar.gz"
mv -f "$WORK/backup.tar.gz" "$FINAL"
chmod 600 "$FINAL"

# Retenção: mantém os $KEEP mais recentes (os nomes são datas, então ordenam certo).
mapfile -t ANTIGOS < <(
  find "$DEST" -maxdepth 1 -type f -name "${PREFIX}*.tar.gz" -printf '%f\n' \
    | sort -r | tail -n +$((KEEP + 1))
)
for f in "${ANTIGOS[@]}"; do
  rm -f -- "$DEST/$f"
  log "removido pela retenção de $KEEP dias: $f"
done

TOTAL="$(find "$DEST" -maxdepth 1 -type f -name "${PREFIX}*.tar.gz" | wc -l)"
log "backup concluído: $FINAL ($(du -h "$FINAL" | cut -f1)); $TOTAL arquivo(s), retenção de $KEEP dias"
