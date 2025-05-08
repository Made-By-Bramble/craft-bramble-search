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
        $tablePrefix = '{{%bramble_search_';

        // Create documents table
        if (!$this->db->tableExists($tablePrefix . 'documents}}')) {
            $this->createTable($tablePrefix . 'documents}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->notNull(),
                'term' => $this->string(255)->notNull(),
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
                'term' => $this->string(255)->notNull(),
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
                'term' => $this->string(255)->notNull(),
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
                'key' => $this->string(255)->notNull(),
                'value' => $this->text()->notNull(),
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
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $tablePrefix = '{{%bramble_search_';

        // Drop tables in reverse order to avoid foreign key constraints
        $this->dropTableIfExists($tablePrefix . 'metadata}}');
        $this->dropTableIfExists($tablePrefix . 'titles}}');
        $this->dropTableIfExists($tablePrefix . 'terms}}');
        $this->dropTableIfExists($tablePrefix . 'documents}}');

        return true;
    }
}
