# Caderninho 🌸

Wiki pessoal de página única para guardar anotações, procedimentos e prints organizados por categorias, com busca em português que ignora acentos.

Feito para rodar em um servidor próprio, sem Docker e sem dependências além do PHP e do PostgreSQL. É um único endpoint PHP, um `index.html` e uma senha de acesso.

## Como funciona

- **Categorias** com nome e emoji (a cor é fixa na paleta do app); o emoji é calculado a partir do nome (dicionário de termos contábeis, com fallback determinístico) e pode ser trocado num seletor de emojis; cada tópico pertence a uma categoria.
- **Tópicos** com título e corpo em HTML leve, com negrito, itálico, títulos, listas e imagens; a criação/edição abre em tela cheia (título, categoria e ações no topo, texto numa coluna de leitura).
- **Busca** full-text em português com `unaccent` (buscar "orcamento" encontra "Orçamento") e por prefixo de palavra.
- **Imagens** coladas com `Ctrl+V` ou enviadas pelo botão 📷 abrem antes num editor em tela cheia, onde dá para recortar (arrastando sobre a imagem) e desenhar por cima (caneta, 5 cores, 3 espessuras e desfazer); só o resultado é enviado e inserido no ponto do texto onde o cursor estava. Ao excluir um tópico, as imagens que ninguém mais usa são apagadas do disco.
- **Login** por senha única, com limite de tentativas por IP e sessão em cookie `HttpOnly`/`SameSite=Lax`.

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
7. opcionalmente emite certificado HTTPS via Certbot, se o host for um domínio.

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
- **HTML do editor** é sanitizado por *allowlist* de tags e atributos (DOMDocument): nenhum atributo sobrevive além de `<img src>` apontando para `uploads/`, e `script`/`iframe`/`style`/`svg` são removidos com o conteúdo.
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

Três coisas precisam de backup: o banco, as imagens em `public/uploads` e o `.env` (sem ele a instalação não sobe — guarde-o em local seguro, não junto do backup público).

O app guarda o conteúdo no PostgreSQL e as imagens em `public/uploads`:

```bash
pg_dump -h localhost -U caderninho -Fc caderninho > caderninho-$(date +%F).dump
tar czf uploads-$(date +%F).tar.gz -C /var/www/caderninho/public uploads
```

Restauração:

```bash
pg_restore -h localhost -U caderninho -d caderninho --clean --if-exists caderninho-AAAA-MM-DD.dump
tar xzf uploads-AAAA-MM-DD.tar.gz -C /var/www/caderninho/public
```

## Atualização

1. Atualize o código: `sudo ./install.sh` (se o `.env` já existe, ele só atualiza arquivos e nginx).
2. Aplique o schema — ele é idempotente e serve também como atualização de bancos antigos:

   ```bash
   PGPASSWORD='senha-do-banco' psql -h localhost -U caderninho -d caderninho -v ON_ERROR_STOP=1 -f schema.sql
   ```

   Em bancos criados por versões anteriores isso adiciona `created_at`, cria `login_attempts` e remove a coluna morta `parent_id`. Em banco novo, nada acontece.

## Limitações conhecidas

- **Senha única**, sem usuários, autorias ou auditoria.
- **Upload de até 10 MB** (acima disso o PHP devolve 413 e a interface mostra o motivo).
- **Imagem sempre vira PNG**: o ajuste acontece em `canvas` e é exportado como PNG com o lado maior limitado a 2400 px — GIF animado perde a animação e fotos grandes são reduzidas.
- **Limite de login por IP**: atrás de proxy reverso todos os acessos compartilham o IP do proxy — ajuste `client_ip()` em `api.php` se for o caso.
- **Editor usa `document.execCommand`**, obsoleto: funciona nos navegadores atuais, mas deve ser substituído por Markdown ou pela Selection API no futuro.
- **Sem CSP na página HTML**: o `index.html` tem CSS/JS inline, o que exige `script-src 'unsafe-inline'`; para uma CSP restritiva seria preciso extrair `app.js`/`app.css` e trocar os `onclick` inline por listeners.
- **Fontes vêm do Google Fonts** (requer internet; para uso offline, hospede as fontes localmente).
- **Sem migrações numeradas**: o `schema.sql` é idempotente, mas alterações estruturais futuras precisam ser escritas nele (com `IF NOT EXISTS`/`ADD COLUMN IF NOT EXISTS`).
- **Sem suíte de testes automatizados** no repositório.

## Licença

MIT — veja [LICENSE](LICENSE).
