<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;

class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Laravel/PHP does not populate multipart form-data fields for native PUT requests.
        // Hydrate them manually so validation and controller receive the data.
        $this->hydrateMultipartPut();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'phone' => ['required', 'string', 'max:30'],

            'email' => ['required', 'email', 'max:255'],

            'address' => ['required', 'string', 'max:500'],

            'tagline' => ['sometimes', 'nullable', 'string', 'max:255'],

            'about' => ['sometimes', 'nullable', 'string', 'max:3000'],

            'logo' => [
                'sometimes',
                'nullable',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:4096'
            ],
        ];
    }


    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422));
    }


    private function hydrateMultipartPut(): void
    {
        if (! in_array($this->getRealMethod(), ['PUT', 'PATCH'], true)) {
            return;
        }

        $contentType = (string) $this->header('Content-Type', '');

        if (! str_contains($contentType, 'multipart/form-data')) {
            return;
        }


        // Already parsed by Laravel
        if ($this->request->count() > 0 || $this->files->count() > 0) {
            return;
        }


        if (! preg_match('/boundary=(.*)$/i', $contentType, $matches)) {
            return;
        }


        $boundary = trim($matches[1], " \t\"'");

        $raw = $this->getContent();

        if ($raw === '' || $raw === false) {
            return;
        }


        $input = [];
        $files = [];


        foreach (array_slice(explode('--' . $boundary, $raw), 1) as $part) {

            if ($part === '--' || trim($part) === '--') {
                break;
            }


            $part = ltrim($part, "\r\n");


            if ($part === '' || ! str_contains($part, "\r\n\r\n")) {
                continue;
            }


            [$headersPart, $body] = explode("\r\n\r\n", $part, 2);


            $body = preg_replace('/\r\n$/', '', $body) ?? $body;


            if (! preg_match('/name="([^"]+)"/', $headersPart, $nameMatch)) {
                continue;
            }


            $name = $nameMatch[1];


            // File field
            if (preg_match('/filename="([^"]*)"/', $headersPart, $fileMatch)) {

                $filename = $fileMatch[1];


                if ($filename === '') {
                    continue;
                }


                $tmpPath = tempnam(
                    sys_get_temp_dir(),
                    'company_profile_put_'
                );


                file_put_contents($tmpPath, $body);


                $mime = 'application/octet-stream';


                if (preg_match('/Content-Type:\s*(\S+)/i', $headersPart, $mimeMatch)) {
                    $mime = $mimeMatch[1];
                }


                $files[$name] = new UploadedFile(
                    $tmpPath,
                    $filename,
                    $mime,
                    null,
                    true
                );


            } else {

                // Normal form field
                $input[$name] = $body;

            }
        }


        if ($input !== []) {
            $this->merge($input);
        }


        foreach ($files as $key => $file) {
            $this->files->set($key, $file);
        }
    }
}
