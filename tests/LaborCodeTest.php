<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Domain\LaborCode;
use PHPUnit\Framework\TestCase;

final class LaborCodeTest extends TestCase
{
    public function testArticleDependsOnCountry(): void
    {
        self::assertSame('ст. 125 ТК РФ', LaborCode::longPartArticle('ru'));
        self::assertSame('ч. 1 ст. 174 ТК РБ', LaborCode::longPartArticle('by'));
        self::assertSame('', LaborCode::longPartArticle('kz'));
        self::assertSame('', LaborCode::longPartArticle(''));
    }

    public function testCountryFromLicensePrefixFirst(): void
    {
        self::assertSame('by', LaborCode::country('by_pro100', 'company.bitrix24.ru'));
        self::assertSame('ru', LaborCode::country('ru_std', 'crm.example.com'));
        // Казахстанский тариф на адресе .ru: страна по тарифу, ссылки на статью нет
        self::assertSame('kz', LaborCode::country('kz_basic', 'company.bitrix24.ru'));
    }

    public function testDomainZoneWhenLicenseUnknown(): void
    {
        self::assertSame('by', LaborCode::country('', 'company.bitrix24.by'));
        self::assertSame('ru', LaborCode::country('ru_selfhosted', 'Company.Bitrix24.RU'));
        self::assertSame('', LaborCode::country('', 'crm.example.by'));
        self::assertSame('com', LaborCode::country('project', 'company.bitrix24.com'));
    }
}
