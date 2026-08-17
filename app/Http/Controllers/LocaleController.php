<?php

namespace App\Http\Controllers;

use App\Support\AppLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *   name="Locale",
 *   description="Language switching (Arabic / English) without reloading the app. Send X-Locale or Accept-Language on every API request."
 * )
 */
class LocaleController extends Controller
{
    /**
     * @OA\Get(
     *   path="/api/translations",
     *   summary="Get the full translation dictionary for the requested locale",
     *   description="Use this to switch UI language instantly without a page refresh. Then send X-Locale: ar|en (or Accept-Language) on subsequent API calls so `message` fields match.",
     *   tags={"Locale"},
     *   @OA\Parameter(
     *     name="lang",
     *     in="query",
     *     required=false,
     *     description="ar or en. Also accepted via X-Locale or Accept-Language headers.",
     *     @OA\Schema(type="string", enum={"ar","en"})
     *   ),
     *   @OA\Response(response=200, description="Translation dictionary")
     * )
     */
    public function translations(Request $request): JsonResponse
    {
        $locale = AppLocale::fromRequest($request);
        app()->setLocale($locale);

        return response()->json([
            'success' => true,
            'data' => [
                'locale' => $locale,
                'dir' => AppLocale::dir($locale),
                'translations' => $this->dictionary($locale),
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function dictionary(string $locale): array
    {
        $arabicPath = lang_path('ar.json');
        $arabic = is_file($arabicPath)
            ? (json_decode((string) file_get_contents($arabicPath), true) ?: [])
            : [];

        if ($locale === AppLocale::ENGLISH) {
            $keys = array_keys($arabic);

            return array_combine($keys, $keys) ?: [];
        }

        $localePath = lang_path($locale.'.json');
        if (is_file($localePath)) {
            return json_decode((string) file_get_contents($localePath), true) ?: [];
        }

        return $arabic;
    }
}
