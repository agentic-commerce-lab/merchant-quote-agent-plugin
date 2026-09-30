# Merchant Quote Agent

A Shopware 6.7 extension that answers your B2B customers' price requests on
quotes for you. It works only inside the limits you set, and it hands every
other request to a person on your team.

- **It never goes past your cap.** You set the largest discount it may give.
  Ordinary code checks every offer against your limits before it is saved and
  again after. An offer above your limits is thrown away, and a person takes
  over the quote.
- **Anything that isn't about price goes to a person.** That includes shipping,
  payment terms and changes to the products on the quote. The agent tells the
  customer a colleague will follow up and puts the quote in front of you. If a
  request is unclear, it first asks the customer one short question.
- **Every decision is logged.** A dashboard shows what it offered, what it
  escalated and why.
- **It starts switched off.** A fresh install answers nothing until you turn it
  on and set a discount limit.

## What you need

| | |
| --- | --- |
| **Shopware** | 6.7.1 or newer, on PHP 8.3 or newer |
| **B2B quotes** | SwagCommercial 6.7.1.2 or newer, with quote management licensed (`QUOTE_MANAGEMENT-6302947`). The agent works on the quotes this feature creates. |
| **An AI provider** | Your own API key, base URL and model name. OpenAI and any OpenAI-compatible endpoint work (Azure, your own gateway, a model you host). You pay the provider directly. |
| **A background worker** | `bin/console messenger:consume` must be running. Without it the agent receives requests and never answers them. A silent agent is most often missing this. |
| *Optional:* **Agentic Commerce** | Only if your customers' own AI assistants should request and negotiate quotes directly. Everything else works without it. |
| *Optional:* **Shopping assistant starter kit** | Lets shoppers ask the storefront chat assistant about their quotes. |

## Install

1. **Download the plugin zip.** Open the repository's *Actions* tab, pick the
   latest successful **Plugin Zip** run on `main`, and download the
   `MerchantQuoteAgentPlugin` artifact. You need to be signed in to GitHub, and
   each download stays available for 30 days.
2. **Upload it.** In the Administration go to **Extensions → My extensions →
   Upload extension** and choose the zip.
3. **Install and activate** *Merchant Quote Agent* from the same list.

The upload fetches the plugin's libraries with Composer inside the web request.
For that to work, the shop's `composer.json`, `composer.lock` and `vendor/`
must be writable by the web server. PHP's `memory_limit` and
`max_execution_time` must also allow a dependency install. If your host doesn't
allow that, have your developer install from the command line instead.

<details>
<summary>Installing from the command line (for your developer)</summary>

Needs Composer 2.10.0 or newer.

```bash
unzip MerchantQuoteAgentPlugin.zip -d /path/to/shop/custom/plugins/
cd /path/to/shop
composer require shopware/merchant-quote-agent-plugin
rm -f config/packages/ai_generic_platform.yaml
bin/console plugin:refresh
bin/console plugin:install --activate MerchantQuoteAgentPlugin
bin/console cache:clear
```

Don't skip the `composer require` or the `rm`. Either one fails later with an
error that doesn't mention this plugin, and the missing `rm` stops the whole
shop from booting.
[Installing into a shop](docs/end-to-end.md#9-installing-into-a-shop)
explains both.

</details>

## Set it up

Everything is under **Settings → Extensions → Merchant Quote Agent**. Every
setting can differ per sales channel. A good start is a cautious policy
everywhere, turned on for one sales channel first.

1. **Model settings:** enter your **LLM API key**, the **LLM base URL** (leave
   the default for OpenAI) and the **Model name**. The model name has no
   default: you choose the cost and quality. If the key or the model is
   missing, every quote goes to a person.
2. **Negotiation policies:** set the **Maximum discount (%)**. It starts at
   `0`, which sends every price request to a person. Start low. Optional
   limits:
   - **Counter-offer ceiling (%):** asks above your maximum but within this
     ceiling get a counter-offer at your maximum instead of going to a person.
   - **Minimum margin on purchase price (%):** the agent never offers less
     than a product's purchase price plus this markup.
   - **Maximum quote value for negotiation (net):** larger quotes always go to
     a person. It's set per currency.
   - **Round the agent's offers:** makes the offers read like round numbers.
   - **Default offer validity (days):** how long an offer stays valid.
3. **Negotiation strategy** (optional): choose a tone. The built-in strategies
   are *Margin defender*, *Fast close* and *Relationship builder*. You can
   also duplicate one and write your own. A strategy changes how the agent
   words and paces its offers. It never changes your limits.
4. **Agent activation:** tick **Enable the quote agent**. You can also turn
   on **Draft Mode**, which holds every reply until you approve it. That's a
   safe way to watch the agent before you let it answer customers directly.

What always goes to a person, what the dashboard shows and what the AI
provider sees are all in the merchant guide below.

## Learn more

| Guide | For |
| --- | --- |
| [**Merchant guide**](docs/for-merchants.md) | Whoever runs the shop. Setup in detail, how it decides, what always goes to a person, the dashboard, costs and data. No code. |
| [Costs and data](docs/for-merchants.md#costs-and-data) | What reaches your AI provider, and what the anonymized export sends. Read it before running `merchant-quote-agent:export`. |
| [End to end](docs/end-to-end.md) | Developers and operators. The full process, configuration reference and running it in production. |
| [Without Agentic Commerce](docs/end-to-end.md#11-without-agentic-commerce) | What changes when the optional extension is not installed. |
| [The shopping assistant](docs/end-to-end.md#12-the-shopping-assistant) | The optional storefront chat integration. |
| [Development](docs/development.md) | Working on the plugin: test shop, checks, evals. |
| [Architecture decisions](docs/adr/) | Why it is built the way it is. |

## License

[MIT](LICENSE)
