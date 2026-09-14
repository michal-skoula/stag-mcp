<?php

namespace App\Contracts;

use App\Exceptions\StagException;

interface StagClient
{
    /**
     * Performs a GET request against a STAG endpoint.
     *
     * @param  array<string, mixed>  $query  Request's query string (&key=val)
     * @return array<array-key, mixed>
     *
     * @throws StagException
     */
    public function get(string $path, array $query = []): array;

    /**
     * Performs a POST request against a STAG endpoint.
     *
     * @param  array<string, mixed>  $data  Request's JSON body
     * @return array<array-key, mixed>
     *
     * @throws StagException
     */
    public function post(string $path, array $data = []): array;

    /**
     * Performs a PUT request against a STAG endpoint.
     *
     * @param  array<string, mixed>  $query  Request's query string (&key=val)
     * @return array<array-key, mixed>
     *
     * @throws StagException
     */
    public function put(string $path, array $query = []): array;
}
