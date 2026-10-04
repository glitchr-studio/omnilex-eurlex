<?php

namespace Omnilex\Eurlex;

use Omnilex\Config;
use Omnilex\Source\SourceFactory;
use Omnilex\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * EUR-Lex, from the Publications Office's open endpoint - no key.
 *
 *   options:
 *     language: fr          # ISO 639-1: titles, labels and texts
 *     content: true         # text() and decision() also fetch the text (one more call)
 *     throttle: 0.5         # seconds between two calls
 *     base_uri: 'https://publications.europa.eu/webapi/rdf/sparql'
 *     resource_uri: 'https://publications.europa.eu/resource/'
 */
final class EurlexSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilex.factory_name' => 'eurlex',
            'omnilex.required_options' => [],
            'language' => 'fr',
            'content' => true,
            'throttle' => 0.5,
            'base_uri' => EurlexSource::SPARQL_URI,
            'resource_uri' => EurlexSource::RESOURCE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new EurlexSource(
            $this->http ?? HttpClient::create(['timeout' => 60]),
            (string) $c->get('language', 'fr'),
            (bool) $c['content'],
            (string) $c->get('base_uri', EurlexSource::SPARQL_URI),
            (string) $c->get('resource_uri', EurlexSource::RESOURCE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c['throttle'],
        );
    }
}
