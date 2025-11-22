# Interface de Exibição de Erros - BpMessage

## Visão Geral

Agora os erros das mensagens falhadas são exibidos de forma destacada na **interface web** do Mautic, facilitando para o usuário identificar e corrigir problemas.

---

## 📍 Localização

**Menu:** Channels > BpMessage Lots > [Clicar em um lote]

**Rota:** `/s/bpmessage/lot/view/{id}`

---

## 🎨 Novo Layout da Página de Detalhes do Lote

### 1. **Painel de Informações** (existente)
- Nome do lote
- Status
- ID externo
- Datas, configurações

### 2. **Painel de Estatísticas** (existente)
- Total de mensagens
- Pendentes
- Enviadas
- **Falhadas** (em vermelho)

### 3. **⭐ NOVO: Painel de Mensagens com Erro** (só aparece se houver falhas)

#### Visual:
```
╔═══════════════════════════════════════════════════════════════╗
║  ⚠️  Mensagens com Erro (5)                                   ║
╠═══════════════════════════════════════════════════════════════╣
║                                                                ║
║  ┌────────────────────────────────────────────────────────┐  ║
║  │ ID    │ Lead              │ Tentativas │ Mensagem Erro │  ║
║  ├────────────────────────────────────────────────────────┤  ║
║  │ #123  │ 👤 João Silva     │     1      │ ❌ HTTP 400:  │  ║
║  │       │ joao@email.com    │            │ 'Contract'    │  ║
║  │       │                   │            │ must not be   │  ║
║  │       │                   │            │ empty.        │  ║
║  │       │                   │            │ [+ Ver comp.] │  ║
║  ├────────────────────────────────────────────────────────┤  ║
║  │ #124  │ 👤 Maria Santos   │     2      │ ❌ HTTP 400:  │  ║
║  │       │ maria@email.com   │            │ Invalid phone │  ║
║  │       │                   │            │ format        │  ║
║  └────────────────────────────────────────────────────────┘  ║
║                                                                ║
║  ℹ️ Como Corrigir                                             ║
║  Corrija os erros nos contatos (verifique campos vazios)     ║
║  e depois reprocesse este lote usando o botão 'Reprocessar'  ║
║  ou execute:                                                  ║
║  php bin/console mautic:bpmessage:process --lot-id=12        ║
║                                                                ║
╚═══════════════════════════════════════════════════════════════╝
```

### 4. **Painel de Todas as Mensagens** (existente, com melhorias)
- Lista completa de todas as mensagens do lote
- Filtros por status
- Tooltip com erro (mantido como estava)

---

## 🎯 Recursos da Nova Seção

### ✅ Características

1. **Visibilidade Alta**
   - Painel vermelho (`.panel-danger`)
   - Aparece **antes** da lista completa de mensagens
   - Só é exibido quando `statistics.failed > 0`

2. **Informações Detalhadas**
   - **ID da mensagem**: `#123` (link para fila)
   - **Lead**: Nome + email (link para perfil do contato)
   - **Tentativas**: Badge vermelho com número de retries
   - **Erro completo**: Primeiros 200 caracteres
   - **Ver completo**: Link para mostrar erro inteiro em popup

3. **Instruções de Correção**
   - Box informativo azul no rodapé
   - Comando exato para reprocessar
   - Link para botão "Reprocessar" (se disponível)

4. **Integração com Lead**
   - Link direto para o perfil do contato
   - Possibilidade de corrigir dados diretamente

---

## 📊 Exemplo de Erro Exibido

### Erro: "Contract must not be empty"

**Na interface, o usuário verá:**

```
╔════════════════════════════════════════════════════════════╗
║  #456  │ 👤 Fulano da Silva                  │  1  │       ║
║        │ fulano@email.com                    │     │       ║
║        │                                      │     │       ║
║        │ ❌ HTTP 400: {"messages":["'Contract' must    ║
║        │ not be empty."]}                              ║
║        │ [+ Ver completo]                              ║
╚════════════════════════════════════════════════════════════╝
```

**Ações do usuário:**

1. Clicar em "👤 Fulano da Silva" → Abre perfil do contato
2. Verificar campo `contractnumber`
3. Se estiver vazio, preencher com o valor correto
4. Salvar contato
5. Voltar para o lote e clicar em "Reprocessar"

---

## 🔧 Fluxo de Correção Completo

### Cenário: Lote #12 com 5 mensagens falhadas

```
1. Acessar Interface Web
   ↓
2. Menu: Channels > BpMessage Lots
   ↓
3. Clicar em "Lote #12"
   ↓
4. Ver seção "⚠️ Mensagens com Erro (5)"
   ↓
5. Para cada mensagem:
   - Clicar no nome do lead
   - Verificar campos obrigatórios (contract, cpf, phone)
   - Corrigir campos vazios ou inválidos
   - Salvar contato
   ↓
6. Voltar para o lote
   ↓
7. Clicar em "Reprocessar" ou executar comando
   ↓
8. Aguardar reprocessamento
   ↓
9. Verificar se erros foram resolvidos
```

---

## 💡 Melhorias Implementadas

### Antes (apenas tooltip)
```
Status: ❌ Falhou (?)  ← tooltip com erro ao passar mouse
```

**Problemas:**
- ❌ Erro escondido em tooltip
- ❌ Difícil de copiar mensagem de erro
- ❌ Não mostra múltiplas falhas de uma vez
- ❌ Usuário precisa passar mouse em cada item

### Depois (seção dedicada)
```
╔════════════════════════════════════════════╗
║  ⚠️  Mensagens com Erro (5)               ║
║  [Tabela completa com todos os erros]     ║
║  ℹ️ Como Corrigir                         ║
╚════════════════════════════════════════════╝
```

**Vantagens:**
- ✅ Todos os erros visíveis imediatamente
- ✅ Mensagens de erro completas e copiáveis
- ✅ Links diretos para editar contatos
- ✅ Instruções de como corrigir
- ✅ Destaque visual forte (vermelho)
- ✅ Contador de falhas no título

---

## 🚀 Como Funciona Tecnicamente

### View (Twig Template)

**Arquivo:** `Resources/views/Batch/view.html.twig`

**Lógica:**
```twig
{% if statistics.failed > 0 %}
    {# Mostra painel vermelho com erros #}
    <div class="panel panel-danger">
        {# Loop por todas as mensagens #}
        {% for message in messages %}
            {% if message.status == 'FAILED' %}
                {# Mostra linha com erro #}
                <tr>
                    <td>#{{ message.id }}</td>
                    <td>
                        <a href="link_para_lead">Nome do Lead</a>
                        <br>
                        <small>email</small>
                    </td>
                    <td>{{ message.retryCount }}</td>
                    <td>
                        <div class="alert alert-danger">
                            {{ message.errorMessage }}
                        </div>
                    </td>
                </tr>
            {% endif %}
        {% endfor %}
    </div>
{% endif %}
```

### Traduções

**Arquivo:** `Translations/pt_BR/messages.ini`

```ini
mautic.bpmessage.lot.failed_messages="Mensagens com Erro"
mautic.bpmessage.error_message="Mensagem de Erro"
mautic.bpmessage.no_error_message="Sem mensagem de erro"
mautic.bpmessage.fix_and_retry="Como Corrigir"
mautic.bpmessage.fix_and_retry_help="Corrija os erros nos contatos..."
```

---

## 📝 Mensagens de Erro Comuns

### 1. Contract must not be empty
```
❌ HTTP 400: {"messages":["'Contract' must not be empty."]}

Solução:
- Preencher campo 'contractnumber' no contato
- Ou configurar campo correto na campanha
```

### 2. Invalid phone format
```
❌ HTTP 400: {"messages":["Invalid phone format"]}

Solução:
- Verificar formato do telefone (deve ser 11987654321)
- Remover caracteres especiais
- Garantir DDD + número
```

### 3. Area Code must not be empty
```
❌ HTTP 400: {"messages":["'Area Code' must not be empty."]}

Solução:
- Telefone deve conter DDD (primeiros 2 dígitos)
- Exemplo correto: 11987654321 (11 = DDD)
```

---

## 🧪 Testando a Interface

### Simular erro para ver a interface:

1. **Criar contato sem campo obrigatório:**
```sql
INSERT INTO leads (firstname, lastname, email, mobile, contractnumber)
VALUES ('Teste', 'Erro', 'teste@email.com', '11987654321', NULL);
```

2. **Adicionar à campanha BpMessage**

3. **Disparar campanha:**
```bash
php bin/console mautic:campaigns:trigger
```

4. **Acessar interface:**
   - Menu: Channels > BpMessage Lots
   - Clicar no lote criado
   - Ver seção vermelha com erro

---

## 🎉 Resultado Final

Agora o usuário pode:

✅ **Ver todos os erros** de uma vez, sem precisar procurar
✅ **Entender exatamente** qual campo está faltando
✅ **Corrigir diretamente** clicando no lead
✅ **Reprocessar facilmente** com um botão ou comando
✅ **Acompanhar progresso** vendo contador de falhas diminuir

**A experiência do usuário é muito melhor!** 🚀
