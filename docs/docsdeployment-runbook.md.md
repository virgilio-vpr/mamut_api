# Mamut — Runbook de Implantação e Operação
### Módulo: Gestão de Manutenção de Ativos e Predial

> **Público-alvo**: DevOps, SRE e desenvolvedores responsáveis por colocar o Mamut em produção.
> **Premissas**: VPS ou nuvem (AWS, GCP, Azure, Hetzner, DigitalOcean) com Docker e Docker Compose instalados.

---

## 1. Checklist Pré-Implantação

Antes de qualquer deploy em produção, confirme:

- [ ] Domínio registrado e apontando para o servidor
- [ ] Certificado SSL obtido (Let's Encrypt ou comercial)
- [ ] Credenciais do banco de dados geradas (senhas fortes, únicas)
- [ ] Backup automatizado configurado (pg_dump agendado)
- [ ] Fuso horário do servidor definido como `America/Sao_Paulo`
- [ ] Firewall configurado (portas 80, 443, 22 abertas; 5432, 6379 fechadas)
- [ ] Monitoramento e alertas configurados (Sentry, UptimeRobot)
- [ ] Variáveis de ambiente sensíveis em `.env.production` (nunca no Git)

---

## 2. Estrutura de Diretórios no Servidor

```
/var/www/mamut/
├── .env.production          # Variáveis de ambiente (fora do Git)
├── docker-compose.yml
├── docker-compose.prod.yml
├── Dockerfile
├── app/                     # Código Laravel
├── storage/                 # Logs, cache, uploads (volume persistente)
└── backups/                 # Dumps do PostgreSQL (volume persistente)
```

---

## 3. Variáveis de Ambiente de Produção

**`.env.production`** (exemplo — adaptar valores):

```dotenv
APP_NAME=Mamut
APP_ENV=production
APP_KEY=base64:CHAVE_GERADA_AQUI
APP_DEBUG=false
APP_URL=https://api.mamut.suaempresa.com.br
APP_TIMEZONE=America/Sao_Paulo

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=mamut_prod
DB_USERNAME=mamut_prod
DB_PASSWORD=SENHA_FORTE_UNICA

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=SENHA_REDIS

SANCTUM_STATEFUL_DOMAINS=api.mamut.suaempresa.com.br
SANCTUM_TOKEN_EXPIRATION=10080

MAIL_MAILER=smtp
MAIL_HOST=smtp.suaempresa.com.br
MAIL_PORT=587
MAIL_USERNAME=nao-responder@mamut.suaempresa.com.br
MAIL_PASSWORD=SENHA_EMAIL
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=nao-responder@mamut.suaempresa.com.br
MAIL_FROM_NAME="Mamut"

SENTRY_LARAVEL_DSN=https://xxxxx@sentry.io/xxxxx
```

> **Segurança**: nunca commitar `.env.production`. Manter em cofre (1Password, Vault) ou variáveis de ambiente do orquestrador.

---

## 4. `docker-compose.prod.yml`

```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    restart: unless-stopped
    environment:
      - APP_ENV=production
    env_file:
      - .env.production
    volumes:
      - ./storage:/var/www/html/storage
    depends_on:
      - db
      - redis
    networks:
      - mamut
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8000/up"]
      interval: 30s
      timeout: 5s
      retries: 3

  db:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_DB: mamut_prod
      POSTGRES_USER: mamut_prod
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - pgdata:/var/lib/postgresql/data
      - ./backups:/backups
    networks:
      - mamut

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: ["redis-server", "--requirepass", "${REDIS_PASSWORD}"]
    volumes:
      - redisdata:/data
    networks:
      - mamut

  nginx:
    image: nginx:alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf
      - ./nginx/certs:/etc/nginx/certs:ro
    depends_on:
      - app
    networks:
      - mamut

networks:
  mamut:
    driver: bridge

volumes:
  pgdata:
  redisdata:
```

---

## 5. `nginx/default.conf`

```nginx
server {
    listen 80;
    server_name api.mamut.suaempresa.com.br;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name api.mamut.suaempresa.com.br;

    ssl_certificate     /etc/nginx/certs/fullchain.pem;
    ssl_certificate_key /etc/nginx/certs/privkey.pem;

    client_max_body_size 20M;

    location / {
        proxy_pass http://app:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 90;
    }

    location /health {
        access_log off;
        return 200 "ok\n";
    }
}
```

---

## 6. Procedimento de Deploy Inicial

Execute os comandos abaixo **na ordem**, na primeira implantação:

```bash
# 1. Clonar o repositório
cd /var/www/mamut
git clone git@github.com:suaempresa/mamut.git .

# 2. Criar .env.production e ajustar permissões
cp .env.production.example .env.production
chmod 600 .env.production

# 3. Subir containers (build inicial)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# 4. Gerar chave da aplicação
docker compose exec app php artisan key:generate

# 5. Rodar migrações
docker compose exec app php artisan migrate --force

# 6. Popular dados iniciais (roles + admin)
docker compose exec app php artisan db:seed --force

# 7. Otimizações de produção
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache

# 8. Ajustar permissões de storage e cache
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache

# 9. Validar
curl -I https://api.mamut.suaempresa.com.br/health
```

---

## 7. Procedimento de Deploy Contínuo (Updates)

Para **cada nova versão** em produção:

```bash
cd /var/www/mamut

# 1. Backup do banco antes de qualquer coisa
./scripts/backup-db.sh

# 2. Colocar em modo manutenção (opcional em deploy blue-green)
docker compose exec app php artisan down --render="errors::503"

# 3. Baixar a nova versão
git pull origin main

# 4. Rebuild dos containers
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# 5. Rodar migrações
docker compose exec app php artisan migrate --force

# 6. Limpar e reconstruir caches
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache

# 7. Reiniciar filas (workers)
docker compose exec app php artisan queue:restart

# 8. Sair do modo manutenção
docker compose exec app php artisan up
```

> **Dica**: para deploys de maior risco, use **estratégia blue-green**: suba os novos containers em portas alternativas, valide com smoke tests e só então alterne o tráfego no Nginx.

---

## 8. Scripts Operacionais

### 8.1 `scripts/backup-db.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="/var/www/mamut/backups"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
FILENAME="mamut_prod_${TIMESTAMP}.sql.gz"

mkdir -p "$BACKUP_DIR"

docker compose exec -T db pg_dump -U mamut_prod mamut_prod \
  | gzip > "${BACKUP_DIR}/${FILENAME}"

# Retenção: manter últimos 30 dias
find "$BACKUP_DIR" -name "*.sql.gz" -mtime +30 -delete

echo "Backup concluído: ${FILENAME}"
```

**Agendar via cron** (todos os dias às 02:00):

```cron
0 2 * * * /var/www/mamut/scripts/backup-db.sh >> /var/log/mamut-backup.log 2>&1
```

### 8.2 `scripts/restore-db.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

if [ -z "${1:-}" ]; then
  echo "Uso: $0 <arquivo_backup.sql.gz>"
  exit 1
fi

echo "⚠️  ATENÇÃO: este comando irá sobrescrever o banco de dados atual."
read -p "Digite CONFIRMAR para prosseguir: " CONFIRM
[ "$CONFIRM" = "CONFIRMAR" ] || exit 1

gunzip -c "$1" | docker compose exec -T db psql -U mamut_prod mamut_prod
echo "Restauração concluída."
```

### 8.3 `scripts/health-check.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

ENDPOINT="https://api.mamut.suaempresa.com.br/health"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$ENDPOINT")

if [ "$HTTP_CODE" -ne 200 ]; then
  echo "❌ Health check falhou (HTTP $HTTP_CODE)"
  # Aqui pode disparar alerta no Slack, email, etc.
  exit 1
fi

echo "✅ API saudável (HTTP $HTTP_CODE)"
```

**Agendar via cron** (a cada 5 minutos):

```cron
*/5 * * * * /var/www/mamut/scripts/health-check.sh >> /var/log/mamut-health.log 2>&1
```

---

## 9. Agendamento de Tarefas (Scheduler)

### 9.1 Cron do Sistema para o Laravel Scheduler

Adicionar ao crontab do usuário que roda os containers:

```cron
* * * * * cd /var/www/mamut && docker compose exec -T app php artisan schedule:run >> /dev/null 2>&1
```

### 9.2 Alternativa: Container Dedicado ao Scheduler

Adicionar no `docker-compose.prod.yml`:

```yaml
  scheduler:
    build:
      context: .
      dockerfile: Dockerfile
    restart: unless-stopped
    command: ["php", "artisan", "schedule:work"]
    env_file:
      - .env.production
    depends_on:
      - db
      - redis
    networks:
      - mamut

  queue-worker:
    build:
      context: .
      dockerfile: Dockerfile
    restart: unless-stopped
    command: ["php", "artisan", "queue:work", "--tries=3", "--timeout=120"]
    env_file:
      - .env.production
    depends_on:
      - db
      - redis
    networks:
      - mamut
```

---

## 10. Monitoramento e Observabilidade

### 10.1 Logs

| Log | Local | Rotação |
|---|---|---|
| Aplicação | `storage/logs/laravel.log` | Diária, 30 dias |
| Nginx | `docker compose logs nginx` | Configurável |
| PostgreSQL | `docker compose logs db` | Configurável |
| Redis | `docker compose logs redis` | Configurável |

### 10.2 Sentry (recomendado)

Instalar:

```bash
docker compose exec app composer require sentry/sentry-laravel
docker compose exec app php artisan sentry:publish --dsn=...
```

Adicionar ao `.env.production`:

```dotenv
SENTRY_LARAVEL_DSN=https://xxxxx@sentry.io/xxxxx
SENTRY_TRACES_SAMPLE_RATE=0.1
```

### 10.3 Uptime

Configurar um monitor externo (UptimeRobot, BetterStack) apontando para:

```
https://api.mamut.suaempresa.com.br/health
```

---

## 11. Segurança em Produção

### 11.1 Checklist Obrigatório

- [ ] `APP_DEBUG=false` em produção
- [ ] `APP_ENV=production`
- [ ] HTTPS obrigatório (redirect 301 de HTTP → HTTPS)
- [ ] Tokens Sanctum com expiração (`SANCTUM_TOKEN_EXPIRATION=10080` = 7 dias)
- [ ] Rate limiting global ativo (`throttle:api`)
- [ ] CORS restrito aos domínios do frontend
- [ ] Backups criptografados (GPG ou bucket com SSE)
- [ ] PostgreSQL e Redis **não expostos** à internet
- [ ] Usuário `postgres` não usado em produção (usar usuário dedicado)
- [ ] Firewall (ufw/iptables) restringindo portas
- [ ] Fail2ban ativo no SSH
- [ ] Autenticação SSH apenas por chave (sem senha)
- [ ] Rotação de credenciais a cada 90 dias

### 11.2 Configuração CORS

**`config/cors.php`**:

```php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://app.mamut.suaempresa.com.br',
        'https://mamut.suaempresa.com.br',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
```

---

## 12. Troubleshooting

| Sintoma | Causa provável | Ação |
|---|---|---|
| 502 Bad Gateway | Container `app` caiu | `docker compose ps`, ver logs |
| 500 em todas as rotas | `APP_KEY` ausente ou `.env` inválido | `php artisan key:generate`, revisar `.env` |
| Migração falha | Constraints faltando | Restaurar backup, revisar migrations |
| Email não envia | Credenciais SMTP erradas | Testar com `php artisan tinker` → `Mail::raw()` |
| Scheduler não dispara | Cron do host desconfigurado | `crontab -l`, testar `schedule:run` manualmente |
| Filas travadas | Worker sem `queue:restart` após deploy | `docker compose exec app php artisan queue:restart` |
| Redis fora do ar | Senha errada ou container parado | `docker compose logs redis` |
| Backup vazio | Permissão no volume `backups/` | `chown` apropriado |

---

## 13. Rollback de Emergência

Se um deploy quebrou produção:

```bash
cd /var/www/mamut

# 1. Voltar para o commit anterior
git log --oneline -5
git checkout <commit_anterior>

# 2. Rebuildar containers
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# 3. Se houve migração destrutiva, restaurar backup
./scripts/restore-db.sh backups/mamut_prod_YYYYMMDD_HHMMSS.sql.gz

# 4. Limpar caches
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache

# 5. Reiniciar workers
docker compose exec app php artisan queue:restart
```

---

## 14. Contatos e Escalação

| Papel | Responsável | Contato |
|---|---|---|
| Arquiteto de Software | (definir) | (definir) |
| DevOps / SRE | (definir) | (definir) |
| Product Owner | (definir) | (definir) |
| Suporte N1 | (definir) | (definir) |

> **Ação em incidentes críticos (P1)**: notificar DevOps + Arquiteto imediatamente; abrir canal no Slack; documentar timeline no post-mortem em até 48h.

---

## 15. Rotinas Periódicas

| Frequência | Tarefa |
|---|---|
| Diária | Verificar `/health` e logs de erro (Sentry) |
| Diária | Backup do PostgreSQL (02:00) |
| Semanal | Revisar logs do Nginx e taxa de erros 5xx |
| Semanal | Validar espaço em disco (`df -h`) |
| Mensal | Testar restauração de backup em ambiente isolado |
| Trimestral | Rotacionar credenciais (DB, Redis, SMTP) |
| Trimestral | Revisar dependências (`composer outdated`) |
| Semestral | Atualizar versões de PHP, PostgreSQL e Redis |
| Anual | Revisão de arquitetura e plano de capacidade |