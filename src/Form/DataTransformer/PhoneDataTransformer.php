<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;

/** Converts a stored international digit string into country / area / subscriber fields. */
final class PhoneDataTransformer implements DataTransformerInterface
{
    public const array COUNTRY_CODES = ['598', '595', '54', '56', '55'];
    public const string DEFAULT_COUNTRY_CODE = '54';

    public function transform(mixed $value): array
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
        $country = null;
        foreach (self::COUNTRY_CODES as $code) {
            if (str_starts_with($digits, $code)) { $country = $code; break; }
        }
        if ($country === null) return ['country_code' => self::DEFAULT_COUNTRY_CODE, 'area_code' => '', 'number' => $digits];
        $rest = substr($digits, strlen($country));
        if ($country === '54' && str_starts_with($rest, '9') && strlen($rest) >= 11) $rest = substr($rest, 1);
        $areaLength = strlen($rest) >= 10 ? strlen($rest) - 8 : (strlen($rest) >= 8 ? 2 : 0);

        return [
            'country_code' => $country,
            'area_code' => substr($rest, 0, $areaLength),
            'number' => substr($rest, $areaLength),
        ];
    }

    public function reverseTransform(mixed $value): string
    {
        if (!is_array($value)) return '';
        $country = (string) ($value['country_code'] ?? self::DEFAULT_COUNTRY_CODE);
        $area = preg_replace('/\D+/', '', (string) ($value['area_code'] ?? '')) ?? '';
        $number = preg_replace('/\D+/', '', (string) ($value['number'] ?? '')) ?? '';
        $digits = $area . $number;
        if ($digits === '') return '';
        if ($country === '54' && !str_starts_with($digits, '9') && strlen($digits) >= 10) $digits = '9' . $digits;

        return $country . $digits;
    }
}
