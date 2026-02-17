<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\migrations;

use craft\db\Migration;

/**
 * Updates term column collation to an accent-sensitive collation.
 *
 * Fixes GitHub issue #6: accented characters (e.g. 'vélo' vs 'velo') were treated as
 * duplicates under the default utf8mb4_unicode_ci collation, causing unique index violations.
 *
 * MySQL 8.0+: utf8mb4_0900_as_ci (accent-sensitive, case-insensitive)
 * MariaDB:    utf8mb4_bin (binary; accent+case-sensitive — terms are stored lowercase so
 *             case-sensitivity has no practical effect on search behaviour)
 */
class m260216_000000_update_term_collation extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if ($this->db->getDriverName() !== 'mysql') {
            return true;
        }

        $isMariaDb = stripos($this->db->getServerVersion(), 'mariadb') !== false;
        $collation = $isMariaDb ? 'utf8mb4_bin' : 'utf8mb4_0900_as_ci';

        $tablePrefix = '{{%bramble_search_';
        $tables = [
            'documents',
            'terms',
            'titles',
            'ngrams',
            'ngram_index',
        ];

        foreach ($tables as $table) {
            $tableName = $tablePrefix . $table . '}}';
            if ($this->db->tableExists($tableName)) {
                $quotedTable = $this->db->quoteTableName($tableName);
                $this->db->createCommand(
                    "ALTER TABLE {$quotedTable} CHANGE `term` `term` varchar(255) CHARACTER SET utf8mb4 COLLATE {$collation} NOT NULL"
                )->execute();
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        if ($this->db->getDriverName() !== 'mysql') {
            return true;
        }

        $tablePrefix = '{{%bramble_search_';
        $tables = [
            'documents',
            'terms',
            'titles',
            'ngrams',
            'ngram_index',
        ];

        foreach ($tables as $table) {
            $tableName = $tablePrefix . $table . '}}';
            if ($this->db->tableExists($tableName)) {
                $quotedTable = $this->db->quoteTableName($tableName);
                $this->db->createCommand(
                    "ALTER TABLE {$quotedTable} CHANGE `term` `term` varchar(255) NOT NULL"
                )->execute();
            }
        }

        return true;
    }
}
