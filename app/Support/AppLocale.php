<?php

namespace App\Support;

class AppLocale
{
    public const ARABIC = 'ar';

    public const ENGLISH = 'en';

    /** @var list<string> */
    public const SUPPORTED = [self::ARABIC, self::ENGLISH];

    public static function parse(?string $raw): string
    {
        $fallback = config('app.locale', self::ENGLISH);

        if (! is_string($raw) || trim($raw) === '') {
            return $fallback;
        }

        foreach (preg_split('/\s*,\s*/', $raw) as $part) {
            $tag = strtolower(trim(explode(';', $part, 2)[0]));
            $code = substr($tag, 0, 2);

            if (in_array($code, self::SUPPORTED, true)) {
                return $code;
            }
        }

        return $fallback;
    }

    public static function fromRequest(\Illuminate\Http\Request $request): string
    {
        return self::parse(
            $request->header('X-Locale')
            ?? $request->query('lang')
            ?? $request->header('Accept-Language')
        );
    }

    public static function dir(string $locale): string
    {
        return $locale === self::ARABIC ? 'rtl' : 'ltr';
    }
}
