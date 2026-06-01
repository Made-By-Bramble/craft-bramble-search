<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\StringHelper;
use yii\db\Expression;

/**
 * Repairs duplicated MySQL metadata rows created by earlier indexing runs.
 */
class m260601_000000_repair_metadata_singletons extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $table = '{{%bramble_search_metadata}}';
        if (!$this->db->tableExists($table)) {
            return true;
        }

        $this->deduplicateDocumentRows($table);

        $totalDocs = (new Query())
            ->select(new Expression('COUNT(DISTINCT [[value]])'))
            ->from($table)
            ->where(['key' => 'doc'])
            ->scalar($this->db);

        $totalLength = (new Query())
            ->from('{{%bramble_search_documents}}')
            ->where(['term' => '_length'])
            ->sum('frequency', $this->db);

        $this->replaceSingletonRow($table, 'totalDocs', (string)((int)$totalDocs));
        $this->replaceSingletonRow($table, 'totalLength', (string)((int)$totalLength));

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        return true;
    }

    private function deduplicateDocumentRows(string $table): void
    {
        $rows = (new Query())
            ->select(['id', 'value'])
            ->from($table)
            ->where(['key' => 'doc'])
            ->orderBy(['id' => SORT_ASC])
            ->all($this->db);

        $seen = [];
        $deleteIds = [];
        foreach ($rows as $row) {
            $value = (string)$row['value'];
            if (isset($seen[$value])) {
                $deleteIds[] = (int)$row['id'];
                continue;
            }

            $seen[$value] = true;
        }

        foreach (array_chunk($deleteIds, 1000) as $chunk) {
            $this->delete($table, ['id' => $chunk]);
        }
    }

    private function replaceSingletonRow(string $table, string $key, string $value): void
    {
        $this->delete($table, ['key' => $key]);

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $this->insert($table, [
            'key' => $key,
            'value' => $value,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]);
    }
}
