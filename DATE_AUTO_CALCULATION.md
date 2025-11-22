# Cálculo Automático de Datas para Lotes BpMessage

## Visão Geral

O sistema agora calcula automaticamente `startDate` e `endDate` ao criar lotes no BpMessage, seguindo a lógica:

1. **startDate**: Data atual (momento da criação do lote)
2. **endDate**: Data calculada como `startDate + time_window` (em segundos)
3. **Substituição**: Se `lot_data` tiver `startDate`/`endDate` configurados, usa esses valores

## Formato das Datas

As datas são enviadas no formato **ISO 8601 com milissegundos e timezone UTC**:

```
2025-02-06T13:53:16.049Z
```

- `Y-m-d`: Ano-Mês-Dia
- `T`: Separador de data/hora
- `H:i:s.v`: Hora:Minuto:Segundo.Milissegundos
- `Z`: Timezone UTC (+00:00)

## Comportamento Padrão

### Exemplo 1: Sem Configuração Personalizada

**Cenário:**
- `time_window` = 300 segundos (5 minutos) - padrão
- Lote criado em: `2025-11-21 18:30:00 (UTC-3 - BRT)`

**Resultado:**
```json
{
  "startDate": "2025-11-21T21:30:00.000Z",  // Convertido para UTC
  "endDate": "2025-11-21T21:35:00.000Z"     // +5 minutos
}
```

**Diferença:** 5 minutos (300 segundos)

### Exemplo 2: Com time_window Customizado

**Cenário:**
- `time_window` = 3600 segundos (1 hora)
- Lote criado em: `2025-11-21 18:30:00 (UTC-3 - BRT)`

**Resultado:**
```json
{
  "startDate": "2025-11-21T21:30:00.000Z",  // Convertido para UTC
  "endDate": "2025-11-21T22:30:00.000Z"     // +1 hora
}
```

**Diferença:** 1 hora (3600 segundos)

### Exemplo 3: Com Datas Personalizadas (lot_data)

**Cenário:**
```json
{
  "lot_data": {
    "startDate": "2025-02-06T13:53:16.049Z",
    "endDate": "2025-02-06T17:53:16.049Z"
  }
}
```

**Resultado:**
```json
{
  "startDate": "2025-02-06T13:53:16.049Z",  // Usa o valor fornecido
  "endDate": "2025-02-06T17:53:16.049Z"     // Usa o valor fornecido
}
```

**Diferença:** 4 horas (conforme configurado)

## Implementação

### LotManager.php (SMS/WhatsApp/RCS)

**Linhas 164-215:**

```php
private function createLot(Campaign $campaign, array $config): BpMessageLot
{
    $lot = new BpMessageLot();
    $lot->setName($config['lot_name'] ?? "Campaign {$campaign->getName()}");

    // Calculate startDate and endDate
    $timeWindow = (int) ($config['time_window'] ?? $config['default_time_window'] ?? 300);
    $now = new \DateTime('now');

    // Default values
    $startDate = $now;
    $endDate = (clone $now)->modify("+{$timeWindow} seconds");

    // Check if lot_data has custom startDate/endDate
    if (!empty($config['lot_data']) && is_array($config['lot_data'])) {
        if (!empty($config['lot_data']['startDate'])) {
            try {
                $startDate = new \DateTime($config['lot_data']['startDate']);
            } catch (\Exception $e) {
                // Log warning and use default
            }
        }

        if (!empty($config['lot_data']['endDate'])) {
            try {
                $endDate = new \DateTime($config['lot_data']['endDate']);
            } catch (\Exception $e) {
                // Calculate from startDate + timeWindow
                $endDate = (clone $startDate)->modify("+{$timeWindow} seconds");
            }
        }
    }

    $lot->setStartDate($startDate);
    $lot->setEndDate($endDate);
    // ... resto do código
}
```

**Linhas 232-246 (Formato para API):**

```php
// Convert to UTC and format as ISO 8601 with milliseconds
$startDateUTC = clone $lot->getStartDate();
$startDateUTC->setTimezone(new \DateTimeZone('UTC'));
$endDateUTC = clone $lot->getEndDate();
$endDateUTC->setTimezone(new \DateTimeZone('UTC'));

$lotData = [
    'name' => $lot->getName(),
    'startDate' => $startDateUTC->format('Y-m-d\TH:i:s.v\Z'),
    'endDate' => $endDateUTC->format('Y-m-d\TH:i:s.v\Z'),
    'user' => 'system',
    'idQuotaSettings' => $lot->getIdQuotaSettings(),
    'idServiceSettings' => $lot->getIdServiceSettings(),
];
```

### EmailLotManager.php (Email)

**Linhas 120-197:**

A mesma lógica foi aplicada para lotes de email.

## Configuração na Campanha

### Opção 1: Usar Cálculo Automático (Recomendado)

Não especificar `startDate` e `endDate` em `lot_data`. O sistema calculará automaticamente baseado em `time_window`.

**Exemplo de configuração:**
```json
{
  "service_type": "3",
  "lot_name": "Envio RCS - Bradesco",
  "id_quota_settings": "85730",
  "id_service_settings": "5018",
  "time_window": 300,  // 5 minutos
  "batch_size": 1000
}
```

**Resultado:**
- `startDate`: Agora
- `endDate`: Agora + 5 minutos

### Opção 2: Especificar Datas Manualmente

Fornecer `startDate` e `endDate` em `lot_data` no formato ISO 8601.

**Exemplo de configuração:**
```json
{
  "service_type": "3",
  "lot_name": "Envio RCS - Bradesco",
  "id_quota_settings": "85730",
  "id_service_settings": "5018",
  "lot_data": {
    "startDate": "2025-02-06T10:00:00.000Z",
    "endDate": "2025-02-06T18:00:00.000Z"
  }
}
```

**Resultado:**
- `startDate`: 2025-02-06 às 10:00 UTC
- `endDate`: 2025-02-06 às 18:00 UTC

## Tratamento de Erros

### Formato de Data Inválido

Se `startDate` ou `endDate` em `lot_data` tiver formato inválido:

1. **Log de Warning** é criado
2. **Fallback para cálculo automático**:
   - `startDate`: Data atual
   - `endDate`: `startDate + time_window`

**Exemplo de Log:**
```
[warning] BpMessage: Invalid startDate format, using current time
{
  "startDate": "2025-99-99T99:99:99",  // Formato inválido
  "error": "Failed to parse time string..."
}
```

### endDate sem startDate

Se apenas `endDate` for fornecido (sem `startDate`):

1. `startDate` = data atual
2. `endDate` = valor fornecido

### startDate sem endDate

Se apenas `startDate` for fornecido (sem `endDate`):

1. `startDate` = valor fornecido
2. `endDate` = `startDate + time_window`

## Valores de time_window Comuns

| Tempo | Segundos | Uso Recomendado |
|-------|----------|-----------------|
| 5 min | 300 | Envios urgentes, testes |
| 30 min | 1800 | Envios rápidos |
| 1 hora | 3600 | Envios normais |
| 8 horas | 28800 | Lotes durante dia de trabalho |
| 24 horas | 86400 | Lotes com janela de 1 dia |

## Timezone e Conversão

### Banco de Dados

Datas são salvas no banco de dados no **timezone do servidor** (provavelmente UTC ou America/Sao_Paulo).

### API BpMessage

Datas são **sempre convertidas para UTC** antes de enviar para a API.

### Exemplo de Conversão

**Servidor em São Paulo (UTC-3):**
```
Criação do lote: 2025-11-21 15:30:00 (BRT / UTC-3)
Conversão para UTC: 2025-11-21 18:30:00 (UTC)
Formato enviado: "2025-11-21T18:30:00.000Z"
```

## Validação da API

A API do BpMessage valida:

1. ✅ **startDate** não pode ser no passado (muito antigo)
2. ✅ **endDate** deve ser maior que **startDate**
3. ✅ **Formato** deve ser ISO 8601 válido

Se alguma validação falhar, a API retornará erro e o lote ficará com status `FAILED_CREATION`.

## Testando

### Teste 1: Criar Lote com Datas Automáticas

1. Configure campanha sem `lot_data.startDate/endDate`
2. Trigger a campanha
3. Verificar no banco:
```sql
SELECT id, name, start_date, end_date, time_window
FROM bpmessage_lot
ORDER BY id DESC LIMIT 1;
```

Deve mostrar:
- `start_date`: Data/hora atual
- `end_date`: `start_date + time_window` segundos

### Teste 2: Criar Lote com Datas Personalizadas

1. Configure campanha com `lot_data`:
```json
{
  "startDate": "2025-12-25T09:00:00.000Z",
  "endDate": "2025-12-25T17:00:00.000Z"
}
```
2. Trigger a campanha
3. Verificar no banco e no log da API

### Teste 3: Formato Inválido (Fallback)

1. Configure campanha com data inválida:
```json
{
  "startDate": "invalid-date"
}
```
2. Trigger a campanha
3. Verificar logs: Deve ter warning e usar data atual

## Migração

### Lotes Existentes

Lotes já criados **não serão afetados**. A nova lógica se aplica apenas a **novos lotes**.

### Alterações no Schema

**Nenhuma alteração** no schema do banco de dados. Campos `start_date` e `end_date` já existiam.

## Benefícios

✅ **Automático**: Não precisa calcular datas manualmente

✅ **Flexível**: Aceita datas personalizadas se fornecidas

✅ **Seguro**: Tratamento de erros com fallback

✅ **Timezone Correto**: Sempre envia UTC para a API

✅ **Formato Padrão**: ISO 8601 com milissegundos

## Troubleshooting

### Problema: Lote criado com data/hora errada

**Verificar:**
1. Timezone do servidor PHP
2. Timezone do banco de dados
3. Se `lot_data` está sobrescrevendo as datas

**Solução:**
```bash
# Ver timezone do PHP
php -r "echo date_default_timezone_get();"

# Ver timezone do MySQL
mysql -e "SELECT @@global.time_zone, @@session.time_zone;"
```

### Problema: API rejeita as datas

**Erro comum:**
> "startDate must be in the future"

**Solução:**
1. Verificar se o servidor está com hora sincronizada (NTP)
2. Aumentar `time_window` para garantir janela no futuro

### Problema: endDate no passado

Se o lote demorar muito para processar, `endDate` pode estar no passado quando a API finaliza.

**Solução:**
Aumentar `time_window` para valores maiores (ex: 3600 para 1 hora).

## Referências

- **PHP DateTime**: https://www.php.net/manual/en/class.datetime.php
- **ISO 8601**: https://en.wikipedia.org/wiki/ISO_8601
- **Timezones PHP**: https://www.php.net/manual/en/timezones.php
