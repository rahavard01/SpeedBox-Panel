<?php

namespace App\Services\Plugin;

use Exception;
use Symfony\Component\HttpFoundation\Response;

class InterceptResponseException extends Exception
{
    public function __construct(protected Response $response)
    {
        parent::__construct('Response intercepted');
    }

    public function getResponse(): Response
    {
        return $this->response;
    }
}
