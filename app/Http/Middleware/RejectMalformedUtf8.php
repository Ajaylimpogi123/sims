<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * API guard (api middleware group): any query/body value or key that isn't
 * valid UTF-8 is answered with a normal 422 before it reaches validation,
 * MySQL ("Incorrect string value" -> 500) or json_encode (which can't
 * render the bad bytes in an error message). Uploaded files are not
 * inspected; their client file names are never stored as-is.
 */
class RejectMalformedUtf8
{
    public function handle(Request $request, Closure $next): Response
    {
        $errors = [];

        $this->inspect(array_merge($request->query->all(), $request->request->all()), '', $errors);

        if ($request->isJson()) {
            // An undecodable JSON body (bad UTF-8, truncated, not JSON) would
            // otherwise read as an empty body and pass "nullable" rules.
            if (! self::isDecodableJson($request->getContent())) {
                throw ValidationException::withMessages(['input' => ['The request body is not valid JSON.']]);
            }

            $this->inspect($request->json()->all(), '', $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $next($request);
    }

    /**
     * A blank body is fine (no fields); anything else must decode the way
     * Request::json() decodes it.
     */
    private static function isDecodableJson(string $content): bool
    {
        if (trim($content) === '') {
            return true;
        }

        json_decode($content, true);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  array<string, array<int, string>>  $errors
     */
    private function inspect(array $input, string $prefix, array &$errors): void
    {
        foreach ($input as $key => $value) {
            if (is_string($key) && ! mb_check_encoding($key, 'UTF-8')) {
                $errors['input'] = ['The request contains a field name that is not valid UTF-8 text.'];

                continue;
            }

            $field = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $this->inspect($value, $field, $errors);
            } elseif (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $label = str_replace('_', ' ', Str::snake(str_replace('.', ' ', $field)));
                $errors[$field] = ["The {$label} field must be valid UTF-8 text."];
            }
        }
    }
}
