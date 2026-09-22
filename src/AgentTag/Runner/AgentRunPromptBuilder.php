<?php

namespace App\AgentTag\Runner;

use App\Entity\AgentRun;

use function Symfony\Component\String\u;

final readonly class AgentRunPromptBuilder
{
    public function build(AgentRun $run, ?string $steering = null): string
    {
        $continuation = null === $run->codexThreadId()
            ? sprintf(
                "Session context:\n%s\n\nCurrent user request (authoritative; answer this request):\n%s",
                $run->contextSnapshot() ?? '(none)',
                $run->inputSummary() ?? '(none)',
            )
            : $this->resumeContext($run, $steering);

        return u(<<<PROMPT
You are AgentTag running a durable task inside an isolated session workspace.

Interaction rules:
- Answer only in French or English.
- Answer in English only when the latest user message is confidently determined to be English. Otherwise answer in French, including when the message is French, mixed, ambiguous, language-neutral, written in another language, or its language is uncertain.
- Keep Mattermost updates concise, specific, and free of raw command output or harness internals.
- During longer work, emit occasional meaningful progress messages. The first sentence becomes the current task-card stage.
- Complete the task directly in this Codex session. Do not delegate it to a subagent.
- Ask for confirmation only for sensitive actions such as pushing to main, force pushing, deleting, overwriting, or destructive data changes.
- Opening a pull request or writing a Linear comment is not sensitive by itself.
- Continue until the requested outcome is genuinely complete and verified.

Completion response:
- Start directly with the outcome, without another status heading.
- When relevant, use the short sections "Cause", "Changed", "Verification", and "PR".
- Name changed files and concrete verification results.

Durable continuation protocol:
- If completion depends on CI, a review, a scheduled time, or another external event, do the work currently possible and explain the milestone.
- End the response with exactly one machine-readable comment on its own line:
  <!-- agentag:{"action":"wait","seconds":300,"reason":"Waiting for CI"} -->
- Use 30 to 86400 seconds. Do not emit this comment when the task is complete or needs user input.

{$continuation}
PROMPT)->trim()->toString();
    }

    private function resumeContext(AgentRun $run, ?string $steering): string
    {
        $lines = [
            'This is a resumed stage of the same task. Preserve and inspect the existing workspace state.',
        ];
        if (null !== $run->waitReason()) {
            $lines[] = 'Scheduled wake reason: '.$run->waitReason();
        }
        if (null !== $steering && '' !== u($steering)->trim()->toString()) {
            $lines[] = "New user steering:\n".u($steering)->trim();
        } else {
            $lines[] = 'Re-check the pending external state and continue from the last completed stage.';
        }

        return u("\n\n")->join($lines)->toString();
    }
}
