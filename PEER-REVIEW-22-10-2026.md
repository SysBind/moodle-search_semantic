# Peer review, 22 October 2026

Scope: Moodle core branch `MDL-90218` (SysBind/moodle, `git diff main...MDL-90218`, 8 commits, 23 files)
and the `search_semantic` plugin (`main`, HEAD `1a1e1da`).

Tracker: https://moodle.atlassian.net/browse/MDL-90218

## Verdict

The core branch is close to peer-review ready but has two blockers. The plugin is sound in its
vector-store design but is not releasable yet, mainly for privacy, failure handling and test coverage.

| Check | Result |
|---|---|
| core_ai + aiprovider_ollama PHPUnit suites | 171 tests, 765 assertions, OK |
| search_semantic PHPUnit suite (pgvector tests ran against the live container DB) | 13 tests, 46 assertions, OK |
| phpcs (moodle standard) on lines added by the core branch | 3 errors, all spacing in `public/lib/db/upgrade.php` |
| phpcs on the plugin | 52 issues, nearly all auto-fixable with phpcbf |

Tests were run inside the `moodle-search_semantic-moodle-1` container (Moodle 6.0dev, PHP 8.4, Postgres 18
with pgvector) using `vendor/bin/phpunit --testsuite <component>_testsuite`.

---

## Part 1: core branch MDL-90218

### Blockers

**B1. Version not bumped.**
`public/version.php` on main is already `2026102100.00` and the branch adds an upgrade step guarded by
`$oldversion < 2026102100.00` without touching `version.php`. Any site already on this week's main skips
the creation of `ai_action_embed_text`. Fix: bump to `2026102100.01` and use that in the savepoint.

**B2. Existing Ollama provider instances never get an `embed_text` action config.**
`actionconfig` is only built for new instances (`core_ai\provider::__construct` calls
`initialise_action_settings()` only when the stored JSON is empty). Toggling the action on through the
admin UI goes through `manager::set_action_state()`, which writes only `['enabled' => true]`, with no
`settings`. The consumer then calls `process_action()`, and `aiprovider_ollama\abstract_processor::get_model()`
reads `actionconfig[...]['settings']['model']` unguarded: "Undefined array key" warning followed by a
TypeError, which is not turned into an error response. Precedent: when `explain_text` was added,
`upgrade_add_explain_action_to_ai_providers()` in `public/lib/db/upgradelib.php` backfilled existing
instances. Fix: add `upgrade_add_embed_text_action_to_ai_providers()`, call it from the same upgrade step,
and add a test.

### Major

**M1. Upgrade note is in the wrong place and under the wrong version.**
Main uses `.upgradenotes/*.yml`; the per-component `UPGRADING.md` files are generated from those at release
time by "NOBUG: Add upgrade notes" commits. The branch hand-edits `public/ai/UPGRADING.md` under the existing
`## 5.3` heading. Fix: revert the UPGRADING.md edit and add `.upgradenotes/MDL-90218-<timestamp>.yml` with
entries for `core_ai` (new action, new `$providerid` argument on `process_action()`) and `aiprovider_ollama`
(embed_text support, `MODEL_TYPE_EMBEDDING`).

**M2. Bulk-volume side effects are not handled or documented.**
`embed_text` is the first action that is called in bulk by a system process rather than once per user
click, but it goes through the same `process_action()` pipeline as the user-facing actions. See the section
"The double register row issue" below for the details. At minimum this must be documented in the action
docblock and the upgrade note; the rate-limit question needs an explicit decision.

**M3. install.xml and upgrade.php disagree.**
`install.xml` declares `inputhash` NOTNULL with no DEFAULT; `upgrade.php` passes default `''`. The schema
check compares loosely so it does not fail, but the two should match. Regenerate the upgrade code from the
XMLDB editor and add the usual "Define table / Adding fields / Adding keys / Conditionally launch" comments.

### Minor

- Double spaces at `public/lib/db/upgrade.php:2421` (`<  2026102100.00`) and `:2433`
  (`true,  2026102100.00`). These are the three phpcs errors.
- The reportbuilder `actionname` filter appends `embed_text` after an alphabetical list
  (`public/ai/classes/reportbuilder/local/entities/ai_action_register.php:185`). Move it first.
- `form/action_embed_form.php:37-53` is a verbatim copy of the hidden-field block in
  `action_generate_text_form.php:49-65`; phpcpd will flag it. Hoist into a protected helper in `action_form`.
  The class name also breaks the `action_<actionname>_form` convention (`action_embed_text_form`).
- The default model in `action_form::add_model_fields()` changed from the literal `llama3.3` to "first
  model class of this type in alphabetical order". Today that is still `llama3.3` for text and
  `nomic-embed-text` for embeddings, but a future `gemma3` class would silently change the generate_text
  default. Make the default explicit per model type. Also `get_model_list()` is still called a second time
  at `action_form.php:183`; reuse `$modellist`.
- `process_embed_text::handle_api_success()` reports an invalid vector with error code 500 and a hard-coded
  English message. 500 maps to `serverfailure`, which hides the message unless debugging is on. Follow the
  Anthropic provider precedent: 422 plus a lang string such as `error:invalidembedding`.
- The legacy `/api/embeddings` response branch in `handle_api_success()` is dead: `get_endpoint()` is
  hard-coded to `/api/embed`. Remove it or comment why it exists (proxies).
- `'id' => $bodyobj->id ?? null`: Ollama never returns an id. Harmless noise.
- Test methods in `embed_text_test.php` and `response_embed_text_test.php` have no docblocks; the sibling
  `generate_text_test.php` documents every method. The processor tests use `llama3.2` where
  `nomic-embed-text` would be self-documenting.
- Git: squash into `MDL-90218 core_ai: Add embed_text action` and
  `MDL-90218 aiprovider_ollama: Support embed_text`. Current messages use an `aiaction-embed-text:` prefix,
  one has a typo ("manafer"), one is a fixup ("removed test exception"), and the last has no prefix.

### Verified as correct

- Privacy: deletion blanks `inputhash` rather than deleting rows. This matches how every other action table
  is anonymised in place in the same provider (prompt, generatedcontent, sourceurl are blanked; the
  `ai_action_register` row is kept). Blanking the hash is right: a SHA-256 of short, low-entropy text can be
  reversed with a dictionary. Open question: nothing in core reads `inputhash`. If no consumer needs it,
  dropping the column removes the only content-derived personal data from the table.
- `process_embed_text`: token accounting (`prompt_eval_count` → prompttokens, completiontokens null) is
  right; `abstract_processor::query_ai_api()` only calls `handle_api_success` on HTTP 200 exactly; the
  `is_finite()` guard is reachable (`json_decode` turns `1e400` into INF).
- `store_action_result()` reads the same keys `get_response_data()` returns, so model, prompttokens,
  completiontokens and courseid are populated correctly.
- `model_type()` is declared on the `ollama_base` interface and implemented by both model classes.
- Lang strings are in alphabetical order in both `ai.php` and `aiprovider_ollama.php`.
- `#[\Override]` is used on every overriding method; constructor and promoted-property PHPDoc is complete.
- Placements list their actions explicitly and do not include embed_text, so teachers never see it. Nothing
  in the provider/placement management tables requires `_help` strings. `output\action_detail` falls through
  to metadata only for embed_text, which is acceptable.
- Note for consumers: `manager::process_action()` checks neither policy acceptance nor
  `is_action_enabled_in_context()` (course or activity "AI tools" off). Placements do that themselves. A
  consumer embedding course content will send it to the provider even when a teacher has disabled AI tools
  for that course. Document this on the action.

### Test coverage gaps (core)

- `embed_text::store()`: no test with a failed or empty response (dims 0); no multibyte input, so nothing
  proves `core_text::strlen` counts characters rather than bytes.
- `response_embed_text::set_response_data()`: `prompttokens` absent → null, `completiontokens` present →
  int, string-to-int cast, `id` defaulting to null.
- `process_embed_text`: the `is_finite` rejection; a body with no `embeddings` key; invalid JSON; a non-200
  2xx; the `options` branch through `process()`; a rate-limited response.
- Ollama provider: `get_action_settings('embed_text')` returning `action_embed_form`;
  `get_action_setting_defaults('embed_text')`; `initialise_action_settings()` including embed_text;
  `nomic_embed_text` model class; model list for `MODEL_TYPE_EMBEDDING` (and a regression test that the
  generate_text default is still `llama3.3`); no Behat for the new admin row.
- Core: reportbuilder filter option; `core_ai_generator::create_ai_actions()` embed_text branch has no
  caller; no end-to-end embed_text call through `manager::process_action()` asserting the register columns
  (`model`, `prompttokens`, `completiontokens IS NULL`, `courseid`) and the `ai_action_embed_text` row; a
  pinned provider id that exists but is disabled; `get_action_detail()` for an embed_text row; the new
  upgrade backfill helper.

### Done well (core)

- The pinned-provider filter in `manager::process_action()` is minimal and backward compatible, with a clear
  docblock. Its test asserts both the single call and the no-providers path.
- Privacy is complete across metadata, contexts, export, userlist and the three delete methods.
  Not persisting either the text or the vector is a good data-minimisation choice.
- The embed processor validates the response much more defensively than the sibling processors: shape,
  numeric type and finiteness.
- Modern conventions throughout: constructor promotion, named arguments, `#[\Override]`, PHPUnit
  `CoversClass` attributes.
- The Ollama provider test was updated (count of 4 plus `assertContains`), so the existing test stays truthful.

### The double register row issue (M2 explained)

Every call to `manager::process_action()` ends in `store_action_result()`, which runs inside a DB
transaction and writes two rows:

1. One row in `ai_action_register`: actionname, userid, contextid, courseid (resolved from the context on
   every call), provider, success, model, prompttokens, completiontokens, timecreated, timecompleted.
2. One row in the action's own table, here `ai_action_embed_text`: inputhash, inputlength, embeddingdims.

For the user-facing actions this is one pair of rows per user click, which is what the register was
designed for: an audit trail and a usage report. `embed_text` changes the shape of the traffic:

- **Volume.** A semantic search index embeds every chunk of every document. A medium site with 100k
  documents of 3 chunks each produces 300k pairs of rows on first index, and every reindex produces them
  again. Every search page view adds another pair (the query is embedded too). Nothing prunes either table:
  `public/ai/classes/task/` contains only the courseid backfill task.
- **Per-call overhead.** Besides the two inserts, each call runs `get_providers_for_actions()` (DB read of
  all provider instances), `resolve_courseid()` (context lookup) and the rate-limit checks. For a bulk
  indexer this overhead dominates when the embedding model is fast.
- **Rate limits.** The provider's `is_request_allowed()` applies the same per-user and global rate limits
  to embed_text as to generate_text. A site with a modest `globalratelimit` will see indexing starve the
  placements, or the placements block indexing. There is no way to exempt one action today.
- **Hub statistics.** `core\hub\registration::get_ai_usage_data()` loads every `ai_action_register` row
  in the reporting period with `get_records_sql('SELECT aar.* ...')` into memory, then aggregates in PHP.
  After a reindex that is hundreds of thousands of full rows per registration run. Embedding counts are
  also reported to moodle.org as "AI usage".
- **Usage reports.** `report_aiusage` and the reportbuilder source now include system-driven embedding
  volume attributed to whichever userid the consumer passed. `search_semantic` passes the admin user when
  run from cron, so the course AI usage report will show the admin as the heaviest "user" in every course.

Options, roughly in order of effort:

1. Document it (docblock and upgrade note) so consumers and admins know. Minimum for this PR.
2. Aggregate hub statistics in SQL (`COUNT(*)`, `SUM(prompttokens)` grouped by action and provider)
   instead of loading rows. Independent of this issue, but this issue makes it urgent.
3. Let an action declare that it is exempt from the user rate limit, or let the consumer pass a flag;
   keep the global limit.
4. Add a retention setting and a scheduled task that prunes `ai_action_register` rows older than N days
   for non-user-facing actions, or store only aggregated counters for them.
5. Longer term, give system actions a lighter registration path (one summary row per batch).

The first item is done in this pass. Items 2 to 5 are design decisions for the AI team and should be raised
in a tracker comment rather than decided silently in the branch.

---

## Part 2: `search_semantic` plugin

The plugin is in development; this is a snapshot of what stands between it and a first release.

### Blockers

**P-B1. No Privacy API provider.** `classes/privacy/provider.php` does not exist, and the lang string
claims the plugin stores no personal data. The pgvector table stores chunk text from user content plus
`userid` and `owneruserid`, and every chunk and every search query is sent to an AI provider through
`core_ai` with a userid. Core's privacy compliance test fails. Minimum (Solr pattern,
`search/engine/solr/classes/privacy/provider.php`): `add_external_location_link('pgvector', [...])` plus
`add_subsystem_link('core_ai', ...)`. Better: real `get_contexts_for_userid`, `get_users_in_context` and
`delete_data_*` through the store, since it has userid, owneruserid and contextid columns.

**P-B2. Failed documents are silently and permanently skipped.** `add_document()` returns `false` when
the provider is down, the lock does not match, or the dimensions do not match. Core counts that as
"ignored" and still advances the area's `_indexingstart`, so every document lost during an outage is never
retried until it is edited again. Throw `\core_search\engine_exception` for provider-level failures (lock
mismatch, provider unavailable, transport, 5xx, rate limit) so the task fails and retries; keep `false` for
genuine per-document errors (input too long, HTTP 400). Today this is also inconsistent: a dimension
mismatch in `ensure_collection()` throws, a lock mismatch returns false.

**P-B3. Owner restriction missing.** `core_search\engine::execute_query()` requires engines to restrict
results to `owneruserid IN (NO_OWNER_ID, $USER->id)`. simpledb does it in SQL
(`simpledb/classes/engine.php:101`) and again per row. `build_query_filter()` never does. Private messages
are only protected because the message area's `check_access()` denies; third-party areas that rely on the
owner filter would leak. Add `filter::in('owneruserid', [NO_OWNER_ID, $USER->id])` always, including when
`$accessinfo === true`.

**P-B4. `version.php` `requires = 2026041000`** but the API only exists at core `2026102100`. On a core
with `embed_text` but without the `$providerid` parameter, PHP silently accepts the extra argument to
`process_action()`, failover happens and the pinning contract breaks without any error. Set
`requires = 2026102100` (or `.01` after the core bump). Also, `db/install.xml` was added after the current
plugin version with no bump and no `db/upgrade.php`.

**P-B5. The dev environment ships in the plugin directory.** `docker-moodle-config.php` (hard-coded DB
credentials, no GPL header, web-reachable at `/search/engine/semantic/docker-moodle-config.php` once
installed), `docker-compose*.yaml`, `docker-pg-create-db.sh`, `docker-php.conf`, `docker-run-unittests.sh`,
`PR_SPLIT.md`, `SPEC.md`. Move them to a dev repo, or at least `.gitattributes` `export-ignore` plus a
release build that strips them.

**P-B6. Essentially no engine tests.** `tests/engine_test.php` covers instantiation and alternate config
only. Nothing tests `add_document`, `execute_query`, the access filter, the lock, or any guard in
`embedding_client`. Mock the AI manager via `\core\di::set(\core_ai\manager::class, ...)`. The pgvector
tests default to the dev site's own database (`db`/`moodle`/`p@ssw0rd`), create tables outside the PHPUnit
prefix and never drop the registry table. Follow Solr's pattern: skip unless a
`TEST_SEARCH_SEMANTIC_PGVECTOR_*` constant is defined in config.php.

### Major

- **P-M1. Pagination is broken.** `querytotalcount = count($results)` is at most `$limit`;
  `manager::paged_search()` therefore never shows a next-page link.
- **P-M2. No over-fetch.** The store is asked for exactly `$limit` chunks; dedup of multiple chunks per
  document, ACCESS_DENIED hits and malformed ids all shrink the list below `$limit`. Fetch a multiple (for
  example 5x, capped) or loop with an increasing limit.
- **P-M3. Titles and content.** `title` is the first 120 characters of the chunk and `content` is the
  chunk, so users see snippet text as titles. Only `content` is embedded; `title`, `description1`,
  `description2` are ignored. A document whose content becomes empty returns `true` without deleting its
  old chunks, leaving stale vectors.
- **P-M4. Search form filters ignored.** `title`, `timestart`, `timeend` are not handled (map to
  `filter::range('modified', ...)`); `supports_users()` is not overridden (false) yet `userids` is handled.
- **P-M5. Vectors are not tied to provider and model.** The collection name is fixed
  (`search_semantic_chunks`); the registry stores only dimensions and distance; `collection->model` is never
  persisted; the provider id is never recorded in the store. Clearing the lock (which the lang string tells
  admins to do) lets a different model with the same dimensions write into the same table, mixing vector
  spaces. Two sites sharing one pgvector DB overwrite each other. Derive the collection name from
  `hash(siteidentifier | providerid | model | dims)` as the vectorstore README proposes, persist providerid
  and model in the registry, and compare the `model` returned by the response with the locked model.
- **P-M6. The lock cannot be cleared from the UI**, and `delete(null)` ("Delete index") only empties the
  table. In `delete(null)` run `drop_collection()` and `unset_config()` for the four lock keys. Show the
  provider name, not just the id, in the locked heading. The lock is also written before
  `ensure_collection`/`upsert` succeed, and `lockedembedding*` keys are read even after `embeddinglocked` is
  unset.
- **P-M7. Dead schema.** `search_semantic_doc` and `search_semantic_chunk` in `db/install.xml` are never
  read or written. `search_semantic_chunk.end` is a reserved word. Implement the doc table (it would fix
  P-M3 and help P-B1) or remove it until used.
- **P-M8. Delete paths fail when no collection exists.** `delete`, `delete_by_id`,
  `delete_index_for_context/course` run DELETE unconditionally; before the first index "Delete index"
  throws, and every context deletion produces a debugging message. Guard with `collection_exists()`.
- **P-M9. Query-side errors look like "no results".** Lock mismatch, embedding failure, dimension mismatch
  and missing collection all `return []` without setting `$this->queryerror`.
- **P-M10. Performance.** Per document: `get_provider_instances()` (uncached DB read) 2+N times,
  `process_action` re-reads providers and writes 2 rows per chunk, the store runs
  `vector_extension_version` then `CREATE TABLE IF NOT EXISTS` then `collection_exists` then
  `assert_same_collection` then BEGIN/DELETE/N INSERTs/COMMIT. Per search page: the query is re-embedded.
  Resolve the provider once per engine instance, call `ensure_collection()` once in `index_starting()`,
  implement `supports_add_document_batch()`, use one multi-row INSERT, cache query vectors in MUC keyed by
  `hash(providerid|model|q)`. `split_text` is O(n²) on large texts; fixed 800-char chunks with no overlap
  give poor recall at boundaries.
- **P-M11. pgvector before 0.8.** Without `hnsw.iterative_scan`, HNSW returns at most `ef_search` (40)
  candidates before the WHERE clause, so restrictive access filters can return nothing. Require 0.8 in
  `is_ready()` or fall back. `relaxed_order` can return slightly unordered rows while `hit[]` is documented
  "best first": wrap in a materialised CTE and re-order.
- **P-M12. No `sslmode` option** for the pgvector connection; the connection is never closed; no uninstall
  hook drops the vector tables.

### Minor and style (plugin)

- `engine.php`: no docblocks or `#[\Override]` on any public method; `embedding_client::__construct` too.
- Unneeded `defined('MOODLE_INTERNAL')` in namespaced class files with no side effects (engine,
  embedding_client, embedding_options, engine_test).
- Blank line after the opening brace in every vectorstore class and test (phpcs).
- `distance.php`: inline `/** */` on enum cases should be `//` comments (phpcs).
- `store.php`: `function() use` → `function () use`; line 235 is 150 characters; `@` suppression.
- Lang file not alphabetical (first disorder `alternatestoretype_desc`); `embeddinglockdimensionmismatch`
  unused; `pgvectorhost_desc` mentions the Docker "db" host; hard-coded English in `store::is_ready()`.
- `install.xml`: trailing whitespace on the `<FIELDS>` lines; inconsistent key field lists.
- `is_installed()` returns true unconditionally; should check `function_exists('pg_connect')`.
- `resolve_userid()` attributes all indexing AI usage to the admin, logged against course contexts.
- `filter::in` does not validate that values are scalars; very large access filters can exceed Postgres's
  65535 bound-parameter limit.
- `placeholders()` rewrites every `?`, including any inside future string literals or jsonb operators.
- `tests/engine_test.php` is not `final`, has no `#[CoversClass]`, still says "bare skeleton", repeats a
  `set_config` call, test methods have no docblocks. `store_test` has an empty catch.
- `README.md` typos ("retrival", "semriality"); `classes/vectorstore/README.md` describes hashed
  collection names, `index_starting` and drop-on-delete that are not implemented.
- Git: commit typos ("enginge", "comminucate", "pgvectore"); dev-environment and feature commits mixed;
  `docker-compose.yaml` clones `MDL-aiaction-embed-text` rather than `MDL-90218`.

### Done well (plugin)

- Clean vectorstore abstraction: immutable value objects, validated filter tree, fixed field whitelist,
  distance enum, `store_exception` extending `engine_exception`.
- Careful SQL construction in the pgvector store: identifiers whitelisted by regex, columns whitelisted,
  dimensions and limit cast to int, operators from a `match` on the enum, all values bound through
  `pg_query_params`, conninfo escaped, never runs `CREATE EXTENSION`.
- `upsert` replaces whole documents in a transaction; `ensure_collection` refuses a dimension or distance
  mismatch; `in([])` compiles to FALSE and is tested.
- Provider pinning uses the new core `$providerid` argument with thorough eligibility checks (enabled,
  configured, action supported, action enabled, model set).
- Lazy store construction avoids install-time errors; alternate (query-only) settings get no defaults,
  and that is tested.

---

## Part 3: enriching the tracker issue (MDL-90218)

Current state (REST API, 22 Oct 2026): New Feature, component AI, affects 5.3, priority Minor, status
Open. Pull from `https://github.com/SysBind/moodle`, branch `MDL-90218` (value has a leading space), diff URL
set. A peer reviewer is assigned. No labels, no fix version, no testing instructions, no comments, no
GitHub Actions runs on the fork. Description is three lines.

1. **Rewrite the description** to answer what reviewers will ask: why a core action rather than a
   plugin-defined one; why pinning to a provider instance is needed (vectors are not comparable across
   instances or models); why neither text nor vector is persisted; why Ollama is the first provider. Link the
   plugin repo as the reference consumer and mention RAG.
2. **Add testing instructions** in the standard format: install or upgrade; confirm `ai_action_embed_text`
   exists; create an Ollama instance with `nomic-embed-text` pulled; enable the embed_text action and check
   the model default; run a short PHP snippet (or the plugin indexer) calling
   `process_action(new embed_text(...), $providerid)`; confirm an `ai_action_register` row with model and
   prompt tokens; confirm the AI usage report filter shows "Embed text (internal)"; run a privacy export and
   deletion for the user and check `inputhash` is blanked.
3. **Fix the Pull fields.** Remove the leading space from Pull Main Branch; re-check the diff URL after the
   squash and rebase.
4. **Versions and labels.** Affects version should be the main dev version, not the released 5.3. Add
   `api_change`, `dev_docs_required`, `docs_required`, `unit_test_required`. Fix version is set by integrators.
5. **Enable GitHub Actions on the fork** so the issue can show a green run.
6. **Upgrade note.** The `.upgradenotes` YAML file is what generates the UPGRADING entries.
7. **Comment on the design decisions up front**: register-row volume and rate-limit sharing (see M2),
   usage attribution of system-driven embeddings, and the course "AI tools off" gap. Raising these yourself
   is better than having the AI team find them.
8. **Move status to "Waiting for peer review"** only after the blockers and the squash are done.

---

## Part 4: fixes applied to the core branch in this pass (22 Oct 2026, uncommitted)

All core blockers and majors above were addressed in the working tree of `MDL-90218`. Nothing was committed.

| Item | Change |
|---|---|
| B1 version | `public/version.php` bumped to `2026102100.01`; upgrade step and savepoint use the same number |
| B2 backfill | New `upgrade_add_embed_text_action_to_ai_providers()` in `public/lib/db/upgradelib.php`, called from the upgrade step. Adds `['enabled' => true, 'settings' => ['model' => 'nomic-embed-text']]` to every `aiprovider_ollama\provider` instance that has no complete embed_text entry; leaves complete entries and other providers untouched. New test `test_upgrade_add_embed_text_action_to_ai_providers` in `public/lib/tests/upgradelib_test.php` covers: no entry, entry with no settings (admin toggled before upgrade), complete entry, unsupported provider |
| M1 upgrade note | Hand edit to `public/ai/UPGRADING.md` reverted (file now identical to main). Added `.upgradenotes/MDL-90218-2026102223195225.yml` with two `core_ai` notes (new action, `$providerid`) and one `aiprovider_ollama` note. Written by hand because `node_modules` is not installed locally; run `npm ci && npm run upgradenote` once to let the tool validate it |
| M2 bulk usage | Action docblock in `public/ai/classes/aiactions/embed_text.php` now lists the register rows, rate limits, usage attribution and the policy / "AI tools" switch gap. The upgrade note repeats it. The rate-limit exemption and hub aggregation remain open design questions for the tracker |
| M3 schema | Upgrade step regenerated in XMLDB-editor form: `inputhash` has no default (matches install.xml), standard comments added, double spaces removed |

Verification after the changes, inside the container (PHPUnit re-initialised for the new version):

| Check | Result |
|---|---|
| `test_upgrade_add_embed_text_action_to_ai_providers` with `--fail-on-notice` | 1 test, 5 assertions, OK |
| `core_ai_testsuite` + `aiprovider_ollama_testsuite` | 171 tests, 765 assertions, OK |
| phpcs (moodle) on lines changed in this pass | 0 violations |

Still to do before "Waiting for peer review": the minor items in Part 1, the coverage gaps, squashing the
commits, enabling GitHub Actions on the fork, and the tracker fields in Part 3.
