# Vector store abstraction

`search_semantic\vectorstore` hides the vector database (pgvector, Qdrant, ...) behind a single
interface, `store`. The search engine talks only to this interface, so backends can be swapped
without touching indexing or query code.

## Responsibilities

| Engine (`search_semantic\engine`) | Store (`vectorstore\store` implementation) |
|---|---|
| Split documents into chunks | Persist chunks: vector, text, metadata |
| Call the embedding model | Create and drop collections and indexes |
| Turn Moodle access info into a `filter` | Translate `filter` into the backend's dialect |
| Run `check_access()` on final results | Run filtered nearest-neighbour search |
| Rerank results and build RAG prompts | |

The store knows nothing about embedding models or Moodle permissions.

## Types

| Class | Role |
|---|---|
| `store` | The interface backends implement |
| `collection` | Name, dimensions, `distance`, embedding model id |
| `distance` | Enum: `cosine`, `dot`, `euclidean` |
| `chunk` | One embedded piece of a search document, written by `upsert()` |
| `filter` | Backend-neutral boolean expression over chunk metadata |
| `query` | Vector, limit, optional `filter`, optional minimum score |
| `hit` | One search result: docid, chunk index, score, text, metadata |
| `store_exception` | Thrown on backend failures (extends `core_search\engine_exception`) |

## The `store` interface

```php
interface store {
    public function is_ready(): true|string;
    public function collection_exists(collection $collection): bool;
    public function ensure_collection(collection $collection): void;
    public function drop_collection(collection $collection): void;
    public function upsert(collection $collection, array $chunks): void;
    public function delete(collection $collection, filter $filter): void;
    public function search(collection $collection, query $query): array; // hit[]
}
```

How the engine uses it:

| `core_search\engine` method | Store call |
|---|---|
| `is_server_ready()` | `is_ready()` and `collection_exists()` |
| `index_starting()` | `ensure_collection()` |
| `add_document()` / `add_document_batch()` | `upsert()` |
| `delete_by_id($id)` | `delete(filter::eq('docid', $id))` |
| `delete($areaid)` | `delete(filter::eq('areaid', $areaid))` |
| `delete(null)` | `drop_collection()` then `ensure_collection()` |
| `delete_index_for_context($id)` | `delete(filter::eq('contextid', $id))` |
| `delete_index_for_course($id)` | `delete(filter::eq('courseid', $id))` |
| `execute_query()` | `search()` |

## Design decisions

### Documents are split into chunks

A `core_search\document` is split into one or more chunks, each with its own vector, because
embedding models have input limits and smaller chunks retrieve more precisely. Every chunk carries
its document's metadata.

- A chunk id is `{docid}#{index}`, for example `mod_forum-post-12#0`. Backends that need numeric or
  UUID keys (such as Qdrant) should derive them from this id.
- `upsert()` replaces whole documents. For every docid in the batch, all of that document's stored
  chunks are deleted before the new ones are written. Without this, a document that shrinks from 5
  chunks to 3 would leave 2 stale chunks in the index.
- `hit` returns a single chunk. The engine groups hits by `docid` to build one result per document.

### Metadata fields are fixed

Chunks can only carry, and filters can only use, the fields in `chunk::FILTERABLE_FIELDS`:

`docid`, `areaid`, `itemid`, `contextid`, `courseid`, `owneruserid`, `userid`, `groupid`, `type`,
`modified`

The names match `core_search\document` fields, so `chunk::from_document()` copies them directly.
Fields the document does not set (for example `groupid`) are stored as null. Unknown fields throw a
`coding_exception`.

The list is fixed rather than free-form so that backends can store these fields as real, indexed
columns (pgvector) or indexed payload fields (Qdrant). Filtering on free-form JSON is slow. Adding
a field means changing `FILTERABLE_FIELDS` and the schema of every backend.

### Filters are applied during the search

`search()` must apply the query filter inside the nearest-neighbour scan, not on the top-k results
afterwards.

With post-filtering, a user who can see only a few courses gets the global top 10 chunks, finds
that all 10 are in courses they cannot access, and sees no results even though relevant content
exists. In pgvector this means using iterative index scans (`hnsw.iterative_scan`) or exact search
for very selective filters. In Qdrant it means passing the filter in the search request.

The engine still calls `check_access()` on the final results, as `core_search\engine` requires.
The filter is there so that enough results survive that check, not to replace it.

### Filters are an expression tree

`filter` is built with static constructors and is read by backends through its public `op`,
`field`, `value` and `children` properties:

| Constructor | Meaning |
|---|---|
| `filter::all()` | Matches every chunk |
| `filter::eq($field, $value)` | Field equals value |
| `filter::in($field, $values)` | Field equals one of the values; an empty list matches nothing |
| `filter::range($field, $min, $max)` | Inclusive range; either bound may be null |
| `filter::is_null($field)` | Field is null |
| `filter::and(...$filters)` | All match |
| `filter::or(...$filters)` | Any matches |
| `filter::not($filter)` | Does not match |

`and()` and `or()` with a single operand return that operand unchanged.

This is enough to express the access information `core_search\manager` passes to
`execute_query()` when the engine supports group filtering. For example:

```php
use search_semantic\vectorstore\filter;

// Contexts the user can access, per search area.
$areas = [];
foreach ($accessinfo->usercontexts as $areaid => $contextids) {
    $areas[] = filter::and(
        filter::eq('areaid', $areaid),
        filter::in('contextid', $contextids),
    );
}

$access = filter::and(
    filter::or(...$areas),
    // Documents with no owner, or owned by the current user.
    filter::in('owneruserid', [\core_search\manager::NO_OWNER_ID, $USER->id]),
    // In separate-groups contexts: no group, or one of the user's groups.
    filter::or(
        filter::not(filter::in('contextid', $accessinfo->separategroupscontexts)),
        filter::is_null('groupid'),
        filter::in('groupid', $accessinfo->usergroups),
    ),
);
```

The real translation also has to handle `everything` (admin, no restriction), the
`visiblegroupscontextsareas` exceptions, and the user's filters (`areaids`, `courseids`,
`groupids`, `userids`, and `timestart`/`timeend` mapped to `range('modified', ...)`).

### Collections are tied to one embedding model

Vectors from different models, or with different dimensions, cannot be compared. A `collection`
therefore records its dimensions, distance metric and model id.

`ensure_collection()` is idempotent but must throw if the collection already exists with
different dimensions or distance, rather than silently reusing it.

Collection names must match `^[a-z][a-z0-9_]{0,62}$`, so they are safe as SQL table names.

## How the engine uses collections

### The collection is derived from the engine settings

The engine builds its `collection` in its constructor from its embedding settings. No I/O is
involved, and no separate "current collection" setting is stored:

```php
public function __construct(bool $alternateconfiguration = false) {
    parent::__construct($alternateconfiguration);
    $model = $this->config->embeddingmodel;
    $dims = (int)$this->config->embeddingdimensions;
    $this->collection = new collection(
        'semantic_' . substr(sha1($model . '|' . $dims), 0, 12),
        $dims, distance::cosine, $model);
}
```

The name is a hash of the model and dimensions, so changing the model gives a different
collection.

### Only indexing creates collections

- `index_starting()` calls `ensure_collection()`.
- `is_server_ready()` calls `collection_exists()` and reports an error if the collection is
  missing. The query side never calls `ensure_collection()`, because that would silently create
  empty collections.
- `delete(null)` (admin "Delete index", or `search/cli/indexer.php --reindex`) drops and recreates
  the collection, which is faster than deleting every chunk.

### Changing the embedding model

Moodle stores indexing progress per search area (`_lastindexrun` and related settings), not per
engine. After a model change the new collection is empty, but Moodle still treats every area as
indexed, so only content edited afterwards would be indexed. A full reindex is required.

To change models without search downtime, use core's query-only engine
(`$CFG->searchenginequeryonly`) together with the engine's alternate configuration
(`engine::has_alternate_configuration()`). With the alternate configuration, every `alternateX`
setting replaces `X` in `$this->config`, so an alternate engine set to the old model derives the old
collection with no extra code.

1. Copy the current model settings into `alternateembeddingmodel` and
   `alternateembeddingdimensions`.
2. Set "Query-only search engine" to `semantic-alternate`. Queries keep using the old collection.
3. Change the main model settings, then run `php search/cli/indexer.php --reindex`. This resets the
   per-area progress and fills the new collection. The `delete()` it calls only affects the
   indexing engine's collection.
4. Clear "Query-only search engine", then drop the old collection.

The engine needs `has_alternate_configuration()` and the alternate settings in `settings.php` for
this to work.

### Guarding against a half-filled index

After a successful full index (`index_complete($numdocs, true)`) the engine saves the collection
name in its config. If the configured collection differs from the saved one, `is_server_ready()`
returns "Embedding model changed, full reindex required". Admins then see the problem instead of
search results that quietly miss content.

### Scores are "higher is better"

`hit::$score` is always a similarity where a higher value is better, whatever the collection's
distance metric. Backends convert distances (for example cosine similarity = 1 − cosine distance)
so rankers, `query::$minscore` and the UI do not depend on the metric.

### Chunk text is stored with the vector

`hit::$text` holds the chunk text, so results can be shown and passed to RAG prompts without
loading the source records again.

## Implementing a backend

A `store` implementation must:

- Store each chunk's vector, text, index, `docid` and every field in `chunk::FILTERABLE_FIELDS`,
  allowing nulls.
- Index the fields used most in filters (`docid`, `areaid`, `contextid`, `courseid`), and build a
  vector index (HNSW or similar) for the collection's distance.
- Make `ensure_collection()` idempotent, and throw if an existing collection does not match.
- Keep `collection_exists()` read-only: it must never create anything.
- In `upsert()`, replace whole documents, atomically per call where the backend allows it (for
  example in one database transaction).
- Translate every `filter` operator. `in` with an empty list matches nothing. `not`, `and` and
  `or` nest to any depth.
- Apply filters during the vector search, not afterwards.
- Return hits best first, with "higher is better" scores, and leave out hits below
  `query::$minscore`.
- Throw `store_exception` on backend failures, and return an error message (not throw) from
  `is_ready()`.

## Open items

- No backend implementation yet. pgvector comes first, since the docker environment runs it.
- No embedding source yet. The Moodle AI subsystem on `MDL-RAG-AI-ACTIONS` has no embedding
  action.
- The engine does not yet translate access information into a `filter`, or use the store.
- The engine settings (embedding model and dimensions, their alternate versions) and
  `has_alternate_configuration()` do not exist yet.
- Optional capabilities such as hybrid (vector + keyword) search are not part of the interface.
  They can be added later as a separate interface that a store may also implement.
