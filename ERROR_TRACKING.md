# Sistema de Registro de Erros - BpMessage Plugin

## Visão Geral

O plugin BpMessage agora registra todos os erros detalhados que ocorrem durante o envio de mensagens para a API do BpMessage. Isso permite que você visualize e corrija problemas facilmente.

## Como Funciona

### 1. Captura Automática de Erros

Quando ocorre um erro ao enviar contatos para o BpMessage, o sistema:

1. **Salva o erro no lote** - O erro é registrado no campo `error_message` da tabela `bpmessage_lot`
2. **Atualiza o status** - O status do lote é alterado para `FAILED`
3. **Marca os contatos** - As mensagens na fila são marcadas como `FAILED` com detalhes do erro
4. **Registra nos logs** - O erro também é logado em `var/logs/mautic_prod.log`

### 2. Tipos de Erros Capturados

#### Erro de Validação da API

Quando a API do BpMessage rejeita os dados por campos obrigatórios vazios:

```
Batch 0 failed: HTTP 400: {"messages":["'Area Code' must not be empty.","'Contract' must not be empty."]}
```

#### Erro de Formato JSON

Quando o formato dos dados está incorreto:

```
Batch 0 failed: HTTP 400: {"errors":{"[0]":["Cannot deserialize the current JSON array..."]}}
```

#### Erro de Conexão

Quando a API do BpMessage está inacessível:

```
Batch 0 failed: Connection timeout to https://api.bpmessage.com.br
```

#### Erro de Autenticação

Quando as credenciais estão incorretas:

```
Batch 0 failed: HTTP 401: Unauthorized
```

## Comandos Disponíveis

### 1. Listar Lotes com Erros

```bash
# Listar lotes que falharam
php bin/console mautic:bpmessage:list-failed-lots

# Mostrar mais detalhes
php bin/console mautic:bpmessage:list-failed-lots --verbose

# Limitar resultados
php bin/console mautic:bpmessage:list-failed-lots --limit=10
```

**Saída:**
```
BpMessage Lots with Errors
==========================

Found 3 lot(s) with errors

┌────┬──────────────────┬────────┬──────────┬──────────┬─────────────────────┬────────────────────────────────┐
│ ID │ Name             │ Status │ Type     │ Messages │ Created At          │ Error                          │
├────┼──────────────────┼────────┼──────────┼──────────┼─────────────────────┼────────────────────────────────┤
│ 5  │ Send WhatsApp    │ FAILED │ WhatsApp │ 1        │ 2025-11-21 19:49:23 │ Batch 0 failed: HTTP 400...    │
│ 4  │ Send SMS         │ FAILED │ SMS      │ 2        │ 2025-11-21 19:30:15 │ Batch 0 failed: Connection...  │
└────┴──────────────────┴────────┴──────────┴──────────┴─────────────────────┴────────────────────────────────┘

 ! [NOTE] To see full error details, run this command with --verbose (-v) flag
 ! [NOTE] To retry a failed lot, run: php bin/console mautic:bpmessage:process --lot-id=<ID>
```

### 2. Processar Lote Específico

Ao processar um lote que falhou, o erro será exibido:

```bash
php bin/console mautic:bpmessage:process --lot-id=5
```

**Saída com erro:**
```
BpMessage Queue Processor
=========================

Processing lot #5
-----------------

 [ERROR] Failed to process lot #5

 ! [WARNING] Error details:
Batch 0 failed: HTTP 400: {"messages":["'Area Code' must not be empty.","'Contract' must not be empty."]}

 ! [NOTE] Fix the error and retry with: php bin/console mautic:bpmessage:process --lot-id=5
```

### 3. Verificar Erros no Banco de Dados

```sql
-- Ver lotes com erro
SELECT
    id,
    name,
    status,
    error_message,
    created_at
FROM bpmessage_lot
WHERE status IN ('FAILED', 'FAILED_CREATION')
   OR error_message IS NOT NULL
ORDER BY created_at DESC;

-- Ver detalhes de um lote específico
SELECT * FROM bpmessage_lot WHERE id = 5\G

-- Ver contatos que falharam
SELECT
    q.id,
    q.lead_id,
    q.status,
    q.retry_count,
    q.error_message,
    l.name as lot_name
FROM bpmessage_queue q
JOIN bpmessage_lot l ON q.lot_id = l.id
WHERE q.status = 'FAILED'
ORDER BY q.id DESC
LIMIT 20;
```

## Fluxo de Correção de Erros

### Passo 1: Identificar o Problema

```bash
# Listar todos os lotes com erro
php bin/console mautic:bpmessage:list-failed-lots -v
```

### Passo 2: Analisar o Erro

Exemplos de erros comuns e suas soluções:

#### ❌ Erro: "Area Code must not be empty"

**Causa:** O campo `dddmobile` do contato está vazio ou o token `{contactfield=dddmobile}` não está mapeado.

**Solução:**
```sql
-- Verificar contato
SELECT id, mobile, dddmobile FROM leads WHERE id = <lead_id>;

-- Corrigir contato
UPDATE leads SET dddmobile = '48' WHERE id = <lead_id>;
```

#### ❌ Erro: "Contract must not be empty"

**Causa:** O campo `contractnumber` do contato está vazio.

**Solução:**
```sql
-- Corrigir contato
UPDATE leads SET contractnumber = '1234567890' WHERE id = <lead_id>;
```

#### ❌ Erro: "Cannot deserialize JSON array"

**Causa:** Formato do payload está incorreto.

**Solução:** Verificar se o payload está em formato de objeto e não array:
```json
// ✅ Correto
{
  "control": true,
  "contactName": "João"
}

// ❌ Incorreto
[
  {
    "control": true,
    "contactName": "João"
  }
]
```

### Passo 3: Corrigir os Dados

Após corrigir os dados do contato, é necessário atualizar o payload na fila:

```sql
-- Ver payload atual
SELECT id, payload_json FROM bpmessage_queue WHERE lot_id = <lot_id>\G

-- Atualizar payload manualmente (se necessário)
UPDATE bpmessage_queue
SET payload_json = '<json_corrigido>'
WHERE id = <queue_id>;
```

**OU** recriar o lote disparando a campanha novamente para o contato.

### Passo 4: Retentar o Envio

```bash
# Resetar status do lote
UPDATE bpmessage_lot SET status = 'OPEN', error_message = NULL WHERE id = <lot_id>;

# Resetar mensagens pendentes
UPDATE bpmessage_queue
SET status = 'PENDING', error_message = NULL, retry_count = 0
WHERE lot_id = <lot_id> AND status = 'FAILED';

# Processar novamente
php bin/console mautic:bpmessage:process --lot-id=<lot_id>
```

## Comandos K3s

### Listar Erros no K3s

```bash
# Listar lotes com erro
kubectl exec -n marketing deployment/mautic-web -c mautic -- \
  php bin/console mautic:bpmessage:list-failed-lots -v

# Processar lote específico
kubectl exec -n marketing deployment/mautic-web -c mautic -- \
  php bin/console mautic:bpmessage:process --lot-id=5 -vvv

# Ver logs
kubectl exec -n marketing deployment/mautic-web -c mautic -- \
  tail -100 /var/www/html/var/logs/mautic_prod.log | grep -i bpmessage
```

### Corrigir Dados no K3s

```bash
# Conectar ao MySQL
kubectl exec -n marketing deployment/mautic-web -c mautic -- \
  mysql -h $MAUTIC_DB_HOST -u $MAUTIC_DB_USER -p$MAUTIC_DB_PASSWORD $MAUTIC_DB_NAME

# Executar queries de correção
kubectl exec -n marketing deployment/mautic-web -c mautic -- \
  mysql -h $MAUTIC_DB_HOST -u $MAUTIC_DB_USER -p$MAUTIC_DB_PASSWORD $MAUTIC_DB_NAME \
  -e "UPDATE leads SET dddmobile = '48' WHERE id = 1;"
```

## Prevenção de Erros

### 1. Validar Campos Obrigatórios

Antes de enviar para RCS, garanta que o contato tenha:

- ✅ `mobile` - Número do telefone
- ✅ `dddmobile` - Código de área (DDD)
- ✅ `contractnumber` - Número do contrato
- ✅ `cpf` ou `cpjcnpj` - CPF/CNPJ do destinatário

### 2. Usar Segmentos na Campanha

Criar segmentos que filtrem apenas contatos com dados completos:

```
Filters:
- Mobile: not empty
- DDD Mobile: not empty
- Contract Number: not empty
- CPF: not empty
```

### 3. Importar Dados Completos

Ao importar contatos via CSV, garantir que todas as colunas obrigatórias estejam preenchidas.

### 4. Monitorar Erros Regularmente

Agendar verificação periódica de lotes com erro:

```bash
# No cron (a cada hora)
0 * * * * php /var/www/html/bin/console mautic:bpmessage:list-failed-lots --limit=50 >> /var/log/bpmessage-errors.log
```

## Arquitetura

### Fluxo de Erro

```
1. Campanha dispara ação BpMessage
   ↓
2. Contato é adicionado à fila (bpmessage_queue)
   ↓
3. Cron processa lote
   ↓
4. Tenta enviar para API BpMessage
   ↓
5. API retorna erro (HTTP 400)
   ↓
6. Sistema registra erro:
   - Salva no lote (bpmessage_lot.error_message)
   - Atualiza status para FAILED
   - Marca mensagens como FAILED
   - Loga erro
   ↓
7. Usuário visualiza erro via comando
   ↓
8. Usuário corrige dados
   ↓
9. Usuário reprocessa lote
```

### Arquivos Modificados

1. **`Service/LotManager.php`** (linha 447-467)
   - Captura erro da API
   - Salva no lote com `setErrorMessage()`
   - Atualiza status para `FAILED`

2. **`Command/ProcessBpMessageQueuesCommand.php`** (linha 106-139)
   - Busca lote do banco após falha
   - Exibe erro detalhado ao usuário

3. **`Model/BpMessageModel.php`** (linha 472-477)
   - Novo método `getLotById()` para buscar lote

4. **`Command/ListFailedLotsCommand.php`** (novo arquivo)
   - Comando para listar lotes com erro
   - Tabela formatada com resumo
   - Modo verbose para detalhes completos

## Benefícios

✅ **Visibilidade Total** - Todos os erros são registrados e podem ser visualizados facilmente

✅ **Recuperação Rápida** - Corrige o problema e reprocessa sem perder dados

✅ **Auditoria** - Histórico completo de erros no banco de dados

✅ **Debugging Facilitado** - Mensagens de erro detalhadas com contexto

✅ **Prevenção de Perda** - Contatos nunca são perdidos, mesmo com erro na API

## Suporte

Em caso de dúvidas ou problemas:

1. Verificar logs: `var/logs/mautic_prod.log`
2. Listar lotes com erro: `php bin/console mautic:bpmessage:list-failed-lots -v`
3. Verificar banco de dados diretamente
4. Consultar documentação em `TROUBLESHOOT_LOT_ERROR.md`
