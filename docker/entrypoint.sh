#!/bin/sh
# Prepara o banco antes de iniciar o processo do container (servidor web ou fila de e-mails).
#   SKIP_DB_INIT=true  não roda o bin/init_db.php (o serviço "worker" usa isso; quem prepara o banco é o "app")
#   SEED_DEMO=true     carrega os dados fictícios de demonstração (bin/seed.php, quando existir)
set -e

if [ "${SKIP_DB_INIT:-false}" != "true" ]; then
  echo "[saa] preparando o banco (bin/init_db.php)..."
  php bin/init_db.php
fi

if [ "${SEED_DEMO:-false}" = "true" ] && [ -f bin/seed.php ]; then
  echo "[saa] carregando dados de demonstração (bin/seed.php)..."
  php bin/seed.php || echo "[saa] aviso: o seed falhou; a aplicação continua sem os dados de demonstração."
fi

exec "$@"
