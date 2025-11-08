# Guia Rápido de Instalação - MauticBpMessageBundle

## 📦 Passo a Passo

### 1. Copiar Plugin

```bash
cd /path/to/mautic
# Plugin já está em: plugins/MauticBpMessageBundle
```

### 2. Criar Tabelas do Banco

**Opção A: Automática (Recomendado)**
```bash
php bin/console doctrine:schema:update --dump-sql  # Visualizar mudanças
php bin/console doctrine:schema:update --force     # Aplicar mudanças
```

**Opção B: Via SQL direto**
```bash
mysql -u seu_usuario -p seu_banco < plugins/MauticBpMessageBundle/install.sql
```

**Opção C: Via DDEV**
```bash
ddev exec php bin/console doctrine:schema:update --force
```

### 3. Limpar Cache

```bash
php bin/console cache:clear
php bin/console cache:warmup
```

### 4. Instalar Plugin no Mautic

1. Acesse: **Configurações** → **Plugins**
2. Clique em **Install/Upgrade Plugins**
3. Encontre "BpMessage" na lista
4. Clique em **Install**

### 5. Verificar Instalação

```bash
# Verificar se as tabelas foram criadas
mysql -u seu_usuario -p seu_banco -e "SHOW TABLES LIKE 'bpmessage%';"

# Deve retornar:
# bpmessage_lot
# bpmessage_queue
```

### 6. Configurar Cron

Adicione ao crontab:

```bash
crontab -e
```

Adicione a linha:
```bash
*/5 * * * * php /path/to/mautic/bin/console mautic:bpmessage:process >> /var/log/mautic-bpmessage.log 2>&1
```

### 7. Testar Comando

```bash
php bin/console mautic:bpmessage:process -vvv
```

Se ver "No lots to process" → Instalação bem-sucedida! ✅

## 🔧 Configuração Inicial

### Obter Credenciais BpMessage

Você precisa:
- ✅ URL da API: `https://api.bpmessage.com.br`
- ✅ CPF do usuário
- ✅ ID Quota Settings
- ✅ ID Service Settings

**Como obter IDs:**
```bash
curl -X GET https://api.bpmessage.com.br/api/ServiceSettings/GetRoutes \
  -H "Authorization: Bearer SEU_TOKEN"
```

### Criar Primeira Campanha

1. **Campaigns** → **New**
2. Adicione fonte de contatos
3. **Add Action** → **Send BpMessage**
4. Preencha:
   - API Base URL: `https://api.bpmessage.com.br`
   - User CPF: `12345678900`
   - ID Quota Settings: `123`
   - ID Service Settings: `456`
   - Service Type: WhatsApp
   - Contract Field: `contract_number`
   - CPF Field: `cpf`
   - Message Text: `Olá {contactfield=firstname}!`

## ✅ Checklist de Instalação

- [ ] Plugin copiado para `plugins/MauticBpMessageBundle`
- [ ] Tabelas criadas no banco de dados
- [ ] Cache limpo
- [ ] Plugin instalado no Mautic Admin
- [ ] Cron configurado
- [ ] Comando testado
- [ ] Credenciais BpMessage obtidas
- [ ] Primeira campanha criada

## 🐛 Problemas Comuns

### Plugin não aparece na lista

```bash
# Verificar permissões
chmod -R 755 plugins/MauticBpMessageBundle
chown -R www-data:www-data plugins/MauticBpMessageBundle

# Limpar cache novamente
php bin/console cache:clear --env=prod
```

### Erro ao criar tabelas

```bash
# Verificar conexão do banco
php bin/console doctrine:query:sql "SELECT 1"

# Criar tabelas manualmente
mysql -u root -p seu_banco < plugins/MauticBpMessageBundle/install.sql
```

### Cron não está executando

```bash
# Testar manualmente
php bin/console mautic:bpmessage:process -vvv

# Verificar logs
tail -f var/logs/mautic_prod.log | grep BpMessage

# Verificar se cron está ativo
service cron status
```

## 📞 Suporte

- Documentação completa: `README.md`
- Email: dev@bellinati.com.br
- Logs: `var/logs/mautic_prod.log`

## 🎉 Próximos Passos

Após instalação bem-sucedida:

1. Leia o `README.md` completo
2. Configure sua primeira campanha
3. Teste com poucos contatos primeiro (5-10)
4. Monitore os logs
5. Escale gradualmente

**Boa sorte! 🚀**
