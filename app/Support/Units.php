<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Stock units — Site Settings › Localization. Every stock quantity is stored
 * in the Default Unit; a converted unit of the same kind can be shown next
 * to it ("5 m · 5.47 yd"). Display only: nothing stored is ever converted.
 * Each unit: [label, short, kind, factor to the kind's base]; box and set
 * have no kind, so they never convert.
 */
class Units
{
    public const UNITS = [
        'pcs'   => ['Pieces', 'pcs', 'count', 1.0],
        'meter' => ['Meter', 'm', 'length', 1.0],
        'yard'  => ['Yard', 'yd', 'length', 0.9144],
        'feet'  => ['Feet', 'ft', 'length', 0.3048],
        'inch'  => ['Inch', 'in', 'length', 0.0254],
        'cm'    => ['Centimeter', 'cm', 'length', 0.01],
        'kg'    => ['Kilogram', 'kg', 'weight', 1.0],
        'gram'  => ['Gram', 'g', 'weight', 0.001],
        'liter' => ['Liter', 'L', 'volume', 1.0],
        'ml'    => ['Milliliter', 'ml', 'volume', 0.001],
        'dozen' => ['Dozen', 'dozen', 'count', 12.0],
        'pair'  => ['Pair', 'pair', 'count', 2.0],
        'box'   => ['Box', 'box', null, null],
        'set'   => ['Set', 'set', null, null],
    ];

    public const DEFAULT = 'meter';

    /** @return array<string, string> key => "Meter (m)" */
    public static function options(): array
    {
        return array_map(fn ($u) => $u[0] === $u[1] || strtolower($u[0]) === $u[1] ? $u[0] : "{$u[0]} ({$u[1]})", self::UNITS);
    }

    /** Other units of the same kind — what $unit can be shown in. */
    public static function sameKind(string $unit): array
    {
        $kind = self::UNITS[$unit][2] ?? null;

        return $kind === null ? [] : array_keys(array_filter(self::UNITS, fn ($u, $k) => $u[2] === $kind && $k !== $unit, ARRAY_FILTER_USE_BOTH));
    }

    public static function convert(float $value, string $from, string $to): ?float
    {
        [, , $fromKind, $fromFactor] = self::UNITS[$from] ?? [null, null, null, null];
        [, , $toKind, $toFactor] = self::UNITS[$to] ?? [null, null, null, null];

        return $fromKind !== null && $fromKind === $toKind ? $value * $fromFactor / $toFactor : null;
    }

    public static function short(string $unit): string
    {
        return self::UNITS[$unit][1] ?? $unit;
    }

    /** "5", "5.47", "0.125" — at most $decimals places, trailing zeros trimmed. */
    public static function number(float $value, int $decimals = 3): string
    {
        $s = rtrim(rtrim(number_format($value, $decimals, '.', ','), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /**
     * The stock unit, the converted unit (null when off or not convertible),
     * and the factors the browser needs to switch units — read once per request.
     *
     * @return array{unit: string, short: string, converted: ?string, units: array<string, array{0: string, 1: float}>}
     */
    public static function config(): array
    {
        return once(function () {
            $unit = (string) Setting::get('default_unit', self::DEFAULT, 'localization');
            $unit = isset(self::UNITS[$unit]) ? $unit : self::DEFAULT;

            $converted = (bool) Setting::get('show_converted_unit', '0', 'localization')
                ? (string) Setting::get('converted_unit', '', 'localization')
                : '';
            $converted = in_array($converted, self::sameKind($unit), true) ? $converted : null;

            $units = [];
            foreach ([$unit, ...self::sameKind($unit)] as $k) {
                $units[$k] = [self::UNITS[$k][1], (float) self::UNITS[$k][3]];
            }

            return ['unit' => $unit, 'short' => self::short($unit), 'converted' => $converted, 'units' => $units];
        });
    }

    /**
     * wire:key for one <x-qty>: its place on the page + its value. A Livewire
     * re-render keeps Alpine state, so a changed quantity must come back as a
     * new element; the running number keeps equal values in one row distinct.
     */
    public static function domKey(float $value, bool $signed): string
    {
        static $n = 0;

        return 'qty-' . (++$n) . '-' . crc32($value . ($signed ? '+' : ''));
    }

    /** "5 m", or "5 m · 5.47 yd" when a converted unit is on. */
    public static function text(float $value): string
    {
        $c = self::config();
        $text = self::number($value) . ' ' . $c['short'];

        if ($c['converted'] !== null) {
            $text .= ' · ' . self::number(self::convert($value, $c['unit'], $c['converted']), 2) . ' ' . self::short($c['converted']);
        }

        return $text;
    }
}
