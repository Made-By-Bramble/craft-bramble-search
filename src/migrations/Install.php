<?php

namespace MadeByBramble\BrambleSearch\migrations;

use craft\db\Migration;

/**
 * Install migration for Bramble Search plugin.
 *
 * This migration creates the database tables required for the MySQL search adapter.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        (new m261103_000000_add_rebuild_manifests(['db' => $this->db, 'compact' => $this->compact]))->safeUp();
        (new m261104_000001_add_rebuild_lifecycle(['db' => $this->db, 'compact' => $this->compact]))->safeUp();
        $tablePrefix = '{{%bramble_search_';
        $isMysql = $this->db->getDriverName() === 'mysql';
        $termCol = $isMysql
            ? 'varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL'
            : $this->string(255)->notNull();

        // Create documents table
        if (!$this->db->tableExists($tablePrefix . 'documents}}')) {
            $this->createTable($tablePrefix . 'documents}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->notNull(),
                'term' => $termCol,
                'frequency' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Add indexes for documents table
            $this->createIndex(
                null,
                $tablePrefix . 'documents}}',
                ['siteId', 'elementId', 'term'],
                true
            );
            $this->createIndex(
                null,
                $tablePrefix . 'documents}}',
                ['siteId', 'elementId']
            );
            $this->createIndex(
                null,
                $tablePrefix . 'documents}}',
                ['term']
            );
        }

        // Create terms table
        if (!$this->db->tableExists($tablePrefix . 'terms}}')) {
            $this->createTable($tablePrefix . 'terms}}', [
                'id' => $this->primaryKey(),
                'term' => $termCol,
                'docId' => $this->string(255)->notNull(),
                'frequency' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Add indexes for terms table
            $this->createIndex(
                null,
                $tablePrefix . 'terms}}',
                ['term', 'docId'],
                true
            );
            $this->createIndex(
                null,
                $tablePrefix . 'terms}}',
                ['term']
            );
            $this->createIndex(
                null,
                $tablePrefix . 'terms}}',
                ['docId']
            );
        }

        // Create titles table
        if (!$this->db->tableExists($tablePrefix . 'titles}}')) {
            $this->createTable($tablePrefix . 'titles}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->notNull(),
                'term' => $termCol,
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Add indexes for titles table
            $this->createIndex(
                null,
                $tablePrefix . 'titles}}',
                ['siteId', 'elementId', 'term'],
                true
            );
            $this->createIndex(
                null,
                $tablePrefix . 'titles}}',
                ['siteId', 'elementId']
            );
            $this->createIndex(
                null,
                $tablePrefix . 'titles}}',
                ['term']
            );
        }

        // Create metadata table
        if (!$this->db->tableExists($tablePrefix . 'metadata}}')) {
            $this->createTable($tablePrefix . 'metadata}}', [
                'id' => $this->primaryKey(),
                'key' => $termCol,
                'value' => $this->longText()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Add indexes for metadata table
            $this->createIndex(
                null,
                $tablePrefix . 'metadata}}',
                ['key']
            );
            if ($isMysql) {
                $this->createIndex(null, $tablePrefix . 'metadata}}', ['key', new \yii\db\Expression('[[value]](191)')]);
            }
        }

        // Create n-grams table for fuzzy search optimization
        if (!$this->db->tableExists($tablePrefix . 'ngrams}}')) {
            $this->createTable($tablePrefix . 'ngrams}}', [
                'id' => $this->primaryKey(),
                'ngram' => $isMysql ? 'varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL' : $this->string(5)->notNull(),
                'term' => $termCol,
                'ngram_type' => $this->tinyInteger()->notNull(), // 2=bigram, 3=trigram, etc.
                'siteId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Add indexes for n-grams table
            $this->createIndex(
                null,
                $tablePrefix . 'ngrams}}',
                ['ngram', 'siteId']
            );
            $this->createIndex(
                null,
                $tablePrefix . 'ngrams}}',
                ['term', 'siteId']
            );
            $this->createIndex(
                null,
                $tablePrefix . 'ngrams}}',
                ['ngram_type', 'siteId']
            );
        }

        // Create n-gram index table for fast term lookup
        if (!$this->db->tableExists($tablePrefix . 'ngram_index}}')) {
            $this->createTable($tablePrefix . 'ngram_index}}', [
                'id' => $this->primaryKey(),
                'term' => $termCol,
                'ngram_count' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Add indexes for n-gram index table
            $this->createIndex(
                null,
                $tablePrefix . 'ngram_index}}',
                ['term', 'siteId'],
                true // unique
            );
            $this->createIndex(
                null,
                $tablePrefix . 'ngram_index}}',
                ['siteId']
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%bramble_search_rebuild_chunks}}');
        $this->dropTableIfExists('{{%bramble_search_rebuild_runs}}');
        $tablePrefix = '{{%bramble_search_';

        // Drop tables in reverse order to avoid foreign key constraints
        $this->dropTableIfExists($tablePrefix . 'ngram_index}}');
        $this->dropTableIfExists($tablePrefix . 'ngrams}}');
        $this->dropTableIfExists($tablePrefix . 'metadata}}');
        $this->dropTableIfExists($tablePrefix . 'titles}}');
        $this->dropTableIfExists($tablePrefix . 'terms}}');
        $this->dropTableIfExists($tablePrefix . 'documents}}');

        return true;
    }
}
