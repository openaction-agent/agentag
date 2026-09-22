<?php

namespace App\AgentTag\Runner;

use App\AgentTag\Configuration\AgentTagSettings;
use Psr\Log\LoggerInterface;

use function Symfony\Component\String\u;

final readonly class CodexTaskModelSelector implements TaskModelSelector
{
    public function __construct(
        private ProcessFactory $processFactory,
        private AgentTagSettings $settings,
        private ?LoggerInterface $logger = null,
    ) {
    }

    #[\Override]
    public function select(string $request): TaskModelSelection
    {
        $fallback = TaskModelSelection::fallback('The model selector was unavailable, so the general-purpose route was used.');
        $identifier = bin2hex(random_bytes(8));
        $outputPath = sys_get_temp_dir().'/agentag-model-selection-'.$identifier.'.json';
        $schemaPath = sys_get_temp_dir().'/agentag-model-selection-schema-'.$identifier.'.json';
        $prompt = <<<'PROMPT'
You are a model router for a coding and operations agent working on OpenAction (Linear, GitHub, Sentry, Coolify previews, OpenAction instance MCP tools). Maximize answer quality per unit of cost. Route by the work actually required (scope, risk, verifiability), not by keywords. The selected route is kept for the whole Mattermost thread, so route by the overall task the request starts.

Honor an explicit request for a model or route. When only a model is requested, pick the matching route with the most appropriate effort. gpt-6-sol-max, gpt-6-astra-max and opus-5-5-max are used only when explicitly requested.

Routes:
- gpt-6-luna-medium: control and meta messages only — stop/cancel, ping, "are you there", model/access/MCP connection checks, bare "continue"/"ok"/"go" with no new content.
- opus-5-5-medium: default for technical implementation and specification writing:
  - Implementing an issue, feature or bugfix ($implement-issue), opening a PR, including a single issue handled end to end (ticket + implementation + PR).
  - Code changes on an existing PR or branch: requested modifications, fixing review findings or validation failures, simplification.
  - Writing or revising functional or technical specs ($specify-issue, $specify).
  - Porting, backporting or syncing code between repositories or forks ($sync-fork, $rebase-pr), including conflict resolution.
  - Infrastructure and DevOps changes (Coolify preview stack, docker compose, CI, deployment configuration).
- opus-5-5-high: large-scale implementation spanning a whole epic or many issues in one task (e.g. "implement all sub-issues of this epic", multi-issue or multi-repository delivery).
- gpt-6-astra-medium: code review of a PR ($review-pr, "review la PR", "refais une review complète", feature-flag isolation checks), and tasks that combine review with fixing or improving the code in the same request ("review et corrige", "review et améliore au maximum").
- gpt-6-sol-high: everything else, including:
  - Functional validation of a PR on a preview or local environment ($validate-pr).
  - Bug and incident diagnosis without a requested code change (Sentry errors, "why does X fail", fork-vs-europe regression checks, bug reproduction).
  - Questions about product behavior or business rules answered from the code.
  - Linear operations (create, assign, label, status, link, comment, duplicates, status questions).
  - Reads, statistics and operations on live OpenAction instances via MCP.
  - Product thinking, architecture discussions, user stories, research and writing.
  - Conversational follow-ups and clarifications.
- gpt-6-sol-max, gpt-6-astra-max, opus-5-5-max: only on explicit request.

Rules:
- Precedence: explicit model or route request, then opus-5-5-high, then gpt-6-astra-medium, then opus-5-5-medium, then gpt-6-luna-medium, then gpt-6-sol-high.
- A request that combines several kinds of work is routed by its heaviest part (e.g. "create a ticket and implement it" is opus-5-5-medium; "implement, then review and fix" is opus-5-5-medium unless it spans an epic).
- A code review that only reports findings, or posts them as a comment, is gpt-6-astra-medium; fixing findings from an earlier review, without reviewing again, is opus-5-5-medium.
- Number of files, tool calls, MCP calls or arithmetic alone never justify escalation. Escalate to opus-5-5-high only for epic-scale or multi-issue implementation.

Return only the JSON object required by the output schema. Keep selection_reason concise and in the same language as the request when it is French or English.

User request:
PROMPT;
        $prompt .= "\n".$request;

        try {
            $schema = json_encode($this->outputSchema(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);
            if (false === file_put_contents($schemaPath, $schema)) {
                throw new \RuntimeException('Unable to write the model-selection output schema.');
            }

            $process = $this->processFactory->create([
                'codex', 'exec',
                '--ephemeral',
                '--ignore-rules',
                '--skip-git-repo-check',
                '--sandbox', 'read-only',
                '--model', $this->settings->modelSelectionModel(),
                '-c', 'model_reasoning_effort="max"',
                '--output-schema', $schemaPath,
                '--output-last-message', $outputPath,
                '-',
            ], sys_get_temp_dir(), [], $prompt, $this->settings->modelSelectionTimeoutSeconds());
            $callback = static function (string $_type, string $_buffer): void {};
            $process->start($callback);
            $process->wait($callback);
            if (0 !== $process->exitCode() || !is_file($outputPath)) {
                return $fallback;
            }

            return $this->parse((string) file_get_contents($outputPath)) ?? $fallback;
        } catch (\Throwable $exception) {
            $this->logger?->warning('Task model selection failed; using the general-purpose route.', [
                'error' => $exception->getMessage(),
            ]);

            return $fallback;
        } finally {
            foreach ([$outputPath, $schemaPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'route' => [
                    'type' => 'string',
                    'enum' => TaskModelSelection::selectableRoutes(),
                ],
                'selection_reason' => ['type' => 'string'],
            ],
            'required' => ['route', 'selection_reason'],
            'additionalProperties' => false,
        ];
    }

    private function parse(string $output): ?TaskModelSelection
    {
        $data = json_decode(u($output)->trim()->toString(), true);
        if (!is_array($data)) {
            return null;
        }

        $route = is_string($data['route'] ?? null) ? $data['route'] : '';
        $reason = is_string($data['selection_reason'] ?? null) ? $data['selection_reason'] : '';
        if (!in_array(u($route)->trim()->lower()->toString(), TaskModelSelection::selectableRoutes(), true)) {
            return null;
        }

        return TaskModelSelection::fromRoute($route, $reason);
    }
}
