# omnilex/eurlex

EU law for [glitchr/omnilex](https://github.com/glitchr-studio/omnilex), from the Publications
Office's open access to Cellar, the repository behind EUR-Lex: an act **in its consolidated
version at a date**, one article of it, a judgment of the Court of Justice by its ECLI, the
links between acts and judgments, what is new. No key.

```php
$eurlex = (new EurlexSourceFactory($http))->create(['language' => 'fr']);

$gdpr = $eurlex->text('32016R0679', new DateTimeImmutable('2018-06-01'));
$gdpr->title;                         // Règlement (UE) 2016/679 du Parlement européen et du Conseil du 27 avril 2016 ...
$gdpr->version->id;                   // 02016R0679-20160504: the consolidated version of that day
$gdpr->versions;                      // as published, then each consolidated version

$eurlex->article('32016R0679', '17')->title;          // Droit à l'effacement («droit à l'oubli»)

$judgment = $eurlex->decision('ECLI:EU:C:2014:317');  // Google Spain, C-131/12
$judgment->citations;                                 // interprets 31995L0046; cites 62012CJ0473...

$eurlex->citations('32016R0679', 20);                 // what the regulation cites, what cites it
$eurlex->recent(new DateTimeImmutable('-14 days'), new Query(kind: Kind::DECISION, jurisdictions: ['CJ'], types: ['JUDG']));
```

```yaml
omnilex:
    sources:
        eurlex: { factory: eurlex, options: { language: fr } }
```

## What you need to obtain

Nothing: the endpoint is open, without a key nor an account. The conditions of re-use are those
of EUR-Lex's legal notice: read it before publishing these texts, and name the source.

| Option | |
|---|---|
| `language` | ISO 639-1, one of the 24 official languages: titles, labels and texts (`fr`) |
| `content` | `false`: `text()` and `decision()` do not fetch the text itself (one call less, up to a few megabytes) |
| `throttle` | seconds between two calls (0.5) |
| `base_uri`, `resource_uri` | the SPARQL endpoint and the resources, to override |

**Consolidated versions are documentary tools: only the texts published in the Official
Journal of the European Union are authentic.**

Verified against the real service on 2026-10-04 (see [docs/](docs/index.md)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
