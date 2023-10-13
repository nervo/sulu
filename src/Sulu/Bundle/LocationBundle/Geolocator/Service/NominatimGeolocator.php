<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\LocationBundle\Geolocator\Service;

use GuzzleHttp\ClientInterface;
use PHPUnit\Util\Exception;
use Psr\Http\Message\ResponseInterface;
use Sulu\Bundle\LocationBundle\Geolocator\GeolocatorInterface;
use Sulu\Bundle\LocationBundle\Geolocator\GeolocatorLocation;
use Sulu\Bundle\LocationBundle\Geolocator\GeolocatorResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Geolocator which uses the open street maps nominatim service.
 *
 * http://wiki.openstreetmap.org/wiki/Nominatim
 */
class NominatimGeolocator implements GeolocatorInterface
{
    /**
     * @var HttpClientInterface|ClientInterface
     */
    protected $client;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var string
     */
    private $key;

    public function __construct(
        $client,
        string $baseUrl,
        string $key
    ) {
        if ($client instanceof ClientInterface) {
            @trigger_deprecation(
                'sulu/sulu',
                '2.3',
                \sprintf(
                    'Instantiating NominatimGeolocator with %s as first argument is deprecated, please use %s instead.',
                    ClientInterface::class, HttpClientInterface::class
                )
            );
        } elseif (!($client instanceof HttpClientInterface)) {
            throw new \InvalidArgumentException(
                \sprintf('Please provide a %s as client', HttpClientInterface::class)
            );
        }

        $this->client = $client;
        $this->baseUrl = $baseUrl;
        $this->key = $key;
    }

    public function locate(string $query): GeolocatorResponse
    {
        $response = $this->client->request(
            'GET',
            $this->baseUrl,
            [
                'query' => [
                    'location' => $query,
                    'format' => 'json',
                    'addressdetails' => 1,
                    'key' => $this->key,
                ],
            ]
        );

        if (200 != $response->getStatusCode()) {
            throw new HttpException(
                $response->getStatusCode(),
                \sprintf(
                    'Server at "%s" returned HTTP "%s". Body: ',
                    $this->baseUrl,
                    $response->getStatusCode()
                )
            );
        }

        if ($response instanceof ResponseInterface) {
            // BC to support for guzzle client
            $responseBody = \json_decode($response->getBody(), true);
        } else {
            $responseBody = $response->toArray();
        }

        $response = new GeolocatorResponse();


        if (0 === $responseBody['info']['statuscode']) {
            if (array_key_exists('results', $responseBody) and 0 < count($responseBody['results'])) {
                $results = $responseBody['results'];

                foreach ($results as $result) {
                    $locations = $result['locations'];

                    foreach ($locations as $locationId => $location) {
                        $geoLocation = new GeolocatorLocation();

                        foreach ([
                             'setStreet' => 'street',
                             'setCode' => 'postalCode',
                             'setTown' => 'adminArea5',
                             'setCountry' => 'adminArea1',
                         ] as $method => $key) {
                            if (isset($location[$key])) {
                                $geoLocation->$method($location[$key]);
                            }
                        }

                        if (isset($location['latLng'])) {
                            $geoLocation->setLatitude($location['latLng']['lat']);
                            $geoLocation->setLongitude($location['latLng']['lng']);
                        }

                        $geoLocation->setId($locationId);
                        $displayTitle = trim($geoLocation->getStreet() . ', ' . $geoLocation->getTown() . ', ' . $geoLocation->getCountry(), ',');
                        $geoLocation->setDisplayTitle($displayTitle);

                        $response->addLocation($geoLocation);
                    }
                }
            } else {
                throw new Exception('No results found.');
            }
        } else {
            throw new Exception($responseBody['info']['messages'][0]);
        }

        return $response;
    }
}
