<?php
declare(strict_types=1);

namespace Kb24Rest\Domain;

/**
 * Нормы и ссылки на трудовой кодекс страны портала. Для стран без сверенной статьи ссылки нет.
 */
final class LaborCode
{
    public const RU = 'ru';
    public const BY = 'by';

    /** Минимум ежегодного отпуска, когда страна портала неизвестна или для неё норма не сверена */
    public const DEFAULT_MIN_ANNUAL_DAYS = 20;

    /**
     * Наименьшая норма ежегодного отпуска за рабочий год.
     * РФ: 28 календарных дней, ст. 115 ТК РФ. РБ: основной отпуск не менее 24 календарных дней, ст. 155 ТК РБ.
     */
    public static function minAnnualDays(string $country): int
    {
        return match ($country)
        {
            self::RU => 28,
            self::BY => 24,
            default => self::DEFAULT_MIN_ANNUAL_DAYS,
        };
    }

    /** Статья о продолжительности ежегодного отпуска или пусто */
    public static function annualDaysArticle(string $country): string
    {
        return match ($country)
        {
            self::RU => 'ст. 115 ТК РФ',
            self::BY => 'ст. 155 ТК РБ',
            default => '',
        };
    }

    /**
     * Статья о части ежегодного отпуска не меньше 14 календарных дней.
     * РФ: ст. 125 ТК РФ. РБ: ч. 1 ст. 174 ТК РБ.
     */
    public static function longPartArticle(string $country): string
    {
        return match ($country)
        {
            self::RU => 'ст. 125 ТК РФ',
            self::BY => 'ч. 1 ст. 174 ТК РБ',
            default => '',
        };
    }

    /**
     * Страна по тарифу из app.info: LICENSE приходит с префиксом региона, например ru_std или by_pro100.
     * У коробки префикс — язык (ru_selfhosted), поэтому он ничего не говорит о стране.
     */
    public static function countryFromLicense(string $license): string
    {
        $license = strtolower(trim($license));
        if ($license === '' || str_ends_with($license, '_selfhosted') || !str_contains($license, '_'))
        {
            return '';
        }

        return (string)strstr($license, '_', true);
    }

    /** Страна по зоне адреса портала: company.bitrix24.by. Свой домен компании стране не соответствует. */
    public static function countryFromDomain(string $domain): string
    {
        $host = strtolower(trim($domain));
        if (!preg_match('/\.bitrix24\.([a-z]{2,3})$/', $host, $m))
        {
            return '';
        }

        return $m[1];
    }

    /** Страна портала: сначала по тарифу, если его нет — по зоне адреса. Пустая строка — неизвестна. */
    public static function country(string $license, string $domain): string
    {
        return self::countryFromLicense($license) ?: self::countryFromDomain($domain);
    }
}
