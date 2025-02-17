<?php

namespace GitHub;

use Bitrix\Catalog\PriceTable;
use Bitrix\Main\ORM\Query\Query;

/**
 * Определяем код продукта по коду цены
 */

class ProductByPrice
{
    protected static $memExt = [];

    public static function getProductIdByPrice($priceId = null)
    {
        $result = null;

        if ($priceId) {
            $priceInfo = static::$memExt[$priceId] ?? null;
            if ($priceInfo) {
                $result = $priceInfo['PRODUCT_ID'];
            }

            if (!$result) {
                $info = static::loadInfo($priceId);
                $result = $info['PRODUCT_ID'];
            }
        }

        return $result;
    }

    protected static function loadInfo($priceId = null)
    {
        $qRes = (new Query(PriceTable::getEntity()))
            ->setSelect(['PRODUCT_ID', 'ID', 'CATALOG_GROUP_ID', 'PRICE'])
            ->where('ID', $priceId)
            ->setLimit(1)
            ->exec();

        if ($qArr = $qRes->fetch()) {
            self::$memExt[$priceId] = $qArr;
        }

        return self::$memExt[$priceId] ?? null;
    }
}
