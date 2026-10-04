<?php

namespace Omnilex\Eurlex\Tests;

use Omnilex\Eurlex\EurlexSource;
use Omnilex\Eurlex\EurlexSourceFactory;
use Omnilex\Exception\NotSupportedException;
use Omnilex\Exception\RateLimitedException;
use Omnilex\Exception\UnavailableException;
use Omnilex\Model\Capabilities;
use Omnilex\Model\Citation;
use Omnilex\Model\Identifier;
use Omnilex\Model\Kind;
use Omnilex\Model\Query;
use Omnilex\Model\Relation;
use Omnilex\Model\Scheme;
use Omnilex\Model\Status;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from the Publications Office on 2026-10-04:
 * publications.europa.eu/webapi/rdf/sparql (the *.json: the work behind
 * CELEX 32016R0679 and behind ECLI:EU:C:2014:317, the consolidated versions,
 * the links both ways, a search of the titles, the judgments since
 * 2026-09-24) and publications.europa.eu/resource/celex/... (the *.xhtml,
 * cut down to a few articles: the regulation as published, its consolidated
 * version of 2016-05-04, the start of the judgment C-131/12).
 * work-02016R0679-20160504.json is the consolidated version asked by its
 * own CELEX number.
 */
final class EurlexSourceTest extends TestCase
{
    /** @var list<array{url: string, query: string, headers: array<string, string>}> */
    private array $calls = [];

    private function source(array $options = []): EurlexSource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);
            $sparql = (string) ($params['query'] ?? '');
            $headers = [];
            foreach ($options['normalized_headers'] as $name => $values) {
                $headers[$name] = substr($values[0], \strlen($name) + 2);
            }
            $this->calls[] = ['url' => strtok($url, '?'), 'query' => $sparql, 'headers' => $headers];

            if (str_contains($url, '/resource/celex/')) {
                $file = __DIR__.'/Fixtures/'.rawurldecode(basename((string) parse_url($url, \PHP_URL_PATH))).'.xhtml';

                return is_file($file) ? new MockResponse((string) file_get_contents($file), ['response_headers' => ['Content-Type: application/xhtml+xml;charset=UTF-8']]) : new MockResponse('', ['http_code' => 404]);
            }
            $fixture = match (true) {
                str_contains($sparql, 'act_consolidated_consolidates_resource_legal') => 'versions-32016R0679.json',
                str_contains($sparql, '?w ?rel ?t .') && str_contains($sparql, '"62012CJ0131"') => 'links-out-62012CJ0131.json',
                str_contains($sparql, '?w ?rel ?t .') => 'links-out-32016R0679.json',
                str_contains($sparql, '?t ?rel ?w .') => 'links-in-32016R0679.json',
                str_contains($sparql, 'bif:contains') => 'search-protection-des-donnees.json',
                str_contains($sparql, 'ORDER BY DESC(?date) ?celex') => 'recent-decisions.json',
                str_contains($sparql, 'cdm:case-law_ecli "ECLI:EU:C:2014:317"') => 'work-ecli-EU-C-2014-317.json',
                str_contains($sparql, 'cdm:resource_legal_eli "http://data.europa.eu/eli/reg/2016/679/oj"') => 'work-eli-en.json',
                str_contains($sparql, 'cdm:resource_legal_id_celex "32016R0679"') => 'work-32016R0679.json',
                str_contains($sparql, 'cdm:resource_legal_id_celex "02016R0679-20160504"') => 'work-02016R0679-20160504.json',
                default => 'work-unknown.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['response_headers' => ['Content-Type: application/sparql-results+json']]);
        });

        return (new EurlexSourceFactory($http))->create($options + ['throttle' => 0]);
    }

    public function testWhatItDoes(): void
    {
        $source = $this->source();

        self::assertSame('eurlex', $source->getName());
        self::assertSame(['search', 'text', 'article', 'decision', 'citations', 'recent'], Capabilities::operations($source));
        $capabilities = $source->capabilities();
        self::assertSame(['EU'], $capabilities->jurisdictions);
        self::assertTrue($capabilities->versions);
        self::assertTrue($capabilities->reads(Scheme::CELEX));
        self::assertTrue($capabilities->reads(Scheme::ECLI));
        self::assertFalse($capabilities->filters('at'));
        self::assertFalse($capabilities->pseudonymised);
    }

    public function testARegulationInItsConsolidatedVersionAtADate(): void
    {
        $text = $this->source()->text('32016R0679', new \DateTimeImmutable('2018-06-01'));

        self::assertSame('32016R0679', $text->id);
        self::assertStringStartsWith('Règlement (UE) 2016/679 du Parlement européen et du Conseil du 27 avril 2016 relatif à la protection des personnes physiques', $text->title);
        self::assertSame(['celex:32016R0679', 'eli:http://data.europa.eu/eli/reg/2016/679/oj'], $text->identifiers->keys());
        self::assertSame('REG', $text->type);
        self::assertSame('2016-04-27', $text->date->format('Y-m-d'));
        self::assertSame('fr', $text->language);
        self::assertSame('https://eur-lex.europa.eu/legal-content/FR/TXT/?uri=CELEX:32016R0679', $text->url);
        self::assertContains('Protection des consommateurs', $text->subjects);

        self::assertCount(2, $text->versions);
        [$published, $consolidated] = $text->versions;
        self::assertSame(['32016R0679', '2016-04-27', '2016-05-03', Status::MODIFIED], [$published->id, $published->from->format('Y-m-d'), $published->to->format('Y-m-d'), $published->status]);
        self::assertSame(['02016R0679-20160504', '2016-05-04', null, Status::IN_FORCE], [$consolidated->id, $consolidated->from->format('Y-m-d'), $consolidated->to, $consolidated->status]);
        self::assertSame($consolidated, $text->version, 'the consolidated version is the one of 2018');
        self::assertTrue($text->isInForce());

        self::assertStringContainsString('Ce texte constitue seulement un outil de documentation', $text->content);
        self::assertStringContainsString('Droit à l\'effacement («droit à l\'oubli»)', $text->content);
        self::assertStringNotContainsString('<p', $text->content);

        // Three calls: the work, its consolidated versions, the text of the version.
        self::assertCount(3, $this->calls);
        self::assertSame(EurlexSource::SPARQL_URI, $this->calls[0]['url']);
        self::assertSame('application/sparql-results+json', $this->calls[0]['headers']['accept']);
        self::assertStringContainsString('?w cdm:resource_legal_id_celex "32016R0679"^^<http://www.w3.org/2001/XMLSchema#string>', $this->calls[0]['query']);
        self::assertStringContainsString('<http://publications.europa.eu/resource/authority/language/FRA>', $this->calls[0]['query']);
        self::assertSame('https://publications.europa.eu/resource/celex/02016R0679-20160504', $this->calls[2]['url']);
        self::assertSame('fra', $this->calls[2]['headers']['accept-language']);
        self::assertStringStartsWith('application/xhtml+xml', $this->calls[2]['headers']['accept']);
        self::assertStringStartsWith('omnilex/1.x', $this->calls[2]['headers']['user-agent']);
    }

    public function testBeforeItsConsolidationTheActIsReadAsPublished(): void
    {
        $text = $this->source()->text('CELEX:32016R0679', new \DateTimeImmutable('2016-04-30'));

        self::assertSame('32016R0679', $text->version->id);
        self::assertSame(Status::FUTURE, $text->version->status, 'adopted on 27 April 2016, in force on 24 May: not in force yet');
        self::assertFalse($text->isInForce());
        self::assertStringContainsString('Journal officiel de l\'Union européenne', $text->content);
        self::assertSame('https://publications.europa.eu/resource/celex/32016R0679', $this->calls[2]['url']);
    }

    public function testBeforeTheActThereIsNothing(): void
    {
        self::assertNull($this->source()->text('32016R0679', new \DateTimeImmutable('2010-01-01')));
        self::assertCount(2, $this->calls, 'no text fetched');
    }

    public function testAConsolidatedCelexNumberNamesItsVersion(): void
    {
        $text = $this->source()->text('02016R0679-20160504', new \DateTimeImmutable('2030-01-01'));

        self::assertSame('02016R0679-20160504', $text->id);
        self::assertSame('CONS_TEXT', $text->type);
        self::assertSame('02016R0679-20160504', $text->version->id);
        self::assertSame('2016-05-04', $text->version->from->format('Y-m-d'));
        self::assertSame([], $text->versions);
        self::assertSame('eli:http://data.europa.eu/eli/reg/2016/679/2016-05-04', $text->identifiers->keys()[1]);
        self::assertCount(2, $this->calls, 'the versions are not listed, the date is not read');

        $article = $this->source()->article('02016R0679-20160504', '99');
        self::assertSame('Entrée en vigueur et application', $article->title);
        self::assertStringEndsWith('2. Il est applicable à partir du 25 mai 2018.', $article->content);
    }

    public function testAnUnknownActIsNull(): void
    {
        self::assertNull($this->source()->text('32016R9999'));
        self::assertNull($this->source()->decision('ECLI:EU:C:2099:1'));
        self::assertSame([], $this->source()->citations('32016R9999'));
    }

    public function testByEliInAnotherLanguageWithoutTheText(): void
    {
        $text = $this->source(['language' => 'en', 'content' => false])->text('http://data.europa.eu/eli/reg/2016/679/oj');

        self::assertStringStartsWith('Regulation (EU) 2016/679 of the European Parliament and of the Council', $text->title);
        self::assertNull($text->content);
        self::assertSame('en', $text->language);
        self::assertStringContainsString('cdm:resource_legal_eli "http://data.europa.eu/eli/reg/2016/679/oj"^^<http://www.w3.org/2001/XMLSchema#anyURI>', $this->calls[0]['query']);
        self::assertStringContainsString('language/ENG>', $this->calls[0]['query']);
        self::assertCount(2, $this->calls);
    }

    public function testOneArticleOfTheVersionInForceAtADate(): void
    {
        $source = $this->source();
        $article = $source->article('32016R0679', '17', new \DateTimeImmutable('2018-06-01'));

        self::assertSame('02016R0679-20160504#art_17', $article->id);
        self::assertSame('17', $article->number);
        self::assertSame('Droit à l\'effacement («droit à l\'oubli»)', $article->title);
        self::assertStringStartsWith("1. La personne concernée a le droit d'obtenir du responsable du traitement l'effacement", $article->content);
        self::assertStringContainsString("\n\na) les données à caractère personnel ne sont plus nécessaires", $article->content, 'a list\'s letter back before its item');
        self::assertStringEndsWith('à la constatation, à l\'exercice ou à la défense de droits en justice.', $article->content);
        self::assertStringNotContainsString('Article 18', $article->content);
        self::assertSame('02016R0679-20160504', $article->version->id);
        self::assertTrue($article->isInForce());
        self::assertSame('32016R0679', $article->textId);
        self::assertSame('https://eur-lex.europa.eu/legal-content/FR/TXT/?uri=CELEX:02016R0679-20160504#art_17', $article->url);
        self::assertStringStartsWith('<div class="eli-subdivision" id="art_17">', $article->html);

        $published = $source->article('32016R0679', '17', new \DateTimeImmutable('2016-04-28'));
        self::assertSame('32016R0679#art_17', $published->id, 'as published in the Official Journal: another markup, the same article');
        self::assertSame('Droit à l\'effacement («droit à l\'oubli»)', $published->title);
        self::assertStringStartsWith('1. La personne concernée a le droit', $published->content);

        self::assertNull($source->article('32016R0679', '999', new \DateTimeImmutable('2018-06-01')));
    }

    public function testAnArticleNeedsItsNumber(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('does not read an article without its number');
        $this->source()->article('32016R0679');
    }

    public function testAJudgmentByItsEcli(): void
    {
        $decision = $this->source()->decision('ECLI:EU:C:2014:317');

        self::assertSame('62012CJ0131', $decision->id);
        self::assertSame('Arrêt de la Cour (grande chambre) du 13 mai 2014. - Google Spain SL et Google Inc. contre Agencia Española de Protección de Datos (AEPD) et Mario Costeja González.', $decision->title);
        self::assertSame(['celex:62012CJ0131', 'ecli:ECLI:EU:C:2014:317'], $decision->identifiers->keys());
        self::assertSame('ECLI:EU:C:2014:317', $decision->ecli());
        self::assertSame(['Cour de justice', 'CJ', 'EU', 'Grande chambre'], [$decision->court->name, $decision->court->code, $decision->court->country, $decision->court->formation]);
        self::assertSame('2014-05-13', $decision->date->format('Y-m-d'));
        self::assertSame('C-131/12', $decision->number);
        self::assertSame('JUDG', $decision->type);
        self::assertSame('Demande de décision préjudicielle, introduite par l\'Audiencia Nacional.', $decision->summary);
        self::assertContains('Protection des données', $decision->subjects);
        self::assertNull($decision->pseudonymised, 'the Office does not say');
        self::assertStringContainsString('ARRÊT DE LA COUR (grande chambre)', $decision->content);

        $interpreted = array_values(array_filter($decision->citations, static fn (Citation $c) => Relation::INTERPRETS === $c->relation));
        self::assertSame(['12007P007', '12007P008', '31995L0046'], array_map(static fn (Citation $c) => $c->target->id, $interpreted), 'the texts the Court interprets');
        self::assertSame(Kind::TEXT, $interpreted[2]->target->kind);
        self::assertStringStartsWith('Directive 95/46/CE du Parlement européen et du Conseil', $interpreted[2]->target->title);
        $cited = array_values(array_filter($decision->citations, static fn (Citation $c) => Relation::CITES === $c->relation && Kind::DECISION === $c->target->kind));
        self::assertSame('62012CJ0473', $cited[0]->target->id);
        self::assertSame('ECLI:EU:C:2013:715', $cited[0]->target->identifiers->value(Scheme::ECLI));
        self::assertSame('2013-11-07', $cited[0]->target->date->format('Y-m-d'));

        self::assertStringContainsString('?w cdm:case-law_ecli "ECLI:EU:C:2014:317"', $this->calls[0]['query']);
    }

    public function testAnActIsNotADecisionNorAJudgmentAText(): void
    {
        try {
            $this->source()->decision('32016R0679');
            self::fail();
        } catch (NotSupportedException $e) {
            self::assertStringContainsString('cannot read as a decision the act "32016R0679"', $e->getMessage());
        }
        try {
            $this->source()->decision('ECLI:FR:CCASS:2018:C301117');
            self::fail();
        } catch (NotSupportedException $e) {
            self::assertStringContainsString('not an ECLI of the EU courts', $e->getMessage());
        }
        $this->expectException(NotSupportedException::class);
        $this->source()->text('LEGITEXT000006070721');
    }

    public function testTheLinksOfAnActBothWays(): void
    {
        $citations = $this->source()->citations(Identifier::celex('32016R0679'), 5);

        self::assertCount(10, $citations, 'five each way');
        self::assertSame([Relation::CITES, '32016L0680', Kind::TEXT, 'DIR'], [$citations[0]->relation, $citations[0]->target->id, $citations[0]->target->kind, $citations[0]->target->type]);
        self::assertSame(Relation::CITED_BY, $citations[5]->relation);
        self::assertSame('52026PC0517', $citations[5]->target->id);
        self::assertSame('52026PC0517', $citations[5]->target->title, 'no title in French yet: its number');
        self::assertSame('2026-10-01', $citations[5]->target->date->format('Y-m-d'));
        self::assertStringContainsString('LIMIT 10', $this->calls[1]['query']);
        self::assertStringContainsString('cdm:case-law_interpretes_resource_legal', $this->calls[1]['query']);
    }

    public function testASearchOfTheTitlesWithFilters(): void
    {
        $results = $this->source()->search(new Query(text: 'protection des données', kind: Kind::TEXT, types: ['REG'], from: '2016-01-01', limit: 5));

        self::assertCount(5, $results);
        self::assertSame('5', $results->next);
        self::assertNull($results->total, 'the endpoint does not count');
        self::assertSame(['32026R1165', '32024R0868', '32019R0493', '32018R1725', '32016R0679R(02)'], array_map(static fn ($r) => $r->id, $results->items));
        self::assertSame(Kind::TEXT, $results->items[0]->kind);
        self::assertSame('REG', $results->items[0]->type);
        self::assertSame('2026-05-20', $results->items[0]->date->format('Y-m-d'));
        self::assertSame('celex:32026R1165', $results->items[0]->identifiers->keys()[0]);

        $sparql = $this->calls[0]['query'];
        self::assertStringContainsString('?title bif:contains "\"protection\" AND \"des\" AND \"données\""', $sparql);
        self::assertStringContainsString('FILTER(?sector IN ("3"^^<http://www.w3.org/2001/XMLSchema#string>))', $sparql);
        self::assertStringContainsString('FILTER(?type IN (<http://publications.europa.eu/resource/authority/resource-type/REG>))', $sparql);
        self::assertStringContainsString('FILTER(?date >= "2016-01-01"^^<http://www.w3.org/2001/XMLSchema#date>)', $sparql);
        self::assertStringContainsString('ORDER BY DESC(?date) ?celex LIMIT 21 OFFSET 0', $sparql);
    }

    public function testACriterionItCannotApplyIsRefused(): void
    {
        $source = $this->source();
        foreach ([new Query(text: 'données', at: '2018-01-01'), new Query(number: '2016/679'), new Query(text: 'données', kind: Kind::ARTICLE)] as $query) {
            try {
                $source->search($query);
                self::fail('A criterion was dropped.');
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);

        $this->expectException(\InvalidArgumentException::class);
        $source->search(new Query(text: 'données', subjects: ['PROT> } DROP']));
    }

    public function testWhatIsNewSinceADate(): void
    {
        $results = $this->source()->recent(new \DateTimeImmutable('2026-09-24'), new Query(kind: Kind::DECISION, jurisdictions: ['CJ'], types: ['JUDG'], limit: 5));

        self::assertSame(['62024CJ0845', '62024CJ0855', '62025CJ0020', '62025CJ0131', '62025CJ0209'], array_map(static fn ($r) => $r->id, $results->items));
        self::assertSame('9', $results->next, 'the row where the sixth judgment starts: several rows may describe one');
        $first = $results->items[0];
        self::assertSame(Kind::DECISION, $first->kind);
        self::assertSame('C-845/24', $first->number);
        self::assertSame('ECLI:EU:C:2026:801', $first->identifiers->value(Scheme::ECLI));
        self::assertStringStartsWith('Arrêt de la Cour (troisième chambre) du 1er octobre 2026. - Silgan Holdings', $first->title);

        $sparql = $this->calls[0]['query'];
        self::assertStringContainsString('FILTER(?date >= "2026-09-24"^^<http://www.w3.org/2001/XMLSchema#date>)', $sparql);
        self::assertStringContainsString('?w cdm:work_created_by_agent ?court . FILTER(?court IN (<http://publications.europa.eu/resource/authority/corporate-body/CJ>))', $sparql);
        self::assertStringContainsString('FILTER(?sector IN ("6"^^', $sparql);
        self::assertStringNotContainsString('bif:contains', $sparql);
    }

    public function testDownOrSlowIsNeverAnEmptyAnswer(): void
    {
        $source = (new EurlexSourceFactory(new MockHttpClient([new MockResponse('Virtuoso 42000 Error', ['http_code' => 503]), new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 5']])])))->create(['throttle' => 0]);

        try {
            $source->recent(new \DateTimeImmutable('2026-09-24'));
            self::fail('A watch read "down" as "nothing new".');
        } catch (UnavailableException $e) {
            self::assertSame(503, $e->status);
        }
        $this->expectException(RateLimitedException::class);
        $source->text('32016R0679');
    }

    public function testAnUnknownLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"xx" is not an official language of the EU');
        $this->source(['language' => 'xx']);
    }
}
