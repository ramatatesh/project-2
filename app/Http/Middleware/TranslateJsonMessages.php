<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TranslateJsonMessages
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $contentType = (string) $response->headers->get('Content-Type', '');
        if (! str_contains($contentType, 'json')) {
            return $response;
        }

        $decoded = json_decode((string) $response->getContent(), true);
        if (! is_array($decoded)) {
            return $response;
        }

        $changed = false;

        if (isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== '') {
            $decoded['message'] = $this->translateLine($decoded['message']);
            $changed = true;
        }

        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $decoded['errors'] = $this->translateErrors($decoded['errors']);
            $changed = true;
        }

        if ($changed) {
            if ($response instanceof JsonResponse) {
                $response->setData($decoded);
            } else {
                $response->setContent(json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $errors
     * @return array<string, mixed>
     */
    private function translateErrors(array $errors): array
    {
        foreach ($errors as $field => $messages) {
            if (is_array($messages)) {
                $errors[$field] = array_map(
                    fn ($message) => is_string($message) ? $this->translateLine($message) : $message,
                    $messages
                );
            } elseif (is_string($messages)) {
                $errors[$field] = $this->translateLine($messages);
            }
        }

        return $errors;
    }

    private function translateLine(string $message): string
    {
        if (mb_strlen($message) > 400) {
            return $message;
        }

        return __($message);
    }
}
