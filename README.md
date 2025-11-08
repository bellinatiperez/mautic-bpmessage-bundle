# MauticBpMessageBundle

Plugin para Mautic que integra com a API BpMessage para envio de mensagens SMS, WhatsApp, RCS e Emails em lote.

## 📋 Índice

- [Características](#características)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Ações de Campanha](#ações-de-campanha)
- [Comandos CLI](#comandos-cli)
- [Estrutura do Banco de Dados](#estrutura-do-banco-de-dados)
- [Unicidade de Lotes](#unicidade-de-lotes)
- [Fluxo de Funcionamento](#fluxo-de-funcionamento)
- [Troubleshooting](#troubleshooting)
- [Desenvolvimento](#desenvolvimento)

## 🚀 Características

- ✅ **3 Tipos de Ação**: SMS/WhatsApp/RCS, Email Personalizado e Email Template
- ✅ **Envio em Lote**: Agrupa mensagens para envio otimizado (até 5000 por lote)
- ✅ **Múltiplos Canais**: Suporta SMS, WhatsApp, RCS e Email
- ✅ **Integração com Campanhas**: 3 ações nativas no Campaign Builder do Mautic
- ✅ **Tokens Dinâmicos**: Use `{contactfield=fieldname}` para personalizar mensagens
- ✅ **Gestão de Filas**: Sistema robusto de filas com retry automático
- ✅ **Configuração Flexível**: Controle de tamanho de lote e janela de tempo
- ✅ **Templates do Mautic**: Use templates de email existentes do Mautic
- ✅ **SQL Fallback**: Garante persistência durante batch processing
- ✅ **Unicidade de Lotes**: Lotes únicos por configuração (quota + service + type)
- ✅ **Force Close**: Comando para processar lotes imediatamente
- ✅ **Logs Detalhados**: Auditoria completa de todas as operações
- ✅ **CLI Commands**: Comandos para processar filas e fazer limpeza

## 📦 Requisitos

- Mautic 4.x ou 5.x
- PHP 7.4+ ou 8.0+
- MySQL 5.7+ ou MariaDB 10.2+
- Conta ativa na API BpMessage
- Credenciais da API BpMessage

## 🔧 Instalação

### 1. Copiar Plugin

```bash
cd /path/to/mautic
cp -r MauticBpMessageBundle plugins/
```

### 2. Limpar Cache

```bash
php bin/console cache:clear
```

### 3. Instalar no Mautic

1. Acesse Mautic Admin → Plugins
2. Clique em "Install/Upgrade Plugins"
3. O plugin "BpMessage" aparecerá na lista
4. Clique para publicar

### 4. Criar Tabelas do Banco

```bash
php bin/console doctrine:schema:update --force
```

Ou manualmente:

```sql
CREATE TABLE bpmessage_lot (
    id INT AUTO_INCREMENT PRIMARY KEY,
    external_lot_id VARCHAR(255),
    name VARCHAR(255) NOT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    user_cpf VARCHAR(14) NOT NULL,
    id_quota_settings INT NOT NULL,
    id_service_settings INT NOT NULL,
    service_type INT NULL,
    id_book_business_send_group INT NULL,
    image_url TEXT NULL,
    image_name VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL,
    messages_count INT DEFAULT 0,
    created_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    campaign_id INT NULL,
    api_base_url VARCHAR(255) NOT NULL,
    batch_size INT NOT NULL,
    time_window INT NOT NULL,
    error_message TEXT NULL,
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_campaign_id (campaign_id)
);

CREATE TABLE bpmessage_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lot_id INT NOT NULL,
    lead_id INT NOT NULL,
    payload_json TEXT NOT NULL,
    status VARCHAR(20) NOT NULL,
    retry_count SMALLINT DEFAULT 0,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    FOREIGN KEY (lot_id) REFERENCES bpmessage_lot(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    INDEX idx_lot_status (lot_id, status),
    INDEX idx_created_at (created_at),
    INDEX idx_status (status)
);
```

### 5. Configurar Cron

Adicione ao crontab para processar as filas:

```bash
# Processar filas a cada 5 minutos
*/5 * * * * php /path/to/mautic/bin/console mautic:bpmessage:process

# Limpar lotes antigos uma vez por semana (opcional)
0 2 * * 0 php /path/to/mautic/bin/console mautic:bpmessage:cleanup --days=30
```

## ⚙️ Configuração

### 1. Configurar Plugin no Mautic

1. Acesse **Settings** → **Plugins** → **BpMessage**
2. Configure:
   - **API Base URL**: URL da API (ex: `https://api.bpmessage.com.br`)
   - **Default Batch Size**: Tamanho padrão de lote (padrão: 1000)
   - **Default Time Window**: Janela de tempo padrão em segundos (padrão: 300)
3. Clique em **Save & Close**
4. Marque como **Published**

### 2. Obter Credenciais da BpMessage

Você precisará obter as seguintes informações da BpMessage:

- **ID Quota Settings**: ID da cota disponível
- **ID Service Settings**: ID da rota de envio

Para obter IDs de cota e rota, consulte o endpoint da BpMessage:
```
GET /api/ServiceSettings/GetRoutes
```

## 📱 Ações de Campanha

O plugin oferece **3 tipos de ação** para campanhas:

### 1. Send BpMessage (SMS/WhatsApp/RCS)

Envia mensagens de texto via SMS, WhatsApp ou RCS.

**Configuração:**

- **ID Quota Settings**: ID da cota (obrigatório, deve ser > 0)
- **ID Service Settings**: ID da rota (obrigatório, deve ser > 0)
- **Service Type**:
  - `1` = SMS
  - `2` = WhatsApp (padrão)
  - `3` = RCS
- **Batch Size**: Tamanho do lote (padrão: 1000, máx: 5000)
- **Time Window**: Tempo em segundos (padrão: 300)

**Mapeamento de Campos:**
- **Contract Field**: Campo que contém o número do contrato
- **CPF Field**: Campo que contém o CPF/CNPJ
- **Phone Field**: Campo que contém o telefone (padrão: `mobile`)

**Exemplo de Mensagem:**
```
Olá {contactfield=firstname},

Seu contrato {contactfield=contract_number} foi atualizado.

Qualquer dúvida, entre em contato.
```

**Para RCS:**
- **Template ID**: ID do template RCS cadastrado na BpMessage

### 2. Send BpMessage Email

Envia emails personalizados via BpMessage API.

**Configuração:**

- **ID Service Settings**: ID da rota de email (obrigatório)
- **Batch Size**: Tamanho do lote (padrão: 1000)
- **Time Window**: Tempo em segundos (padrão: 300)

**Campos do Email:**
- **From**: Email do remetente (ex: `noreply@example.com`)
- **To Field**: Campo que contém o email do destinatário (padrão: `email`)
- **Subject**: Assunto do email (suporta tokens)
- **Body**: Corpo do email em HTML (suporta tokens)

**Campos Adicionais (opcionais):**
- **Contract Field**: Campo do contrato
- **CPF/CNPJ Receiver Field**: Campo do CPF/CNPJ
- **CRM ID**: ID do CRM
- **Book Business Foreign ID**: ID externo do negócio
- **Step Foreign ID**: ID externo da etapa

**Exemplo:**
```
Subject: Olá {contactfield=firstname}!

Body:
<html>
  <body>
    <h1>Olá {contactfield=firstname}!</h1>
    <p>Seu contrato <strong>{contactfield=contract_number}</strong> foi atualizado.</p>
    <p>Qualquer dúvida, entre em contato.</p>
  </body>
</html>
```

### 3. Send BpMessage Email Template

Envia emails usando templates existentes do Mautic.

**Configuração:**

- **ID Service Settings**: ID da rota de email (obrigatório)
- **Email Template**: Selecione um template de email do Mautic
- **Batch Size**: Tamanho do lote (padrão: 1000)
- **Time Window**: Tempo em segundos (padrão: 300)

**Campos Adicionais (opcionais):**
- **Contract Field**: Campo do contrato
- **CPF/CNPJ Receiver Field**: Campo do CPF/CNPJ
- **CRM ID**: ID do CRM
- **Book Business Foreign ID**: ID externo do negócio
- **Step Foreign ID**: ID externo da etapa

**Vantagens:**
- ✅ Usa editor visual do Mautic
- ✅ Templates reutilizáveis
- ✅ Tokens substituídos automaticamente
- ✅ Subject e body do template são usados

## 🖥️ Comandos CLI

### Processar Filas

Processa lotes abertos e envia mensagens pendentes:

```bash
# Processar lotes que atingiram critério de fechamento
php bin/console mautic:bpmessage:process

# Forçar fechamento de TODOS os lotes abertos (útil para testes)
php bin/console mautic:bpmessage:process --force-close

# Processar lote específico
php bin/console mautic:bpmessage:process --lot-id=123

# Retentar mensagens com falha
php bin/console mautic:bpmessage:process --retry

# Retentar com máximo de 5 tentativas
php bin/console mautic:bpmessage:process --retry --max-retries=5
```

### Limpeza

Remove lotes e mensagens antigas:

```bash
# Remover lotes finalizados há mais de 30 dias
php bin/console mautic:bpmessage:cleanup

# Remover lotes finalizados há mais de 60 dias
php bin/console mautic:bpmessage:cleanup --days=60

# Modo dry-run (preview)
php bin/console mautic:bpmessage:cleanup --dry-run
```

### Comandos de Teste

```bash
# Criar template de teste
php bin/console mautic:bpmessage:create-test-template

# Testar todas as 3 ações com 50 contatos
php bin/console mautic:bpmessage:test-actions --contacts=50

# Testar apenas uma ação específica
php bin/console mautic:bpmessage:test-actions --action=message
php bin/console mautic:bpmessage:test-actions --action=email
php bin/console mautic:bpmessage:test-actions --action=template
```

## 🗄️ Estrutura do Banco de Dados

### Tabela `bpmessage_lot`

Armazena informações dos lotes:

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | INT | ID interno |
| `external_lot_id` | VARCHAR(255) | ID retornado pela API BpMessage |
| `name` | VARCHAR(255) | Nome do lote |
| `status` | VARCHAR(20) | Status: CREATING, OPEN, SENDING, FINISHED, FAILED |
| `messages_count` | INT | Quantidade de mensagens |
| `campaign_id` | INT | ID da campanha |
| `id_quota_settings` | INT | ID da quota (0 para emails) |
| `id_service_settings` | INT | ID do serviço |
| `service_type` | INT | 1=SMS, 2=WhatsApp, 3=RCS, NULL=Email |
| `batch_size` | INT | Tamanho máximo do lote |
| `time_window` | INT | Janela de tempo em segundos |
| `created_at` | DATETIME | Data de criação |
| `finished_at` | DATETIME | Data de finalização |

### Tabela `bpmessage_queue`

Armazena mensagens na fila:

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | INT | ID interno |
| `lot_id` | INT | FK para bpmessage_lot |
| `lead_id` | INT | FK para leads |
| `payload_json` | TEXT | Payload da mensagem em JSON |
| `status` | VARCHAR(20) | PENDING, SENT, FAILED |
| `retry_count` | SMALLINT | Contador de tentativas |
| `created_at` | DATETIME | Data de criação |
| `sent_at` | DATETIME | Data de envio |

## 🔑 Unicidade de Lotes

Os lotes são **únicos** pela combinação de:

### Para Message Lots (SMS/WhatsApp/RCS):
- `campaign_id` - Qual campanha
- `id_quota_settings` - Qual quota
- `id_service_settings` - Qual configuração de serviço
- `service_type` - 1=SMS, 2=WhatsApp, 3=RCS

### Para Email Lots:
- `campaign_id` - Qual campanha
- `id_quota_settings` - Sempre 0 (não usado para emails)
- `id_service_settings` - Qual configuração de email

**Exemplo:**

Se a mesma campanha tem 2 ações:
- Ação 1: WhatsApp (quota=1000, service=100, type=2)
- Ação 2: SMS (quota=1000, service=200, type=1)

**Resultado:** 2 lotes separados serão criados! ✅

Isso garante que:
- ✅ Mensagens com configurações diferentes não se misturam
- ✅ Cada lote é processado independentemente
- ✅ Relatórios e auditoria são precisos

## 🔄 Fluxo de Funcionamento

```
┌─────────────────────────────────────────────────────────────┐
│              FLUXO DE ENVIO EM LOTE (SMS/WhatsApp)          │
└─────────────────────────────────────────────────────────────┘

1. CONTATO ENTRA NA CAMPANHA
   └── CampaignSubscriber::onCampaignTriggerAction()
       └── BpMessageModel::sendMessage()

2. VALIDAÇÃO
   └── MessageMapper::validateLead()
       ├── Verifica campos obrigatórios
       └── Valida formato do telefone

3. OBTER OU CRIAR LOTE
   └── LotManager::getOrCreateActiveLot()
       ├── Busca lote OPEN com mesma configuração:
       │   • campaign_id
       │   • id_quota_settings
       │   • id_service_settings
       │   • service_type
       └── Se não existe:
           ├── POST /api/Lot/CreateLot → retorna idLot
           ├── EntityManager flush()
           └── SQL UPDATE (fallback para garantir persistência)

4. MAPEAR E ENFILEIRAR MENSAGEM
   └── MessageMapper::mapLeadToMessage()
       └── LotManager::queueMessage()
           ├── Salva em bpmessage_queue (status: PENDING)
           ├── Incrementa lot.messages_count
           └── SQL UPDATE (fallback para garantir incremento)

5. PROCESSAR LOTE (VIA CRON OU --force-close)
   └── ProcessBpMessageQueuesCommand
       └── BpMessageModel::processOpenLots()
           ├── Filtra apenas message lots (idQuotaSettings > 0)
           └── Para cada lote que atingiu critério:
               ├── LotManager::sendLotMessages()
               │   └── POST /api/Lot/AddMessageToLot/{idLot}
               │       (batches de até 5000)
               └── LotManager::finishLot()
                   ├── POST /api/Lot/FinishLot/{idLot}
                   ├── lot.status = 'FINISHED'
                   ├── EntityManager flush()
                   └── SQL UPDATE (fallback para garantir FINISHED)

6. RESULTADO
   └── Status: FINISHED ✅
   └── Mensagens: SENT ✅
```

```
┌─────────────────────────────────────────────────────────────┐
│                    FLUXO DE ENVIO EMAIL                     │
└─────────────────────────────────────────────────────────────┘

1. CONTATO ENTRA NA CAMPANHA
   └── CampaignSubscriber::onCampaignTriggerAction()
       └── BpMessageEmailModel::sendEmail()
           ou BpMessageEmailTemplateModel::sendEmail()

2. VALIDAÇÃO
   └── EmailMessageMapper::validateLead()
       ├── Verifica email válido
       └── Valida campos obrigatórios

3. OBTER OU CRIAR LOTE
   └── EmailLotManager::getOrCreateActiveLot()
       ├── Busca lote OPEN com mesma configuração:
       │   • campaign_id
       │   • id_quota_settings = 0 (fixo)
       │   • id_service_settings
       └── Se não existe:
           ├── POST /api/LotEmail/CreateLotEmail → retorna idLotEmail
           ├── EntityManager flush()
           └── SQL UPDATE (fallback)

4. MAPEAR E ENFILEIRAR EMAIL
   └── EmailMessageMapper::mapLeadToEmail()
       └── EmailLotManager::queueEmail()
           ├── Salva em bpmessage_queue (status: PENDING)
           ├── Incrementa lot.messages_count
           └── SQL UPDATE (fallback)

5. PROCESSAR LOTE (VIA CRON OU --force-close)
   └── ProcessBpMessageQueuesCommand
       └── BpMessageEmailModel::processOpenLots()
           ├── Filtra apenas email lots (idQuotaSettings = 0)
           └── Para cada lote que atingiu critério:
               ├── EmailLotManager::sendLotEmails()
               │   └── POST /api/LotEmail/AddEmailToLot/{idLotEmail}
               │       (batches de até 5000)
               └── EmailLotManager::finishLot()
                   ├── POST /api/LotEmail/FinishLotEmail/{idLotEmail}
                   ├── lot.status = 'FINISHED'
                   ├── EntityManager flush()
                   └── SQL UPDATE (fallback)

6. RESULTADO
   └── Status: FINISHED ✅
   └── Emails: SENT ✅
```

### Estados do Lote

- **CREATING**: Lote está sendo criado na API BpMessage
- **OPEN**: Lote aberto, aceitando mensagens
- **SENDING**: Lote enviando mensagens para a API
- **FINISHED**: Lote finalizado com sucesso
- **FAILED**: Lote falhou durante criação

### Estados da Mensagem

- **PENDING**: Aguardando envio
- **SENT**: Enviada com sucesso
- **FAILED**: Falhou (será retentada até 3x)

### SQL Fallback Pattern

Para garantir persistência durante batch processing do Mautic, o plugin usa SQL direto como fallback:

```php
// 1. Tenta via EntityManager
$lot->setStatus('FINISHED');
$this->entityManager->flush();

// 2. Garante com SQL direto
$connection = $this->entityManager->getConnection();
$connection->executeStatement(
    'UPDATE bpmessage_lot SET status = ? WHERE id = ?',
    ['FINISHED', $lot->getId()]
);

// 3. Atualiza entidade
$this->entityManager->refresh($lot);
```

Este padrão é aplicado em:
- ✅ `createLot()` - Marcar como OPEN
- ✅ `queueMessage()` - Incrementar messages_count
- ✅ `finishLot()` - Marcar como FINISHED

## 🐛 Troubleshooting

### Mensagens não estão sendo enviadas

1. **Verifique o cron**:
```bash
crontab -l | grep bpmessage
```

2. **Execute manualmente**:
```bash
php bin/console mautic:bpmessage:process --force-close -vvv
```

3. **Verifique logs**:
```bash
tail -f var/logs/mautic_prod.log | grep BpMessage
```

### Erro: "Configuration field 'id_quota_settings' must be greater than 0"

Para **message lots** (SMS/WhatsApp/RCS), o `id_quota_settings` deve ser > 0.

Para **email lots**, o sistema automaticamente usa 0.

Verifique a configuração da ação na campanha.

### Lote ficou em OPEN mesmo após processar

Este problema foi corrigido! O sistema agora usa SQL fallback para garantir que o status FINISHED seja persistido.

Se ainda ocorrer:
```bash
# Verificar status do lote
php bin/console ddev exec -- mysql -e "
SELECT id, status, finished_at, messages_count
FROM bpmessage_lot
WHERE id = X
"

# Forçar processamento
php bin/console mautic:bpmessage:process --lot-id=X
```

### Lotes duplicados criados

Certifique-se de que a coluna `service_type` existe no banco:

```sql
ALTER TABLE bpmessage_lot
ADD COLUMN service_type INT NULL AFTER id_service_settings;
```

O sistema verifica unicidade por:
- campaign_id
- id_quota_settings
- id_service_settings
- service_type

### Ver status dos lotes

```sql
-- Lotes por status
SELECT status, COUNT(*) as count, SUM(messages_count) as total_messages
FROM bpmessage_lot
GROUP BY status;

-- Lotes abertos há mais tempo
SELECT id, campaign_id, status, messages_count,
       id_quota_settings, id_service_settings, service_type,
       created_at
FROM bpmessage_lot
WHERE status = 'OPEN'
ORDER BY created_at ASC;

-- Mensagens pendentes por lote
SELECT lot_id, status, COUNT(*) as count
FROM bpmessage_queue
GROUP BY lot_id, status;

-- Verificar unicidade de lotes
SELECT campaign_id, id_quota_settings, id_service_settings,
       service_type, COUNT(*) as count
FROM bpmessage_lot
WHERE status = 'OPEN'
GROUP BY campaign_id, id_quota_settings, id_service_settings, service_type
HAVING count > 1;
```

### Forçar fechamento de lote específico

```bash
php bin/console mautic:bpmessage:process --lot-id=123
```

### Limpar tudo e começar do zero

```bash
# Limpar eventos da campanha
php bin/console ddev exec -- mysql -e "DELETE FROM campaign_lead_event_log WHERE campaign_id = X"

# Limpar filas e lotes
php bin/console ddev exec -- mysql -e "DELETE FROM bpmessage_queue; DELETE FROM bpmessage_lot;"

# Limpar cache
php bin/console cache:clear

# Executar campanha novamente
php bin/console mautic:campaigns:trigger --campaign-id=X
```

## 🔧 Desenvolvimento

### Estrutura do Código

```
MauticBpMessageBundle/
├── Command/                    # Comandos CLI
│   ├── ProcessBpMessageQueuesCommand.php
│   ├── CleanupBpMessageCommand.php
│   ├── TestBpMessageActionsCommand.php
│   └── CreateTestTemplateCommand.php
├── Config/                     # Configurações
│   ├── config.php              # Serviços e dependências
│   └── services.php            # Container config
├── Entity/                     # Entidades Doctrine
│   ├── BpMessageLot.php
│   ├── BpMessageLotRepository.php
│   ├── BpMessageQueue.php
│   └── BpMessageQueueRepository.php
├── EventListener/              # Event Subscribers
│   └── CampaignSubscriber.php
├── Form/Type/                  # Form Types
│   ├── BpMessageActionType.php
│   ├── BpMessageEmailActionType.php
│   └── BpMessageEmailTemplateActionType.php
├── Http/                       # Cliente HTTP
│   └── BpMessageClient.php
├── Integration/                # Integração Mautic
│   ├── BpMessageIntegration.php
│   └── Support/
│       └── BpMessageSupport.php
├── Model/                      # Models
│   ├── BpMessageModel.php
│   ├── BpMessageEmailModel.php
│   └── BpMessageEmailTemplateModel.php
├── Service/                    # Services
│   ├── LotManager.php
│   ├── EmailLotManager.php
│   ├── MessageMapper.php
│   ├── EmailMessageMapper.php
│   └── EmailTemplateMessageMapper.php
└── Translations/               # Traduções
    ├── en_US/messages.ini
    └── pt_BR/messages.ini
```

### Padrões de Código

#### 1. SQL Fallback Pattern

Use este padrão para operações críticas:

```php
// EntityManager
$entity->setField($value);
$this->entityManager->flush();

// SQL Fallback
$connection = $this->entityManager->getConnection();
$connection->executeStatement(
    'UPDATE table SET field = ? WHERE id = ?',
    [$value, $entity->getId()]
);

// Refresh
$this->entityManager->refresh($entity);
```

#### 2. Unicidade de Lotes

Ao buscar ou criar lotes, sempre verifique:

```php
// Para message lots
$qb->where('l.campaignId = :campaignId')
    ->andWhere('l.idQuotaSettings = :idQuotaSettings')
    ->andWhere('l.idServiceSettings = :idServiceSettings')
    ->andWhere('l.serviceType = :serviceType');

// Para email lots
$qb->where('l.campaignId = :campaignId')
    ->andWhere('l.idQuotaSettings = 0')
    ->andWhere('l.idServiceSettings = :idServiceSettings');
```

#### 3. Processamento Separado

Message lots e email lots são processados separadamente:

```php
// BpMessageModel
->andWhere('l.idQuotaSettings > 0')  // Message lots

// BpMessageEmailModel
->andWhere('l.idQuotaSettings = 0')  // Email lots
```

### Adicionar Nova Ação de Campanha

1. Criar Form Type em `Form/Type/`:
```php
class NewActionType extends AbstractType { ... }
```

2. Criar Model em `Model/`:
```php
class NewModel {
    public function sendNewType(Lead $lead, array $config, Campaign $campaign): array
}
```

3. Registrar no `CampaignSubscriber.php`:
```php
CampaignEvents::CAMPAIGN_ON_BUILD => [
    ['onCampaignBuild', 0],
],
```

4. Adicionar traduções em `Translations/*/messages.ini`

5. Registrar serviços em `Config/config.php`

### Testes

```bash
# Criar template de teste
php bin/console mautic:bpmessage:create-test-template

# Testar ações
php bin/console mautic:bpmessage:test-actions --contacts=10 --action=message

# Ver logs
tail -f var/logs/mautic_dev.log | grep BpMessage
```

### Debug

Ativar logs detalhados:

```bash
# Ver requests HTTP
tail -f var/logs/mautic_dev.log | grep "BpMessage HTTP"

# Ver operações de lote
tail -f var/logs/mautic_dev.log | grep "BpMessage.*lot"

# Ver processamento
tail -f var/logs/mautic_dev.log | grep "Processing"
```

## 📄 Licença

GPL-3.0-or-later

## 👥 Autores

**Bellinati Perez**

## 🤝 Contribuindo

Contribuições são bem-vindas! Por favor:

1. Fork o projeto
2. Crie uma branch para sua feature (`git checkout -b feature/AmazingFeature`)
3. Commit suas mudanças (`git commit -m 'Add some AmazingFeature'`)
4. Push para a branch (`git push origin feature/AmazingFeature`)
5. Abra um Pull Request

## 📞 Suporte

Para suporte, abra uma issue no repositório.

---

## 📝 Changelog

### v2.0.0 (2025-01-08)

- ✅ Adicionadas 3 ações de campanha (Message, Email, Email Template)
- ✅ Implementado sistema de unicidade de lotes
- ✅ Adicionado SQL fallback pattern para garantir persistência
- ✅ Implementado --force-close para processamento imediato
- ✅ Separado processamento de message lots e email lots
- ✅ Corrigida persistência do finishLot()
- ✅ Adicionado campo service_type para unicidade
- ✅ Melhorias nos logs e debugging
- ✅ Comandos de teste e validação
