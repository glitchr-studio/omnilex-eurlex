# omnilex/eurlex

## Installation

```sh
composer require omnilex/eurlex
```

```php
use Omnilex\Eurlex\EurlexSourceFactory;

$eurlex = (new EurlexSourceFactory($httpClient))->create(['language' => 'fr']);
```

## The service

Two open accesses of the Publications Office to Cellar
(<https://op.europa.eu/web/cellar/cellar-data/metadata/knowledge-graph>), both without a key:

- the **SPARQL endpoint**, `https://publications.europa.eu/webapi/rdf/sparql`, over the
  metadata described with the Common Data Model (CDM): titles, dates, whether an act is in
  force, its consolidated versions, the links between acts and judgments;
- the **resources by CELEX number**, `https://publications.europa.eu/resource/celex/32016R0679`,
  with content negotiation: `Accept: application/xhtml+xml` and `Accept-Language: fra` redirect
  to the XHTML of the document in that language.

EUR-Lex's own search web service, for registered users, is not used.

## Calls

| Method | Reads | Calls |
|---|---|---|
| `text($id, $at)` | CELEX, ELI (`http://data.europa.eu/eli/...`) | the work's metadata; its consolidated versions; the XHTML of the version of the day |
| `article($id, $number, $at)` | CELEX or ELI of the act, and the article's number | the same; the article is the `eli-subdivision` `art_<number>` of the XHTML |
| `decision($id)` | ECLI (`ECLI:EU:...`), CELEX (sector 6) | the work's metadata; its XHTML; its links |
| `citations($id, $limit)` | CELEX, ELI, ECLI | the links from the document, the links to it |
| `search(Query)` | | one SPARQL query |
| `recent($since, ?Query)` | | the same, by date of the document, newest first |

### An act at a date

An act is published in the Official Journal (CELEX `32016R0679`), then consolidated each time
it is amended or corrected (CELEX `02016R0679-20160504`: the state of the text on 4 May 2016).
`Text::$versions` lists them: the act as published from its date, then each consolidated version
from its date to the day before the next; the last one runs to the act's end of validity.
`text()` and `article()` read the version that covers the day asked for (today by default).

- A day before the act's date: `null`.
- A day between its date and its entry into force: the version, with `Status::FUTURE`.
- A day after its end of validity (a repealed directive): `null`.
- The last version says whether the act is in force (`resource_legal_in-force`): `IN_FORCE`,
  `REPEALED` (ended), `EXPIRED`, or `UNKNOWN` when the Office does not say; the earlier ones
  are `MODIFIED`.
- A consolidated CELEX number names its version: the day is not read.

A version's `from` is the day the text took that wording. The dates of entry into force and of
application are in `Text::$raw['resource_legal_date_entry-into-force']`.

Checked on 2026-10-04: regulation (EU) 2016/679 (two versions), directive 2006/112/EC (28
versions: 2008-06-01 gives `02006L0112-20071229`, 2019-03-03 gives `02006L0112-20160601`),
directive 95/46/EC (repealed: nothing today, `01995L0046-20031120` in 2010).

### An article

The Office publishes recent acts as XHTML divided into `eli-subdivision` blocks. `article()`
takes the block `art_<number>` of the version of the day: its heading as `title`, its text as
`content`, the block as `html`. An article that is not there is `null`; an act whose text is
not divided into articles in that language is a `NotSupportedException`.

### Search and what is new

| `Query` | SPARQL |
|---|---|
| `text`, `title` | `bif:contains` on the titles in the source's language: every word, or the exact expression in double quotes |
| `kind` | `TEXT`: sector 3 (legislation); `DECISION`: sector 6 (case law: judgments, orders, opinions of the Advocates General - `type` says which); none: both |
| `types` | `work_has_resource-type`: `REG`, `DIR`, `DEC`, `REG_IMPL`, `JUDG`, `ORDER`, `OPIN_AG`... |
| `subjects` | `resource_legal_is_about_subject-matter`: the codes of the subject-matter table (`PROT`, `ENV`...) |
| `jurisdictions` | `work_created_by_agent`: `CJ` (Court of Justice), `GCEU` (General Court); `EU` means any |
| `from`, `to` | `work_date_document` |
| `sort` | by date: newest first, or `oldest`; no relevance |
| `limit`, `cursor` | 100 a page at most; the cursor is a row offset; no total |

`recent()` reads the date of the document (`work_date_document`), not the day it was added to
Cellar: a document published late with an old date is not listed as new.

### Links

`work_cites_work` (`CITES`), `case-law_interpretes_resource_legal` (`INTERPRETS`),
`resource_legal_based_on_resource_legal` (`BASED_ON`), `resource_legal_amends_resource_legal`
(`AMENDS`), `resource_legal_repeals_resource_legal` and its implicit form (`REPEALS`), and their
inverses when read from the document cited. Targets without a CELEX number are left out;
newest first; at most `$limit` each way. A target with no title in the source's language is
titled with its CELEX number.

## What is not supported, and why

| | Why |
|---|---|
| Full-text search | the open endpoint indexes metadata and titles, not the texts |
| `at` in `search()` | the endpoint filters acts by date of document; read each act at the date with `text()` |
| `number` in `search()` | an act is read by its CELEX number or its ELI, which carry its number |
| `kind: ARTICLE` in `search()`, `article()` without a number | articles are parts of the XHTML, not works of their own in Cellar |
| `article()` of an act not divided into articles | older acts are published as plain XHTML or PDF |
| French ECLIs, French ELIs | not EU law: see `omnilex/legifrance`, `omnilex/judilibre`, `omnilex/justice-administrative` |

## Limits

No limit is published for the endpoint. Calls are spaced by `throttle` (0.5 s); a 429 is a
`RateLimitedException`, a 5xx or a timeout an `UnavailableException`. A whole text weighs up to
a few megabytes (directive 2006/112/EC: 1.9 MB): cache it - a version that is no longer the last
never changes.

## Tests

`Tests/EurlexSourceTest.php`, on `MockHttpClient`, with answers recorded on 2026-10-04:
regulation 32016R0679 and its versions, its text as published and as consolidated (cut down to
a few articles), judgment ECLI:EU:C:2014:317 and its links, a search of the titles, the
judgments since 2026-09-24. `.samples/live.php` of the workspace runs the same cases against
the real service.
