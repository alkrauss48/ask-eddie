<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => 'openai',
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    // Corpus vectors and query vectors must come from the same model, and
    // whereVectorSimilarTo() auto-embeds a string query with no provider or
    // model argument -- so leaving this on "openai" would embed every query
    // with a different model than the corpus, with no exception raised and
    // plausible-looking output. See config/books.php's embedding block.
    'default_for_embeddings' => env('AI_EMBEDDINGS_PROVIDER', 'tei'),
    'default_for_reranking' => env('AI_RERANKING_PROVIDER', 'tei-rerank'),

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversations
    |--------------------------------------------------------------------------
    |
    | Where a guest's tab is written down. The tables were published with the
    | package's migration on day one and sat empty until tabs arrived; the keys
    | are named here rather than left to the inline defaults scattered through
    | the store, the models and the migration, so the connection and the table
    | names are visible in one place. A null connection is the default one --
    | config('database.default') cannot be read from this file, which is loaded
    | before database.php.
    |
    | "generate_title" is off deliberately. With it on, laravel/ai names each
    | new conversation with an extra call to the provider's cheapestTextModel()
    | -- a model nobody in config/bar.php chose, billed once per tab, which is
    | the same silent-provider failure .ai/rules/bar.md disqualifies AgentTool
    | for. App\Ai\Bar\TabKeeper creates the row itself and titles it with what
    | the guest said, so the call would not fire today in any case; this is here
    | so a future caller that lets the middleware open a conversation does not
    | quietly start paying for one.
    |
    */

    'conversations' => [
        'connection' => env('AI_CONVERSATIONS_CONNECTION'),
        'generate_title' => false,
        'tables' => [
            'conversations' => 'agent_conversations',
            'messages' => 'agent_conversation_messages',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
            'image_deployment' => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-1'),
            'store' => env('AZURE_OPENAI_STORE', true),
        ],

        'bedrock' => [
            'driver' => 'bedrock',
            'region' => env('AWS_BEDROCK_REGION', 'us-east-1'),
            'key' => env('AWS_BEARER_TOKEN_BEDROCK'),
            'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
            'assume_role' => [
                'arn' => env('AWS_BEDROCK_ASSUME_ROLE_ARN'),
                'session_name' => env('AWS_BEDROCK_ASSUME_ROLE_SESSION_NAME'),
                'duration_seconds' => env('AWS_BEDROCK_ASSUME_ROLE_DURATION_SECONDS'),
                'external_id' => env('AWS_BEDROCK_ASSUME_ROLE_EXTERNAL_ID'),
            ],
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        /*
        | The driver falls back to its own default text model when this block
        | is absent, which is the expensive tier. Eddie's answers are short and
        | the retrieval work is done by the time the model sees them, so the
        | cheaper model is pinned here explicitly rather than left to the
        | package's tier mapping, which can move between releases.
        */
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', true),
            'models' => [
                'text' => [
                    'default' => env('OPENAI_TEXT_MODEL', 'gpt-5.6-luna'),
                ],
            ],
        ],

        'openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => env('OPENAI_COMPATIBLE_URL'),
            'key' => env('OPENAI_COMPATIBLE_API_KEY'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        /*
        | Text Embeddings Inference, running locally in Docker. It speaks the
        | OpenAI /v1/embeddings shape, so the stock openai-compatible driver
        | drives it with no custom code. The default model is required: the
        | driver throws rather than guessing one.
        */
        'tei' => [
            'driver' => 'openai-compatible',
            'url' => env('TEI_EMBED_URL', 'http://tei-embed:80/v1'),
            'key' => env('TEI_EMBED_KEY', ''),
            'models' => [
                'embeddings' => [
                    'default' => env('BOOKS_EMBEDDING_MODEL', 'BAAI/bge-m3'),
                    'dimensions' => (int) env('BOOKS_EMBEDDING_DIMENSIONS', 1024),
                ],
            ],
        ],

        /*
        | TEI's cross-encoder endpoint is /rerank rather than anything OpenAI
        | defines, and the package ships reranking for Bedrock, Jina, Cohere and
        | VoyageAI only -- so this driver is registered by the application in
        | AppServiceProvider::boot() via Ai::extend(). It is a real provider, so
        | Reranking::of(), Reranking::fake() and the rest work unchanged.
        */
        'tei-rerank' => [
            'driver' => 'tei-rerank',
            'url' => env('TEI_RERANK_URL', 'http://tei-rerank:80'),
            'key' => env('TEI_RERANK_KEY', ''),
            'models' => [
                'reranking' => [
                    'default' => env('BOOKS_RERANKING_MODEL', 'BAAI/bge-reranker-v2-m3'),
                ],
            ],
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

];
