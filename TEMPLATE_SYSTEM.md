# Sistema de Templates BpMessage

## Visão Geral

O plugin BpMessage suporta **2 tipos de mensagens com templates**:

1. **Mensagens RCS com Template** - Templates pré-cadastrados na API BpMessage
2. **Emails com Template do Mautic** - Templates HTML do próprio Mautic

---

## 1. Mensagens RCS com Template

### Como Funciona

Para mensagens RCS, você deve usar um **template pré-cadastrado** na plataforma BpMessage. O Mautic envia apenas o **ID do template** e as **variáveis** para preenchimento.

### Fluxo de Envio

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Campanha Mautic                                           │
│    - Configuração: service_type = 3 (RCS)                   │
│    - id_template: "123456"                                   │
│    - message_variables: {nome, cpf, valor}                   │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. MessageMapper (processMessageVariables)                   │
│    - Substitui tokens: {contactfield=firstname}             │
│    - Resultado: [                                            │
│        {"key": "nome", "value": "João Silva"},              │
│        {"key": "cpf", "value": "12345678900"},              │
│        {"key": "valor", "value": "R$ 100,00"}               │
│      ]                                                        │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. Mensagem Enviada para API BpMessage                      │
│    {                                                          │
│      "idServiceType": 3,                                     │
│      "idTemplate": "123456",                                 │
│      "variables": [                                          │
│        {"key": "nome", "value": "João Silva"},              │
│        {"key": "cpf", "value": "12345678900"},              │
│        {"key": "valor", "value": "R$ 100,00"}               │
│      ],                                                       │
│      "areaCode": "11",                                       │
│      "phone": "987654321",                                   │
│      "contract": "123456"                                    │
│    }                                                          │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. API BpMessage                                             │
│    - Busca template "123456" no banco de dados              │
│    - Substitui {{nome}}, {{cpf}}, {{valor}} pelos valores   │
│    - Envia mensagem RCS formatada para o destinatário       │
└─────────────────────────────────────────────────────────────┘
```

### Configuração da Campanha

**No formulário da campanha:**

```
Service Type: RCS (3)
ID Template: 123456
Message Variables:
  ├─ nome = {contactfield=firstname} {contactfield=lastname}
  ├─ cpf = {contactfield=cpfcnpj}
  └─ valor = R$ 100,00
```

### Exemplo de Template na API BpMessage

O template **não é enviado** pelo Mautic. Ele já existe na plataforma BpMessage:

**Template ID 123456:**
```
Olá {{nome}},

Seu CPF {{cpf}} foi aprovado!
Valor liberado: {{valor}}

Clique aqui para continuar: [Botão]
```

**Após substituição:**
```
Olá João Silva,

Seu CPF 12345678900 foi aprovado!
Valor liberado: R$ 100,00

Clique aqui para continuar: [Botão]
```

### Código Responsável

**MessageMapper.php - Linhas 275-319:**

```php
private function processMessageVariables(Lead $lead, array $config): array
{
    if (empty($config['message_variables'])) {
        return [];
    }

    // Get contact values for token replacement
    $contactValues = $this->getContactValues($lead);

    // Process from key-value format
    $variables = [];

    foreach ($data as $key => $value) {
        $variables[] = [
            'key' => $key,  // Nome da variável do template
            'value' => rawurldecode(TokenHelper::findLeadTokens($value, $contactValues, true)),
        ];
    }

    return $variables;
}
```

**Linhas 46-58 - Adiciona template ao payload:**

```php
// Add message text for SMS/WhatsApp
if (in_array($serviceType, [1, 2])) {
    $text = $config['message_text'] ?? '';
    $message['text'] = $this->processTokens($text, $lead);
}

// Add template for RCS
if (3 === $serviceType) { // RCS
    if (empty($config['id_template'])) {
        throw new \InvalidArgumentException('idTemplate is required for RCS messages');
    }

    $message['idTemplate'] = $config['id_template'];
}
```

---

## 2. Emails com Template do Mautic

### Como Funciona

Para emails, você **cria um template HTML no Mautic** e o plugin extrai o conteúdo (subject + body) e envia **todo o HTML** para a API BpMessage.

### Fluxo de Envio

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Template de Email no Mautic                              │
│    - Subject: "Olá {contactfield=firstname}!"               │
│    - Body: "<html><body>Bem-vindo {{Nome}}!</body></html>"  │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. Campanha Mautic                                           │
│    - Ação: Send BpMessage Email (Template)                  │
│    - email_template: ID do template (5)                      │
│    - email_variables: variáveis personalizadas              │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. EmailTemplateMessageMapper                                │
│    - Carrega template do banco de dados                     │
│    - Extrai subject e customHtml/content                     │
│    - Substitui {contactfield=*} com TokenHelper             │
│    - Processa email_variables                                │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. Mensagem Enviada para API BpMessage                      │
│    {                                                          │
│      "control": true,                                        │
│      "from": "noreply@empresa.com",                          │
│      "to": "joao@email.com",                                 │
│      "subject": "Olá João!",                                 │
│      "body": "<html><body>Bem-vindo João Silva!</body>",    │
│      "idForeignBookBusiness": "12345",                       │
│      "contract": "789",                                      │
│      "cpfCnpjReceiver": "12345678900",                       │
│      "variables": [                                          │
│        {"key": "custom1", "value": "valor1"}                │
│      ]                                                        │
│    }                                                          │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ 5. API BpMessage                                             │
│    - Recebe subject e body completos                         │
│    - Envia email formatado para o destinatário              │
└─────────────────────────────────────────────────────────────┘
```

### Configuração da Campanha

**No formulário da campanha:**

```
Email Template: [Selecionar Template "Boas-vindas" (ID: 5)]
Email From: noreply@empresa.com (opcional, usa do template)
Email To: {contactfield=email} (opcional, usa do lead)
Book Business Foreign ID: 12345
Additional Data:
  ├─ contract = {contactfield=contractnumber}
  ├─ cpfCnpjReceiver = {contactfield=cpfcnpj}
Email Variables:
  └─ custom1 = {contactfield=custom_field}
```

### Exemplo de Template Mautic

**Template "Boas-vindas" (ID: 5):**

**Subject:**
```
Olá {contactfield=firstname}!
```

**Body (HTML):**
```html
<!DOCTYPE html>
<html>
<head>
    <title>Bem-vindo</title>
</head>
<body>
    <h1>Olá {contactfield=firstname} {contactfield=lastname}!</h1>
    <p>Seu contrato <strong>{contactfield=contractnumber}</strong> foi aprovado.</p>
    <p>CPF/CNPJ: {contactfield=cpfcnpj}</p>
    <p>Clique aqui para continuar.</p>
</body>
</html>
```

**Após substituição para lead "João Silva":**

**Subject:**
```
Olá João!
```

**Body:**
```html
<!DOCTYPE html>
<html>
<head>
    <title>Bem-vindo</title>
</head>
<body>
    <h1>Olá João Silva!</h1>
    <p>Seu contrato <strong>123456</strong> foi aprovado.</p>
    <p>CPF/CNPJ: 12345678900</p>
    <p>Clique aqui para continuar.</p>
</body>
</html>
```

### Código Responsável

**EmailTemplateMessageMapper.php - Linhas 45-112:**

```php
public function mapLeadToEmail(Lead $lead, array $config, Campaign $campaign, ?BpMessageLot $lot = null): array
{
    // 1. Carregar template do banco de dados
    $templateId = is_array($config['email_template']) ? reset($config['email_template']) : $config['email_template'];
    $emailTemplate = $this->em->getRepository(Email::class)->find($templateId);

    if (!$emailTemplate) {
        throw new \InvalidArgumentException("Email template #{$templateId} not found");
    }

    // 2. Obter valores do contato para substituição
    $contactValues = $this->getContactValues($lead);

    // 3. Extrair subject e body do template
    $rawSubject = $emailTemplate->getSubject();
    $rawBody = $emailTemplate->getCustomHtml();

    if (empty($rawBody)) {
        $rawBody = $emailTemplate->getContent();
    }

    // 4. Substituir tokens usando TokenHelper
    $subject = rawurldecode(TokenHelper::findLeadTokens($rawSubject, $contactValues, true));
    $body = rawurldecode(TokenHelper::findLeadTokens($rawBody, $contactValues, true));

    // 5. Construir mensagem de email
    $email = [
        'control' => $config['control'] ?? true,
        'from' => $this->getFromAddress($emailTemplate, $config, $contactValues),
        'to' => $this->getToAddress($lead, $config, $contactValues),
        'subject' => $subject,  // Subject completo com tokens substituídos
        'body' => $body,        // HTML completo com tokens substituídos
    ];

    // 6. Adicionar book business ID
    if ($lot && $lot->getBookBusinessForeignId()) {
        $email['idForeignBookBusiness'] = $lot->getBookBusinessForeignId();
    }

    // 7. Processar additional_data (contract, cpfCnpjReceiver, etc)
    $additionalData = $this->processAdditionalData($lead, $config);
    if (!empty($additionalData)) {
        $email = array_merge($email, $additionalData);
    }

    // 8. Processar email_variables
    $emailVariables = $this->processEmailVariables($lead, $config);
    if (!empty($emailVariables)) {
        $email['variables'] = $emailVariables;
    }

    return $email;
}
```

---

## Sistema de Substituição de Tokens

### Tokens Suportados

Ambos os tipos de template usam o **TokenHelper do Mautic** para substituição:

| Token | Descrição | Exemplo |
|-------|-----------|---------|
| `{contactfield=firstname}` | Primeiro nome | João |
| `{contactfield=lastname}` | Sobrenome | Silva |
| `{contactfield=email}` | Email | joao@email.com |
| `{contactfield=mobile}` | Telefone | 11987654321 |
| `{contactfield=cpfcnpj}` | CPF/CNPJ | 12345678900 |
| `{contactfield=contractnumber}` | Número do contrato | 123456 |
| `{contactfield=custom_field}` | Campo customizado | Qualquer valor |
| `{timestamp}` | Timestamp Unix | 1700000000 |
| `{date_now}` | Data/hora atual | 2025-11-22 14:30:00 |

### Como Funciona a Substituição

**MessageMapper.php - Linhas 140-161:**

```php
private function processTokens(string $text, Lead $lead): string
{
    // 1. Obter valores do contato
    $contactValues = $this->getContactValues($lead);

    // 2. Usar TokenHelper para substituir {contactfield=*}
    $text = rawurldecode(TokenHelper::findLeadTokens($text, $contactValues, true));

    // 3. Substituir tokens especiais
    $text = str_replace('{timestamp}', (string) time(), $text);
    $text = str_replace('{date_now}', date('Y-m-d H:i:s'), $text);

    return $text;
}
```

**Linhas 205-232 - getContactValues():**

```php
private function getContactValues(Lead $lead): array
{
    // Obtém todos os campos do lead
    $fields = $lead->getProfileFields();

    // Garante que campos básicos sempre existam
    $fields['id'] = $lead->getId();

    if ($lead->getEmail()) {
        $fields['email'] = $lead->getEmail();
    }

    if ($lead->getFirstname()) {
        $fields['firstname'] = $lead->getFirstname();
    }

    if ($lead->getLastname()) {
        $fields['lastname'] = $lead->getLastname();
    }

    return $fields;
}
```

---

## Diferenças Entre os Dois Sistemas

| Aspecto | RCS Template | Email Template |
|---------|-------------|----------------|
| **Onde está o template** | API BpMessage | Banco Mautic |
| **O que é enviado** | ID + Variáveis | HTML completo |
| **Service Type** | 3 (RCS) | - (Email) |
| **Campo no config** | `id_template` | `email_template` |
| **Substituição** | Feita pela API | Feita pelo Mautic |
| **Tamanho do payload** | Pequeno (~200 bytes) | Grande (HTML inteiro) |
| **Flexibilidade** | Limitada ao template | Total (HTML dinâmico) |
| **Formato** | RCS Rich Cards | HTML Email |

---

## Variáveis Adicionais

Além dos tokens no texto/subject, você pode enviar **variáveis adicionais** no payload:

### message_variables (RCS)

Usado para **preencher placeholders do template RCS**.

**Exemplo:**
```json
{
  "message_variables": {
    "nome_cliente": "{contactfield=firstname}",
    "valor_fatura": "R$ 150,00",
    "data_vencimento": "30/12/2025"
  }
}
```

**Resultado enviado à API:**
```json
{
  "variables": [
    {"key": "nome_cliente", "value": "João"},
    {"key": "valor_fatura", "value": "R$ 150,00"},
    {"key": "data_vencimento", "value": "30/12/2025"}
  ]
}
```

### email_variables (Email)

Usado para **variáveis customizadas** que não fazem parte do HTML do template.

**Exemplo:**
```json
{
  "email_variables": {
    "tracking_id": "{contactfield=id}",
    "source": "campaign_boas_vindas"
  }
}
```

**Resultado enviado à API:**
```json
{
  "variables": [
    {"key": "tracking_id", "value": "123"},
    {"key": "source", "value": "campaign_boas_vindas"}
  ]
}
```

---

## Resumo

### Para enviar mensagem RCS com template:

1. ✅ Crie template na **API BpMessage** e anote o ID
2. ✅ Configure campanha com `service_type = 3` e `id_template`
3. ✅ Mapeie variáveis do template em `message_variables`
4. ✅ Mautic envia **apenas ID + variáveis** para a API
5. ✅ API BpMessage substitui variáveis e envia RCS

### Para enviar email com template:

1. ✅ Crie template HTML no **Mautic** (Emails > Templates)
2. ✅ Use tokens `{contactfield=*}` no subject e body
3. ✅ Configure campanha com ação "Send BpMessage Email (Template)"
4. ✅ Selecione o template criado
5. ✅ Mautic substitui tokens e envia **HTML completo** para API
6. ✅ API BpMessage envia email formatado

**O conteúdo do template é sempre processado e enviado completo para a API, com todos os tokens já substituídos!**
