<?php

namespace GitHub;

use Bitrix\Catalog\Model\Event;
use Bitrix\Catalog\Model\Price;
use Bitrix\Catalog\PriceTable;
use Bitrix\Main\EventManager;
use Bitrix\Main\ORM\Query\Query;

class SquareMettersPrices
{
    protected static $eventDeployed = false;
    protected static $eventOrder = [];

    // TODO: Код типа цены за квадратный метр
    protected static $squarePriceGroupId = 3;

    public static function onPriceEvent(Event $event): void
    {
        $productId = null;
        $priceId = null;

        if (is_numeric($event)) {
            $priceId = $event;
        } else if ($event instanceof Event) {
            $fields = $event->getParameter('fields');

            $productId = $fields['PRODUCT_ID'] ?? null;
            $priceId = $event->getParameter('id');
            
            $priceType = $fields['CATALOG_GROUP_ID'] ?? null;
            if ($priceType == static::$squarePriceGroupId) {
                return;
            }
        }

        /**
         * Ситуация: мы работаем через групповую операцию изменения цены в списке.
         * Код продукта в этом случае не приходит, приходит код цены
         */
        if (!$productId && $priceId) {
            $productId = ProductByPrice::getProductIdByPrice($priceId);
        }

        if ($productId) {
            $squarePrice = static::calculateSquarePrice($productId);

            // Решаем, что нужно.
            // Накапливаем набор для действий
            static::pushToEvent(
                $productId, static::$squarePriceGroupId,
                $squarePrice
            );
        }
    }

    protected static function calculateSquarePrice($productId)
    {
        // Расчет цены за квадратный метр
        // TODO: Здесь нужна имплементация под проект
        $squarePrice = 1;

        return $squarePrice;
    }

    protected static function pushToEvent($productId, $squarePriceGroupId, $squarePrice)
    {
        $pushEvent = false;

        /**
         * Читаем у целевого товара цену целевого типа
         * Если есть и отличается от того, что мы собираемся записать, - обновляем
         * Если нет - добавляем
         */
        $priceEntity = PriceTable::getEntity();
        $res = (new Query($priceEntity))
            ->where('PRODUCT_ID', $productId)
            ->where('CATALOG_GROUP_ID', $squarePriceGroupId)
            ->setSelect(['ID', 'PRICE'])
            ->setLimit(1)
            ->exec();

        if ($elArr = $res->fetch()) {
            // Если цена уже есть в таблице b_catalog_price
            $priceValue = $elArr['PRICE'];
            if ($priceValue != $squarePrice) {
                static::$eventOrder['UPDATE'] = static::$eventOrder['UPDATE'] ?? [];
                static::$eventOrder['UPDATE'][] = [
                    'ID' => $elArr['ID'],
                    'PRICE' => $squarePrice,
                    'PRICE_SCALE' => $squarePrice,
                ];

                $pushEvent = true;
            }

        } else {
            // Если цены в таблице b_catalog_price нет
            static::$eventOrder['ADD'] = static::$eventOrder['ADD'] ?? [];
            static::$eventOrder['ADD'][$elArr['ID']] = [
                'PRODUCT_ID' => $productId,
                'CATALOG_GROUP_ID' => $squarePriceGroupId,
                'CURRENCY' => 'RUB',
                'PRICE' => $squarePrice,
            ];

            $pushEvent = true;
        }

        if ($pushEvent && !static::$eventDeployed) {
            static::$eventDeployed = true;

            // Прямо по ходу дела подписываемся на событие,
            // когда БД будет разлочена от текущей операции с ценой
            // и можно будет накатить изменения
            $eventManager = EventManager::getInstance();
            $eventManager->addEventHandler(
                'main',
                'OnBeforeEndBufferContent',
                [__CLASS__, 'processPricesStack']
            );
        }
    }

    /**
     * Разбираем накопленный стек изменений.
     * Нас должны запускть в эпилоге.
     * @return void
     */
    public static function processPricesStack()
    {
        foreach (static::$eventOrder as $type => $records) {
            foreach ($records as $priceData) {
                if ($type === 'UPDATE') {
                    $recordId = $priceData['ID'];
                    unset($priceData['ID']);

                    $updateResult = Price::update($recordId, $priceData);
                    if (!$updateResult->isSuccess()) {
                        static::errorCatch(
                            'Price update error. Fields: ' . print_r($priceData, true),
                            $recordId
                        );
                    }

                } else {
                    $addResult = Price::add($priceData);
                    if (!$addResult->isSuccess()) {
                        static::errorCatch(
                            'Price add error. Fields: ' . print_r($priceData, true)
                        );
                    }

                }
            }
        }
    }

    protected static function errorCatch($message, $itemId = 0)
    {
        \CEventLog::Log(
            \CEventLog::SEVERITY_ERROR,
            'price_update',
            'catalog',
            $itemId,
            $message
        );
    }

}
