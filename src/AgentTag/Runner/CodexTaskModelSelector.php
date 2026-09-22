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
        $fallback = TaskModelSelection::solMedium('The model selector was unavailable, so the general-purpose route was used.');
        $identifier = bin2hex(random_bytes(8));
        $outputPath = sys_get_temp_dir().'/agentag-model-selection-'.$identifier.'.json';
        $schemaPath = sys_get_temp_dir().'/agentag-model-selection-schema-'.$identifier.'.json';
        $prompt = <<<'PROMPT'
You are a model router. Minimize quota usage while preserving correctness, judgment, and completeness. Route by actual scope, uncertainty, sensitivity, and verifiability, not merely by keywords such as "code" or "implement."

Honor an explicit request for a model or route. When only a model is requested, choose the appropriate effort for that model from the available routes.

Routing:
- Stop/cancel, ping, health/model/skills check, or simple confirmation: luna-medium.
- Genuinely simple or deterministic work, excluding coding tasks, including linear status, assignment, labels, comments, or writing: luna-xhigh.
- OpenAction MCP manipulation or information retrieval that is not part of a coding task: terra-high.
- Default for routine agentic, product behavior questions, and multi-step tool work: terra-high.
- Functional testing: sol-high.
- Default for coding tasks, including specification writing, implementation, PR reviews, and technical diagnostics/debugging: sol-high.
- Security-sensitive, architectural, high-blast-radius, highly ambiguous, uncertain, or exceptionally difficult/complex work: sol-xhigh.

Rules:
- Apply the routes in this precedence order: explicit model request, sol-xhigh risk, coding or functional testing, OpenAction MCP-only work, then the remaining defaults.
- Coding and functional-testing routes override tool-based routes. In particular, $implement-issue and any request to implement a Linear issue are coding tasks and must use sol-high even though they also use Linear, GitHub, or other OpenAction MCP tools.
- Multiple files, tool calls, MCP calls, and arithmetic do not alone justify escalation.
- Escalate from Luna only when the work meets a Terra or Sol condition above; use Sol when the discovered risk meets a Sol condition.

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
                '-c', 'model_reasoning_effort="high"',
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
                    'enum' => [
                        'luna-high',
                        'luna-max',
                        'luna-medium',
                        'luna-xhigh',
                        'terra-medium',
                        'terra-high',
                        'terra-xhigh',
                        'terra-max',
                        'sol-medium',
                        'sol-high',
                        'sol-xhigh',
                    ],
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

        return TaskModelSelection::fromRoute($route, $reason);
    }
}
