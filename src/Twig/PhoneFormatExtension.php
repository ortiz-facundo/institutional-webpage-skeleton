<?php

declare(strict_types=1);

namespace App\Twig;

use App\Form\DataTransformer\PhoneDataTransformer;
use Twig\Attribute\AsTwigFunction;

final class PhoneFormatExtension
{
    #[AsTwigFunction('format_phone_intl')]
    public function format(?string $phone): ?string
    {
        if ($phone === null || $phone === '') return $phone;
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        foreach (PhoneDataTransformer::COUNTRY_CODES as $country) {
            if (!str_starts_with($digits, $country)) continue;
            $rest = substr($digits, strlen($country));
            $prefix = '+' . $country;
            if ($country === '54' && str_starts_with($rest, '9') && strlen($rest) >= 11) {
                $prefix .= ' 9';
                $rest = substr($rest, 1);
            }
            if (strlen($rest) < 8) return trim($prefix . ' ' . $rest);
            $areaLength = strlen($rest) >= 10 ? strlen($rest) - 8 : 2;
            $number = substr($rest, $areaLength);
            if (strlen($number) === 8) $number = substr($number, 0, 4) . '-' . substr($number, 4);

            return $prefix . ' ' . substr($rest, 0, $areaLength) . ' ' . $number;
        }

        return $digits === '' ? $phone : '+' . $digits;
    }
}
