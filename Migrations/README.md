# MauticBpMessageBundle Migrations

Sistema de migrations automáticas para o MauticBpMessageBundle.

## Como funciona

As migrations são executadas automaticamente quando:
1. O plugin é carregado (boot do bundle)
2. O cache é limpo
3. O Mautic é iniciado

## Estrutura

- `Migration/AbstractMigration.php` - Classe base para migrations
- `Migration/MigrationInterface.php` - Interface para migrations
- `Migration/Engine.php` - Motor que executa as migrations
- `Migrations/` - Diretório com arquivos de migration

## Como criar uma nova migration

1. Crie um novo arquivo em `Migrations/` com o padrão `Version_X_Y_Z.php`
2. Estenda `AbstractMigration`
3. Implemente os métodos `isApplicable()` e `up()`

### Exemplo:

```php
<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use MauticPlugin\MauticBpMessageBundle\Migration\AbstractMigration;

class Version_1_0_1 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('bpmessage_lot'));

            // Retorna true se a coluna NÃO existir (precisa adicionar)
            return !$table->hasColumn('new_column');
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function up(): void
    {
        $table = $this->concatPrefix('bpmessage_lot');

        // Adicionar nova coluna
        $this->addSql("ALTER TABLE {$table} ADD new_column VARCHAR(255) NULL DEFAULT NULL");

        // Adicionar índice
        $this->addSql("CREATE INDEX idx_new_column ON {$table} (new_column)");
    }
}
```

## Métodos auxiliares disponíveis

- `concatPrefix(string $name)` - Adiciona prefixo da tabela
- `addSql(string $sql)` - Adiciona SQL para executar
- `hasColumn(string $tableName, string $columnName)` - Verifica se coluna existe
- `generatePropertyName(string $table, string $type, array $columns)` - Gera nome para constraint/index
- `generateIndexStatement(string $table, array $columns)` - Gera statement de índice
- `generateAlterTableForeignKeyStatement(...)` - Gera statement de foreign key

## Ordem de execução

As migrations são executadas em ordem alfabética pelo nome do arquivo.
Por isso, use o padrão `Version_X_Y_Z.php` onde:
- X = major version
- Y = minor version
- Z = patch version

## Verificação

As migrations verificam automaticamente se já foram aplicadas através do método `isApplicable()`.
Isso evita erros ao tentar criar tabelas/colunas que já existem.

## Logs

Erros de migration são logados em `var/logs/mautic_dev.log` (ou `mautic_prod.log` em produção).
Procure por: "BpMessage migrations failed"
