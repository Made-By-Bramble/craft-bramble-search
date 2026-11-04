<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\helpers;

final class NumericTerm
{
    private const METRIC_SYMBOLS = [
        'm', 'm2', 'm3', 'g', 's', 'a', 'k', 'mol', 'cd', 'rad', 'sr', 'hz',
        'n', 'pa', 'j', 'w', 'c', 'v', 'f', 'ohm', 'wb', 't', 'h', 'lm', 'lx',
        'bq', 'gy', 'sv', 'kat', 'l', 'ev', 'da', 'u', 'wh', 'ah', 'bar', 'degc', 'b', 'np',
        'gf', 'cal', 'dyn', 'erg', 'p', 'st', 'oe', 'mx', 'ph', 'sb', 'gal', 'ba', 'torr', 'ci', 'rem', 'r',
    ];

    private const PREFIX_SYMBOLS = ['q', 'r', 'y', 'z', 'a', 'f', 'p', 'n', 'u', 'm', 'c', 'd', 'da', 'h', 'k', 'g', 't', 'e'];

    private const PREFIX_NAMES = [
        'quecto', 'ronto', 'yocto', 'zepto', 'atto', 'femto', 'pico', 'nano',
        'micro', 'milli', 'centi', 'deci', 'deca', 'deka', 'hecto', 'kilo',
        'mega', 'giga', 'tera', 'peta', 'exa', 'zetta', 'yotta', 'ronna', 'quetta',
    ];

    private const METRIC_NAMES = [
        'metre', 'meter', 'gram', 'second', 'ampere', 'kelvin', 'mole', 'candela',
        'radian', 'steradian', 'hertz', 'newton', 'pascal', 'joule', 'watt',
        'coulomb', 'volt', 'farad', 'ohm', 'siemens', 'weber', 'tesla', 'henry',
        'lumen', 'lux', 'becquerel', 'gray', 'sievert', 'katal', 'litre', 'liter', 'gramme',
        'tonne', 'electronvolt', 'dalton',
    ];

    public const UNITS = [
        ...self::METRIC_SYMBOLS,
        'kg', 'km', 'cm', 'mm', 'mg', 'mcg', 'ug', 'ml', 'cl', 'dl', 'hl',
        'ha', 'are', 'ares', 'hectare', 'hectares', 'cc', 'sqm', 'cbm', 'au', 'astronomicalunit', 'astronomicalunits',
        'min', 'mins', 'minute', 'minutes', 'hr', 'hrs', 'hour', 'hours',
        'd', 'day', 'days', 'wk', 'week', 'weeks', 'yr', 'year', 'years',
        'deg', 'degree', 'degrees', 'degc', 'degf', 'degr', 'celsius', 'fahrenheit', 'rankine',
        'in', 'inch', 'inches', 'ft', 'foot', 'feet', 'yd', 'yard', 'yards',
        'mi', 'mile', 'miles', 'mil', 'mils', 'thou', 'hand', 'hands',
        'fathom', 'fathoms', 'rd', 'rod', 'rods', 'pole', 'poles', 'perch', 'perches',
        'ch', 'chain', 'chains', 'link', 'links', 'fur', 'furlong', 'furlongs',
        'nmi', 'nauticalmile', 'nauticalmiles', 'cable', 'cables', 'point', 'points', 'pc', 'pica', 'picas',
        'in2', 'in3', 'ft2', 'ft3', 'yd2', 'yd3', 'mi2', 'mi3',
        'sqin', 'sqft', 'sqyd', 'sqmi', 'cuin', 'cuft', 'cuyd',
        'ac', 'acre', 'acres', 'rood', 'roods',
        'oz', 'ounce', 'ounces', 'lb', 'lbs', 'lbm', 'pound', 'pounds', 'st', 'stone', 'stones',
        'gr', 'grain', 'grains', 'dr', 'dram', 'drams', 'dwt', 'pennyweight', 'pennyweights',
        'ozt', 'troyounce', 'troyounces', 'lbt', 'troypound', 'troypounds', 'scruple', 'scruples',
        'qr', 'quarter', 'quarters', 'cwt', 'hundredweight', 'hundredweights', 'ton', 'tons',
        'floz', 'fluidounce', 'fluidounces', 'gi', 'gill', 'gills',
        'pt', 'pts', 'pint', 'pints', 'qt', 'qts', 'quart', 'quarts',
        'gal', 'gallon', 'gallons', 'bbl', 'barrel', 'barrels',
        'cup', 'cups', 'tsp', 'teaspoon', 'teaspoons', 'tbsp', 'tablespoon', 'tablespoons',
        'minim', 'minims', 'fldr', 'fluiddram', 'fluiddrams', 'boardfoot', 'boardfeet', 'acrefoot', 'acrefeet',
        'pk', 'peck', 'pecks', 'bu', 'bushel', 'bushels', 'cord', 'cords',
        'lbf', 'ozf', 'pdl', 'poundal', 'poundals', 'slug', 'slugs',
        'psi', 'psia', 'psig', 'psf', 'inhg', 'inwc', 'inh2o', 'ftlb', 'lbft',
        'hp', 'horsepower', 'btu', 'therm', 'therms', 'quad', 'quads', 'tonneforce',
        'mph', 'kph', 'kmh', 'fps', 'fpm', 'kn', 'kt', 'knot', 'knots',
        'cal', 'calorie', 'calories', 'erg', 'ergs', 'dyn', 'dyne', 'dynes',
        'poise', 'stokes', 'angstrom', 'angstroms', 'micron', 'microns',
        'db', 'decibel', 'decibels', 'neper', 'nepers', 'bel', 'bels', 'rpm', 'rps', 'iu',
    ];

    private static function units(): array
    {
        static $units;
        if ($units !== null) {
            return $units;
        }
        $units = array_fill_keys(self::UNITS, true);
        foreach (self::METRIC_SYMBOLS as $symbol) {
            foreach (self::PREFIX_SYMBOLS as $prefix) {
                $units[$prefix . $symbol] = true;
            }
        }
        foreach (self::METRIC_NAMES as $name) {
            $plural = ['henry' => 'henries', 'hertz' => 'hertz', 'siemens' => 'siemens', 'lux' => 'lux'][$name] ?? $name . 's';
            foreach (['', ...self::PREFIX_NAMES] as $prefix) {
                $units[$prefix . $name] = true;
                $units[$prefix . $plural] = true;
                if ($name === 'metre' || $name === 'meter') {
                    foreach (['square', 'cubic'] as $power) {
                        $units[$power . $prefix . $name] = true;
                        $units[$power . $prefix . $plural] = true;
                    }
                }
            }
        }
        foreach (['inch' => 'inches', 'foot' => 'feet', 'yard' => 'yards', 'mile' => 'miles', 'rod' => 'rods', 'pole' => 'poles', 'perch' => 'perches', 'chain' => 'chains', 'link' => 'links', 'furlong' => 'furlongs', 'fathom' => 'fathoms', 'nauticalmile' => 'nauticalmiles', 'cable' => 'cables', 'hand' => 'hands', 'mil' => 'mils', 'thou' => 'thou'] as $name => $plural) {
            foreach (['square', 'cubic'] as $power) {
                $units[$power . $name] = true;
                $units[$power . $plural] = true;
            }
        }

        return $units;
    }

    public static function normalizeMeasurements(string $text): string
    {
        $text = strip_tags(preg_replace('/<br\s*\/?>|<\/\w+>/i', ' ', $text));
        $text = preg_replace_callback('/&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]+);/i', static function(array $entity): string {
            $decoded = html_entity_decode($entity[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return preg_match('/\A[\p{Nd}\p{Z}\sµμΩωΩÅå²³°]+\z/uD', $decoded) === 1 ? $decoded : $entity[0];
        }, $text);

        return preg_replace_callback('/[^\s\p{Z}]+/u', static function(array $match): string {
            $parts = self::measurementParts($match[0]);
            if ($parts === null) {
                return $match[0];
            }
            $value = self::classify($parts[2]);
            if ($value !== null) {
                return $parts[1] . $value['digits'] . $value['unit'] . $parts[3];
            }
            $unit = self::classify('0' . $parts[2]);
            if ($unit !== null && $unit['unit'] !== '') {
                return $parts[1] . $unit['unit'] . $parts[3];
            }

            return $match[0];
        }, $text);
    }

    public static function classify(string $term, bool $inProgress = false): ?array
    {
        $value = self::measurementParts($term);
        if ($value === null || preg_match('/\A(\p{Nd}+)([\p{L}\p{N}°]*)\z/uD', $value[2], $parts) !== 1) {
            return null;
        }
        $digits = preg_replace_callback('/\p{Nd}/u', static fn(array $match): string => (string)\IntlChar::digit($match[0]), $parts[1]);
        $unit = strtolower(strtr($parts[2], ['µ' => 'u', 'μ' => 'u', 'Ω' => 'ohm', 'ω' => 'ohm', 'Ω' => 'ohm', 'Å' => 'angstrom', 'å' => 'angstrom', '²' => '2', '³' => '3', '°' => 'deg']));
        $units = self::units();
        if ($unit === '' || isset($units[$unit])) {
            return ['digits' => $digits, 'unit' => $unit, 'incomplete' => false];
        }
        if ($inProgress) {
            foreach (array_keys($units) as $candidate) {
                if (str_starts_with($candidate, $unit)) {
                    return ['digits' => $digits, 'unit' => $unit, 'incomplete' => true];
                }
            }
        }

        return null;
    }

    private static function measurementParts(string $term): ?array
    {
        return preg_match('/\A([\p{Ps}\p{Pi}"\x{0027}]*)([\p{L}\p{N}°]+)([\p{Pe}\p{Pf}"\x{0027},;:!?。.…،؛؟，；：？！．、﹐﹑﹒﹔﹕﹖﹗]*)\z/uD', $term, $parts) === 1 ? $parts : null;
    }

    public static function accepts(array $input, string $candidate, bool $inProgress, bool $exact = false): bool
    {
        $value = self::classify($candidate);
        if ($value === null) {
            return false;
        }
        if ($exact) {
            return !$input['incomplete'] && $input['digits'] === $value['digits'] && $input['unit'] === $value['unit'];
        }
        if ($input['incomplete']) {
            return $input['digits'] === $value['digits'] && str_starts_with($value['unit'], $input['unit']);
        }
        if ($input['unit'] !== '') {
            return $input['digits'] === $value['digits'] && $input['unit'] === $value['unit'];
        }

        return $inProgress ? str_starts_with($value['digits'], $input['digits']) : $value['digits'] === $input['digits'];
    }

    public static function directCandidates(array $input, bool $exact): array
    {
        if ($exact || (!$input['incomplete'] && $input['unit'] !== '')) {
            return [$input['digits'] . $input['unit']];
        }
        $terms = $input['incomplete'] ? [] : [$input['digits']];
        foreach (array_keys(self::units()) as $unit) {
            if (!$input['incomplete'] || str_starts_with($unit, $input['unit'])) {
                $terms[] = $input['digits'] . $unit;
            }
        }

        return $terms;
    }

    public static function isCompound(string $term): bool
    {
        return self::classify($term) === null && preg_match('/\p{Nd}/u', $term) === 1 && preg_match('/[^\p{L}\p{N}\s]/u', $term) === 1;
    }
}
