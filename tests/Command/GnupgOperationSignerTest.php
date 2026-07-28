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

use App\Command\GnupgOperationSigner;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Asking the machine's key to authorize a call — including when it says no.
 *
 * The binary is injected so the refusal path is reachable. It is the branch that runs whenever an
 * operator changes their mind at the card, and with a real key in the loop it would be the one
 * branch no test could ever visit.
 */
#[CoversClass(GnupgOperationSigner::class)]
final class GnupgOperationSignerTest extends TestCase
{
    /** @var list<string> */
    private array $scripts = [];

    protected function tearDown(): void
    {
        foreach ($this->scripts as $script) {
            @unlink($script);
        }
    }

    private function gpgPrinting(string $output): string
    {
        $path = sys_get_temp_dir() . '/fake-sign-' . bin2hex(random_bytes(6));
        file_put_contents($path, "#!/usr/bin/env bash\ncat <<'EOF'\n{$output}\nEOF\n");
        chmod($path, 0o700);
        $this->scripts[] = $path;

        return $path;
    }

    public function test_it_returns_the_payload_it_signed_and_the_signature(): void
    {
        $signer = new GnupgOperationSigner($this->gpgPrinting("-----BEGIN PGP SIGNATURE-----\nx\n-----END PGP SIGNATURE-----"));

        $result = $signer->sign('plugins.remove', ['name' => 'MailPlugin'], 'cm4070', 1_800_000_000);

        self::assertNotNull($result);
        [$payload, $signature] = $result;
        self::assertStringContainsString('BEGIN PGP SIGNATURE', $signature);

        // The payload must be the canonical authorization, byte for byte — the verifier rebuilds
        // it from the call and compares, so anything else is rejected as a different call.
        $authorization = OperationAuthorization::fromCanonical($payload);
        self::assertSame('plugins.remove', $authorization?->operation);
        self::assertSame(['name' => 'MailPlugin'], $authorization?->arguments);
        self::assertSame('cm4070', $authorization?->host);
    }

    public function test_a_declined_signature_authorizes_nothing(): void
    {
        // gpg printing anything that is not a signature: the operator said no, the card was
        // removed, no key exists. All the same answer.
        $signer = new GnupgOperationSigner($this->gpgPrinting('gpg: signing failed: Operation cancelled'));

        self::assertNull($signer->sign('plugins.remove', ['name' => 'MailPlugin'], 'cm4070', 1_800_000_000));
    }

    public function test_a_missing_binary_authorizes_nothing(): void
    {
        $signer = new GnupgOperationSigner('/nonexistent/gpg');

        self::assertNull($signer->sign('plugins.remove', [], 'cm4070', 1_800_000_000));
    }

    public function test_every_authorization_gets_its_own_nonce(): void
    {
        // Two signatures for the identical call must not be interchangeable, or the single-use
        // ledger would reject the second legitimate one — and worse, the first would still work
        // twice.
        $signer = new GnupgOperationSigner($this->gpgPrinting("-----BEGIN PGP SIGNATURE-----\nx\n-----END PGP SIGNATURE-----"));

        $first = $signer->sign('plugins.remove', ['name' => 'MailPlugin'], 'cm4070', 1_800_000_000);
        $second = $signer->sign('plugins.remove', ['name' => 'MailPlugin'], 'cm4070', 1_800_000_000);

        self::assertNotSame(
            OperationAuthorization::fromCanonical((string) $first[0])?->nonce,
            OperationAuthorization::fromCanonical((string) $second[0])?->nonce,
        );
    }

    public function test_it_leaves_no_authorization_on_disk(): void
    {
        // The payload names an operation and its arguments — what someone was about to do.
        $before = (array) glob(sys_get_temp_dir() . '/milpa-authz-*');

        $signer = new GnupgOperationSigner($this->gpgPrinting('not a signature'));
        $signer->sign('plugins.remove', ['name' => 'MailPlugin'], 'cm4070', 1_800_000_000);

        self::assertSame(\count($before), \count((array) glob(sys_get_temp_dir() . '/milpa-authz-*')));
    }
}
