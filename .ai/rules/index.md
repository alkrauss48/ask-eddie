# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| routes/api.php, app/Http/Controllers/BarAskController.php, app/Http/Controllers/BartendersController.php, app/Http/Controllers/SearchController.php, app/Http/Middleware/VerifyBarKey.php, app/Ai/Bar/WebTabKeeper.php, app/Ai/Streaming/SseAnswerStream.php, app/Ai/Streaming/AnswerStream.php | .ai/rules/api.md |
| app/Ai/Bar/**, app/Tools/Consultation.php, app/Tools/AskSasha.php, app/Tools/AskEddie.php, app/Ai/Streaming/AnswerStream.php, app/Agents/EddieAgent.php, app/Agents/SashaAgent.php, app/Console/Commands/BarAskCommand.php, config/ai.php, app/Agents/** | .ai/rules/bar.md |
| app/Services/Books/**, app/Services/Books/PageTextNormalizer.php, app/Services/Books/PageExtractor.php, app/Services/Books/Drink*.php | .ai/rules/books.md |
| config/books.php, config/bar.php | .ai/rules/config.md |
| docker/** | .ai/rules/docker.md |
| compose.yaml, docker/**, config/filesystems.php | .ai/rules/general.md |
| app/Services/House/**, app/Models/House*.php, app/Console/Commands/House/**, app/Services/Embedding/**, config/house.php | .ai/rules/house.md |
| database/migrations/** | .ai/rules/migrations.md |
| app/Models/BookChunk.php, app/Models/Drink.php | .ai/rules/models.md |
| app/Services/Retrieval/**, app/Ai/Tei/**, app/Tools/SearchTheBooks.php, app/Agents/EddieAgent.php, app/Services/Books/ChunkEmbedder.php, app/Services/Embedding/**, app/Services/Retrieval/Drink*.php, app/Tools/BrowseTheMenus.php, app/Tools/SearchTheHouse.php, app/Agents/SashaAgent.php, app/Console/Commands/BarAskCommand.php | .ai/rules/retrieval.md |
| tests/** | .ai/rules/tests.md |
| .github/workflows/** | .ai/rules/workflows.md |
