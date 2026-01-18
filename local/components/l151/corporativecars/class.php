<?php

namespace l151\Component;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use \Exception;

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();

class CorporativeComponent extends \CBitrixComponent
{
    protected \Bitrix\Iblock\Elements\EO_ElementEmploess $emploess;
    protected ?\Bitrix\Iblock\Elements\EO_ElementCars_Collection $allowedCars;
    protected const CAR_BOOKING_TABLE_NAME = 'cars_booking';
    protected const CAR_BOOKING_HL_ID = 7;

    function executeComponent()
    {
        try {
            $this->init();
            vdump($this->arResult);
        } catch (\Exception $e) {
            vdump($e->getMessage());
        }
    }

    /**
     * @throws Exception
     */
    public function init(): void
    {
        $this->arResult['ALLOWED_CARS_CLASSES'] = $this->getAllowedClassesCars();
        $this->arResult['ALLOWED_CARS_IDS'] = $this->getAllowedCarsIds();
        $this->arResult['AVAILABLE_CARS'] = $this->getAvailableCarsData();
    }

    /**
     * @throws Exception
     */
    protected function getEmploess(): \Bitrix\Iblock\Elements\EO_ElementEmploess
    {
        if(isset($this->emploess)) {
            return $this->emploess;
        }

        $emploess = \Bitrix\Iblock\Elements\ElementEmploessTable::query()
            ->where('USER.VALUE', '=', $this->arParams['USER_ID'])
            ->setSelect(['NAME', 'POSITION.ELEMENT.CAR_CLASS.VALUE'])
            ->fetchObject();

        if(is_null($emploess)) {
            throw new Exception('Сотрудник не найден');
        }

        return $this->emploess = $emploess;
    }

    /**
     * @throws Exception
     */
    protected function getAllowedClassesCars(): array
    {
        $carClasses = [];
        foreach ($this->getEmploess()?->getPosition()?->getElement()?->getCarClass() as $class) {
            $carClasses[] = $class->getValue();
        }

        return $carClasses;
    }

    /**
     * @throws Exception
     */
    protected function getAllowedCars(): ?\Bitrix\Iblock\Elements\EO_ElementCars_Collection
    {
        if(isset($this->allowedCars)) {
            return $this->allowedCars;
        }

        return $this->allowedCars = \Bitrix\Iblock\Elements\ElementCarsTable::query()
            ->whereIn('CLASS.VALUE', $this->getAllowedClassesCars())
            ->setSelect(['ID', 'NAME', 'DRIVER.ELEMENT.NAME', 'CLASS.VALUE'])
            ->fetchCollection();
    }

    /**
     * @throws Exception
     */
    protected function getAllowedCarsIds(): array
    {
        $carsIds = [];

        foreach ($this->getAllowedCars() as $car) {
            $carsIds[] = $car->getId();
        }

        return $carsIds;
    }


    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws Exception
     */
    protected function getAbsentCarsIdsByDate(): array
    {
        $hlblock = HighloadBlockTable::query()
            ->where('TABLE_NAME', '=', self::CAR_BOOKING_TABLE_NAME)
            ->fetch();

        //fix для тестового сервера
        if(empty($hlblock['ID'])) {
            $hlblock = [
                'ID' => self::CAR_BOOKING_HL_ID,
                'NAME' => 'CarsBooking',
                'TABLE_NAME' => 'cars_booking',
            ];
        }

        $entity = HighloadBlockTable::compileEntity($hlblock);
        $entityDataClass = $entity->getDataClass();

        $dateFrom = $this->getDateFrom();
        $dateTo = $this->getDateTo();

        if ($dateFrom > $dateTo) {
            throw new Exception('Дата начала не может быть позже даты окончания');
        }

        $reservedItem = $entityDataClass::query()
            ->where('UF_ACTIVE', '=', true)
            ->where('UF_DATE_FROM', '<=', $dateTo)
            ->where('UF_DATE_TO', '>=', $dateFrom)
            ->whereIn('UF_CAR_IB', $this->getAllowedCarsIds())
            ->setSelect(['UF_CAR_IB'])
            ->fetchAll();

        $reservedCarsIds = [];
        foreach ($reservedItem as $item) {
            $reservedCarsIds[] = $item['UF_CAR_IB'];
        }

        return $reservedCarsIds;
    }

    protected function getAvailableCarsIds(): array
    {
        return array_diff($this->getAllowedCarsIds(), $this->getAbsentCarsIdsByDate());
    }

    /**
     * @throws Exception
     */
    protected function getAvailableCarsData(): array
    {
        $carsData = [];

        foreach ($this->getAvailableCarsIds() as $carId) {
            $obCar = $this->getAllowedCars()->getByPrimary($carId);

            $carsData[] = [
                'ID' => $obCar->getId(),
                'NAME' => $obCar->getName(),
                'DRIVER' => $obCar->getDriver()?->getElement()?->getName(),
                'CLASS' => $obCar->getClass()?->getValue(),
            ];
        }

        return $carsData;
    }


    /**
     * @throws Exception
     */
    protected function getDateFrom(): DateTime
    {
        return $this->createDateFromString($this->request->get('from'), 'from');
    }

    /**
     * @throws Exception
     */
    protected function getDateTo(): DateTime
    {
        return $this->createDateFromString($this->request->get('to'), 'to');
    }

    /**
     * @throws Exception
     */
    private function createDateFromString(?string $dateString, string $fieldName): DateTime
    {
        if (!$dateString) {
            throw new Exception(sprintf('Дата "%s" не указана', $fieldName));
        }

        try {
            return new DateTime($dateString);
        } catch (\Exception $e) {
            throw new Exception(
                sprintf('Неверный формат даты "%s". Ожидается: %s', $fieldName, DateTime::getFormat()),
                0,
                $e
            );
        }
    }


    /**
     * @throws LoaderException
     * @throws Exception
     */
    public function onPrepareComponentParams($arParams): array
    {
        if(!\Bitrix\Main\Loader::includeModule('iblock')) {
            throw new Exception('iblock module is not installed');
        }
        if(!\Bitrix\Main\Loader::includeModule('highloadblock')) {
            throw new Exception('highloadblock module is not installed');
        }

        if((int) $arParams['USER_ID'] <= 0) {
            throw new Exception('USER_ID должен быть целым числом');
        }

        return parent::onPrepareComponentParams($arParams);
    }
}