<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\migrations;

use craft\db\Migration;

/** Adds durable continuation state without connecting to an external index. */
final class m261104_000001_add_rebuild_lifecycle extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%bramble_search_rebuild_runs}}';
        if ($this->db->tableExists($table) && !isset($this->db->getTableSchema($table, true)->columns['lifecycle'])) {
            $this->addColumn($table, 'lifecycle', $this->longText());
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
