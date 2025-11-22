# Troubleshooting: "Contract must not be empty"

## Erro em Produção

```bash
php bin/console mautic:bpmessage:process --force-close --lot-id 12

Processing lot #12
Failed to process lot #12
Error details:
Batch 0 failed: HTTP 400: {"messages":["'Contract' must not be empty."]}
```

---

## 🔍 Causa do Problema

A API BpMessage rejeita mensagens sem o campo **`contract`**, que é obrigatório para identificação do destinatário.

### Onde o campo `contract` é configurado?

O campo `contract` vem da configuração **`additional_data`** da ação de campanha:

```json
{
  "additional_data": {
    "contract": "{contactfield=contractnumber}"
  }
}
```

Se o lead não tiver o campo `contractnumber` preenchido, ou se a configuração estiver incorreta, o campo `contract` ficará vazio e a API rejeitará a mensagem.

---

## 🛠️ Como Resolver

### Passo 1: Verificar qual lead está com problema

Agora com a correção da query, você pode ver os IDs dos leads que falharam:

```bash
php bin/console mautic:bpmessage:process --lot-id 12
```

**Saída esperada:**
```
Processing lot #12
Failed to process lot #12
Error details:
Batch 0 failed: HTTP 400: {"messages":["'Contract' must not be empty."]}

Failed Messages Sample (first 5):
  Queue ID: 123, Lead ID: 456, Retries: 1, Error: HTTP 400: 'Contract' must not be empty.
  Queue ID: 124, Lead ID: 457, Retries: 1, Error: HTTP 400: 'Contract' must not be empty.
```

### Passo 2: Verificar os leads no banco de dados

```bash
kubectl exec -it mautic-web-xxx -n marketing -- bash

# Verificar dados dos leads
mysql -h db -u root -p -D mautic_prod -e "
SELECT
    id,
    email,
    contractnumber,
    contract_number,
    mobile,
    firstname,
    lastname
FROM leads
WHERE id IN (456, 457);
"
```

**Possíveis cenários:**

#### Cenário 1: Campo `contractnumber` está vazio
```
id  | email            | contractnumber | mobile      | firstname
456 | joao@email.com   | NULL           | 11987654321 | João
```

**Solução:** Preencher o campo `contractnumber` para esses leads:
```bash
mysql -h db -u root -p -D mautic_prod -e "
UPDATE leads
SET contractnumber = '123456'
WHERE id = 456;
"
```

#### Cenário 2: Nome do campo está incorreto
O campo pode estar salvo como `contract_number` ao invés de `contractnumber`:
```
id  | email            | contract_number | contractnumber | firstname
456 | joao@email.com   | 123456          | NULL           | João
```

**Solução:** Verificar qual campo está preenchido e atualizar a configuração da campanha:
```json
{
  "additional_data": {
    "contract": "{contactfield=contract_number}"  // Note o underscore
  }
}
```

### Passo 3: Verificar a configuração da campanha

```bash
mysql -h db -u root -p -D mautic_prod -e "
SELECT
    e.id,
    e.name,
    e.properties
FROM campaign_events e
WHERE e.campaign_id = 3
  AND e.type LIKE 'bpmessage%'
LIMIT 1\G
"
```

Procure por `additional_data` nas propriedades e verifique se o mapeamento está correto.

### Passo 4: Reprocessar o lote após correção

Depois de corrigir os dados dos leads ou a configuração:

```bash
# Reprocessar lote específico
php bin/console mautic:bpmessage:process --lot-id 12
```

---

## 🔧 Prevenção

### 1. Validação Antes de Adicionar à Campanha

Adicione um **segmento filter** na campanha para garantir que apenas leads com `contractnumber` preenchido sejam processados:

**Segmento:**
```
Contact Field: contractnumber
Operator: is not empty
```

### 2. Configuração com Fallback

Se alguns leads podem não ter contrato, configure um valor padrão:

```json
{
  "additional_data": {
    "contract": "{contactfield=contractnumber|default:NENHUM}"
  }
}
```

**⚠️ Atenção:** Isso pode não funcionar com o TokenHelper do Mautic. Nesse caso, preencha o campo antes de adicionar à campanha.

### 3. Pré-processamento com Webhook

Configure um webhook que:
1. Verifica se o lead tem `contractnumber`
2. Se não tiver, busca de outra fonte ou preenche com valor padrão
3. Atualiza o lead antes de adicionar à campanha

---

## 📊 Diagnosticar Lotes com Problema

### Verificar quantos leads têm contract vazio

```bash
mysql -h db -u root -p -D mautic_prod -e "
SELECT
    COUNT(*) as total_leads,
    SUM(CASE WHEN contractnumber IS NULL OR contractnumber = '' THEN 1 ELSE 0 END) as sem_contract,
    SUM(CASE WHEN contractnumber IS NOT NULL AND contractnumber != '' THEN 1 ELSE 0 END) as com_contract
FROM leads
WHERE id IN (
    SELECT DISTINCT lead_id
    FROM campaign_leads
    WHERE campaign_id = 3
);
"
```

### Verificar mensagens falhadas por motivo

```bash
mysql -h db -u root -p -D mautic_prod -e "
SELECT
    error_message,
    COUNT(*) as quantidade
FROM bpmessage_queue
WHERE status = 'FAILED'
  AND lot_id = 12
GROUP BY error_message
ORDER BY quantidade DESC;
"
```

---

## 🚀 Solução Automática (Script)

Crie um script para corrigir leads sem contract antes de processar:

```bash
#!/bin/bash
# fix-empty-contracts.sh

CAMPAIGN_ID=3
DEFAULT_CONTRACT="PENDING"

echo "Buscando leads sem contractnumber na campanha ${CAMPAIGN_ID}..."

mysql -h db -u root -p -D mautic_prod -e "
UPDATE leads l
INNER JOIN campaign_leads cl ON l.id = cl.lead_id
SET l.contractnumber = '${DEFAULT_CONTRACT}'
WHERE cl.campaign_id = ${CAMPAIGN_ID}
  AND (l.contractnumber IS NULL OR l.contractnumber = '');
"

echo "Leads atualizados! Agora pode processar o lote."
```

---

## 📝 Resumo das Correções

### 1. Correção no Comando (já feito)

**Arquivo:** `ProcessBpMessageQueuesCommand.php`
**Linha 126:** Mudou de `q.leadId` para `IDENTITY(q.lead) as leadId`

**Antes:**
```php
->select('q.id', 'q.leadId', 'q.errorMessage', 'q.retryCount')
```

**Depois:**
```php
->select('q.id', 'IDENTITY(q.lead) as leadId', 'q.errorMessage', 'q.retryCount')
```

### 2. Correção nos Leads

**Opção A:** Preencher campo manualmente
```sql
UPDATE leads SET contractnumber = '123456' WHERE id = 456;
```

**Opção B:** Corrigir mapeamento na campanha
```json
"additional_data": {
  "contract": "{contactfield=contract_number}"
}
```

**Opção C:** Adicionar validação no segmento
```
Filtro: contractnumber is not empty
```

---

## ⚠️ Notas Importantes

1. O erro `"Contract must not be empty"` é validado pela **API BpMessage**, não pelo plugin
2. O campo `contract` é **obrigatório** para SMS/WhatsApp/RCS
3. Mensagens rejeitadas ficam com status `FAILED` e podem ser reprocessadas
4. O lote fica com status `FAILED` se **qualquer** mensagem falhar no batch
5. Depois de corrigir os dados, você pode reprocessar o mesmo lote com `--lot-id`

---

## 🧪 Testar Após Correção

```bash
# 1. Ver lotes com erro
php bin/console mautic:bpmessage:failed-lots

# 2. Reprocessar lote específico
php bin/console mautic:bpmessage:process --lot-id 12

# 3. Verificar se foi corrigido
php bin/console mautic:bpmessage:process --lot-id 12
```

Se tudo estiver correto, você verá:
```
Processing lot #12
Lot #12 processed successfully
```
