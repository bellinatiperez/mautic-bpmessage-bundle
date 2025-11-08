# Exemplos de Uso - MauticBpMessageBundle

## 📱 Exemplos de Mensagens

### Exemplo 1: SMS Simples

**Configuração:**
```yaml
Service Type: SMS
Message Text: |
  Olá {contactfield=firstname},

  Seu código de verificação é: 123456
```

**Resultado:**
```
Olá João,

Seu código de verificação é: 123456
```

---

### Exemplo 2: WhatsApp com Dados do Contrato

**Configuração:**
```yaml
Service Type: WhatsApp
Contract Field: contract_number
CPF Field: cpf
Phone Field: mobile

Message Text: |
  Olá {contactfield=firstname} {contactfield=lastname},

  Seu contrato {contactfield=contract_number} foi renovado!

  Valor: R$ {contactfield=contract_value}
  Vencimento: {contactfield=due_date}

  Em caso de dúvidas, responda esta mensagem.
```

**Resultado:**
```
Olá João Silva,

Seu contrato CONT-001 foi renovado!

Valor: R$ 1.500,00
Vencimento: 15/03/2025

Em caso de dúvidas, responda esta mensagem.
```

---

### Exemplo 3: Cobrança com Boleto

**Configuração:**
```yaml
Service Type: WhatsApp
Attach URL Field: boleto_url
Attach Name: Boleto {contactfield=contract_number}

Message Text: |
  {contactfield=firstname}, seu boleto está disponível!

  Contrato: {contactfield=contract_number}
  Valor: R$ {contactfield=amount}
  Vencimento: {contactfield=due_date}

  Clique no link abaixo para baixar:
```

---

### Exemplo 4: Notificação de Atraso

**Configuração:**
```yaml
Service Type: WhatsApp

Message Text: |
  Prezado(a) {contactfield=firstname},

  Identificamos um atraso no pagamento do seu contrato.

  Dados:
  - Contrato: {contactfield=contract_number}
  - Vencimento: {contactfield=due_date}
  - Valor: R$ {contactfield=amount}
  - Dias em atraso: {contactfield=days_overdue}

  Regularize sua situação o quanto antes para evitar juros.
```

---

### Exemplo 5: RCS com Template

**Configuração:**
```yaml
Service Type: RCS
Template ID: TPL-12345

RCS Variables:
  - Key: Nome
    Value: {contactfield=firstname}

  - Key: Contrato
    Value: {contactfield=contract_number}

  - Key: Valor
    Value: {contactfield=amount}
```

---

## 🎯 Cenários de Uso

### Cenário 1: Campanha de Renovação de Contratos

**Objetivo:** Notificar clientes 30 dias antes do vencimento

**Segmento:**
- Contrato vence em 30 dias
- Status = Ativo

**Configuração da Ação:**
```yaml
Batch Size: 500
Time Window: 300 (5 minutos)
Service Type: WhatsApp

Message:
  Olá {contactfield=firstname}!

  Seu contrato {contactfield=contract_number} vence em 30 dias.

  Para renovar, acesse nosso portal ou entre em contato.
```

**Resultado Esperado:**
- 500 mensagens agrupadas por lote
- Enviadas a cada 5 minutos
- Taxa de entrega: ~98%

---

### Cenário 2: Cobrança Automática

**Objetivo:** Enviar boleto no dia do vencimento

**Segmento:**
- Vencimento = Hoje
- Boleto não pago

**Configuração:**
```yaml
Batch Size: 1000
Time Window: 600 (10 minutos)
Service Type: WhatsApp
Attach URL Field: boleto_url

Message:
  {contactfield=firstname}, seu boleto vence hoje!

  Valor: R$ {contactfield=amount}

  Clique para pagar:
  {contactfield=boleto_url}
```

**Cron:**
```bash
# Executar às 9h da manhã
0 9 * * * php /path/to/mautic/bin/console mautic:bpmessage:process
```

---

### Cenário 3: Campanha de Marketing em Massa

**Objetivo:** Enviar oferta para base toda

**Segmento:**
- Todos os contatos ativos
- Aceitou marketing = Sim

**Configuração:**
```yaml
Batch Size: 5000  # Máximo
Time Window: 300
Service Type: WhatsApp

Message:
  🎉 {contactfield=firstname}, OFERTA ESPECIAL!

  50% de desconto na renovação!

  Válido até 31/12/2025.

  Responda SIM para mais informações.
```

**Estratégia:**
- Base: 50.000 contatos
- Lotes de 5000 mensagens
- 10 lotes no total
- Tempo estimado: 50 minutos

---

### Cenário 4: Recuperação de Inadimplentes

**Objetivo:** Série de mensagens progressivas

**Fluxo:**
1. **Dia do vencimento**: Lembrete amigável
2. **+3 dias**: Aviso de atraso
3. **+7 dias**: Notificação de juros
4. **+15 dias**: Última notificação

**Campanha (Decisão por dias de atraso):**

```
[Decisão: dias_overdue = 0]
    └─→ [Ação: Enviar lembrete amigável]

[Decisão: dias_overdue = 3]
    └─→ [Ação: Enviar aviso de atraso]

[Decisão: dias_overdue = 7]
    └─→ [Ação: Enviar notificação de juros]

[Decisão: dias_overdue = 15]
    └─→ [Ação: Última notificação]
```

**Mensagens:**

**Dia 0:**
```
Olá {contactfield=firstname},

Seu boleto vence hoje!

Evite juros, pague agora.
```

**Dia +3:**
```
{contactfield=firstname}, detectamos um atraso.

Contrato: {contactfield=contract_number}
Valor: R$ {contactfield=amount}
Atraso: 3 dias

Regularize o quanto antes.
```

**Dia +7:**
```
ATENÇÃO {contactfield=firstname}!

Seu contrato está com 7 dias de atraso.

Juros aplicados: R$ {contactfield=late_fee}

Pague agora para evitar mais encargos.
```

**Dia +15:**
```
ÚLTIMA NOTIFICAÇÃO

{contactfield=firstname}, seu contrato será suspenso em 48h.

Pague agora: {contactfield=payment_link}
```

---

## 📊 Monitoramento

### Queries SQL Úteis

**Ver status dos lotes em tempo real:**
```sql
SELECT
    status,
    COUNT(*) as total_lotes,
    SUM(messages_count) as total_mensagens,
    AVG(messages_count) as media_por_lote
FROM bpmessage_lot
GROUP BY status;
```

**Últimos lotes criados:**
```sql
SELECT
    id,
    name,
    status,
    messages_count,
    created_at,
    finished_at
FROM bpmessage_lot
ORDER BY created_at DESC
LIMIT 10;
```

**Mensagens pendentes:**
```sql
SELECT
    l.name as lote,
    l.messages_count,
    COUNT(q.id) as pendentes,
    l.created_at
FROM bpmessage_lot l
LEFT JOIN bpmessage_queue q ON q.lot_id = l.id AND q.status = 'PENDING'
WHERE l.status = 'OPEN'
GROUP BY l.id;
```

**Taxa de sucesso:**
```sql
SELECT
    l.campaign_id,
    COUNT(CASE WHEN q.status = 'SENT' THEN 1 END) as enviadas,
    COUNT(CASE WHEN q.status = 'FAILED' THEN 1 END) as falhas,
    ROUND(
        COUNT(CASE WHEN q.status = 'SENT' THEN 1 END) * 100.0 / COUNT(*),
        2
    ) as taxa_sucesso
FROM bpmessage_lot l
JOIN bpmessage_queue q ON q.lot_id = l.id
WHERE l.campaign_id = 123
GROUP BY l.campaign_id;
```

---

## 🧪 Testes

### Teste 1: Envio Individual

```bash
# 1. Criar campanha de teste
# 2. Adicionar apenas 1 contato
# 3. Executar comando manualmente
php bin/console mautic:bpmessage:process --lot-id=1 -vvv

# 4. Verificar logs
tail -f var/logs/mautic_prod.log | grep BpMessage
```

### Teste 2: Envio em Lote Pequeno

```bash
# 1. Criar segmento com 10 contatos
# 2. Criar campanha
# 3. Aguardar 5 minutos ou forçar
php bin/console mautic:bpmessage:process --force-close

# 4. Verificar resultado
mysql -u root -p -e "SELECT status, COUNT(*) FROM bpmessage_queue WHERE lot_id = 1 GROUP BY status;"
```

### Teste 3: Envio em Massa

```bash
# 1. Criar segmento com 6000 contatos
# 2. Configurar batch_size = 5000
# 3. Processar
php bin/console mautic:bpmessage:process -vvv

# 4. Deve criar 2 batches:
#    - Batch 1: 5000 mensagens
#    - Batch 2: 1000 mensagens
```

---

## 🔍 Debug

### Ativar Logs Detalhados

Adicione em `app/config/config_dev.php`:

```php
$container->loadFromExtension('monolog', [
    'handlers' => [
        'bpmessage' => [
            'type' => 'stream',
            'path' => '%kernel.logs_dir%/bpmessage.log',
            'level' => 'debug',
        ],
    ],
]);
```

### Ver Payload de uma Mensagem

```sql
SELECT
    l.id,
    l.name,
    q.payload_json,
    q.status,
    q.error_message
FROM bpmessage_queue q
JOIN bpmessage_lot l ON l.id = q.lot_id
WHERE q.lead_id = 123;
```

### Forçar Reprocessamento

```sql
-- Resetar mensagens com falha para PENDING
UPDATE bpmessage_queue
SET status = 'PENDING', retry_count = 0
WHERE lot_id = 123 AND status = 'FAILED';

-- Reabrir lote
UPDATE bpmessage_lot
SET status = 'OPEN'
WHERE id = 123;

-- Processar
php bin/console mautic:bpmessage:process --lot-id=123
```

---

## 📈 Otimizações

### Para Grandes Volumes (>100k mensagens/dia)

1. **Aumentar batch_size:**
```yaml
batch_size: 5000  # Máximo suportado pela API
```

2. **Reduzir time_window:**
```yaml
time_window: 60  # Fecha lote após 1 minuto
```

3. **Executar cron mais frequentemente:**
```bash
# A cada 1 minuto
* * * * * php /path/to/mautic/bin/console mautic:bpmessage:process
```

4. **Usar múltiplas campanhas paralelas:**
- Campanha 1: Segmento A
- Campanha 2: Segmento B
- Campanha 3: Segmento C

### Para Alta Confiabilidade

1. **Ativar retry automático:**
```bash
# Adicionar ao cron
*/10 * * * * php /path/to/mautic/bin/console mautic:bpmessage:process --retry
```

2. **Monitorar lotes travados:**
```sql
-- Lotes em OPEN há mais de 1 hora
SELECT * FROM bpmessage_lot
WHERE status = 'OPEN'
AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

3. **Alertas:**
```bash
# Script de monitoramento (executar via cron)
#!/bin/bash
STUCK=$(mysql -u user -p -D mautic -N -e "SELECT COUNT(*) FROM bpmessage_lot WHERE status = 'OPEN' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)")

if [ "$STUCK" -gt 0 ]; then
    echo "ALERTA: $STUCK lotes travados!" | mail -s "BpMessage Alert" admin@empresa.com
fi
```

---

## 🎓 Boas Práticas

1. ✅ **Sempre teste com poucos contatos primeiro**
2. ✅ **Monitore os logs nas primeiras execuções**
3. ✅ **Configure alertas para falhas**
4. ✅ **Faça backup antes de grandes envios**
5. ✅ **Documente suas configurações de campanha**
6. ✅ **Use nomes descritivos para os lotes**
7. ✅ **Revise os tokens antes de publicar**
8. ✅ **Teste diferentes horários de envio**
9. ✅ **Analise taxas de entrega e resposta**
10. ✅ **Mantenha a base de contatos limpa**
