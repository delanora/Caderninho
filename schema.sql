-- Schema do Caderninho. Pode ser executado mais de uma vez com segurança.
-- Requer PostgreSQL 12+ e a extensão unaccent.

CREATE EXTENSION IF NOT EXISTS unaccent;

-- Configuração de busca em português que ignora acentos.
-- (CREATE TEXT SEARCH CONFIGURATION não aceita IF NOT EXISTS, daí o bloco.)
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_ts_config WHERE cfgname = 'pt_unaccent') THEN
    CREATE TEXT SEARCH CONFIGURATION pt_unaccent (COPY = portuguese);
  END IF;
END $$;
ALTER TEXT SEARCH CONFIGURATION pt_unaccent
  ALTER MAPPING FOR hword, hword_part, word WITH unaccent, portuguese_stem;

CREATE TABLE IF NOT EXISTS categories (
  id         int GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name       text NOT NULL CHECK (char_length(btrim(name)) BETWEEN 1 AND 80),
  emoji      text NOT NULL DEFAULT '🌸' CHECK (char_length(emoji) <= 32),
  color      text NOT NULL DEFAULT '#ffd6e7' CHECK (color ~ '^#[0-9a-fA-F]{6}$'),
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS topics (
  id          int GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  category_id int NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
  title       text NOT NULL CHECK (char_length(btrim(title)) BETWEEN 1 AND 200),
  body        text NOT NULL DEFAULT '' CHECK (octet_length(body) <= 500000),
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now(),
  tsv tsvector GENERATED ALWAYS AS (
    to_tsvector('pt_unaccent', title || ' ' || regexp_replace(body, '<[^>]+>', ' ', 'g'))
  ) STORED
);

-- Atualização de bancos criados por versões anteriores (no-op em banco novo).
ALTER TABLE categories ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now();
ALTER TABLE topics     ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now();
-- parent_id nunca foi usado pelo app; subcategorias não existem.
ALTER TABLE categories DROP COLUMN IF EXISTS parent_id;

CREATE INDEX IF NOT EXISTS topics_tsv_idx      ON topics USING gin (tsv);
CREATE INDEX IF NOT EXISTS topics_category_idx ON topics (category_id);
CREATE INDEX IF NOT EXISTS topics_updated_idx  ON topics (updated_at DESC);

-- updated_at mantido pelo banco, não pelo código.
CREATE OR REPLACE FUNCTION touch_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS topics_touch ON topics;
CREATE TRIGGER topics_touch BEFORE UPDATE ON topics
  FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Controle de tentativas de login (limite por IP).
CREATE TABLE IF NOT EXISTS login_attempts (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  ip text NOT NULL,
  at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS login_attempts_ip_at_idx ON login_attempts (ip, at DESC);
