<?php

/**
 * This file is part of Milpa Skeleton — the bootable runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/skeleton
 */

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CliProjector;
use App\Command\OperationSigner;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\ToolRuntime\Identity\NonceLedger;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorizer;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The consent gate on the CLI surface, now that consent is a signature.
 *
 * The interesting cases are the refusals, and there are four of them for what used to be one
 * missing flag. Each has to say a different thing, because "declined at the card", "expired",
 * "already used" and "that authorizes a different call" send the operator to four different places
 * — and a gate that collapses them into "denied" teaches people to retry blindly until something
 * works.
 */
#[CoversClass(CliProjector::class)]
final class CliProjectorSignatureGateTest extends TestCase
{
    /** @var list<string> */
    private array $out = [];

    /** @var array<string, mixed>|null */
    private ?array $ranWith = null;

    private function container(): DIContainerInterface
    {
        return new class () implements DIContainerInterface {
            public function registerService(string $id, string|object $classOrInstance): void
            {
            }

            public function get(string $id): mixed
            {
                return null;
            }

            public function has(string $id): bool
            {
                return false;
            }

            public function tryGet(string $id): mixed
            {
                return null;
            }

            public function resolve(string $className, bool $singleton = true): mixed
            {
                throw new \RuntimeException('no autowiring');
            }

            public function compileContainer(): void
            {
            }

            public function getContainer(): \Psr\Container\ContainerInterface
            {
                throw new \RuntimeException('not needed');
            }
        };
    }

    private function operation(): Operation
    {
        return new Operation(
            name: 'plugins.remove',
            description: 'Removes a plugin',
            handler: function (array $input): int {
                $this->ranWith = $input;

                return 0;
            },
            inputSchema: null,
            mutating: true,
            requiresConfirmation: true,
        );
    }

    /**
     * A signer that builds a real authorization from what the projector hands it.
     *
     * Not a canned string: the payload has to be the genuine canonical form, or the authorizer
     * under test would reject every case for the wrong reason and the suite would still be green.
     * `$secondsAgo` backdates it, which is the only way to reach the expiry branch without waiting.
     */
    private function signer(?int $secondsAgo = 0, ?array $overrideArguments = null): OperationSigner
    {
        return new class ($secondsAgo, $overrideArguments) implements OperationSigner {
            /** @param array<string, mixed>|null $overrideArguments */
            public function __construct(
                private readonly ?int $secondsAgo,
                private readonly ?array $overrideArguments,
            ) {
            }

            public function sign(string $operation, array $arguments, string $host, int $now): ?array
            {
                if ($this->secondsAgo === null) {
                    return null; // declined at the card, or no key
                }

                $authorization = new OperationAuthorization(
                    operation: $operation,
                    arguments: $this->overrideArguments ?? $arguments,
                    host: $host,
                    issuedAt: gmdate('c', $now - $this->secondsAgo),
                    nonce: bin2hex(random_bytes(8)),
                );

                return [$authorization->canonical(), 'a signature the fake verifier will accept'];
            }
        };
    }

    /**
     * The real authorizer, driven through its ports.
     *
     * Doubling it would have tested a stand-in; this exercises the actual freshness, target and
     * single-use logic, and only what genuinely leaves the process is faked.
     */
    private function authorizer(bool $signatureVerifies = true, bool $nonceIsFresh = true): OperationAuthorizer
    {
        $verifier = new class ($signatureVerifies) implements SignatureVerifier {
            public function __construct(private readonly bool $verifies)
            {
            }

            public function verify(string $payload, string $signature): ?VerifiedSigner
            {
                return $this->verifies
                    ? new VerifiedSigner('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', 'Rodrigo Vicente <rodrigo@teamx.agency>')
                    : null;
            }
        };

        $ledger = new class ($nonceIsFresh) implements NonceLedger {
            public function __construct(private readonly bool $fresh)
            {
            }

            public function spend(string $nonce, int $ttlSeconds, int $now): bool
            {
                return $this->fresh;
            }
        };

        return new OperationAuthorizer($verifier, $ledger, 120);
    }

    private function project(CliProjector $projector, array $argv): int
    {
        $this->out = [];

        return $projector->run($this->operation(), $argv, $this->container(), function (string $line): void {
            $this->out[] = $line;
        });
    }

    private function printed(): string
    {
        return implode("\n", $this->out);
    }

    public function test_without_sign_the_operation_does_not_run(): void
    {
        $exit = $this->project(new CliProjector(), ['--name=MailPlugin']);

        self::assertSame(1, $exit);
        self::assertNull($this->ranWith, 'Nothing ran.');
        self::assertStringContainsString('--sign', $this->printed());
    }

    public function test_the_old_flag_no_longer_authorizes_anything(): void
    {
        // The point of the change, stated as a test: whoever still types --yes gets told what
        // replaced it rather than silently mutating.
        $exit = $this->project(new CliProjector(), ['--name=MailPlugin', '--yes']);

        self::assertSame(1, $exit);
        self::assertNull($this->ranWith);
    }

    public function test_a_declined_or_missing_key_stops_the_operation(): void
    {
        $projector = new CliProjector(signer: $this->signer(secondsAgo: null));

        $exit = $this->project($projector, ['--name=MailPlugin', '--sign']);

        self::assertSame(1, $exit);
        self::assertNull($this->ranWith);
        self::assertStringContainsString('Nothing was signed', $this->printed());
    }

    public function test_an_expired_authorization_stops_the_operation_and_says_what_to_do(): void
    {
        // Backdated past the window. The message has to distinguish this from a bad signature:
        // one means sign again, the other means stop.
        $projector = new CliProjector(signer: $this->signer(secondsAgo: 300), authorizer: $this->authorizer());

        $exit = $this->project($projector, ['--name=MailPlugin', '--sign']);

        self::assertSame(1, $exit);
        self::assertNull($this->ranWith);
        self::assertStringContainsString('expired', $this->printed());
        self::assertStringContainsString('sign the operation again', $this->printed());
    }

    public function test_a_signature_that_does_not_verify_stops_the_operation(): void
    {
        $projector = new CliProjector(signer: $this->signer(), authorizer: $this->authorizer(signatureVerifies: false));

        $exit = $this->project($projector, ['--name=MailPlugin', '--sign']);

        self::assertSame(1, $exit);
        self::assertNull($this->ranWith);
        self::assertStringContainsString('does not verify', $this->printed());
    }

    public function test_an_already_used_authorization_stops_the_operation(): void
    {
        $projector = new CliProjector(signer: $this->signer(), authorizer: $this->authorizer(nonceIsFresh: false));

        $exit = $this->project($projector, ['--name=MailPlugin', '--sign']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('already used', $this->printed());
    }

    public function test_an_authorization_naming_another_target_stops_the_operation(): void
    {
        // The property that `--yes` could never have. The signature is genuine, fresh and unused —
        // and authorizes removing a different plugin than the one on this command line.
        $projector = new CliProjector(
            signer: $this->signer(overrideArguments: ['name' => 'BillingPlugin']),
            authorizer: $this->authorizer(),
        );

        $exit = $this->project($projector, ['--name=MailPlugin', '--sign']);

        self::assertSame(1, $exit);
        self::assertNull($this->ranWith, 'MailPlugin was never authorized.');
        self::assertStringContainsString('different call', $this->printed());
    }

    public function test_a_granted_authorization_runs_the_operation_and_names_the_key(): void
    {
        $projector = new CliProjector(signer: $this->signer(), authorizer: $this->authorizer());

        $exit = $this->project($projector, ['--name=MailPlugin', '--sign']);

        self::assertSame(0, $exit);
        self::assertSame('MailPlugin', $this->ranWith['name'] ?? null);
        // Shown before the effect, so a wrong card is caught by the person standing there rather
        // than by an audit weeks later.
        self::assertStringContainsString('authorized by BE7554E9', $this->printed());
    }

    public function test_a_non_confirmable_operation_is_untouched(): void
    {
        // Unattended work keeps working. A mutating operation that never asked for confirmation is
        // a deploy step or a cron job, and neither has a hand to touch a card.
        $unattended = new Operation(
            name: 'cache.warm',
            description: 'Warms the cache',
            handler: function (array $input): int {
                $this->ranWith = $input;

                return 0;
            },
            inputSchema: null,
            mutating: true,
            requiresConfirmation: false,
        );

        $this->out = [];
        $exit = (new CliProjector())->run($unattended, [], $this->container(), function (string $l): void {
            $this->out[] = $l;
        });

        self::assertSame(0, $exit);
        self::assertNotNull($this->ranWith);
    }
}
