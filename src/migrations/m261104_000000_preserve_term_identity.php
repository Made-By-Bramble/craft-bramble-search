<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\migrations;

use craft\db\Migration;

final class m261104_000000_preserve_term_identity extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->getIsMysql()) {
            return true;
        }
        foreach ([
            'documents' => ['term'], 'terms' => ['term'], 'titles' => ['term'],
            'ngrams' => ['term', 'ngram'], 'ngram_index' => ['term'], 'metadata' => ['key'],
        ] as $suffix => $columns) {
            $table = '{{%bramble_search_' . $suffix . '}}';
            $schema = $this->db->getTableSchema($table, true);
            if ($schema === null) {
                continue;
            }
            $collations = array_column($this->db->createCommand(
                'SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                [':table' => $this->db->getSchema()->getRawTableName($table)],
            )->queryAll(), 'COLLATION_NAME', 'COLUMN_NAME');
            foreach ($columns as $column) {
                if (($collations[$column] ?? null) === 'utf8mb4_bin') {
                    continue;
                }
                $definition = $schema->columns[$column] ?? null;
                if ($definition === null || $definition->allowNull || preg_match('/\Avarchar\(([0-9]+)\)\z/iD', $definition->dbType, $matches) !== 1) {
                    throw new \RuntimeException('Expected a non-null VARCHAR search index column.');
                }
                $this->alterColumn($table, $column, 'varchar(' . $matches[1] . ') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
