<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\migrations;

use craft\db\Migration;

final class m261103_000000_add_rebuild_manifests extends Migration
{
    public function safeUp(): bool
    {
        $runs = '{{%bramble_search_rebuild_runs}}';
        $chunks = '{{%bramble_search_rebuild_chunks}}';
        if (!$this->db->tableExists($runs)) {
            $this->createTable($runs, [
                'id' => $this->primaryKey(),
                'indexIdentity' => $this->string(64)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'runId' => $this->string(32)->notNull(),
                'status' => $this->string(16)->notNull(),
                'elementTypes' => $this->longText()->notNull(),
                'totalItems' => $this->integer()->notNull()->defaultValue(0),
                'nextOffset' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, $runs, ['indexIdentity', 'siteId'], true);
            $this->createIndex(null, $runs, ['runId'], true);
        }
        if (!$this->db->tableExists($chunks)) {
            $this->createTable($chunks, [
                'id' => $this->primaryKey(),
                'runId' => $this->string(32)->notNull(),
                'chunkIndex' => $this->integer()->notNull(),
                'items' => $this->longText()->notNull(),
                'itemCount' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, $chunks, ['runId', 'chunkIndex'], true);
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
