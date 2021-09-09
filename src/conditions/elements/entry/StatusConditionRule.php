<?php

namespace craft\conditions\elements\entry;

use Craft;
use craft\conditions\BaseSelectOperatorConditionRule;
use craft\conditions\elements\ElementQueryConditionRuleInterface;
use craft\elements\Entry;
use yii\db\QueryInterface;

/**
 * Entry status condition rule.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0.0
 */
class StatusConditionRule extends BaseSelectOperatorConditionRule implements ElementQueryConditionRuleInterface
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('app', 'Status');
    }

    /**
     * @inheritdoc
     */
    public function getSelectOptions(): array
    {
        return Entry::statuses();
//        return ArrayHelper::map($sections, 'uid', 'name');
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(QueryInterface $query): void
    {
        $query->status($this->value);
    }
}
