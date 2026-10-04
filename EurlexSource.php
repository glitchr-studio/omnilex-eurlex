<?php

namespace Omnilex\Eurlex;

use Omnilex\Exception\NotSupportedException;
use Omnilex\Exception\ProviderException;
use Omnilex\Model\Article;
use Omnilex\Model\Capabilities;
use Omnilex\Model\Citation;
use Omnilex\Model\Court;
use Omnilex\Model\Decision;
use Omnilex\Model\Identifier;
use Omnilex\Model\Identifiers;
use Omnilex\Model\Kind;
use Omnilex\Model\Query;
use Omnilex\Model\Reference;
use Omnilex\Model\Relation;
use Omnilex\Model\Results;
use Omnilex\Model\Scheme;
use Omnilex\Model\Status;
use Omnilex\Model\Text;
use Omnilex\Model\Version;
use Omnilex\Source\ArticleReaderInterface;
use Omnilex\Source\CitationsInterface;
use Omnilex\Source\DecisionReaderInterface;
use Omnilex\Source\HttpSource;
use Omnilex\Source\RecentInterface;
use Omnilex\Source\SearchInterface;
use Omnilex\Source\TextReaderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * EU law, from the Publications Office's open access to Cellar, the
 * repository behind EUR-Lex - no key:
 *
 *   - its SPARQL endpoint (publications.europa.eu/webapi/rdf/sparql), for
 *     the metadata: titles, dates, whether an act is in force, its
 *     consolidated versions, the links between acts and judgments;
 *   - its resources by CELEX number, with content negotiation
 *     (publications.europa.eu/resource/celex/32016R0679, Accept:
 *     application/xhtml+xml, Accept-Language: fra), for the texts.
 *
 * An act is read in the consolidated version that was the state of the law
 * on the day asked for; before its first consolidation, as published in the
 * Official Journal. Consolidated versions are documentary: only the
 * Official Journal is authentic.
 */
final class EurlexSource extends HttpSource implements SearchInterface, TextReaderInterface, ArticleReaderInterface, DecisionReaderInterface, CitationsInterface, RecentInterface
{
    public const SPARQL_URI = 'https://publications.europa.eu/webapi/rdf/sparql';
    public const RESOURCE_URI = 'https://publications.europa.eu/resource/';

    private const CDM = 'http://publications.europa.eu/ontology/cdm#';
    private const AUTHORITY = 'http://publications.europa.eu/resource/authority/';
    private const XSD_STRING = '^^<http://www.w3.org/2001/XMLSchema#string>';
    private const XSD_DATE = '^^<http://www.w3.org/2001/XMLSchema#date>';
    private const MAX = 100;

    /** The properties of a work the models read. */
    private const PROPERTIES = [
        'resource_legal_id_celex', 'resource_legal_eli', 'case-law_ecli', 'resource_legal_id_sector',
        'work_date_document', 'resource_legal_date_signature', 'resource_legal_date_entry-into-force', 'resource_legal_date_end-of-validity',
        'resource_legal_in-force', 'act_consolidated_date', 'work_has_resource-type', 'resource_legal_is_about_subject-matter',
        'work_created_by_agent', 'case-law_delivered_by_court-formation', 'case-law_has_procjur', 'resource_legal_published_in_official-journal',
    ];

    /** The links read from a document to others, and their names read the other way. */
    private const RELATIONS = [
        'work_cites_work' => [Relation::CITES, Relation::CITED_BY],
        'case-law_interpretes_resource_legal' => [Relation::INTERPRETS, Relation::INTERPRETED_BY],
        'resource_legal_based_on_resource_legal' => [Relation::BASED_ON, Relation::BASIS_OF],
        'resource_legal_amends_resource_legal' => [Relation::AMENDS, Relation::AMENDED_BY],
        'resource_legal_repeals_resource_legal' => [Relation::REPEALS, Relation::REPEALED_BY],
        'resource_legal_implicitly_repeals_resource_legal' => [Relation::REPEALS, Relation::REPEALED_BY],
    ];

    /** ISO 639-1 to the codes of the Office's language authority table. */
    private const LANGUAGES = [
        'bg' => 'BUL', 'cs' => 'CES', 'da' => 'DAN', 'de' => 'DEU', 'el' => 'ELL', 'en' => 'ENG', 'es' => 'SPA', 'et' => 'EST',
        'fi' => 'FIN', 'fr' => 'FRA', 'ga' => 'GLE', 'hr' => 'HRV', 'hu' => 'HUN', 'it' => 'ITA', 'lt' => 'LIT', 'lv' => 'LAV',
        'mt' => 'MLT', 'nl' => 'NLD', 'pl' => 'POL', 'pt' => 'POR', 'ro' => 'RON', 'sk' => 'SLK', 'sl' => 'SLV', 'sv' => 'SWE',
    ];

    private readonly string $language;
    private readonly string $authorityLanguage;

    /**
     * @param string                $language ISO 639-1: the language of the titles, labels and texts ("fr")
     * @param bool                  $content  whether text() and decision() also fetch the text itself (one more call, up to a few megabytes)
     * @param array<string, string> $headers
     */
    public function __construct(
        HttpClientInterface $http,
        string $language = 'fr',
        private readonly bool $content = true,
        string $sparqlUri = self::SPARQL_URI,
        private readonly string $resourceUri = self::RESOURCE_URI,
        array $headers = [],
        float $throttle = 0.5,
    ) {
        $language = strtolower($language);
        if (!isset(self::LANGUAGES[$language])) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an official language of the EU (ISO 639-1).', $language));
        }
        $this->language = $language;
        $this->authorityLanguage = self::LANGUAGES[$language];
        parent::__construct($http, $sparqlUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'eurlex';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            kinds: [Kind::TEXT, Kind::ARTICLE, Kind::DECISION],
            identifiers: [Scheme::CELEX, Scheme::ELI, Scheme::ECLI],
            criteria: ['text', 'title', 'kind', 'jurisdictions', 'from', 'to', 'subjects', 'types'],
            jurisdictions: ['EU'],
            versions: true,
            pageSize: self::MAX,
        );
    }

    public function text(Identifier|string $id, ?\DateTimeInterface $at = null): ?Text
    {
        $work = $this->work($this->subject($id, 'read a text'));
        if (null === $work) {
            return null;
        }
        if ('6' === $work['sector']) {
            throw NotSupportedException::identifier($this->getName(), $work['celex'], 'read as a text the judgment');
        }
        $asked = Identifier::celex($work['celex']);
        $versions = [];
        $version = null;
        $read = $work['celex'];
        if (null !== $asked->consolidatedOn()) {
            // A consolidated CELEX number names its version.
            $version = new Version($asked->consolidatedOn(), null, Status::UNKNOWN, $work['celex'], null, $asked->url());
        } else {
            $versions = $this->versions($work);
            $version = $this->versionAt($work, $versions, $at);
            if (null === $version) {
                return null;
            }
            $read = $version->id ?? $work['celex'];
        }

        $html = $this->content ? $this->document($read) : null;

        return new Text(
            id: $work['celex'],
            title: $work['title'] ?? $work['celex'],
            identifiers: $this->identifiers($work),
            type: $work['type'],
            date: $work['date'],
            publishedOn: null,
            version: $version,
            versions: $versions,
            content: self::plain(self::body($html)),
            html: $html,
            subjects: $work['subjects'],
            language: $this->language,
            url: $asked->url(),
            source: $this->getName(),
            raw: $work['raw'],
        );
    }

    public function article(Identifier|string $id, ?string $number = null, ?\DateTimeInterface $at = null): ?Article
    {
        if (null === $number || '' === trim($number)) {
            throw NotSupportedException::operation($this->getName(), 'read an article without its number: give the act (a CELEX number or an ELI) and the article\'s number');
        }
        $work = $this->work($this->subject($id, 'read an article of'));
        if (null === $work) {
            return null;
        }
        $asked = Identifier::celex($work['celex']);
        $versions = [];
        if (null !== $asked->consolidatedOn()) {
            $version = new Version($asked->consolidatedOn(), null, Status::UNKNOWN, $work['celex'], null, $asked->url());
        } else {
            $versions = $this->versions($work);
            $version = $this->versionAt($work, $versions, $at);
            if (null === $version) {
                return null;
            }
        }
        $read = $version->id ?? $work['celex'];
        $html = $this->document($read);
        if (null === $html) {
            return null;
        }
        if (!str_contains($html, 'class="eli-subdivision"')) {
            throw NotSupportedException::operation($this->getName(), \sprintf('read the articles of %s: its text is not divided into articles in the %s version the Office publishes', $read, $this->language));
        }
        $anchor = 'art_'.preg_replace('~\s+~', '', strtr(trim($number), ['bis' => 'a', 'ter' => 'b', 'quater' => 'c']));
        $fragment = self::subdivision($html, $anchor) ?? self::subdivision($html, 'art_'.preg_replace('~[^0-9A-Za-z]+~', '', $number));
        if (null === $fragment) {
            return null;
        }
        $heading = preg_match('~<p[^>]*class="(?:oj-)?(?:ti-art|title-article-norm)"[^>]*>(.*?)</p>~s', $fragment, $m) ? self::plain($m[1]) : null;
        $title = preg_match('~<p[^>]*class="(?:oj-)?(?:sti-art|stitle-article-norm)"[^>]*>(.*?)</p>~s', $fragment, $m) ? self::plain($m[1]) : null;
        $content = (string) self::plain($fragment);
        foreach ([$heading, $title] as $line) {
            if (null !== $line && str_starts_with($content, $line)) {
                $content = ltrim(substr($content, \strlen($line)));
            }
        }

        return new Article(
            id: $read.'#'.$anchor,
            number: trim($number),
            content: $content,
            html: $fragment,
            title: $title,
            identifiers: Identifiers::of(Identifier::tryOf(Scheme::CELEX, $read)),
            version: $version,
            versions: $versions,
            textId: $work['celex'],
            textTitle: $work['title'],
            url: Identifier::celex($read)->url().'#'.$anchor,
            source: $this->getName(),
        );
    }

    public function decision(Identifier|string $id): ?Decision
    {
        $work = $this->work($this->subject($id, 'read a decision', [Scheme::ECLI, Scheme::CELEX]));
        if (null === $work) {
            return null;
        }
        if ('6' !== $work['sector']) {
            throw NotSupportedException::identifier($this->getName(), $work['celex'], 'read as a decision the act');
        }
        $html = $this->content ? $this->document($work['celex']) : null;
        // "Arrêt de la Cour (grande chambre) du 13 mai 2014.#Google Spain SL ... contre ...#Demande de décision préjudicielle ...#Affaire C-131/12."
        $parts = array_values(array_filter(array_map('trim', explode('#', (string) $work['title']))));
        $number = preg_match('~\b([CTF])[-‑](\d+)/(\d{2})\b~u', (string) $work['title'], $m) ? "$m[1]-$m[2]/$m[3]" : null;
        preg_match_all('~\b[CTF][-‑]\d+/\d{2}\b~u', (string) $work['title'], $all);

        return new Decision(
            id: $work['celex'],
            title: $parts ? implode(' - ', \array_slice($parts, 0, 2)) : null,
            identifiers: $this->identifiers($work),
            court: $work['court'],
            date: $work['date'],
            number: $number,
            numbers: array_values(array_unique(str_replace('‑', '-', $all[0]))),
            type: $work['type'],
            solution: null,
            publication: null,
            summary: \count($parts) > 2 ? $parts[2] : null,
            subjects: $work['subjects'],
            content: self::plain(self::body($html)),
            html: $html,
            citations: $this->links($work['celex'], true, self::MAX),
            pseudonymised: null,
            language: $this->language,
            url: Identifier::celex($work['celex'])->url(),
            source: $this->getName(),
            raw: $work['raw'],
        );
    }

    public function citations(Identifier|string $id, int $limit = 100): array
    {
        $work = $this->work($this->subject($id, 'follow the links of', [Scheme::CELEX, Scheme::ELI, Scheme::ECLI]), false);
        if (null === $work) {
            return [];
        }
        $limit = max(1, min($limit, 1000));

        return [...$this->links($work['celex'], true, $limit), ...$this->links($work['celex'], false, $limit)];
    }

    public function search(Query $query): Results
    {
        $this->accept($query);

        return $this->select($query, $query->from, $query->to);
    }

    public function recent(\DateTimeInterface $since, ?Query $query = null): Results
    {
        $query ??= new Query();
        $this->accept($query, ['from', 'to']);

        return $this->select($query->with(['sort' => Query::NEWEST]), Query::day($since), null);
    }

    /** One page of works: by words of the title, kind, type, subject, court, dates. */
    private function select(Query $query, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): Results
    {
        $limit = max(1, min($query->limit, self::MAX));
        $offset = max(0, (int) $query->cursor);
        $where = ['?w cdm:resource_legal_id_celex ?celex ; cdm:work_date_document ?date ; cdm:resource_legal_id_sector ?sector .'];

        $words = trim((string) ($query->title ?? $query->text));
        if ('' !== $words) {
            // The endpoint indexes the titles, not the full texts: text and title both search the titles.
            array_unshift($where, \sprintf('?e cdm:expression_title ?title ; cdm:expression_uses_language %s ; cdm:expression_belongs_to_work ?w . ?title bif:contains %s .', $this->languageUri(), self::literal(self::fulltext($words))));
        } else {
            $where[] = \sprintf('OPTIONAL { ?e cdm:expression_belongs_to_work ?w ; cdm:expression_uses_language %s ; cdm:expression_title ?title }', $this->languageUri());
        }
        $sectors = match ($query->kind) {
            Kind::DECISION => ['6'],
            Kind::TEXT => ['3'],
            Kind::ARTICLE => throw NotSupportedException::criterion($this->getName(), 'kind', 'articles are read from their act, not searched'),
            null => ['3', '6'],
        };
        $where[] = \sprintf('FILTER(?sector IN (%s))', implode(', ', array_map(static fn (string $s) => self::literal($s).self::XSD_STRING, $sectors)));
        // Summaries and information notes share the CELEX number of their judgment, with a suffix.
        $where[] = 'FILTER(!CONTAINS(STR(?celex), "_"))';
        $where[] = $query->types
            ? \sprintf('?w cdm:work_has_resource-type ?type . FILTER(?type IN (%s))', implode(', ', array_map(static fn (string $t) => self::authority('resource-type', $t), $query->types)))
            : 'OPTIONAL { ?w cdm:work_has_resource-type ?type }';
        $where[] = 'OPTIONAL { ?w cdm:case-law_ecli ?ecli }';
        if ($query->subjects) {
            $where[] = \sprintf('?w cdm:resource_legal_is_about_subject-matter ?subject . FILTER(?subject IN (%s))', implode(', ', array_map(static fn (string $s) => self::authority('subject-matter', $s), $query->subjects)));
        }
        $courts = array_values(array_filter($query->jurisdictions, static fn (string $j) => 'EU' !== strtoupper($j)));
        if ($courts) {
            $where[] = \sprintf('?w cdm:work_created_by_agent ?court . FILTER(?court IN (%s))', implode(', ', array_map(static fn (string $c) => self::authority('corporate-body', $c), $courts)));
        }
        if (null !== $from) {
            $where[] = \sprintf('FILTER(?date >= %s)', self::literal($from->format('Y-m-d')).self::XSD_DATE);
        }
        if (null !== $to) {
            $where[] = \sprintf('FILTER(?date <= %s)', self::literal($to->format('Y-m-d')).self::XSD_DATE);
        }

        // A work with several types, titles or ECLIs comes on several rows, next to each other: more rows are read than works wanted.
        $fetch = $limit * 4 + 1;
        $rows = $this->sparql(\sprintf(
            "SELECT DISTINCT ?celex ?date ?sector ?title ?type ?ecli WHERE {\n  %s\n} ORDER BY %s(?date) ?celex LIMIT %d OFFSET %d",
            implode("\n  ", $where),
            Query::OLDEST === $query->sort ? 'ASC' : 'DESC',
            $fetch,
            $offset,
        ));

        $items = [];
        $next = null;
        foreach ($rows as $i => $row) {
            if (!isset($items[$row['celex']]) && \count($items) === $limit) {
                // The first row of the work after the last one of this page.
                $next = $offset + $i;
                break;
            }
            $items[$row['celex']] ??= $this->reference($row);
        }
        if (null === $next && \count($rows) === $fetch) {
            // The rows ran out inside a work: it opens the next page.
            $last = array_key_last($items);
            $next = $offset + array_search($last, array_column($rows, 'celex'), true);
            unset($items[$last]);
        }

        return new Results(array_values($items), null !== $next ? (string) $next : null);
    }

    /**
     * The work behind an identifier: its properties, its title in the
     * source's language, the labels of its type, subjects and court.
     *
     * @param array{0: string, 1: string} $subject the property that names the work, and its value
     *
     * @return array{celex: string, sector: string, title: ?string, type: ?string, date: ?\DateTimeImmutable, subjects: list<string>, court: ?Court, raw: array<string, list<string>>}|null
     */
    private function work(array $subject, bool $labels = true): ?array
    {
        [$property, $value] = $subject;
        $properties = implode(', ', array_map(static fn (string $p) => 'cdm:'.$p, self::PROPERTIES));
        $rows = $this->sparql(\sprintf(
            "SELECT DISTINCT ?p ?o ?label WHERE {\n  ?w cdm:%s %s .\n  { ?w ?p ?o . FILTER(?p IN (%s)) OPTIONAL { ?o skos:prefLabel ?label . FILTER(lang(?label) = %s) } }\n  UNION\n  { ?e cdm:expression_belongs_to_work ?w ; cdm:expression_uses_language %s ; cdm:expression_title ?o . BIND(cdm:expression_title AS ?p) }\n}",
            $property,
            $value,
            $properties,
            self::literal($this->language),
            $this->languageUri(),
        ));
        if (!$rows) {
            return null;
        }
        $raw = $labelled = [];
        foreach ($rows as $row) {
            $name = substr($row['p'], \strlen(self::CDM));
            $raw[$name][] = $row['o'];
            if (isset($row['label'])) {
                $labelled[$row['o']] = $row['label'];
            }
        }
        $raw = array_map(static fn (array $values) => array_values(array_unique($values)), $raw);
        // An evolutive work answers to several CELEX numbers (62012CJ0131, 62012CJ0131_1): the plain one is the document.
        $celexes = $raw['resource_legal_id_celex'] ?? [];
        usort($celexes, static fn (string $a, string $b) => [str_contains($a, '_'), \strlen($a)] <=> [str_contains($b, '_'), \strlen($b)]);
        if (!$celexes) {
            return null;
        }
        $label = static fn (?string $uri): ?string => null === $uri ? null : ($labelled[$uri] ?? substr($uri, (int) strrpos($uri, '/') + 1));
        $courtUri = null;
        foreach ($raw['work_created_by_agent'] ?? [] as $agent) {
            if (str_starts_with($agent, self::AUTHORITY.'corporate-body/')) {
                $courtUri ??= $agent;
            }
        }
        $sector = $raw['resource_legal_id_sector'][0] ?? $celexes[0][0];
        $type = $raw['work_has_resource-type'][0] ?? null;

        return [
            'celex' => $celexes[0],
            'sector' => $sector,
            'title' => isset($raw['expression_title'][0]) ? trim($raw['expression_title'][0]) : null,
            'type' => null === $type ? null : substr($type, (int) strrpos($type, '/') + 1),
            'date' => self::day($raw['work_date_document'][0] ?? null),
            'subjects' => $labels ? array_values(array_filter(array_map($label, $raw['resource_legal_is_about_subject-matter'] ?? []))) : [],
            'court' => '6' === $sector && null !== $courtUri ? new Court(
                (string) $label($courtUri),
                substr($courtUri, (int) strrpos($courtUri, '/') + 1),
                'EU',
                null,
                $label($raw['case-law_delivered_by_court-formation'][0] ?? null),
            ) : null,
            'raw' => $raw,
        ];
    }

    /**
     * The versions of an act: as published, then each consolidated version,
     * each ending the day before the next; the last one runs to the act's
     * end of validity.
     *
     * @param array{celex: string, raw: array<string, list<string>>, date: ?\DateTimeImmutable} $work
     *
     * @return list<Version>
     */
    private function versions(array $work): array
    {
        $raw = $work['raw'];
        $inForce = isset($raw['resource_legal_in-force'][0]) ? \in_array($raw['resource_legal_in-force'][0], ['1', 'true'], true) : null;
        $end = self::day($raw['resource_legal_date_end-of-validity'][0] ?? null);
        // The act as published is the state of the text from its own date.
        $points = [['id' => $work['celex'], 'from' => $work['date'], 'label' => 'Official Journal']];

        $base = '0'.substr($work['celex'], 1).'-';
        $rows = $this->sparql(\sprintf(
            "SELECT DISTINCT ?celex ?date WHERE {\n  ?w cdm:resource_legal_id_celex %s .\n  ?c cdm:act_consolidated_consolidates_resource_legal ?w ; cdm:resource_legal_id_celex ?celex ; cdm:act_consolidated_date ?date .\n  FILTER(STRSTARTS(STR(?celex), %s))\n} ORDER BY ?date",
            self::literal($work['celex']).self::XSD_STRING,
            self::literal($base),
        ));
        foreach ($rows as $row) {
            $from = self::day($row['date']);
            if (null !== $from) {
                $points[] = ['id' => $row['celex'], 'from' => $from, 'label' => 'consolidated '.$from->format('Y-m-d')];
            }
        }
        // A consolidation dated the day the act starts to apply replaces the published text from that day.
        $points = array_values(array_filter($points, static fn (array $p) => null !== $p['from']));
        usort($points, static fn (array $a, array $b) => [$a['from'], $a['id'][0] === '0'] <=> [$b['from'], $b['id'][0] === '0']);

        $versions = [];
        foreach ($points as $i => $point) {
            $next = $points[$i + 1]['from'] ?? null;
            if (null !== $next && $next <= $point['from']) {
                continue;
            }
            $to = null !== $next ? $next->modify('-1 day') : $end;
            $last = null === $next;
            $status = match (true) {
                !$last => Status::MODIFIED,
                true === $inForce => Status::IN_FORCE,
                false === $inForce && null !== $end && $end < Query::day('now') => Status::REPEALED,
                false === $inForce => Status::EXPIRED,
                default => Status::UNKNOWN,
            };
            $versions[] = new Version($point['from'], $to, $status, $point['id'], $point['label'], Identifier::celex($point['id'])->url());
        }

        return $versions;
    }

    /**
     * The version that was the state of the act on that day (today when
     * null). Between its adoption and its entry into force an act exists
     * without applying: its version then says FUTURE.
     *
     * @param array{raw: array<string, list<string>>} $work
     * @param list<Version>                           $versions
     */
    private function versionAt(array $work, array $versions, ?\DateTimeInterface $at): ?Version
    {
        $day = Query::day($at ?? 'now');
        $version = Version::at($versions, $day);
        $starts = array_filter(array_map(self::day(...), $work['raw']['resource_legal_date_entry-into-force'] ?? []));
        sort($starts);
        if (null !== $version && isset($starts[0]) && $day < $starts[0]) {
            return new Version($version->from, $version->to, Status::FUTURE, $version->id, $version->label, $version->url);
        }

        return $version;
    }

    /**
     * The links of a document: from it to others, or from others to it.
     *
     * @return list<Citation>
     */
    private function links(string $celex, bool $outgoing, int $limit): array
    {
        $relations = implode(', ', array_map(static fn (string $p) => 'cdm:'.$p, array_keys(self::RELATIONS)));
        $rows = $this->sparql(\sprintf(
            "SELECT DISTINCT ?rel ?celex ?date ?sector ?title ?type ?ecli WHERE {\n  ?w cdm:resource_legal_id_celex %s .\n  %s\n  FILTER(?rel IN (%s))\n  ?t cdm:resource_legal_id_celex ?celex .\n  FILTER(!CONTAINS(STR(?celex), \"_\"))\n  OPTIONAL { ?t cdm:work_date_document ?date }\n  OPTIONAL { ?t cdm:resource_legal_id_sector ?sector }\n  OPTIONAL { ?t cdm:work_has_resource-type ?type }\n  OPTIONAL { ?t cdm:case-law_ecli ?ecli }\n  OPTIONAL { ?e cdm:expression_belongs_to_work ?t ; cdm:expression_uses_language %s ; cdm:expression_title ?title }\n} ORDER BY DESC(?date) ?celex LIMIT %d",
            self::literal($celex).self::XSD_STRING,
            $outgoing ? '?w ?rel ?t .' : '?t ?rel ?w .',
            $relations,
            $this->languageUri(),
            // Several rows may describe one target (two resource types): read some more than asked.
            $limit * 2,
        ));
        $citations = [];
        foreach ($rows as $row) {
            $relation = self::RELATIONS[substr($row['rel'], \strlen(self::CDM))] ?? null;
            if (null === $relation || $row['celex'] === $celex) {
                continue;
            }
            $citations[$relation[0]->value.' '.$row['celex']] ??= new Citation($outgoing ? $relation[0] : $relation[1], $this->reference($row));
        }

        return \array_slice(array_values($citations), 0, $limit);
    }

    /** @param array<string, string> $row celex, and when known: date, sector, title, type, ecli */
    private function reference(array $row): Reference
    {
        $celex = Identifier::tryOf(Scheme::CELEX, $row['celex']);
        $decision = '6' === ($row['sector'] ?? $row['celex'][0]);
        $title = isset($row['title']) ? trim($row['title']) : null;
        if ($decision && null !== $title) {
            $title = implode(' - ', \array_slice(array_values(array_filter(array_map('trim', explode('#', $title)))), 0, 2));
        }

        return new Reference(
            kind: $decision ? Kind::DECISION : Kind::TEXT,
            id: $row['celex'],
            title: $title ?: $row['celex'],
            identifiers: Identifiers::of($celex, Identifier::tryOf(Scheme::ECLI, $row['ecli'] ?? null)),
            date: self::day($row['date'] ?? null),
            type: isset($row['type']) ? substr($row['type'], (int) strrpos($row['type'], '/') + 1) : null,
            number: $decision && null !== $title && preg_match('~\b([CTF])[-‑](\d+)/(\d{2})\b~u', (string) ($row['title'] ?? ''), $m) ? "$m[1]-$m[2]/$m[3]" : null,
            court: null,
            url: $celex?->url(),
            source: $this->getName(),
        );
    }

    /** @param array{celex: string, raw: array<string, list<string>>} $work */
    private function identifiers(array $work): Identifiers
    {
        return new Identifiers([
            Identifier::tryOf(Scheme::CELEX, $work['celex']),
            Identifier::tryOf(Scheme::ELI, $work['raw']['resource_legal_eli'][0] ?? null),
            Identifier::tryOf(Scheme::ECLI, $work['raw']['case-law_ecli'][0] ?? null),
        ]);
    }

    /**
     * The triple that names a work from an identifier the source reads.
     *
     * @param list<Scheme> $schemes
     *
     * @return array{0: string, 1: string}
     */
    private function subject(Identifier|string $id, string $what, array $schemes = [Scheme::CELEX, Scheme::ELI]): array
    {
        $identifier = self::identify($id, ...$schemes) ?? throw NotSupportedException::identifier($this->getName(), (string) $id, $what);

        return match ($identifier->scheme) {
            Scheme::CELEX => ['resource_legal_id_celex', self::literal($identifier->value).self::XSD_STRING],
            Scheme::ECLI => 'EU' === $identifier->country()
                ? ['case-law_ecli', self::literal($identifier->value).self::XSD_STRING]
                : throw NotSupportedException::identifier($this->getName(), $identifier->value, $what.' (not an ECLI of the EU courts)'),
            default => 'EU' === $identifier->country()
                ? ['resource_legal_eli', self::literal($identifier->value).'^^<http://www.w3.org/2001/XMLSchema#anyURI>']
                : throw NotSupportedException::identifier($this->getName(), $identifier->value, $what.' (not an ELI of EU law)'),
        };
    }

    /** The XHTML of a document in the source's language; null when the Office has none. */
    private function document(string $celex): ?string
    {
        return $this->get(rtrim($this->resourceUri, '/').'/celex/'.rawurlencode($celex), [], [
            'Accept' => 'application/xhtml+xml, text/html;q=0.9',
            'Accept-Language' => strtolower($this->authorityLanguage),
        ]);
    }

    /**
     * @return list<array<string, string>> one array per row: variable => value
     */
    private function sparql(string $query): array
    {
        $prefixes = "PREFIX cdm: <".self::CDM.">\nPREFIX skos: <http://www.w3.org/2004/02/skos/core#>\n";
        $data = $this->getJson($this->baseUri, ['query' => $prefixes.$query], ['Accept' => 'application/sparql-results+json']);
        if (!isset($data['results']['bindings']) || !\is_array($data['results']['bindings'])) {
            throw new ProviderException($this->getName(), 'the SPARQL endpoint did not answer with results');
        }

        return array_map(static fn (array $binding) => array_map(static fn (array $term) => (string) $term['value'], $binding), $data['results']['bindings']);
    }

    private function languageUri(): string
    {
        return '<'.self::AUTHORITY.'language/'.$this->authorityLanguage.'>';
    }

    /** The URI of a code in one of the Office's authority tables: REG, JUDG, PROT, CJ... */
    private static function authority(string $table, string $code): string
    {
        if (!preg_match('~^[A-Za-z0-9_.-]+$~', $code)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a code of the %s authority table.', $code, $table));
        }

        return '<'.self::AUTHORITY.$table.'/'.strtoupper($code).'>';
    }

    private static function literal(string $value): string
    {
        return '"'.addcslashes($value, "\\\"\n\r").'"';
    }

    /** Words as Virtuoso's full-text index takes them: all of them, or the exact expression when in double quotes. */
    private static function fulltext(string $text): string
    {
        $text = trim($text);
        if (preg_match('~^"(.+)"$~su', $text, $m)) {
            return '"'.trim((string) preg_replace('~["\'\\\\]+~u', ' ', $m[1])).'"';
        }
        $words = array_filter((array) preg_split('~[^\p{L}\p{N}]+~u', $text), static fn ($w) => mb_strlen((string) $w) > 1);
        if (!$words) {
            throw new \InvalidArgumentException('The search text holds no word.');
        }

        return implode(' AND ', array_map(static fn ($w) => '"'.$w.'"', $words));
    }

    /** What stands between <body> and </body>. */
    private static function body(?string $html): ?string
    {
        if (null === $html) {
            return null;
        }

        return preg_match('~<body[^>]*>(.*)</body>~is', $html, $m) ? $m[1] : $html;
    }

    /** The <div class="eli-subdivision" id="..."> of that id, with what it holds. */
    private static function subdivision(string $html, string $id): ?string
    {
        if (!preg_match('~<div[^>]*\bid="'.preg_quote($id, '~').'"[^>]*>~', $html, $m, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $m[0][1];
        $depth = 0;
        $offset = $start;
        while (preg_match('~<(/?)div\b[^>]*>~', $html, $tag, \PREG_OFFSET_CAPTURE, $offset)) {
            $depth += '/' === $tag[1][0] ? -1 : 1;
            $offset = $tag[0][1] + \strlen($tag[0][0]);
            if (0 === $depth) {
                return substr($html, $start, $offset - $start);
            }
        }

        return null;
    }
}
