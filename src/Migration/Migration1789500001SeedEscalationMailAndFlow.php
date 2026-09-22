<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use MerchantQuoteAgentPlugin\Servicing\QuoteAgentEscalatedEvent;
use Override;
use Shopware\Core\Content\Flow\Dispatching\Action\SendMailAction;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * A mail template and a Flow Builder flow for QuoteAgentEscalatedEvent, so a
 * fresh install has something to turn on (#170).
 *
 * Before this, the event fired into nothing: no template existed to pick, and
 * — see QuoteAgentEscalatedEvent's docblock — the event could not have
 * delivered mail even if a merchant had hand-built a flow, because it did not
 * yet implement MailAware or ScalarValuesAware. Both gaps are fixed together;
 * this migration is the half that gives the fix something to seed.
 *
 * Disabled by default (`flow`.`active` = 0). The Administration notification
 * this plugin already sends (ShopwareEscalationNotifier) needs no setup and
 * reaches every privileged admin session already, so the failure mode this
 * closes is "a merchant who wants email has no template to wire up" rather
 * than "nobody is ever told" — and turning on unsolicited outbound mail to
 * every admin user the moment the plugin activates would trade one silent
 * default for another, just louder. A merchant reviews the template, then
 * flips one switch in Flow Builder.
 *
 * Idempotent by fixed id, the same idiom as
 * Migration1789400001SeedBuiltInStrategies: `INSERT IGNORE` against a
 * deterministic id derived once with `md5()` (not a security primitive, only
 * used for a stable id), so a rerun or reinstall changes nothing a merchant
 * already touched, and does not resurrect a row they deleted outright as a
 * new one — it reappears with the same id, exactly like a built-in strategy
 * would.
 *
 * ponytail: only Defaults::LANGUAGE_SYSTEM gets a translation row, in
 * English. Every other language inherits from it through Shopware's own
 * translation fallback chain, which is the same baseline
 * CreateMailTemplateTrait falls back to for a language outside its own
 * en/de special-casing. Add a translation for a specific language if a
 * merchant asks for one.
 *
 * `mail_template`/`mail_template_type`/`flow`/`flow_sequence` are shared core
 * tables, so MerchantQuoteAgentPlugin::dropPluginTables() rightly never
 * touches them — a DROP TABLE would take the shop's own mail with it. The
 * row-level counterpart lives in
 * MerchantQuoteAgentPlugin::deleteSeededMailAndFlow(), which removes exactly
 * the four ids below on a "remove all data" uninstall, and only while they are
 * still untouched. That is why three of them are public: the delete side must
 * name the same rows this side seeds, and a second copy of a hex literal is a
 * copy that drifts. FLOW_SEQUENCE_ID stays private because deleting the flow
 * cascades it.
 */
class Migration1789500001SeedEscalationMailAndFlow extends MigrationStep
{
    public const MAIL_TEMPLATE_TYPE_ID = 'f6caf8ac80f3db231b89fd00bec1eaff';

    public const MAIL_TEMPLATE_ID = 'c3822f2498c843a66e5865ef0ce34f9f';

    public const FLOW_ID = '8ae9f480fef0ecd82c9d6d2ff62ffd89';

    private const FLOW_SEQUENCE_ID = '6ef5b9206330d85083bc9990d7ce974b';

    private const MAIL_TEMPLATE_TECHNICAL_NAME = 'merchant_quote_agent.quote_escalated';

    private const FLOW_NAME = 'Quote agent: escalation needs a human';

    private const CONTENT_HTML = <<<'HTML'
        <div style="font-family: arial; font-size: 12px;">
            <p>The quote agent escalated quote {{ quoteNumber }} and needs a human decision.</p>
            <p>Reason: {{ escalationReason }}</p>
            <p>Open the quote in your shop administration to respond to the buyer.</p>
        </div>
        HTML;

    private const CONTENT_PLAIN = <<<'TEXT'
        The quote agent escalated quote {{ quoteNumber }} and needs a human decision.

        Reason: {{ escalationReason }}

        Open the quote in your shop administration to respond to the buyer.
        TEXT;

    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789500001;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $languageId = hex2bin(Defaults::LANGUAGE_SYSTEM);

        $connection->executeStatement('INSERT IGNORE INTO `mail_template_type`
                (`id`, `technical_name`, `available_entities`, `created_at`)
             VALUES (:id, :technicalName, :availableEntities, :createdAt)', [
            'id' => hex2bin(self::MAIL_TEMPLATE_TYPE_ID),
            'technicalName' => self::MAIL_TEMPLATE_TECHNICAL_NAME,
            'availableEntities' => '[]',
            'createdAt' => $now,
        ]);

        $connection->executeStatement('INSERT IGNORE INTO `mail_template_type_translation`
                (`mail_template_type_id`, `language_id`, `name`, `created_at`)
             VALUES (:typeId, :languageId, :name, :createdAt)', [
            'typeId' => hex2bin(self::MAIL_TEMPLATE_TYPE_ID),
            'languageId' => $languageId,
            'name' => 'Quote agent escalation',
            'createdAt' => $now,
        ]);

        $connection->executeStatement('INSERT IGNORE INTO `mail_template`
                (`id`, `mail_template_type_id`, `system_default`, `created_at`)
             VALUES (:id, :typeId, 1, :createdAt)', [
            'id' => hex2bin(self::MAIL_TEMPLATE_ID),
            'typeId' => hex2bin(self::MAIL_TEMPLATE_TYPE_ID),
            'createdAt' => $now,
        ]);

        $connection->executeStatement('INSERT IGNORE INTO `mail_template_translation`
                (`mail_template_id`, `language_id`, `sender_name`, `subject`, `description`,
                 `content_html`, `content_plain`, `created_at`)
             VALUES (:templateId, :languageId, :senderName, :subject, :description,
                     :contentHtml, :contentPlain, :createdAt)', [
            'templateId' => hex2bin(self::MAIL_TEMPLATE_ID),
            'languageId' => $languageId,
            'senderName' => 'Merchant Quote Agent',
            'subject' => 'Quote {{ quoteNumber }} needs your review',
            'description' =>
                'Sent when the quote agent could not decide within its limits and handed ' . 'the quote to a human.',
            'contentHtml' => self::CONTENT_HTML,
            'contentPlain' => self::CONTENT_PLAIN,
            'createdAt' => $now,
        ]);

        $connection->executeStatement('INSERT IGNORE INTO `flow`
                (`id`, `name`, `event_name`, `priority`, `active`, `created_at`)
             VALUES (:id, :name, :eventName, 1, 0, :createdAt)', [
            'id' => hex2bin(self::FLOW_ID),
            'name' => self::FLOW_NAME,
            'eventName' => QuoteAgentEscalatedEvent::EVENT_NAME,
            'createdAt' => $now,
        ]);

        $connection->executeStatement('INSERT IGNORE INTO `flow_sequence`
                (`id`, `flow_id`, `action_name`, `position`, `display_group`, `true_case`,
                 `config`, `created_at`)
             VALUES (:id, :flowId, :actionName, 1, 1, 0, :config, :createdAt)', [
            'id' => hex2bin(self::FLOW_SEQUENCE_ID),
            'flowId' => hex2bin(self::FLOW_ID),
            'actionName' => SendMailAction::ACTION_NAME,
            'config' => json_encode(
                [
                    // 'admin': every `user` row with `admin = true` — see
                    // SendMailAction::getRecipients(). An escalation has
                    // no buyer address to fall back to.
                    'recipient' => ['data' => [], 'type' => 'admin'],
                    'mailTemplateId' => self::MAIL_TEMPLATE_ID,
                    'documentTypeIds' => [],
                ],
                \JSON_THROW_ON_ERROR,
            ),
            'createdAt' => $now,
        ]);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. A merchant who edited the template, disabled
        // the flow, or deleted either outright keeps that choice on update —
        // see the class docblock on reinstall behaviour.
    }
}
