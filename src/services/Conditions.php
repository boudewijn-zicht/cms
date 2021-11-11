<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\services;

use Craft;
use craft\conditions\ConditionInterface;
use craft\conditions\ConditionRuleInterface;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use ReflectionException;
use ReflectionProperty;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

/**
 * The Conditions service provides APIs for managing conditions
 *
 * An instance of Conditions service is globally accessible in Craft via [[\craft\base\ApplicationTrait::getConditions()|`Craft::$app->conditions`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0.0
 */
class Conditions extends Component
{
    /**
     * Creates a condition instance.
     *
     * @param string|array{class: string} $config The condition class or configuration array
     * @return ConditionInterface
     * @throws InvalidArgumentException if the condition does not implement [[ConditionInterface]]
     * @throws InvalidConfigException
     */
    public function createCondition($config): ConditionInterface
    {
        if (is_string($config)) {
            $class = $config;
            $config = [];
        } else {
            $class = ArrayHelper::remove($config, 'class');
        }

        if (!is_subclass_of($class, ConditionInterface::class)) {
            throw new InvalidArgumentException("Invalid condition class: $class");
        }

        // The base config will be JSON-encoded within a `config` key if this came from a condition builder
        if (isset($config['config']) && Json::isJsonObject($config['config'])) {
            $config = array_merge(
                Json::decode(ArrayHelper::remove($config, 'config')),
                $config
            );
        }

        /** @var ConditionInterface $condition */
        $condition = Craft::createObject($class);
        $condition->setAttributes($config);
        return $condition;
    }

    /**
     * Creates a condition rule instance.
     *
     * @param string|array{class: string}|array{type: string} $config The condition class or configuration array
     * @return ConditionRuleInterface
     * @throws InvalidArgumentException if the condition rule does not implement [[ConditionRuleInterface]]
     */
    public function createConditionRule($config): ConditionRuleInterface
    {
        if (is_string($config)) {
            $class = $config;
            $config = [];
        } else {
            $class = ArrayHelper::remove($config, 'class');

            // Merge `type` in, if this is coming from a condition builder
            if (isset($config['type'])) {
                $newConfig = Json::decodeIfJson(ArrayHelper::remove($config, 'type'));
                if (is_string($newConfig)) {
                    $newClass = $newConfig;
                    $newConfig = [];
                } else {
                    $newClass = ArrayHelper::remove($newConfig, 'class');
                }

                // Is the type changing?
                if ($class !== null && $newClass !== $class) {
                    // Remove any config attributes that aren't defined by the same class between both types
                    $config = array_filter($config, function($attribute) use ($class, $newClass) {
                        try {
                            $r1 = new ReflectionProperty($class, $attribute);
                            $r2 = new ReflectionProperty($newClass, $attribute);
                            return $r1->getDeclaringClass()->name === $r2->getDeclaringClass()->name;
                        } catch (ReflectionException $e) {
                            return false;
                        }
                    }, ARRAY_FILTER_USE_KEY);
                }

                $class = $newClass;
                $config += $newConfig;
            }
        }

        if (!is_subclass_of($class, ConditionRuleInterface::class)) {
            throw new InvalidArgumentException("Invalid condition rule class: $class");
        }

        /** @var ConditionRuleInterface $rule */
        $rule = Craft::createObject($class);
        $rule->setAttributes($config);
        return $rule;
    }
}
