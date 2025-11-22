# Troubleshooting: HTTP 500 - Object Reference Not Set (Email)

## Erro em Produção

```bash
php bin/console mautic:bpmessage:process --force-close --lot-id 15

Processing lot #15
Failed to process lot #15
Error details:
Batch 0 failed: HTTP 500: {"messages":["Object reference not set to an instance of an object."]}
```

---

## 🔍 Causa do Problema

Este erro **HTTP 500** vem da **API BpMessage** (servidor C#/.NET) e indica que um **campo obrigatório** está `null` ou vazio no payload enviado.

### Campos Obrigatórios para Email (API BpMessage)

| Campo | Descrição | Exemplo |
|-------|-----------|---------|
| `from` | Email remetente | `noreply@empresa.com` |
| `to` | Email destinatário | `joao@email.com` |
| `subject` | Assunto | `Bem-vindo!` |
| `body` | HTML do email | `<html>...</html>` |
| `contract` | Número do contrato | `123456` |
| `cpfCnpjReceiver` | CPF/CNPJ do destinatário | `12345678900` |
| `idForeignBookBusiness` | ID da carteira | `12345` |

---

## 🛠️ Como Diagnosticar

### Passo 1: Verificar logs detalhados

```bash
kubectl exec -it mautic-web-xxx -n marketing -- bash

# Ver logs do BpMessage com detalhes do payload
tail -100 /var/www/html/var/logs/mautic_prod.log | grep -A 20 "lot.*15"
```

**Procure por:**
- `BpMessage: Sending batch` → Ver quantos itens no batch
- `BpMessage: Batch payload` → Ver o JSON completo enviado
- `BpMessage: API Response` → Ver resposta da API

### Passo 2: Verificar mensagens do lote no banco

```sql
SELECT
    id,
    lead_id,
    status,
    error_message,
    retry_count,
    created_at
FROM bpmessage_email_queue
WHERE lot_id = 15
ORDER BY id
LIMIT 10;
```

### Passo 3: Verificar dados dos leads

```sql
SELECT
    l.id,
    l.email,
    l.firstname,
    l.lastname,
    l.contractnumber,
    l.cpfcnpj
FROM leads l
INNER JOIN bpmessage_email_queue q ON l.id = q.lead_id
WHERE q.lot_id = 15
LIMIT 5;
```

### Passo 4: Verificar configuração da campanha

```sql
SELECT
    e.id,
    e.name,
    e.type,
    e.properties
FROM campaign_events e
INNER JOIN bpmessage_email_lot lot ON e.campaign_id = lot.campaign_id
WHERE lot.id = 15;
```

---

## 🔧 Possíveis Causas e Soluções

### Causa 1: Template sem subject ou body

**Sintoma:**
- Template do Mautic está vazio
- Subject ou body está em branco

**Verificar:**
```sql
SELECT
    id,
    name,
    subject,
    LENGTH(custom_html) as html_length,
    LENGTH(content) as content_length
FROM emails
WHERE id = 123; -- ID do template usado
```

**Solução:**
1. Editar template no Mautic
2. Preencher subject e body
3. Salvar e reprocessar lote

---

### Causa 2: Lead sem email

**Sintoma:**
- Campo `to` fica vazio porque lead não tem email

**Verificar:**
```sql
SELECT
    l.id,
    l.email,
    q.id as queue_id
FROM leads l
INNER JOIN bpmessage_email_queue q ON l.id = q.lead_id
WHERE q.lot_id = 15
  AND (l.email IS NULL OR l.email = '');
```

**Solução:**
1. Preencher campo email dos leads
2. Ou remover leads sem email da campanha
3. Reprocessar lote

---

### Causa 3: Campo obrigatório vazio em additional_data

**Sintoma:**
- `contract`, `cpfCnpjReceiver` ou `idForeignBookBusiness` estão vazios

**Verificar configuração da campanha:**

```json
{
  "additional_data": {
    "contract": "{contactfield=contractnumber}",
    "cpfCnpjReceiver": "{contactfield=cpfcnpj}"
  }
}
```

**Verificar se leads têm esses campos:**
```sql
SELECT
    l.id,
    l.contractnumber,
    l.cpfcnpj
FROM leads l
INNER JOIN bpmessage_email_queue q ON l.id = q.lead_id
WHERE q.lot_id = 15
  AND (l.contractnumber IS NULL OR l.contractnumber = '')
LIMIT 5;
```

**Solução:**
1. Preencher campos vazios nos leads
2. Ou ajustar configuração para não enviar campos opcionais
3. Reprocessar lote

---

### Causa 4: idForeignBookBusiness não configurado

**Sintoma:**
- API espera `idForeignBookBusiness` mas não foi enviado

**Verificar:**
```sql
SELECT
    id,
    name,
    book_business_foreign_id
FROM bpmessage_email_lot
WHERE id = 15;
```

Se `book_business_foreign_id` estiver `NULL`:

**Solução:**
Atualizar configuração da campanha:
```json
{
  "book_business_foreign_id": "12345"
}
```

Ou atualizar o lote manualmente:
```sql
UPDATE bpmessage_email_lot
SET book_business_foreign_id = '12345'
WHERE id = 15;
```

---

## 🧪 Debug Avançado

### Habilitar logs debug

Adicione log temporário para ver o payload completo:

**Arquivo:** `Service/EmailLotManager.php`

```php
// Linha ~200 (antes de $client->CreateEmailLot)
$this->logger->debug('BpMessage Email: Full payload', [
    'lot_id' => $lot->getId(),
    'payload' => json_encode($payload, JSON_PRETTY_PRINT),
]);
```

Depois execute:
```bash
php bin/console mautic:bpmessage:process --lot-id 15 -vvv
```

### Testar payload manualmente

1. Copie o payload dos logs
2. Teste com curl:

```bash
curl -X POST "https://api.bpmessage.com.br/CreateEmailLot" \
  -H "Content-Type: application/json" \
  -H "bp-cpfcnpj: 12345678900" \
  -H "bp-password: senha" \
  -d '{
    "lotData": {
      "name": "Teste",
      "startDate": "2025-11-22T10:00:00.000Z",
      "endDate": "2025-11-22T18:00:00.000Z",
      "user": "teste",
      "crmId": "123",
      "bookBusinessForeignId": "456"
    },
    "messages": [
      {
        "from": "noreply@empresa.com",
        "to": "teste@email.com",
        "subject": "Teste",
        "body": "<html><body>Teste</body></html>",
        "contract": "789",
        "cpfCnpjReceiver": "12345678900"
      }
    ]
  }'
```

---

## 📋 Checklist de Validação

Antes de enviar email via BpMessage, garanta:

### Template do Mautic
- [ ] Template tem `subject` preenchido
- [ ] Template tem `body` (custom_html ou content) preenchido
- [ ] Tokens `{contactfield=*}` são válidos

### Leads
- [ ] Todos os leads têm `email` preenchido
- [ ] Campo `contractnumber` está preenchido (se obrigatório)
- [ ] Campo `cpfcnpj` está preenchido (se obrigatório)

### Configuração da Campanha
- [ ] `email_template` selecionado
- [ ] `book_business_foreign_id` configurado
- [ ] `additional_data` com campos corretos:
  - `contract` → `{contactfield=contractnumber}`
  - `cpfCnpjReceiver` → `{contactfield=cpfcnpj}`

### Lote
- [ ] `crm_id` configurado
- [ ] `book_business_foreign_id` configurado
- [ ] `startDate` e `endDate` válidos

---

## 🚀 Solução Rápida

### Se você não sabe qual campo está faltando:

1. **Comparar com lote que funcionou (#14):**

```sql
-- Ver configuração do lote 14 (funcionou)
SELECT
    id,
    name,
    crm_id,
    book_business_foreign_id,
    created_at
FROM bpmessage_email_lot
WHERE id = 14;

-- Comparar com lote 15 (falhou)
SELECT
    id,
    name,
    crm_id,
    book_business_foreign_id,
    created_at
FROM bpmessage_email_lot
WHERE id = 15;
```

2. **Copiar configuração do lote que funcionou:**

```sql
UPDATE bpmessage_email_lot l15
SET
    crm_id = (SELECT crm_id FROM bpmessage_email_lot WHERE id = 14),
    book_business_foreign_id = (SELECT book_business_foreign_id FROM bpmessage_email_lot WHERE id = 14)
WHERE l15.id = 15;
```

3. **Reprocessar:**

```bash
php bin/console mautic:bpmessage:process --lot-id 15
```

---

## 📝 Campos Específicos da API BpMessage Email

### Obrigatórios no lotData:
- `name` ✅
- `startDate` ✅
- `endDate` ✅
- `user` ✅
- `crmId` ⚠️ **Pode estar faltando**
- `bookBusinessForeignId` ⚠️ **Pode estar faltando**

### Obrigatórios em cada message:
- `from` ✅
- `to` ✅
- `subject` ✅
- `body` ✅
- `contract` ⚠️ **Pode estar vazio**
- `cpfCnpjReceiver` ⚠️ **Pode estar vazio**

### Opcionais:
- `cc`
- `zipCode`
- `stepForeignId`
- `isRadarLot`
- `variables`

---

## 🔍 Próximos Passos

1. **Deploy da correção da query** (urgente):
```bash
git add plugins/MauticBpMessageBundle/Command/ProcessBpMessageQueuesCommand.php
git commit -m "fix: Use IDENTITY(q.lead) in failed messages query"
git push
```

2. **Diagnosticar lote #15:**
```bash
# Ver logs
kubectl logs mautic-web-xxx -n marketing | grep "lot.*15"

# Ver dados do lote
kubectl exec -it mautic-web-xxx -n marketing -- mysql -e "
SELECT * FROM bpmessage_email_lot WHERE id = 15\G
" mautic_prod
```

3. **Comparar com lote #14 (que funcionou)**

4. **Corrigir campo faltante e reprocessar**

---

## ⚠️ Nota Importante

O erro **HTTP 500** não é do plugin, mas da **API BpMessage**. Isso significa que:
- ✅ Plugin está enviando requisição corretamente
- ❌ API está rejeitando por falta de algum campo
- 🔍 Precisa identificar qual campo está null/vazio

Após fazer o deploy da correção da query, você poderá ver **exatamente quais leads falharam** e investigar os dados deles.
