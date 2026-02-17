<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\migrations;

use craft\db\Migration;

/**
 * Updates term column collation to utf8mb4_0900_as_ci for accent-sensitive matching.
 *
 * Fixes GitHub issue #6: accented characters (e.g. 'vélo' vs 'velo') were treated as
 * duplicates under the default utf8mb4_unicode_ci collation, causing unique index violations.
 * utf8mb4_0900_as_ci is accent-sensitive and case-insensitive (MySQL 8.0+, required by Craft 5).
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

        $tablePrefix = '{{%bramble_search_';
        $collation = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_ci';

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
                $this->alterColumn(
                    $tableName,
                    'term',
                    $this->string(255)->notNull()->append($collation)
                );
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
                $this->alterColumn(
                    $tableName,
                    'term',
                    $this->string(255)->notNull()
                );
            }
        }

        return true;
    }
}
