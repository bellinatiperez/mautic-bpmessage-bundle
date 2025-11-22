# BpMessage Plugin - Manual Installation Guide

Este guia explica como instalar e gerenciar as tabelas do plugin BpMessage manualmente usando scripts SQL.

## 📁 Scripts Disponíveis

| Script | Descrição |
|--------|-----------|
| `install-schema.sql` | Cria as tabelas e foreign keys do BpMessage |
| `uninstall-schema.sql` | Remove todas as tabelas do BpMessage |
| `verify-schema.sql` | Verifica a instalação e mostra relatório detalhado |

## 🚀 Instalação Manual

### Opção 1: Usando DDEV (Desenvolvimento)

```bash
# 1. Verificar estado atual
ddev exec mysql < plugins/MauticBpMessageBundle/verify-schema.sql

# 2. Instalar tabelas
ddev exec mysql < plugins/MauticBpMessageBundle/install-schema.sql

# 3. Verificar instalação
ddev exec mysql < plugins/MauticBpMessageBundle/verify-schema.sql
```

### Opção 2: Usando MySQL Diretamente (Produção)

```bash
# 1. Verificar estado atual
mysql -u username -p database_name < plugins/MauticBpMessageBundle/verify-schema.sql

# 2. Instalar tabelas
mysql -u username -p database_name < plugins/MauticBpMessageBundle/install-schema.sql

# 3. Verificar instalação
mysql -u username -p database_name < plugins/MauticBpMessageBundle/verify-schema.sql
```

### Opção 3: Via phpMyAdmin ou Cliente MySQL

1. Abra o arquivo `install-schema.sql`
2. Copie todo o conteúdo
3. Cole no phpMyAdmin ou seu cliente MySQL
4. Execute o script

## 🔍 Verificação Pós-Instalação

Execute o script de verificação para confirmar que tudo está correto:

```bash
ddev exec mysql < plugins/MauticBpMessageBundle/verify-schema.sql
```

O relatório mostrará:
- ✅ Existência das tabelas
- ✅ Estrutura das colunas
- ✅ Foreign keys configuradas
- ✅ Indexes criados
- ✅ Compatibilidade de tipos de dados

### Exemplo de Saída Esperada:

```
TABLE EXISTENCE CHECK
table_name          | status   | approximate_rows | size_mb
bpmessage_lot       | ✓ EXISTS | 0                | 0.02
bpmessage_queue     | ✓ EXISTS | 0                | 0.02

FOREIGN KEY CONSTRAINTS
CONSTRAINT_NAME              | TABLE_NAME        | COLUMN_NAME | references        | DELETE_RULE | status
fk_bpmessage_queue_lead     | bpmessage_queue   | lead_id     | leads.id         | CASCADE     | ✓ OK
fk_bpmessage_queue_lot      | bpmessage_queue   | lot_id      | bpmessage_lot.id | CASCADE     | ✓ OK

DATA TYPE COMPATIBILITY CHECK
check_name              | lot_type                          | queue_type                        | status
lot_id compatibility    | bpmessage_lot.id = int unsigned   | bpmessage_queue.lot_id = int...  | ✓ COMPATIBLE
lead_id compatibility   | leads.id = bigint unsigned        | bpmessage_queue.lead_id = big... | ✓ COMPATIBLE
```

## 🗑️ Desinstalação

**⚠️ ATENÇÃO: Isto irá deletar TODOS os dados do BpMessage!**

```bash
# DDEV
ddev exec mysql < plugins/MauticBpMessageBundle/uninstall-schema.sql

# Produção
mysql -u username -p database_name < plugins/MauticBpMessageBundle/uninstall-schema.sql
```

## 🔧 Correção de Problemas Comuns

### Problema 1: Foreign Key já existe

**Erro:**
```
ERROR 1826 (HY000): Duplicate foreign key constraint name 'fk_bpmessage_queue_lot'
```

**Solução:**
```bash
# Remover foreign key existente
ddev exec mysql -e "ALTER TABLE bpmessage_queue DROP FOREIGN KEY fk_bpmessage_queue_lot;"
ddev exec mysql -e "ALTER TABLE bpmessage_queue DROP FOREIGN KEY fk_bpmessage_queue_lead;"

# Executar install novamente
ddev exec mysql < plugins/MauticBpMessageBundle/install-schema.sql
```

### Problema 2: Tabela já existe mas sem foreign keys

**Solução:**
```bash
# Desinstalar completamente
ddev exec mysql < plugins/MauticBpMessageBundle/uninstall-schema.sql

# Reinstalar
ddev exec mysql < plugins/MauticBpMessageBundle/install-schema.sql
```

### Problema 3: Erro de tipo de dados incompatível

**Erro:**
```
ERROR 1005 (HY000): Can't create table (errno: 150 "Foreign key constraint is incorrectly formed")
```

**Causa:** A tabela `leads` tem tipo `BIGINT UNSIGNED` mas `bpmessage_queue.lead_id` tem tipo diferente.

**Solução:** Use o script `install-schema.sql` atualizado que já tem os tipos corretos.

## 📊 Estrutura das Tabelas

### Tabela: bpmessage_lot

Armazena informações de lotes/batches para envio de mensagens.

**Campos principais:**
- `id`: INT UNSIGNED - Chave primária
- `external_lot_id`: ID do lote na API BpMessage
- `status`: CREATING, OPEN, CLOSED, PROCESSING, FINISHED, FAILED
- `messages_count`: Total de mensagens no lote
- `batch_size`: Tamanho máximo do lote (default: 1000)
- `time_window`: Janela de tempo em segundos (default: 300)

### Tabela: bpmessage_queue

Armazena mensagens individuais pendentes de envio.

**Campos principais:**
- `id`: INT UNSIGNED - Chave primária
- `lot_id`: INT UNSIGNED - FK para bpmessage_lot
- `lead_id`: BIGINT UNSIGNED - FK para leads (contatos Mautic)
- `payload_json`: Dados da mensagem em JSON
- `status`: PENDING, SENT, FAILED
- `retry_count`: Contador de tentativas

## 🔄 Migração Automática vs Manual

### Quando usar a migração automática:
- ✅ Durante desenvolvimento local
- ✅ Em ambientes novos/limpos
- ✅ Quando o bundle boot funciona corretamente

A migração automática é executada no boot do bundle:
```bash
php bin/console cache:clear
```

### Quando usar a instalação manual:
- ⚠️ Em produção se a migração automática falhou
- ⚠️ Quando precisa recriar as tabelas
- ⚠️ Para debug ou troubleshooting
- ⚠️ Em ambientes com restrições de permissões

## 📝 Usando o Comando de Correção

Além dos scripts SQL, você pode usar o comando PHP criado:

```bash
# Ver o que seria feito (dry-run)
ddev exec php bin/console mautic:bpmessage:fix-migration --dry-run

# Executar a correção
ddev exec php bin/console mautic:bpmessage:fix-migration

# Forçar recriação (apaga dados!)
ddev exec php bin/console mautic:bpmessage:fix-migration --force
```

Este comando:
- ✅ Detecta automaticamente os tipos de dados corretos
- ✅ Verifica compatibilidade com tabela `leads`
- ✅ Mostra relatório detalhado
- ✅ Oferece modo dry-run para segurança

## 🆘 Suporte

Se encontrar problemas:

1. Execute o script de verificação:
   ```bash
   ddev exec mysql < plugins/MauticBpMessageBundle/verify-schema.sql
   ```

2. Verifique os logs do Mautic:
   ```bash
   tail -f var/logs/mautic_dev.log
   ```

3. Verifique os logs do MySQL/MariaDB para erros de foreign key

4. Use o comando de correção com dry-run:
   ```bash
   ddev exec php bin/console mautic:bpmessage:fix-migration --dry-run
   ```

## 📌 Notas Importantes

1. **Backup**: Sempre faça backup do banco antes de executar scripts de instalação/desinstalação
2. **Prefixo de tabelas**: Os scripts assumem que não há prefixo. Se usar prefixo, edite os scripts
3. **Permissões**: Certifique-se de ter permissões CREATE, ALTER, DROP no banco
4. **Charset**: As tabelas usam UTF8MB4 para suportar emojis e caracteres especiais
5. **Engine**: InnoDB é obrigatório para suportar foreign keys

## 🔐 Segurança

- Os scripts SQL são seguros e não modificam outras tabelas do Mautic
- Foreign keys garantem integridade referencial
- Todas as deleções são em CASCADE para evitar registros órfãos
- Use sempre em ambiente de teste primeiro

---

**Versão do Script:** 1.0.0
**Última Atualização:** 2025-01-13
**Compatibilidade:** Mautic 5.x, MySQL 5.7+, MariaDB 10.3+
