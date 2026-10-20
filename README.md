# Caderninho 🌸

Wiki pessoal de página única para guardar anotações, procedimentos e prints organizados por categorias, com busca em português que ignora acentos.

Feito para rodar em um servidor próprio, sem Docker e sem dependências além do PHP e do PostgreSQL. É um único endpoint PHP, um `index.html` e uma senha de acesso.

## Como funciona

- **Categorias** com nome e emoji (a cor é fixa na paleta do app); o emoji é calculado a partir do nome (dicionário de termos contábeis, com fallback determinístico) e pode ser trocado num seletor de emojis; cada tópico pertence a uma categoria.
- **Tópicos** com título e corpo em HTML leve, com negrito, itálico, títulos, listas e imagens; `Enter` abre um parágrafo novo e `Shift+Enter` quebra a linha dentro do mesmo parágrafo (o corpo guarda `<p>`, `<div>` e `<br>`, então as quebras sobrevivem ao salvar); a criação/edição abre em tela cheia (título, categoria e ações no topo, texto numa coluna de leitura).
- **Lixeira**: excluir um tópico pede o título digitado como confirmação e o manda para a lixeira, onde ele fica **30 dias** (o próprio diálogo explica o prazo). Na lixeira dá para **restaurar** — escolhendo a categoria de destino — ou **excluir de vez**, com aviso de ação destrutiva e o título digitado de novo. Depois dos 30 dias o tópico (e as imagens que só ele usava) é apagado automaticamente.
- **Links** digitados no tópico (`https://…` ou `www.…`) viram âncoras ao concluir a palavra, colar ou salvar; abrem em nova aba ao clicar no tópico (`Ctrl`+clique dentro do editor). Corrigir o endereço desfaz a âncora e ela é refeita com o destino novo.
- **Busca** full-text em português com `unaccent` (buscar "orcamento" encontra "Orçamento") e por prefixo de palavra.
- **Imagens** coladas com `Ctrl+V` ou enviadas pelo botão 📷 abrem antes num editor em tela cheia, onde dá para recortar (arrastando sobre a imagem), desenhar à mão, traçar flechas e escrever texto por cima (arraste para demarcar a área da caixa de texto, que pode ser movida depois pela moldura ou pela alça da barra; acima dela ficam o tamanho da fonte em px e o fundo, branco translúcido por padrão; a caixa cresce em altura enquanto se digita, voltando ao tamanho demarcado ao apagar) e **censurar** (arraste um retângulo e o que estava atrás vira blocos: dá para ler o contexto, não o conteúdo) — 5 cores, 3 espessuras e desfazer (botão ou `Ctrl+Z`); só o resultado é enviado e inserido no ponto do texto onde o cursor estava. Ao excluir um tópico, as imagens que ninguém mais usa são apagadas do disco.
- **Login** por senha única, com limite de tentativas por IP e sessão em cookie `HttpOnly`/`SameSite=Lax`; o ícone de sair ao lado de "Nova categoria" encerra a sessão no servidor (POST com token CSRF) e volta para a tela de senha.
- **Aparência**: o **modo claro é o padrão**; o botão de lua/sol ao lado de "Novo tópico" alterna para o **modo noturno** e a escolha fica lembrada no navegador (`localStorage`), aplicada antes da primeira pintura para a tela não piscar clara ao abrir. Toda a paleta (barra lateral, cartões, diálogos, editor, lixeira, aviso) sai de variáveis CSS, então os dois temas usam a mesma estrutura — o conteúdo da imagem e as amostras de cor da caneta continuam com as cores reais.

## Requisitos

| Item | Versão |
|---|---|
| PHP | 8.0+ com `pdo_pgsql`, `fileinfo` e `php-xml` (DOMDocument, usado na sanitização do editor) |
| PostgreSQL | 12+ (coluna gerada `tsvector`, extensão `unaccent`) |
| nginx | qualquer versão com `fastcgi` |

Testado em Debian com nginx + php-fpm + PostgreSQL locais.

## Instalação automática (Debian)

Como root, no diretório do projeto:

```bash
sudo ./install.sh
```

O script pergunta a senha de acesso do app e o domínio/IP e então:

1. instala nginx, postgresql, php-fpm, php-pgsql e php-xml;
2. cria o usuário e o banco do PostgreSQL (senha aleatória, gravada só no `.env`);
3. aplica o `schema.sql` (pode ser reaplicado sem efeito colateral);
4. copia `public/` para `/var/www/caderninho/public`;
5. grava o `.env` fora do diretório público, com permissão `600`;
6. configura o site no nginx (com `/.user.ini` e uploads não executáveis) e recarrega os serviços;
7. instala a rotina de backup diária (script + timer do systemd, retenção de 7 dias);
8. opcionalmente emite certificado HTTPS via Certbot, se o host for um domínio.

Ao final: acesse `http://SEU_HOST` e entre com a senha informada.

**Rodar de novo é seguro** (`sudo ./install.sh`): se o `.env` (ou o antigo `config.php`) já existe, o script apenas atualiza os arquivos e o nginx, preservando banco, senha e conteúdo. Se o `schema.sql` mudou, ele avisa — aplique com o comando da seção [Atualização](#atualização).

## Instalação manual

1. Instale as dependências:

   ```bash
   apt-get install -y nginx postgresql php-fpm php-pgsql php-xml
   ```

2. Crie o banco e aplique o schema:

   ```bash
   su postgres -c "psql -c \"CREATE USER caderninho PASSWORD 'troque-esta-senha'\""
   su postgres -c "psql -c 'CREATE DATABASE caderninho OWNER caderninho'"
   su postgres -c "psql -d caderninho -c 'CREATE EXTENSION unaccent'"
   PGPASSWORD='troque-esta-senha' psql -h localhost -U caderninho -d caderninho -f schema.sql
   ```

3. Copie a aplicação para `/var/www/caderninho` e crie o `.env` **fora** do diretório público:

   ```bash
   mkdir -p /var/www/caderninho/public/uploads
   cp -r public/. /var/www/caderninho/public/
   cp .env.example /var/www/caderninho/.env
   ```

   Edite `/var/www/caderninho/.env` e preencha `DB_NAME`, `DB_USER`, `DB_PASS` e `APP_PASSWORD_HASH`. Gere o hash sem deixá-lo no histórico do shell:

   ```bash
   php -r 'echo password_hash(readline("Senha: "), PASSWORD_DEFAULT), "\n";'
   ```

   Senha forte para o banco: `openssl rand -hex 24`.

4. Permissões:

   ```bash
   chown -R www-data:www-data /var/www/caderninho
   chmod 600 /var/www/caderninho/.env
   ```

5. nginx (`/etc/nginx/sites-available/caderninho`), ajustando o socket do PHP:

   ```nginx
   server {
     listen 80;
     server_name wiki.exemplo.com;
     root /var/www/caderninho/public;
     index index.html;
     client_max_body_size 12M;

     add_header X-Content-Type-Options nosniff always;
     add_header Referrer-Policy no-referrer always;
     add_header X-Frame-Options DENY always;

     location ~ \.php$ {
       include snippets/fastcgi-php.conf;
       fastcgi_pass unix:/run/php/php8.2-fpm.sock;
     }

     # .user.ini e afins nunca são servidos (mas o ACME continua acessível)
     location ^~ /.well-known/ { allow all; }
     location ~ /\. { deny all; }

     # upload nunca é executado
     location /uploads/ {
       location ~ \.php$ { return 403; }
     }
   }
   ```

   ```bash
   ln -s /etc/nginx/sites-available/caderninho /etc/nginx/sites-enabled/
   nginx -t && systemctl reload nginx
   ```

6. **Use HTTPS.** Com um domínio apontado para o servidor:

   ```bash
   apt-get install -y certbot python3-certbot-nginx
   certbot --nginx -d wiki.exemplo.com
   ```

## Configuração

Tudo fica no `.env`, na raiz da instalação (um nível acima de `public/`, portanto fora do alcance do servidor web). O modelo é o `.env.example` — copie, preencha e proteja:

```bash
cp .env.example .env
chmod 600 .env && chown www-data:www-data .env
```

| Chave | Descrição |
|---|---|
| `DB_HOST` / `DB_PORT` / `DB_NAME` | localização e nome do banco |
| `DB_USER` / `DB_PASS` | credenciais do PostgreSQL |
| `DB_DSN` | DSN completa do PDO (opcional; tem precedência sobre host/porta/nome) |
| `APP_PASSWORD_HASH` | hash bcrypt da senha de login |

Formato do arquivo: `CHAVE=valor`, comentários apenas em linha inteira com `#`, sem interpolação de variáveis e sem escapes. Isso é proposital — o hash bcrypt contém cifrões (`$2y$12$...`) e precisa chegar intacto. Aspas em volta do valor são opcionais e removidas.

Para trocar a senha, gere um hash novo e substitua `APP_PASSWORD_HASH` (não precisa reiniciar serviços):

```bash
php -r 'echo password_hash(readline("Senha: "), PASSWORD_DEFAULT), "\n";'
```

Se a API responder `{"erro":"config","faltando":["pass"]}`, é exatamente a chave que falta no `.env` (o `error_log` registra o mesmo, sem expor valores).

### Migrar do `config.php` antigo

Versões anteriores guardavam as mesmas informações em `config.php` (`DSN`, `DB_USER`, `DB_PASS`, `APP_HASH`). O app ainda aceita esse arquivo, mas **o `.env` tem precedência** quando existe. Para migrar:

```bash
php -r 'require "/var/www/caderninho/config.php"; printf("DB_USER=%s\nDB_PASS=%s\nAPP_PASSWORD_HASH=%s\nDB_DSN=%s\n", DB_USER, DB_PASS, APP_HASH, DSN);' \
  > /var/www/caderninho/.env
chmod 600 /var/www/caderninho/.env && chown www-data:www-data /var/www/caderninho/.env
rm /var/www/caderninho/config.php   # depois de conferir que o login funciona
```

O limite de upload fica em `public/.user.ini` (`upload_max_filesize = 10M`, `post_max_size = 12M`), alinhado ao `client_max_body_size 12M` do nginx.

Esse arquivo é **copiado do repositório** a cada `install.sh`: para mudar o limite de forma permanente, edite `public/.user.ini` no repositório, ajuste o `client_max_body_size` no nginx e rode o instalador de novo (editar só a cópia em `/var/www` funciona até a próxima atualização).

## Segurança

O que a aplicação já faz:

- **Senha** guardada como hash bcrypt; comparação por `password_verify`.
- **Sessão** com `use_strict_mode`, `HttpOnly`, `SameSite=Lax` e `Secure` automático sob HTTPS; o id é regenerado no login (sem session fixation).
- **CSRF**: toda escrita exige o header `X-CSRF` com o token da sessão; o token é rotacionado a cada login.
- **Limite de login**: 8 falhas por IP em 15 minutos → HTTP 429.
- **HTML do editor** é sanitizado por *allowlist* de tags e atributos (DOMDocument): os únicos atributos que sobrevivem são `<img src>` apontando para `uploads/` e `<a href>` com `http(s)`/`mailto` (o resto — `target`, `rel` — é reescrito pelo servidor), e `script`/`iframe`/`style`/`svg` são removidos com o conteúdo. `javascript:`, `data:`, `file:` e endereços relativos perdem o link e mantêm o texto.
- **Uploads** validados por MIME real (`finfo` + `getimagesize`), nome aleatório, extensão derivada do tipo e diretório sem execução de PHP.
- **Respostas JSON** com `nosniff`, `no-referrer`, `frame-ancestors 'none'` e `Cache-Control: no-store`.
- **Segredos fora do docroot**: senha do banco e hash de login ficam no `.env` (permissão `600`, dono `www-data`), um nível acima de `public/`. O repositório não versiona nenhum segredo — `.env`, `.env.*` e `config.php` estão no `.gitignore`, e o modelo `.env.example` não tem valores reais.

Continua sendo **uma senha só, sem usuários**: não há autoria, permissão por tópico nem revogação individual. Não exponha na internet sem HTTPS.

## Estrutura

```
.
├── install.sh          # instalação em Debian (nginx + php-fpm + postgres)
├── schema.sql          # tabelas, índices, busca full-text (idempotente)
├── .env.example        # modelo de configuração (vai para o git)
├── .env                # SEGREDOS reais — gerado no install, fora do git
└── public/             # único diretório exposto pelo nginx
    ├── index.html      # SPA (HTML + CSS + JS inline)
    ├── api.php         # API JSON: ?a=acao
    ├── .user.ini       # limites de upload do PHP-FPM
    └── uploads/        # imagens enviadas pelo app
```

O `.env` fica **um nível acima** de `public/`, junto do `install.sh` — nunca dentro do diretório servido pelo nginx.

### API

Todas as respostas são JSON. Leitura por `GET`, escrita por `POST` (com `X-CSRF`).

| Ação | Método | Descrição |
|---|---|---|
| `me` | GET | estado da sessão + token CSRF |
| `login` | POST | `{"senha"}` |
| `cats` | GET | categorias com contagem de tópicos |
| `cat_save` | POST | `{"id"?, "name", "emoji"}` (a cor não é editável) |
| `cat_del` | POST | `{"id"}` (apaga os tópicos junto) |
| `topics` | GET | `?cat=&q=&limit=&offset=` |
| `topic` | GET | `?id=` |
| `topic_save` | POST | `{"id"?, "title", "category_id", "body"}` |
| `topic_del` | POST | `{"id"}` |
| `upload` | POST | multipart `f` (JPG, PNG, WEBP, GIF) |

## Backup

O backup é **automático**: um timer do systemd roda `/usr/local/bin/caderninho-backup` todo dia **às 03:17** (com até 20 minutos de variação) e grava **um arquivo por dia** em `/var/backups/caderninho/` — permissão `600`, dentro de um diretório `700`. A **retenção é de 7 dias**: ao gravar o oitavo, o mais antigo é apagado.

Cada pacote é um `.tar.gz` com duas coisas:

| Dentro do pacote | O que é |
|---|---|
| `db.dump` | banco inteiro no formato `pg_dump -Fc` |
| `uploads/` | as imagens dos tópicos |

O `.env` (ou `config.php`) **não entra** de propósito: ele guarda a senha do banco e o hash de acesso. Guarde-o em local separado — sem ele a instalação não sobe, embora o conteúdo possa ser restaurado em qualquer instalação nova.

Cada execução confere o dump com `pg_restore -l` antes de dar o backup como bom, e grava o arquivo por `rename` (nunca aparece pela metade). Se algo falhar, o serviço termina em erro e o arquivo do dia não é criado. Se o servidor estiver desligado no horário, o `Persistent=true` do timer roda o backup assim que ele ligar.

O pacote fica **no mesmo servidor**: isso protege de erro humano e de banco corrompido, não de perda do disco ou da máquina. Copie `/var/backups/caderninho/` para fora periodicamente (`rsync`, `scp`, armazenamento externo).

Comandos úteis:

```bash
sudo systemctl start caderninho-backup              # rodar agora
systemctl list-timers caderninho-backup.timer       # quando roda a próxima vez
journalctl -u caderninho-backup -n 30               # o que aconteceu nas execuções
ls -la /var/backups/caderninho                      # arquivos guardados
```

Para mudar horário ou retenção, edite `OnCalendar=` em `/etc/systemd/system/caderninho-backup.timer` ou `Environment=BACKUP_KEEP=` no `.service` e rode `sudo systemctl daemon-reload && sudo systemctl restart caderninho-backup.timer`. Rodar o `install.sh` de novo reescreve as duas units com os padrões; para mudar de forma permanente, use `sudo BACKUP_KEEP=14 ./install.sh` (o mesmo vale para `BACKUP_DIR`).

### Restaurar

```bash
mkdir -p /tmp/restore
tar xzf /var/backups/caderninho/caderninho-AAAA-MM-DD.tar.gz -C /tmp/restore
pg_restore -h localhost -U caderninho -d caderninho --clean --if-exists /tmp/restore/db.dump
cp -a /tmp/restore/uploads/. /var/www/caderninho/public/uploads/
chown -R www-data:www-data /var/www/caderninho/public/uploads
```

O backup manual (sem o timer) continua possível: `sudo APP_DIR=/var/www/caderninho /usr/local/bin/caderninho-backup`, ou direto do repositório com `sudo ./backup.sh`.

## Atualização

1. Atualize o código: `sudo ./install.sh` (se o `.env` já existe, ele só atualiza arquivos e nginx).
2. Aplique o schema — ele é idempotente e serve também como atualização de bancos antigos:

   ```bash
   PGPASSWORD='senha-do-banco' psql -h localhost -U caderninho -d caderninho -v ON_ERROR_STOP=1 -f schema.sql
   ```

   Em bancos criados por versões anteriores isso adiciona `created_at`, `deleted_at` (a lixeira, com índice próprio), cria `login_attempts` e remove a coluna morta `parent_id`. Em banco novo, nada acontece.

## Limitações conhecidas

- **Senha única**, sem usuários, autorias ou auditoria.
- **Upload de até 10 MB** (acima disso o PHP devolve 413 e a interface mostra o motivo).
- **Imagem sempre vira PNG**: o ajuste acontece em `canvas` e é exportado como PNG com o lado maior limitado a 2400 px — GIF animado perde a animação e fotos grandes são reduzidas.
- **Anotações são pintadas na imagem**: depois de confirmadas não dá para reeditar o texto nem mover o retângulo da censura — a caixa cresce em altura se o texto precisar de mais linhas que a área demarcada, e o desfazer continua valendo até inserir a imagem no tópico.
- **Limite de login por IP**: atrás de proxy reverso todos os acessos compartilham o IP do proxy — ajuste `client_ip()` em `api.php` se for o caso.
- **Editor usa `document.execCommand`**, obsoleto: funciona nos navegadores atuais, mas deve ser substituído por Markdown ou pela Selection API no futuro.
- **Sem CSP na página HTML**: o `index.html` tem CSS/JS inline, o que exige `script-src 'unsafe-inline'`; para uma CSP restritiva seria preciso extrair `app.js`/`app.css` e trocar os `onclick` inline por listeners.
- **Fontes vêm do Google Fonts** (requer internet; para uso offline, hospede as fontes localmente).
- **Sem migrações numeradas**: o `schema.sql` é idempotente, mas alterações estruturais futuras precisam ser escritas nele (com `IF NOT EXISTS`/`ADD COLUMN IF NOT EXISTS`).
- **Link só com esquema**: `exemplo.com` sem `https://` ou `www.` fica texto puro (não há adivinhação de domínio). Endereços antigos, gravados como texto antes desta versão, viram link na leitura.
- **Expurgo da lixeira é oportunista**: roda quando a lista, a lixeira ou as categorias são consultadas (não há cron). Um tópico pode passar dos 30 dias e só ser apagado no próximo acesso ao app.
- **Excluir a categoria apaga para sempre** os tópicos dela, inclusive os que estavam na lixeira — a lixeira não protege contra exclusão de categoria.
- **Backup no mesmo servidor**: os pacotes ficam em `/var/backups/caderninho`, no mesmo disco do banco — protege de erro humano, não de perda da máquina; a cópia para fora é manual.
- **Backup diário, sem point-in-time**: mudanças feitas depois das 03:17 só existem no pacote do dia seguinte.
- **Sem suíte de testes automatizados** no repositório.

## Licença

MIT — veja [LICENSE](LICENSE).
