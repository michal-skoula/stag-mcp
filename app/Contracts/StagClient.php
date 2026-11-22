<?php

namespace App\Contracts;

use App\Exceptions\StagException;
use SimpleXMLElement;

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

    /**
     * Performs a GET request that asks for XML, for endpoints whose JSON is unusable.
     *
     * Returned as SimpleXML rather than an array on purpose: a generic conversion
     * cannot tell which elements repeat, so one child comes out as an object and
     * two as a list. Iterating SimpleXML treats one and many alike.
     *
     * @param  array<string, mixed>  $query  Request's query string (&key=val)
     *
     * @throws StagException
     */
    public function getXml(string $path, array $query = []): SimpleXMLElement;
}
