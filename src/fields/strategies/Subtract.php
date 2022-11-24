<?php

namespace venveo\bulkedit\fields\strategies;

use Craft;
use venveo\bulkedit\base\FieldStrategyInterface;

class Subtract implements FieldStrategyInterface
{
    public static function displayName(): string
    {
        return Craft::t('bulk-edit', 'Subtract');
    }
}
