<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Attempt;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class ServicingAttemptDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'merchant_quote_agent_servicing_attempt';

    #[\Override]
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    /** @return class-string<ServicingAttemptCollection> */
    #[\Override]
    public function getCollectionClass(): string
    {
        return ServicingAttemptCollection::class;
    }

    /** @return class-string<ServicingAttemptEntity> */
    #[\Override]
    public function getEntityClass(): string
    {
        return ServicingAttemptEntity::class;
    }

    #[\Override]
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            /** @mago-expect analysis:deprecated-method */
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            /** @mago-expect analysis:deprecated-method */
            (new IntField('attempt_count', 'attemptCount'))->addFlags(new Required()),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
