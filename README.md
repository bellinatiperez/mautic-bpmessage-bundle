# MauticBpMessageBundle

Plugin para Mautic que integra com a API BpMessage para envio de mensagens SMS, WhatsApp e RCS em lote.

## 📋 Índice

- [Características](#características)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Uso](#uso)
- [Comandos CLI](#comandos-cli)
- [Estrutura do Banco de Dados](#estrutura-do-banco-de-dados)
- [Fluxo de Funcionamento](#fluxo-de-funcionamento)
- [Troubleshooting](#troubleshooting)
- [Desenvolvimento](#desenvolvimento)

## 🚀 Características

- ✅ **Envio em Lote**: Agrupa mensagens para envio otimizado (até 5000 por lote)
- ✅ **Múltiplos Canais**: Suporta SMS, WhatsApp e RCS
- ✅ **Integração com Campanhas**: Ação nativa no Campaign Builder do Mautic
- ✅ **Tokens Dinâmicos**: Use `{contactfield=fieldname}` para personalizar mensagens
- ✅ **Gestão de Filas**: Sistema robusto de filas com retry automático
- ✅ **Configuração Flexível**: Controle de tamanho de lote e janela de tempo
- ✅ **Logs Detalhados**: Auditoria completa de todas as operações
- ✅ **CLI Commands**: Comandos para processar filas e fazer limpeza

## 📦 Requisitos

- Mautic 4.x ou 5.x
- PHP 7.4+ ou 8.0+
- Conta ativa na API BpMessage
- Credenciais da API BpMessage (idQuotaSettings, idServiceSettings)

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
4. Clique em "Install"

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

### 1. Obter Credenciais da BpMessage

Antes de configurar, você precisa obter as seguintes informações da BpMessage:

- **API Base URL**: URL da API (ex: `https://api.bpmessage.com.br`)
- **User CPF**: CPF do usuário autorizado
- **ID Quota Settings**: ID da cota disponível
- **ID Service Settings**: ID da rota de envio

Para obter IDs de cota e rota, consulte o endpoint da BpMessage:
```
GET /api/ServiceSettings/GetRoutes
```

### 2. Criar Campanha no Mautic

1. Acesse **Campaigns** → **New**
2. Dê um nome à campanha
3. Configure a fonte de contatos (segmento, formulário, etc.)

### 3. Adicionar Ação BpMessage

1. No Campaign Builder, clique em **"Add Action"**
2. Selecione **"Send BpMessage"**
3. Configure os campos:

#### Configurações da API
- **API Base URL**: `https://api.bpmessage.com.br`
- **User CPF**: CPF do usuário (11 dígitos)

#### Configurações do Lote
- **Lot Name**: Nome descritivo do lote (opcional)
- **Start Date**: Data de início do disparo (padrão: agora)
- **End Date**: Data de término do disparo (padrão: +1 dia)
- **Batch Size**: Quantidade de mensagens por lote (padrão: 1000, máx: 5000)
- **Time Window**: Tempo em segundos para aguardar antes de fechar lote (padrão: 300)

#### Configurações da Rota
- **ID Quota Settings**: ID da cota (obrigatório)
- **ID Service Settings**: ID da rota (obrigatório)
- **ID Book Business Send Group**: ID do grupo (obrigatório para WhatsApp oficial)

#### Tipo de Serviço
- **SMS** (idServiceType: 1)
- **WhatsApp** (idServiceType: 2) - padrão
- **RCS** (idServiceType: 3)

#### Mapeamento de Campos
- **Contract Field**: Nome do campo que contém o número do contrato (ex: `contract_number`)
- **CPF Field**: Nome do campo que contém o CPF/CNPJ (ex: `cpf`)
- **Phone Field**: Nome do campo que contém o telefone (padrão: `mobile`)

#### Mensagem (SMS/WhatsApp)
```
Olá {contactfield=firstname},

Sua mensagem personalizada aqui.

Contrato: {contactfield=contract_number}
```

**Tokens disponíveis:**
- `{contactfield=fieldname}` - Qualquer campo do contato
- `{timestamp}` - Unix timestamp atual
- `{date_now}` - Data e hora atual

#### Template RCS (apenas para RCS)
- **Template ID**: ID do template RCS cadastrado na BpMessage

### 4. Exemplo de Configuração Completa

```yaml
API Base URL: https://api.bpmessage.com.br
User CPF: 12345678900
ID Quota Settings: 123
ID Service Settings: 456
Service Type: WhatsApp
Batch Size: 1000
Time Window: 300 (5 minutos)

Mapeamento:
  Contract Field: contract_number
  CPF Field: cpf
  Phone Field: mobile

Mensagem:
  Olá {contactfield=firstname},

  Seu contrato {contactfield=contract_number} foi atualizado.

  Qualquer dúvida, entre em contato.
```

## 📱 Uso

### Fluxo Normal

1. **Contato entra na campanha** → Ação BpMessage é acionada
2. **Plugin verifica**: Existe lote aberto para esta campanha?
   - **Não existe**: Cria novo lote via API BpMessage
   - **Existe**: Usa o lote existente
3. **Mensagem é adicionada à fila** do lote
4. **Cron processa**: A cada 5 minutos verifica lotes que devem ser fechados
   - **Critério de tempo**: Passou X segundos desde primeira mensagem?
   - **Critério de quantidade**: Atingiu Y mensagens?
5. **Envia mensagens** via `POST /api/Lot/AddMessageToLot/{idLot}`
6. **Finaliza lote** via `POST /api/Lot/FinishLot/{idLot}`

### Estados do Lote

- **CREATING**: Lote está sendo criado na API
- **OPEN**: Lote aberto, aceitando mensagens
- **SENDING**: Lote fechando, enviando mensagens
- **FINISHED**: Lote finalizado com sucesso
- **FAILED**: Lote falhou

### Estados da Mensagem

- **PENDING**: Aguardando envio
- **SENT**: Enviada com sucesso
- **FAILED**: Falhou (será retentada até 3x)

## 🖥️ Comandos CLI

### Processar Filas

Processa lotes abertos e envia mensagens pendentes:

```bash
# Processar lotes que atingiram critério de fechamento
php bin/console mautic:bpmessage:process

# Forçar fechamento de todos os lotes abertos
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

## 🗄️ Estrutura do Banco de Dados

### Tabela `bpmessage_lot`

Armazena informações dos lotes:

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | INT | ID interno |
| `external_lot_id` | VARCHAR(255) | ID retornado pela API BpMessage |
| `name` | VARCHAR(255) | Nome do lote |
| `status` | VARCHAR(20) | Status do lote |
| `messages_count` | INT | Quantidade de mensagens |
| `campaign_id` | INT | ID da campanha |
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
| `status` | VARCHAR(20) | Status da mensagem |
| `retry_count` | SMALLINT | Contador de tentativas |
| `created_at` | DATETIME | Data de criação |
| `sent_at` | DATETIME | Data de envio |

## 🔄 Fluxo de Funcionamento

```
┌─────────────────────────────────────────────────────────────┐
│                    FLUXO DE ENVIO EM LOTE                   │
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
       ├── Busca lote aberto da campanha
       └── Se não existe:
           └── POST /api/Lot/CreateLot → retorna idLot

4. MAPEAR E ENFILEIRAR MENSAGEM
   └── MessageMapper::mapLeadToMessage()
       └── LotManager::queueMessage()
           └── Salva em bpmessage_queue (status: PENDING)

5. PROCESSAR LOTE (VIA CRON)
   └── ProcessBpMessageQueuesCommand
       └── BpMessageModel::processOpenLots()
           └── Para cada lote que atingiu critério:
               ├── LotManager::sendLotMessages()
               │   └── POST /api/Lot/AddMessageToLot/{idLot}
               │       (batches de até 5000)
               └── LotManager::finishLot()
                   └── POST /api/Lot/FinishLot/{idLot}

6. RESULTADO
   └── Status: FINISHED ✅
   └── Mensagens: SENT ✅
```

## 🐛 Troubleshooting

### Mensagens não estão sendo enviadas

1. **Verifique o cron**:
```bash
crontab -l | grep bpmessage
```

2. **Execute manualmente**:
```bash
php bin/console mautic:bpmessage:process -vvv
```

3. **Verifique logs**:
```bash
tail -f var/logs/mautic_prod.log | grep BpMessage
```

### Erro: "Lead validation failed"

Verifique se o contato tem todos os campos obrigatórios:
- Campo de contrato
- Campo de CPF
- Campo de telefone (formato: 11987654321)

### Erro: "Failed to create lot in BpMessage"

1. Verifique a URL da API
2. Verifique as credenciais (idQuotaSettings, idServiceSettings)
3. Teste a conexão:
```bash
curl -X POST https://api.bpmessage.com.br/api/Lot/CreateLot \
  -H "Content-Type: application/json" \
  -d '{...}'
```

### Ver status dos lotes

```sql
-- Lotes por status
SELECT status, COUNT(*) as count, SUM(messages_count) as total_messages
FROM bpmessage_lot
GROUP BY status;

-- Lotes abertos há mais tempo
SELECT id, name, created_at, messages_count
FROM bpmessage_lot
WHERE status = 'OPEN'
ORDER BY created_at ASC;

-- Mensagens pendentes por lote
SELECT lot_id, status, COUNT(*) as count
FROM bpmessage_queue
GROUP BY lot_id, status;
```

### Forçar fechamento de lote específico

```bash
php bin/console mautic:bpmessage:process --lot-id=123
```

## 🔧 Desenvolvimento

### Estrutura do Código

```
MauticBpMessageBundle/
├── Command/                    # Comandos CLI
│   ├── ProcessBpMessageQueuesCommand.php
│   └── CleanupBpMessageCommand.php
├── Config/                     # Configurações
│   └── config.php
├── Entity/                     # Entidades Doctrine
│   ├── BpMessageLot.php
│   ├── BpMessageLotRepository.php
│   ├── BpMessageQueue.php
│   └── BpMessageQueueRepository.php
├── EventListener/              # Event Subscribers
│   └── CampaignSubscriber.php
├── Form/Type/                  # Form Types
│   └── BpMessageActionType.php
├── Http/                       # Cliente HTTP
│   └── BpMessageClient.php
├── Model/                      # Models
│   └── BpMessageModel.php
├── Service/                    # Services
│   ├── LotManager.php
│   └── MessageMapper.php
└── Translations/               # Traduções
    ├── en_US/messages.ini
    └── pt_BR/messages.ini
```

### Adicionar Novo Campo

1. Adicionar no `BpMessageActionType.php`:
```php
$builder->add('new_field', TextType::class, [
    'label' => 'mautic.bpmessage.form.new_field',
    // ...
]);
```

2. Adicionar no `MessageMapper.php`:
```php
if (!empty($config['new_field'])) {
    $message['newField'] = $config['new_field'];
}
```

3. Adicionar tradução em `messages.ini`:
```ini
mautic.bpmessage.form.new_field="New Field"
```

### Logs

Para ativar logs detalhados, adicione em `app/config/config_dev.php`:

```php
$container->loadFromExtension('monolog', [
    'channels' => ['bpmessage'],
    'handlers' => [
        'bpmessage' => [
            'type' => 'stream',
            'path' => '%kernel.logs_dir%/bpmessage_%kernel.environment%.log',
            'level' => 'debug',
            'channels' => ['bpmessage'],
        ],
    ],
]);
```

## 📄 Licença

GPL-3.0-or-later

## 👥 Autores

**Bellinati**
Email: dev@bellinati.com.br

## 🤝 Contribuindo

Contribuições são bem-vindas! Por favor:

1. Fork o projeto
2. Crie uma branch para sua feature (`git checkout -b feature/AmazingFeature`)
3. Commit suas mudanças (`git commit -m 'Add some AmazingFeature'`)
4. Push para a branch (`git push origin feature/AmazingFeature`)
5. Abra um Pull Request

## 📞 Suporte

Para suporte, entre em contato com dev@bellinati.com.br ou abra uma issue no repositório.
