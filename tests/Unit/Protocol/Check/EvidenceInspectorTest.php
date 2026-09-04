<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Check\EvidenceCheckInterface;
use MerchantQuoteAgentPlugin\Protocol\Check\EvidenceInspector;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class EvidenceInspectorTest extends TestCase
{
    public function testItReturnsTheFirstViolationAndStops(): void
    {
        $second = self::check('second');
        $inspector = new EvidenceInspector([self::check('first'), $second]);

        $violation = $inspector->firstViolation(
            ActChain::read(null),
            ProtocolFixtures::snapshot(),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        );

        self::assertSame('first', $violation?->violationType);
        // Ordering is the point: the expensive network check must not run when
        // a local comparison already refused the chain.
        self::assertFalse($second->ran);
    }

    public function testItPassesWhenEveryCheckPasses(): void
    {
        $inspector = new EvidenceInspector([self::check(null), self::check(null)]);

        self::assertNull($inspector->firstViolation(
            ActChain::read(null),
            ProtocolFixtures::snapshot(),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        ));
    }

    private static function check(?string $type): EvidenceCheckInterface
    {
        return new class($type) implements EvidenceCheckInterface {
            public bool $ran = false;

            public function __construct(
                private readonly ?string $type,
            ) {}

            public function check(
                ActChain $chain,
                QuoteSnapshot $snapshot,
                string $sellerDid,
                \DateTimeImmutable $at,
            ): ?ProtocolViolation {
                $this->ran = true;

                return $this->type === null
                    ? null
                    : new ProtocolViolation($at->format(\DATE_ATOM), $this->type, null, 'test');
            }
        };
    }
}
