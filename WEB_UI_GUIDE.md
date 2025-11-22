# Guia da Interface Web - BpMessage Plugin

## Como Visualizar Erros de Lotes na Interface Web

### 1. Acessar a Lista de Lotes

Navegue até a página de lotes do BpMessage:

```
URL: /s/bpmessage/lots
```

**No Mautic:**
- Clique no menu lateral (se houver entrada para BpMessage)
- Ou acesse diretamente: `https://seu-mautic.com/s/bpmessage/lots`

### 2. Visualização na Lista de Lotes

Na tela de listagem, você verá todos os lotes com as seguintes informações:

| Coluna | Descrição |
|--------|-----------|
| **ID** | Número identificador do lote |
| **Nome** | Nome do lote criado pela campanha |
| **External ID** | ID retornado pela API do BpMessage |
| **Campanha** | Campanha que criou o lote |
| **Status** | Status atual do lote |
| **Mensagens** | Contadores: Total / Pending / Sent / Failed |
| **Data** | Data de criação |
| **Ações** | Botões de visualizar e reprocessar |

#### Status Possíveis:

- 🔵 **CREATING** (Azul) - Lote sendo criado na API
- 🟠 **OPEN** (Laranja) - Lote aberto, aguardando envio
- 🟢 **FINISHED** (Verde) - Lote finalizado com sucesso
- 🔴 **FAILED** (Vermelho) - Lote falhou durante envio

#### Ícone de Erro:

Quando um lote tem erro, você verá um **ícone de alerta vermelho** (⚠️) ao lado do status:

```
Status: [FAILED] ⚠️
```

**Ao passar o mouse sobre o ícone**, um tooltip mostra a mensagem de erro completa:

```
Batch 0 failed: HTTP 400: {"messages":["'Area Code' must not be empty."]}
```

### 3. Visualizar Detalhes do Lote com Erro

Clique no botão **"Ver"** (ícone de olho 👁️) ou no ID do lote para abrir a página de detalhes.

Na página de detalhes, você verá:

#### A. Informações do Lote

- ID, Nome, External ID
- Status (com badge colorido)
- Campanha associada
- CPF do usuário
- URL da API
- Tamanho do lote e janela de tempo
- Datas de criação, início e fim

#### B. **Alert de Erro** (se houver erro)

Um alerta vermelho grande será exibido:

```
┌─────────────────────────────────────────────────────┐
│ ⚠️ Error                                             │
│                                                      │
│ Batch 0 failed: HTTP 400:                           │
│ {"messages":[                                        │
│   "'Area Code' must not be empty.",                 │
│   "'Contract' must not be empty.",                  │
│   "'CPF/CNPJ Receiver' must not be empty."          │
│ ]}                                                   │
└─────────────────────────────────────────────────────┘
```

#### C. Estatísticas

Contadores visuais mostrando:

```
┌─────────┬─────────┬─────────┬─────────┐
│  Total  │ Pending │   Sent  │ Failed  │
│    5    │    0    │    0    │    5    │
└─────────┴─────────┴─────────┴─────────┘
```

#### D. Lista de Mensagens

Tabela com todas as mensagens do lote, mostrando:

- ID da mensagem
- Contato (link para ver o lead)
- Email do contato
- Status da mensagem (PENDING, SENT, FAILED)
- Número de tentativas (retry count)
- Data de criação

**Mensagens com erro** mostram:
- Badge vermelho **FAILED**
- Ícone de informação (ℹ️) com tooltip mostrando o erro específico

### 4. Reprocessar Lote com Erro

Se o lote tem status **FAILED** ou **FINISHED** com mensagens falhadas, você verá o botão:

```
[🔄 Reprocess]
```

**Ao clicar:**
1. Todas as mensagens FAILED são resetadas para PENDING
2. O lote volta para status OPEN
3. O erro é limpo
4. O lote será processado novamente pelo cron

### 5. Exemplo Prático

#### Cenário: Lote falhou por campos vazios

**1. Na lista de lotes:**
```
ID: #8
Nome: Envio RCS - Bradesco
Status: [FAILED] ⚠️ (ao passar mouse: "Batch 0 failed: HTTP 400...")
Mensagens: 5 total | 0 pending | 0 sent | 5 failed
```

**2. Ao clicar para ver detalhes:**

```
╔══════════════════════════════════════════════════════╗
║ Lot Information                                      ║
╠══════════════════════════════════════════════════════╣
║ ID: #8                                               ║
║ Name: Envio RCS - Bradesco                           ║
║ Status: [FAILED]                                     ║
║ Campaign: #2                                         ║
╚══════════════════════════════════════════════════════╝

╔══════════════════════════════════════════════════════╗
║ ⚠️ Error                                             ║
╠══════════════════════════════════════════════════════╣
║ Batch 0 failed: HTTP 400:                           ║
║ {"messages":[                                        ║
║   "'Area Code' must not be empty.",                 ║
║   "'Contract' must not be empty."                   ║
║ ]}                                                   ║
╚══════════════════════════════════════════════════════╝

Statistics:
  Total: 5  |  Pending: 0  |  Sent: 0  |  Failed: 5

Messages:
┌────┬─────────────┬───────────────────────┬────────┬────────┐
│ ID │ Lead        │ Email                 │ Status │ Retry  │
├────┼─────────────┼───────────────────────┼────────┼────────┤
│ 10 │ João Silva  │ joao@example.com      │FAILED ℹ│   1    │
│ 11 │ Maria Costa │ maria@example.com     │FAILED ℹ│   1    │
│ 12 │ Pedro Souza │ pedro@example.com     │FAILED ℹ│   0    │
└────┴─────────────┴───────────────────────┴────────┴────────┘
```

**3. Corrigir o problema:**

Identificado que os campos estão vazios, você:

1. Atualiza os contatos com os dados corretos:
   ```sql
   UPDATE leads SET dddmobile = '48', contractnumber = '12345' WHERE id IN (1,2,3);
   ```

2. Atualiza o payload na fila (ou dispara a campanha novamente)

3. Clica no botão **[🔄 Reprocess]** na interface web

4. O lote é reprocessado automaticamente pelo cron

**4. Após correção:**

O lote agora mostra:
```
Status: [FINISHED] ✅
Messages: 5 total | 0 pending | 5 sent | 0 failed
```

## Fluxo Completo de Correção via Interface Web

```
1. Acessar Lista de Lotes
   ↓
2. Identificar lote com erro (Status FAILED + ícone ⚠️)
   ↓
3. Clicar para ver detalhes
   ↓
4. Ler mensagem de erro no alert vermelho
   ↓
5. Identificar campos/dados problemáticos
   ↓
6. Corrigir dados dos contatos no banco
   ↓
7. Atualizar payload na fila (se necessário)
   ↓
8. Clicar no botão [Reprocess]
   ↓
9. Aguardar cron processar (ou processar manualmente)
   ↓
10. Verificar status mudou para FINISHED ✅
```

## URLs de Acesso

### Desenvolvimento Local (DDEV)

```
Lista de Lotes:
https://mautic.ddev.site/s/bpmessage/lots

Ver Lote Específico:
https://mautic.ddev.site/s/bpmessage/lots/view/8

Reprocessar Lote:
https://mautic.ddev.site/s/bpmessage/lots/reprocess/8
```

### Produção K3s

```
Lista de Lotes:
https://seu-dominio.com.br/s/bpmessage/lots

Ver Lote Específico:
https://seu-dominio.com.br/s/bpmessage/lots/view/8
```

## Verificando Lotes com Erro

### Via Interface Web

1. Acesse `/s/bpmessage/lots`
2. Procure por badges vermelhos **[FAILED]**
3. Procure por ícones de alerta ⚠️
4. Observe contadores de "Failed" nas mensagens

### Via Comando CLI

```bash
# Listar todos os lotes com erro
php bin/console mautic:bpmessage:list-failed-lots

# Ver detalhes completos
php bin/console mautic:bpmessage:list-failed-lots -v

# K3s
kubectl exec -n marketing deployment/mautic-web -c mautic -- \
  php bin/console mautic:bpmessage:list-failed-lots -v
```

### Via Banco de Dados

```sql
-- Lotes com erro
SELECT id, name, status, error_message
FROM bpmessage_lot
WHERE status IN ('FAILED', 'FAILED_CREATION')
   OR error_message IS NOT NULL
ORDER BY created_at DESC;
```

## Testando a Interface

Para criar um lote de teste com erro (ambiente de desenvolvimento):

```sql
INSERT INTO bpmessage_lot (
    name, status, service_type, messages_count,
    created_at, campaign_id, api_base_url,
    batch_size, time_window, user_cpf,
    error_message
) VALUES (
    'Teste - Lote com Erro',
    'FAILED',
    3,
    5,
    NOW(),
    2,
    'https://hmlbpmessage.bellinatiperez.com.br',
    1000,
    300,
    '12345678900',
    'Batch 0 failed: HTTP 400: {"messages":["Area Code must not be empty"]}'
);
```

Depois acesse `/s/bpmessage/lots` para ver o lote com erro.

## Capturas de Tela Esperadas

### 1. Lista de Lotes
```
┌───────────────────────────────────────────────────────────────────────────┐
│ BpMessage Lots                                        [🔄 Process Now]     │
├────┬──────────────────┬────────┬──────────┬────────────┬───────────────────┤
│ ID │ Name             │Ext. ID │ Campaign │ Status     │ Messages  │ Date  │
├────┼──────────────────┼────────┼──────────┼────────────┼───────────┼───────┤
│ #8 │ Teste - Erro     │ 98211  │ Camp #2  │[FAILED] ⚠️ │ 0/0/5    │11/21  │
│ #7 │ Envio WhatsApp   │ 98210  │ Camp #2  │[FINISHED]  │ 0/10/0   │11/21  │
│ #6 │ Envio RCS        │ 98209  │ Camp #2  │[FINISHED]  │ 0/1/0    │11/21  │
└────┴──────────────────┴────────┴──────────┴────────────┴───────────┴───────┘
```

### 2. Detalhes do Lote com Erro
```
┌─────────────────────────────────────────────────────────────┐
│ Teste - Lote com Erro de Validação        [← Back] [🔄]    │
├─────────────────────────────────────────────────────────────┤
│ Lot Information                                             │
│   ID: #8                                                    │
│   Status: [FAILED]                                          │
│                                                              │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ ⚠️ Error                                                 │ │
│ │ Batch 0 failed: HTTP 400: {...}                         │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                              │
│ Statistics: Total: 5 | Pending: 0 | Sent: 0 | Failed: 5    │
└─────────────────────────────────────────────────────────────┘
```

## Recursos da Interface

✅ **Badge de Status Colorido** - Identificação visual rápida do status

✅ **Ícone de Alerta com Tooltip** - Prévia do erro ao passar mouse

✅ **Alert Vermelho Destacado** - Erro completo na página de detalhes

✅ **Contadores Visuais** - Estatísticas de pending/sent/failed

✅ **Erros por Mensagem** - Tooltip mostrando erro de cada contato

✅ **Botão de Reprocessar** - Retry direto pela interface

✅ **Paginação** - Para lotes com muitas mensagens

✅ **Links para Contatos** - Navegação direta para editar leads

## Benefícios da Interface Web

🎯 **Visualização Rápida** - Ver todos os lotes com erro em uma tela

🎯 **Detalhes Completos** - Mensagem de erro formatada e legível

🎯 **Ação Direta** - Reprocessar lote com um clique

🎯 **Sem Terminal** - Não precisa de acesso SSH ou comandos CLI

🎯 **Acessível** - Qualquer usuário do Mautic pode visualizar

🎯 **Histórico** - Ver todos os erros passados e atuais

## Suporte

Para mais informações:

- **Documentação de Erros:** `ERROR_TRACKING.md`
- **Troubleshooting:** `TROUBLESHOOT_LOT_ERROR.md`
- **Comandos CLI:** `php bin/console mautic:bpmessage:list-failed-lots --help`
