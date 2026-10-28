# Moodle Semantic Search Engine (`search_semantic`)

Utilize vector embeddings and retrival (via vector database) to search content by semantic semriality,
Optionally (but recommended) enhance and reorder results via reranking model after retrival


## Run Locally

```
docker compose up
```

Or with Ollama service

```
docker compose -f docker-compose.yaml -f docker-compose.ollama.yaml up
```
