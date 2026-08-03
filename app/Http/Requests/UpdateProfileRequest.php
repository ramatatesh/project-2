<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // PHP does not populate $_POST/$_FILES for native HTTP PUT multipart bodies.
        $this->hydrateMultipartPut();
    }

    public function rules(): array
    {
        return [
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'residence' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile_image' => ['sometimes', 'nullable', 'file', 'image', 'max:4096'],
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

        // Already populated (e.g. tests / method-spoofed POST) — nothing to do.
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

        foreach (array_slice(explode('--'.$boundary, $raw), 1) as $part) {
            if ($part === '--' || $part === "--\r\n" || trim($part) === '--') {
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

            if (preg_match('/filename="([^"]*)"/', $headersPart, $fileMatch)) {
                $filename = $fileMatch[1];
                if ($filename === '') {
                    continue;
                }

                $tmpPath = tempnam(sys_get_temp_dir(), 'profile_put_');
                file_put_contents($tmpPath, $body);

                $mime = 'application/octet-stream';
                if (preg_match('/Content-Type:\s*(\S+)/i', $headersPart, $mimeMatch)) {
                    $mime = $mimeMatch[1];
                }

                $files[$name] = new UploadedFile($tmpPath, $filename, $mime, null, true);
            } else {
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
