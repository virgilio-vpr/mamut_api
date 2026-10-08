# Mamut API

API para gestão de manutenção de ativos e instalações, baseada em Laravel 13,
PostgreSQL 16, Redis 7 e FrankenPHP.

## Ambiente de desenvolvimento no Codespaces

Pré-requisitos: Docker com Docker Compose e Git.

1. Crie a configuração local:

   ```bash
   cp .env.example .env
   ```

2. Construa a imagem e inicie a API, o PostgreSQL e o Redis:

   ```bash
   docker compose up -d --build
   ```

3. Gere a chave da aplicação e aplique as migrações:

   ```bash
   docker compose exec app php artisan key:generate
   docker compose exec app php artisan migrate
   docker compose exec app php artisan db:seed --class=RoleSeeder
   ```

4. A API fica disponível na porta `8000`. Para verificar o ambiente:

   ```bash
   docker compose ps
   docker compose exec app php artisan config:clear
   docker compose exec \
     -e APP_ENV=testing \
     -e CACHE_STORE=array \
     -e SESSION_DRIVER=array \
     -e QUEUE_CONNECTION=sync \
     -e DB_CONNECTION=sqlite \
     -e DB_DATABASE=:memory: \
     app php artisan test
   ```

No Codespaces, o serviço `app` usa a rede do host para contornar falhas de
conectividade da rede bridge entre containers. PostgreSQL e Redis continuam
publicados somente em `127.0.0.1`, e a API os acessa por esse endereço; não
amplie o bind dessas portas. O arquivo `.env` é local e não deve ser versionado.

No perfil local, as sessões usam arquivos para que a tela inicial e as rotas web
não dependam da conectividade do Redis entre containers. O Redis continua
configurado para filas e outros recursos que venham a utilizá-lo.

O seeder cria os seis papéis RBAC definidos pela API e pode ser executado mais
de uma vez. A criação de uma conta administrativa fica para a etapa de gestão
de usuários, sem credenciais de demonstração embutidas.

## Documentação

- [Arquitetura do backend](./docs/mamut-backend-architecture.md.md)
- [Referência da API](./docs/docsapi-reference.md.md)
- [Diagrama ER](./docs/docser-diagram.md.md)
- [Runbook de implantação](./docs/docsdeployment-runbook.md.md)
- [Instruções para os documentos](./docs/use-instruction-files-mds.md.md)