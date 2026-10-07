<?php

namespace HarmonWeb\Bachs;

/**
 * Raised for any failed Bachs API call (transport error or non-2xx response).
 *
 * Mirrors the flat error body returned by the API:
 * {"detail": "...", "error_code": "...", "doc_url": "...", "errors": [...]}
 */
class ApiException extends \RuntimeException
{
    /** @var int HTTP status, 0 for transport-level failures */
    private $httpStatus;

    /** @var string|null */
    private $errorCode;

    /** @var array */
    private $body;

    public function __construct($message, $httpStatus = 0, $errorCode = null, array $body = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, (int) $httpStatus, $previous);
        $this->httpStatus = (int) $httpStatus;
        $this->errorCode = $errorCode;
        $this->body = $body;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function isNotFound()
    {
        return $this->httpStatus === 404;
    }

    /** Transport failures, rate limits and 5xx are worth retrying later. */
    public function isRetryable()
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }
}
