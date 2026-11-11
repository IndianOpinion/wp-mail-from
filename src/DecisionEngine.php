<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

/**
 * The From decision for one message (C17 to C25). Pure: no hooks, no I/O,
 * so the live path and the CLI preview give the same answer.
 */
final class DecisionEngine
{
    public function __construct(private readonly Validator $validator)
    {
    }

    public function decide(Identity $identity, ParsedHeaders $headers, string $mode): Decision
    {
        $fromBefore = $headers->fromAddress ?? $identity->coreDefault;
        $nameBefore = $headers->fromName ?? Domains::CORE_DEFAULT_NAME;

        if (!$identity->isValid()) {
            return new Decision(
                'unchanged',
                $mode,
                $fromBefore,
                $fromBefore,
                $nameBefore,
                $nameBefore,
                'no valid configured or derived address; core values kept'
            );
        }
        $configured = (string) $identity->from;

        $explicit = $headers->fromAddress;
        $reason = 'no explicit From; core default replaced';
        if ($explicit !== null && strcasecmp($explicit, $identity->coreDefault) === 0) {
            $explicit = null;
            $reason = 'explicit From is the core default address; replaced';
        }
        $explicitValid = null;
        if ($explicit !== null) {
            $explicitValid = $this->validator->check($explicit);
            if (!$explicitValid->valid) {
                $explicit = null;
                $reason = 'explicit From is invalid (' . $explicitValid->reason . '); replaced';
            }
        }

        if ($explicit === null || $explicitValid === null) {
            return $this->build(
                'replace-default',
                $mode,
                $fromBefore,
                $configured,
                $nameBefore,
                $identity->fromName,
                $reason
            );
        }

        $aligned = Domains::aligned(Domains::domainOf($explicitValid->address), $identity->siteDomain);
        $move = $headers->hasReplyTo() ? null : [
            'address' => $explicit,
            'name' => ($headers->fromName ?? '') === Domains::CORE_DEFAULT_NAME ? '' : ($headers->fromName ?? ''),
        ];

        $isConfigured = strcasecmp($explicitValid->address, $configured) === 0;

        if ($mode === 'override' && !$isConfigured) {
            return $this->build(
                'override',
                $mode,
                $fromBefore,
                $configured,
                $nameBefore,
                $identity->fromName,
                'mode override: explicit From replaced' . ($move !== null ? ', original moved to Reply-To' : ''),
                true,
                $aligned,
                $move
            );
        }
        if ($mode === 'align' && !$aligned && !$isConfigured) {
            return $this->build(
                'align',
                $mode,
                $fromBefore,
                $configured,
                $nameBefore,
                $identity->fromName,
                'mode align: explicit From not aligned with ' . $identity->siteDomain . '; replaced'
                    . ($move !== null ? ', original moved to Reply-To' : ''),
                true,
                $aligned,
                $move
            );
        }

        // Respect, or align with an aligned address: keep the address.
        $nameAfter = $nameBefore;
        if ($nameBefore === '' || $nameBefore === Domains::CORE_DEFAULT_NAME) {
            // C23, C24: the site's name only on the site's own domain.
            $nameAfter = $aligned ? $identity->fromName : '';
        }
        $why = $mode === 'align' ? 'mode align: explicit From is aligned; kept' : 'mode respect: explicit From kept';
        if ($nameAfter !== $nameBefore) {
            $why .= $aligned ? '; configured name set' : '; default name removed from an unaligned address';
        }

        return $this->build(
            $nameAfter !== $nameBefore ? 'set-name' : 'unchanged',
            $mode,
            $fromBefore,
            $explicit,
            $nameBefore,
            $nameAfter,
            $why,
            true,
            $aligned
        );
    }

    /**
     * @param array{address: string, name: string}|null $move
     */
    private function build(
        string $action,
        string $mode,
        string $fromBefore,
        string $fromAfter,
        string $nameBefore,
        string $nameAfter,
        string $reason,
        bool $explicit = false,
        bool $aligned = false,
        ?array $move = null
    ): Decision {
        if ($fromAfter === $fromBefore && $nameAfter === $nameBefore && $move === null) {
            $action = 'unchanged';
        }

        return new Decision(
            $action,
            $mode,
            $fromBefore,
            $fromAfter,
            $nameBefore,
            $nameAfter,
            $reason,
            $explicit,
            $aligned,
            $move
        );
    }
}
