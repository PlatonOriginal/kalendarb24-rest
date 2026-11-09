<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Vacation\Settings;
use Kb24Rest\Vacation\VacationError;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    private function settings(FakePortal $http): Settings
    {
        return new Settings(new RestClient($http, new OAuthClient($http, 'id', 'secret'), new MemoryPortalStore()));
    }

    private function portal(string $domain = 'test.bitrix24.ru'): Portal
    {
        return new Portal(1, 'm1', $domain, 'https://' . $domain . '/rest/', 'a', 'r', time() + 3600, 1);
    }

    /** @return array<string, array{?string, string, int, string}> */
    public static function countries(): array
    {
        return [
            'РФ по тарифу' => ['ru_std', 'test.bitrix24.ru', 28, 'ст. 115 ТК РФ'],
            'РБ по тарифу' => ['by_pro100', 'test.bitrix24.ru', 24, 'ст. 155 ТК РБ'],
            'РБ по адресу, тариф не отдан' => [null, 'test.bitrix24.by', 24, 'ст. 155 ТК РБ'],
            'другая страна' => ['kz_basic', 'test.bitrix24.kz', 20, ''],
            'страна неизвестна' => [null, 'crm.example.com', 20, ''],
        ];
    }

    /** @dataProvider countries */
    public function testAnnualDaysMinimumDependsOnCountry(?string $license, string $domain, int $min, string $article): void
    {
        $http = new FakePortal([], []);
        $http->license = $license;
        $settings = $this->settings($http);
        $portal = $this->portal($domain);

        self::assertSame($min, $settings->minAnnualDays($portal));
        self::assertSame($article, $settings->annualDaysArticle($portal));

        $settings->setAnnualDays($portal, $min);
        self::assertSame($min, $this->settings($http)->annualDays($portal));

        try
        {
            $settings->setAnnualDays($portal, $min - 1);
            self::fail('Сохранил меньше минимума');
        }
        catch (VacationError $e)
        {
            self::assertSame('Ежегодный отпуск: от ' . $min . ' до 100 календарных дней', $e->getMessage());
        }
    }

    public function testStoredValueBelowMinimumIsRaised(): void
    {
        $http = new FakePortal([], []);
        $http->options = ['annual_days' => '22'];

        self::assertSame(28, $this->settings($http)->annualDays($this->portal()));
        $http->license = 'by_pro100';
        self::assertSame(24, $this->settings($http)->annualDays($this->portal()));
        $http->license = 'kz_basic';
        self::assertSame(22, $this->settings($http)->annualDays($this->portal()));
    }
}
