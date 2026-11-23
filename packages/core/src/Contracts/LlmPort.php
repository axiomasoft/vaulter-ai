<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Closure;
use Vaulter\Ai\Core\Data\HostProfile;

interface LlmPort
{
    /**
     * Produce text followed by one complete tool call or finish. Tool messages contain the
     * tool name, server-issued call_id, validated arguments as a stdClass, and response.
     * Adapters resolve connection secrets on the server and assemble tool argument fragments
     * before completion.
     * An adapter may use a server-issued call ID when retransmitting an accepted call.
     *
     * @param  list<array<string,mixed>>  $messages
     * @param  array<string,array<string,mixed>>  $tools
     * @param  Closure():bool  $cancelled
     * @return iterable<
     *     array{type:'textDelta', text:string}
     *     |array{type:'toolInputComplete', tool:string, input:string, providerToolCallId?:string, replayCallId?:string}
     *     |array{type:'finish'}
     * >
     */
    public function stream(array $messages, array $tools, HostProfile $profile, Closure $cancelled): iterable;
}
