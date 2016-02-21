<?php

namespace Stormpath\Http;


/*
 * Copyright 2016 Stormpath, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;
use Stormpath\Http\Authc\RequestSigner;
use Psr\Http\Message\RequestInterface;
use Stormpath\Http\Authc\SAuthc1RequestSigner;

class HttpClientRequestExecutor implements RequestExecutor
{
    private $httpClient;
    private $signer;

    public function __construct(RequestSigner $signer = null)
    {
        $stack = new HandlerStack();
        $stack->setHandler(\GuzzleHttp\choose_handler());
        $stack->push(function (callable $handler) {
           return function (RequestInterface $request, $options) use ($handler) {
                return $handler($request, $options)->then(function (ResponseInterface $response) use ($request) {
                    if (!preg_match('/^[23]/', $response->getStatusCode())) {
                        echo json_encode([
                            'request' => [
                                'method' => $request->getMethod(),
                                'uri' => $request->getUri()->__toString(),
                                'headers' => $request->getHeaders(),
                                'body' => $request->getBody()->__toString()
                            ],
                            'response' => [
                                'status' => $response->getStatusCode(),
                                'body' => $response->getBody()->__toString()
                            ]
                        ]), "\n";
                    }

                    return $response;
                });
           };
        });
        $this->httpClient = new Client(['handler' => $stack]);

        if (!$signer)
            $signer = new SAuthc1RequestSigner;

        $this->signer = $signer;
    }

    public function executeRequest(Request $request, $redirectsLimit = 10)
    {
        $options = [];

        $apiKey = $request->getApiKey();

        if ($apiKey)
        {
            $this->signer->sign($request, $apiKey);

            $options['allow_redirects'] = false;
            $options['exceptions'] = false; // do not throw exceptions from the client
            $options['verify'] = false; // do not verify SSL certificate,
        }

        $options['headers'] = $request->getHeaders();
        $options['query'] = $request->getQueryString();
        $options['body'] = $request->getBody();

        $response = $this->httpClient->request(
            $request->getMethod(),
            $request->getResourceUrl(),
            $options
        );

        if ($response->getHeader('Location') && $redirectsLimit)
        {
            $request->setResourceUrl($response->getHeader('Location')[0]);
            return $this->executeRequest($request, --$redirectsLimit);

        }

        $body = $response->getBody();

        return new DefaultResponse($response->getStatusCode(),
                                   $response->getHeader('Content-Type'),
                                   $body->__toString(),
                                   $body->getSize());

    }

    private function addQueryString(array $queryString, RequestInterface $request)
    {
        ksort($queryString);

        foreach($queryString as $key => $value)
        {
            $request->getQuery()->set($key, $value);
        }
    }

    public function getSigner()
    {
        return $this->signer;
    }


}
