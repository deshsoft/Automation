<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * A permanent publishing error (bad token, rejected media, policy violation...).
 *
 * Transient errors (timeouts, 5xx, rate limits) are thrown as RequestException
 * or ConnectionException instead so the queue retries them.
 */
class PublishingException extends Exception
{
    /**
     * Throw a retryable exception for server errors, or a permanent one for client errors.
     *
     * @throws PublishingException
     * @throws RequestException
     */
    public static function throwUnlessSuccessful(Response $response, string $context): Response
    {
        if ($response->successful()) {
            return $response;
        }

        if ($response->serverError() || $response->status() === 429) {
            $response->throw();
        }

        throw new self($context.': '.self::extractMessage($response));
    }

    public static function extractMessage(Response $response): string
    {
        $message = $response->json('error.message')
            ?? $response->json('error.errors.0.message')
            ?? $response->json('error_description')
            ?? $response->json('error');

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return 'HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300);
    }
}
