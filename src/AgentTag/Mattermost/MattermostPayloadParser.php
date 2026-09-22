<?php

namespace App\AgentTag\Mattermost;

use Symfony\Component\HttpFoundation\Request;

use function Symfony\Component\String\u;

final readonly class MattermostPayloadParser
{
    public function parse(Request $request): MattermostInboundEvent
    {
        $payload = $this->payload($request);

        return new MattermostInboundEvent(
            $this->requiredString($payload, 'post_id'),
            $this->optionalString($payload, 'text'),
            $this->requiredString($payload, 'post_id'),
            $this->optionalString($payload, 'root_id'),
            $this->requiredString($payload, 'channel_id'),
            $this->optionalString($payload, 'channel_type'),
            $this->optionalString($payload, 'team_id'),
            $this->optionalString($payload, 'user_id'),
            $this->optionalString($payload, 'token'),
            $this->optionalString($payload, 'user_name'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if (u((string) $request->headers->get('content-type'))->containsAny('application/json')) {
            $payload = json_decode($request->getContent(), true);
            if (!is_array($payload)) {
                throw new \InvalidArgumentException('Mattermost webhook payload must be a JSON object.');
            }

            return $this->stringKeyedPayload($payload);
        }

        return $this->stringKeyedPayload($request->request->all());
    }

    /**
     * @param array<mixed, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function stringKeyedPayload(array $payload): array
    {
        $normalized = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Mattermost webhook payload must use string keys.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = $this->optionalString($payload, $key);
        if ('' === $value) {
            throw new \InvalidArgumentException(sprintf('Mattermost webhook payload is missing "%s".', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException(sprintf('Mattermost webhook field "%s" must be scalar.', $key));
        }

        return u((string) $value)->trim()->toString();
    }
}
