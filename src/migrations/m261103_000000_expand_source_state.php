<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\migrations;

use craft\db\Migration;
use yii\db\Expression;

/** Occurrence provenance must not inherit the old short metadata payload ceiling. */
final class m261103_000000_expand_source_state extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%bramble_search_metadata}}';
        if ($this->db->tableExists($table) && $this->db->getIsMysql()) {
            if ($this->db->getTableSchema($table, true)->columns['value']->dbType !== 'longtext') {
                $this->alterColumn($table, 'value', $this->longText()->notNull());
            }
            $indexes = $this->db->getSchema()->getTableIndexes($table, true);
            $hasLookupIndex = false;
            foreach ($indexes as $index) {
                if ($index->columnNames === ['key', 'value'] && !$index->isUnique) {
                    $hasLookupIndex = true;
                    break;
                }
            }
            if (!$hasLookupIndex) {
                $this->createIndex(null, $table, ['key', new Expression('[[value]](191)')]);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
