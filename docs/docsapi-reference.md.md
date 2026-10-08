# Mamut API — Referência de Endpoints
### Módulo: Gestão de Manutenção de Ativos e Predial

> **Base URL (dev)**: `http://localhost:8000/api/v1`
> **Autenticação**: Bearer Token via Laravel Sanctum
> **Formato**: JSON (UTF-8)
> **Versionamento**: prefixo `/api/v1`. Futuras versões usarão `/api/v2`.

---

## Status da API

`GET /api/v1/status` — endpoint público para verificar se a API está respondendo.

**Response 200**:

```json
{
  "success": true,
  "message": "API is running.",
  "data": {
    "status": "ok"
  }
}
```

Endpoints inexistentes retornam HTTP `404` no padrão JSON de erro descrito abaixo.

---

## Índice

1. [Convenções Gerais](#1-convenções-gerais)
2. [Autenticação](#2-autenticação)
3. [Usuários e Perfis](#3-usuários-e-perfis)
4. [Setores, Localizações e Equipamentos](#4-setores-localizações-e-equipamentos)
5. [Ordens de Serviço](#5-ordens-de-serviço)
6. [Execuções](#6-execuções)
7. [Planos Preventivos e Cronograma](#7-planos-preventivos-e-cronograma)
8. [Avaliação de Satisfação](#8-avaliação-de-satisfação)
9. [Relatórios e Indicadores](#9-relatórios-e-indicadores)
10. [Códigos de Erro](#10-códigos-de-erro)

---

## 1. Convenções Gerais

### 1.1 Padrão de Resposta

**Sucesso**:

```json
{
  "success": true,
  "message": "Descrição amigável da operação",
  "data": { }
}
```

**Erro**:

```json
{
  "success": false,
  "message": "Descrição do erro",
  "errors": {
    "campo": ["Mensagem de validação"]
  }
}
```

**Listagem paginada**:

```json
{
  "success": true,
  "message": "Success",
  "data": [ ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 145,
    "last_page": 8
  }
}
```

### 1.2 Cabeçalhos Obrigatórios

| Cabeçalho | Valor | Obrigatório |
|---|---|---|
| `Accept` | `application/json` | Sempre |
| `Content-Type` | `application/json` | Em POST/PUT/PATCH |
| `Authorization` | `Bearer {token}` | Exceto login |

### 1.3 Roles Disponíveis

| Role | Descrição |
|---|---|
| `system_admin` | Acesso total |
| `aux_admin` | Painel, triagem, encerramento |
| `planner` | Planejamento + relatórios |
| `manager` | Relatórios gerenciais |
| `client` | Abertura de OS corretiva |
| `maintainer` | Execução de tarefas |

---

## 2. Autenticação

### 2.1 Login

`POST /api/v1/auth/login`

**Request**:

```json
{
  "email": "joao@empresa.com",
  "password": "senha-segura",
  "device_name": "web-chrome"
}
```

**Response 200**:

```json
{
  "token": "1|abc123...",
  "user": {
    "id": 1,
    "name": "João Silva",
    "email": "joao@empresa.com",
    "roles": ["aux_admin"],
    "sector": { "id": 3, "name": "Manutenção" }
  }
}
```

**Erros**:
- `422` — Credenciais inválidas
- `429` — Muitas tentativas (rate limit)

### 2.2 Logout

`POST /api/v1/auth/logout` — requer Bearer token.

**Response 200**:

```json
{ "message": "Logged out successfully." }
```

### 2.3 Usuário Atual

`GET /api/v1/auth/me` — requer Bearer token.

**Response 200**: mesmo objeto `user` do login.

---

## 3. Usuários e Perfis

### 3.1 Listar Usuários

`GET /api/v1/users?role=maintainer&sector_id=3&per_page=20`

**Permissão**: `system_admin`, `planner`

Os filtros `role` e `sector_id` são opcionais. `per_page` aceita de 1 a 100
(padrão: 20). A resposta é paginada e inclui `data`, `links` e `meta`; cada
usuário inclui setor e papéis, nunca a senha ou tokens.

### 3.2 Criar Usuário

`POST /api/v1/users`

**Permissão**: `system_admin`

**Request**:

```json
{
  "name": "Maria Souza",
  "email": "maria@empresa.com",
  "username": "maria.souza",
  "phone": "11999998888",
  "password": "senha-forte-2026",
  "sector_id": 3,
  "roles": ["maintainer"]
}
```

O campo `sector_id` é opcional ou `null`; quando informado, o setor precisa
estar ativo. `roles` deve conter ao menos um papel existente. A senha deve ter
no mínimo 12 caracteres.

**Response 201**: objeto usuário criado, incluindo `roles` e `sector`, sem
credenciais.

**Permissões**:
- `401` — Token ausente ou inválido
- `403` — Papel sem permissão
- `422` — Dados inválidos, papel inexistente ou setor desativado

### 3.3 Atualizar Usuário

`PUT /api/v1/users/{id}` (também aceita `PATCH`) — requer `system_admin`; os
campos são opcionais. Se `roles` for informado, substitui os papéis atuais. A
senha pode ser omitida para mantê-la inalterada.

### 3.4 Desativar Usuário (soft delete)

`DELETE /api/v1/users/{id}` — requer `system_admin`. A operação aplica soft
delete e revoga os tokens Sanctum do usuário.

Emails e nomes de usuário permanecem reservados após a desativação, pois as
colunas têm restrições de unicidade no banco de dados.

---

## 4. Setores, Localizações e Equipamentos

### 4.1 Setores

| Método | Endpoint | Permissão |
|---|---|---|
| GET | `/api/v1/sectors` | autenticado |
| POST | `/api/v1/sectors` | `planner`, `system_admin` |
| PUT | `/api/v1/sectors/{id}` | `planner`, `system_admin` |
| DELETE | `/api/v1/sectors/{id}` | `system_admin` |

**Exemplo de criação**:

```json
{
  "name": "Ala Norte",
  "hierarchical": "operation",
  "site": "HESAP",
  "block": "main_building",
  "cost_center": 1201,
  "logo": null
}
```

### 4.2 Localizações

| Método | Endpoint | Permissão |
|---|---|---|
| GET | `/api/v1/locations` | autenticado |
| POST | `/api/v1/locations` | `planner`, `system_admin` |
| PUT | `/api/v1/locations/{id}` | `planner`, `system_admin` |

**Exemplo**:

```json
{
  "name": "Sala 302",
  "floor": "3",
  "wing": "North"
}
```

### 4.3 Equipamentos

| Método | Endpoint | Permissão |
|---|---|---|
| GET | `/api/v1/equipment` | autenticado |
| POST | `/api/v1/equipment` | `planner`, `system_admin` |
| GET | `/api/v1/equipment/{id}` | autenticado |
| PUT | `/api/v1/equipment/{id}` | `planner`, `system_admin` |

**Exemplo**:

```json
{
  "name": "Split Hi-Wall 18.000 BTU",
  "sector_id": 3,
  "location_id": 12,
  "description": "Ar-condicionado da sala 302",
  "local": "Sala 302 - Parede Leste",
  "type": "air_conditioning",
  "tag_equip": "AC-302-001"
}
```

### 4.4 Componentes e Subcomponentes

- `POST /api/v1/equipment/{id}/components`
- `POST /api/v1/components/{id}/subcomponents`

**Exemplo de componente**:

```json
{
  "name": "Compressor",
  "description": "Compressor scroll",
  "tag_comp": "AC-302-001-CMP"
}
```

---

## 5. Ordens de Serviço

### 5.1 Listar Ordens

`GET /api/v1/work-orders`

**Query params**:

| Parâmetro | Tipo | Descrição |
|---|---|---|
| `status` | string | `open`, `in_progress`, `waiting_material`, `waiting_approval`, `closed`, `cancelled` |
| `type` | string | `corrective`, `preventive`, `predictive`, `inspection` |
| `priority` | string | `emergency`, `urgency`, `high`, `medium`, `low` |
| `sector_id` | integer | Filtro por setor |
| `assigned_to` | integer | Filtro por responsável |
| `search` | string | Busca em `order_number`, `title`, `description` |
| `date_from` / `date_to` | date | Intervalo por `opened_at` |
| `per_page` | integer | Default 20 |
| `page` | integer | Página atual |

### 5.2 Criar OS Corretiva

`POST /api/v1/work-orders`

**Permissão**: qualquer usuário autenticado (`client`, `maintainer`, `aux_admin`, etc.) e também usuário padrão sem login (via endpoint público `/api/v1/public/work-orders`).

**Request**:

```json
{
  "title": "Ar-condicionado não está gelando",
  "description": "Sala 302, ar-condicionado liga, mas não gela.",
  "sector_id": 3,
  "location_id": 12,
  "equipment_id": 45,
  "priority": "high",
  "is_stopped": false
}
```

**Response 201**:

```json
{
  "success": true,
  "message": "Work order created successfully",
  "data": {
    "id": 101,
    "order_number": "OS-20260115-0001",
    "type": "corrective",
    "status": "open",
    "priority": "high",
    "title": "Ar-condicionado não está gelando",
    "opened_at": "2026-01-15T14:22:00Z"
  }
}
```

### 5.3 Detalhar OS

`GET /api/v1/work-orders/{id}`

Retorna o objeto completo com relacionamentos:

```json
{
  "id": 101,
  "order_number": "OS-20260115-0001",
  "type": "corrective",
  "status": "in_progress",
  "priority": "high",
  "requester": { "id": 5, "name": "João Silva" },
  "assignee": { "id": 2, "name": "Maria Souza" },
  "sector": { "id": 3, "name": "Ala Norte" },
  "location": { "id": 12, "name": "Sala 302" },
  "equipment": { "id": 45, "name": "Split Hi-Wall 18.000 BTU", "tag_equip": "AC-302-001" },
  "executions": [
    {
      "id": 201,
      "maintainer": { "id": 10, "name": "Carlos Lima" },
      "started_at": "2026-01-15T15:00:00Z",
      "finished_at": "2026-01-15T16:10:00Z",
      "duration_minutes": 70
    }
  ],
  "status_logs": [
    {
      "from_status": "open",
      "to_status": "in_progress",
      "user": { "id": 2, "name": "Maria Souza" },
      "notes": "Encaminhado para equipe de refrigeração",
      "created_at": "2026-01-15T14:55:00Z"
    }
  ],
  "satisfaction": null
}
```

### 5.4 Triagem (aux_admin / planner)

`POST /api/v1/work-orders/{id}/triage`

**Permissão**: `aux_admin`, `planner`, `system_admin`

**Request**:

```json
{
  "status": "in_progress",
  "priority": "urgency",
  "specialty": "air_conditioning",
  "work_group": "air_conditioning",
  "assigned_to": 2,
  "notes": "Encaminhado para equipe de refrigeração"
}
```

**Valores possíveis de `status` na triagem**:
- `in_progress` — Em execução imediata
- `waiting_material` — Aguardando compra de material
- `waiting_approval` — Aguardando liberação do setor

**Response 200**: objeto OS atualizado.

### 5.5 Encerramento de OS

`POST /api/v1/work-orders/{id}/close`

**Permissão**: `aux_admin`, `planner`, `system_admin`, `maintainer`

**Request**:

```json
{
  "solution": "Substituição do capacitor do compressor",
  "customer_signature": "base64-da-assinatura-no-tablet",
  "supervisor_signature": null
}
```

**Regras**:
- **Corretiva**: exige `customer_signature` (assinatura do cliente) obrigatoriamente.
- **Preventiva / Preditiva / Inspeção**: exige `supervisor_signature`.
- As imagens recebidas são armazenadas em storage privado; o banco guarda somente os caminhos relativos em `customer_signature_path` ou `supervisor_signature_path`.

**Response 200**: OS encerrada + registro de log + email enviado ao solicitante.

---

## 6. Execuções

### 6.1 Adicionar Executor à OS

`POST /api/v1/work-orders/{id}/executions`

**Permissão**: `aux_admin`, `planner`, `system_admin`

**Request**:

```json
{
  "maintainer_id": 10,
  "specialty": "air_conditioning",
  "started_at": "2026-01-15T15:00:00Z",
  "observations": "Início do diagnóstico"
}
```

### 6.2 Finalizar Execução de um Mantenedor

`POST /api/v1/work-orders/{id}/executions/{executionId}/complete`

**Request**:

```json
{
  "observations": "Trocado o capacitor, testado e aprovado"
}
```

**Response 200**:

```json
{
  "id": 201,
  "duration_minutes": 70,
  "finished_at": "2026-01-15T16:10:00Z"
}
```

> **Nota**: o `duration_minutes` é calculado automaticamente pelo backend.

---

## 7. Planos Preventivos e Cronograma

### 7.1 Criar Plano Preventivo

`POST /api/v1/preventive-plans`

**Permissão**: `planner`, `system_admin`

**Request**:

```json
{
  "title": "Limpeza trimestral do Split",
  "equipment_id": 45,
  "sector_id": 3,
  "work_group": "air_conditioning",
  "specialty": "air_conditioning",
  "frequency_days": 90,
  "start_date": "2026-02-01",
  "end_date": "2026-12-31",
  "generate_preventive": true,
  "generate_schedule": true,
  "active_months": [2, 5, 8, 11],
  "justification": "Fabricante recomenda limpeza trimestral"
}
```

### 7.2 Gerar OS Preventivas Manualmente

`POST /api/v1/preventive-plans/generate`

**Permissão**: `planner`, `system_admin`

**Request**:

```json
{
  "date_from": "2026-01-01",
  "date_to": "2026-12-31"
}
```

**Response 200**:

```json
{
  "success": true,
  "message": "12 preventive work orders scheduled",
  "data": {
    "generated": 12,
    "skipped": 3,
    "details": [
      { "plan_id": 5, "scheduled_date": "2026-02-02", "work_order_number": "PM-20260202-0005" }
    ]
  }
}
```

> **Regra**: se já existir agendamento naquele mês/plano, o registro é ignorado (não duplica).

### 7.3 Consultar Cronograma Anual

`GET /api/v1/preventive-schedules?year=2026&sector_id=3`

**Response 200**:

```json
{
  "data": [
    {
      "id": 78,
      "preventive_plan_id": 5,
      "scheduled_date": "2026-02-02",
      "status": "planned",
      "work_order": null
    },
    {
      "id": 79,
      "preventive_plan_id": 5,
      "scheduled_date": "2026-05-02",
      "status": "generated",
      "work_order": { "id": 210, "order_number": "PM-20260502-0005" }
    }
  ]
}
```

---

## 8. Avaliação de Satisfação

### 8.1 Avaliar OS Corretiva (via link do email)

`GET /api/v1/public/satisfaction/{work_order_id}` — sem autenticação (link enviado por email).

### 8.2 Enviar Avaliação

`POST /api/v1/public/satisfaction/{work_order_id}`

**Request**:

```json
{
  "rating": "very_satisfied",
  "comment": "Atendimento rápido e eficiente."
}
```

**Valores de `rating`**:
- `unsatisfied`
- `regular`
- `satisfied` (default quando não avaliado)
- `very_satisfied`

**Regra**: se o cliente não responder em até X dias (definido em config), o registro assume automaticamente `satisfied`.

---

## 9. Relatórios e Indicadores

### 9.1 Dashboard

`GET /api/v1/reports/dashboard?date_from=2026-01-01&date_to=2026-01-31`

**Permissão**: `manager`, `planner`, `system_admin`

**Response 200**:

```json
{
  "total": 145,
  "by_status": { "open": 12, "in_progress": 30, "closed": 98, "waiting_material": 5 },
  "by_type": { "corrective": 90, "preventive": 40, "predictive": 8, "inspection": 7 },
  "by_priority": { "emergency": 3, "urgency": 20, "high": 40, "medium": 60, "low": 22 },
  "by_sector": [
    { "sector_id": 3, "sector": { "name": "Ala Norte" }, "total": 45 }
  ],
  "sla_compliance": {
    "total_with_response": 120,
    "within_sla": 105,
    "compliance_rate": 87.5
  }
}
```

### 9.2 Indicadores de Manutenção

`GET /api/v1/reports/kpis?equipment_id=45&date_from=2026-01-01&date_to=2026-12-31`

**Response 200**:

```json
{
  "equipment_id": 45,
  "mtbf_hours": 720.5,
  "mttr_hours": 2.3,
  "availability_percent": 99.68,
  "total_corrective": 12,
  "total_preventive": 4
}
```

> **Nota**: `mtbf` e `mttr` dependem de dados de tempo operacional fornecidos externamente (ou de um campo `operating_hours` no equipamento, se aplicável).

### 9.3 Exportação

`GET /api/v1/reports/export?type=work_orders&format=csv&date_from=...`

Formatos suportados: `csv`, `xlsx`, `pdf`.

---

## 10. Códigos de Erro

| HTTP | Significado | Quando ocorre |
|---|---|---|
| 200 | OK | Sucesso |
| 201 | Created | Recurso criado |
| 204 | No Content | Delete bem-sucedido |
| 400 | Bad Request | Requisição malformada |
| 401 | Unauthorized | Token ausente/inválido/expirado |
| 403 | Forbidden | Role sem permissão |
| 404 | Not Found | Recurso inexistente |
| 422 | Unprocessable Entity | Falha de validação (payload inválido) |
| 429 | Too Many Requests | Rate limit atingido |
| 500 | Internal Server Error | Erro no servidor |

### Exemplo de erro 422

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "title": ["The title field is required."],
    "sector_id": ["The selected sector id is invalid."]
  }
}
```

### Exemplo de erro 403

```json
{
  "success": false,
  "message": "This action is unauthorized.",
  "errors": null
}
```

---

## Anexo A — Ordem Recomendada de Testes Manuais

Para validar a API após implantação inicial:

1. `POST /api/v1/auth/login` → obter token
2. `GET /api/v1/auth/me` → confirmar roles
3. `POST /api/v1/sectors` → cadastrar setor (planner)
4. `POST /api/v1/locations` → cadastrar local
5. `POST /api/v1/equipment` → cadastrar equipamento
6. `POST /api/v1/work-orders` → abrir OS corretiva (client)
7. `POST /api/v1/work-orders/{id}/triage` → triagem (aux_admin)
8. `POST /api/v1/work-orders/{id}/executions` → adicionar mantenedor
9. `POST /api/v1/work-orders/{id}/executions/{eid}/complete` → fechar execução
10. `POST /api/v1/work-orders/{id}/close` → encerrar OS
11. `GET /api/v1/reports/dashboard` → validar indicadores