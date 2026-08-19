<?php

namespace App\Support;

use Illuminate\Contracts\Validation\Validator;

class ValidationMessages
{
    public static function fromValidator(Validator $validator, ?string $fallback = null): string
    {
        $passwordErrors = $validator->errors()->get('password', []);

        if (count($passwordErrors) > 1) {
            return self::join(array_map('strval', $passwordErrors));
        }

        $messages = collect($validator->errors()->all())->unique()->values()->all();

        if ($messages === []) {
            return __($fallback ?? 'Please check the input and try again.');
        }

        return self::join($messages);
    }

    /**
     * @param  list<string>  $messages
     */
    public static function join(array $messages): string
    {
        return implode(' ', array_map(
            fn (string $message) => __($message),
            $messages
        ));
    }
}
