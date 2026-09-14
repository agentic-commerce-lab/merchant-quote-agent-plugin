# The Quote Agent, for merchants

A plain-language guide for the person who runs the shop and the deal desk. No
code. If you are implementing or extending the plugin, read
[`end-to-end.md`](end-to-end.md) instead.

---

## In one minute

When a B2B customer asks for a better price on a quote, this plugin answers for
you — but only inside limits you set.

You tell it the most discount it may ever give. It reads what the customer
asked, decides whether that is inside your limits, picks a number, updates the
quote, writes the customer a reply, and logs everything. If the ask is outside
your limits, or is about anything other than price, it does not improvise: it
tells the customer a colleague will follow up, and puts the quote in front of
you.

**It cannot exceed your cap.** The cap is enforced by ordinary code after the AI
proposes a number, and again after the quote is saved. An AI suggestion above
your limit is thrown away and the quote goes to a person.

**It starts switched off, and does nothing until you configure it.** Out of the
box the maximum discount is 0%, which means every price request goes to a human.
That is deliberate: a silent agent is far more often "not set up yet" than
"broken".

---

## What you need before you start

Three of these are things you or your team already have. Two need your developer
or hosting provider, once. One is optional and most shops will not want it yet.

| You need | Notes |
| --- | --- |
| Shopware 6.7.1 or newer | Any newer 6.7 release is fine. |
| The B2B quote feature, licensed | This is SwagCommercial with quote management active. The agent works on the quotes that feature creates. |
| An AI provider account and key | **Yours, not ours.** See [Costs and data](#costs-and-data) below. |
| *Your developer:* the plugin installed | It is a normal Shopware extension, but the install has two easy-to-miss steps. |
| *Your developer or host:* a background worker running | Without it the agent receives requests and never acts on them. Ask for "a `messenger:consume` worker". This is the single most common reason a correctly configured agent stays silent. |
| *Optional:* the Agentic Commerce extension | Only if you want your customers' own AI assistants to request and negotiate quotes on their behalf. See below. |

### Do you need the Agentic Commerce extension?

Probably not, to start with. Without it, everything in this guide still works:
your customers request quotes the normal way in the shop, and the agent answers
them with the same policy, the same replies, the same escalations and the same
dashboard.

What it adds is the other direction — letting a *customer's* AI assistant talk
to your shop directly: request a quote, counter it, accept it, without a person
opening your storefront. If that is not a conversation you are having with
customers yet, leave it out. You can add it later, and nothing you have
configured changes.

Turning it on also enables a signed record of each negotiation, for customers
who need one for their own audit trail. That only does anything if the customer
negotiates through an assistant, so it comes and goes with the extension.

---

## Setting it up

Everything is in **Settings → Extensions → Merchant Quote Agent**, and every
setting is per sales channel. A sales channel uses your global value until you
override it, so you can set a cautious policy everywhere and be more generous
on one channel first. That is the recommended way to start.

### 1. Give it model access

Under **Model settings**:

- **LLM API key** — your own key from your AI provider.
- **LLM base URL** — leave it as it is for OpenAI, or point it at Azure, your
  own gateway, or a model you host yourself.
- **Model name** — required, with no default on purpose. Naming a default would
  be us choosing your cost and quality for you.

If the key or the model name is missing, the agent does not quietly fall back to
anything. It hands the quote to a person and records that the configuration is
wrong.

### 2. Set the limits

Under **Negotiation policies** — this is the important screen:

- **Maximum discount (%)** — the most the agent may ever grant. `0` means every
  price request goes to a human. Start low.
- **Counter-offer ceiling (%)** — optional. If a customer asks for more than your
  maximum but no more than this, the agent counters at *your maximum* instead of
  escalating. Leave it blank and anything above the maximum goes to a person.
- **Maximum quote value for negotiation (net)** — optional. Above this value the
  agent always escalates, however small the discount. Set it per currency: a
  quote in a currency you left blank escalates rather than passing, because an
  unknown limit is not an unlimited one.
- **Default offer validity (days)** — how long the offers it sends stay valid.
- **Escalation SLA (hours)** — optional, and it changes nothing the agent does.
  It only lets the dashboard tell you how many escalations your team answered in
  time.

### 3. Optionally, set the tone

Under **Negotiation strategy** you can write how you want it to negotiate, in
your own words. For example:

> Open at 2%, concede in 1% steps, never lead with the maximum. Warm but brief.

This shapes *how* it negotiates and how the reply is worded. **It cannot move a
cap.** If your strategy text asks for more than your policy allows, the policy
wins and the quote goes to a person.

### 4. Turn it on

Under **Agent activation**, tick **Enable the quote agent**. While it is off the
agent is completely silent: it queues nothing and writes nothing.

---

## How a negotiation actually runs

The agent wakes up when a customer submits a quote request, asks for changes on
one, or leaves a comment on one. Then, for that one quote:

1. **It reads the quote and the customer's message.** If nothing has changed
   since its own last reply, it stops here and costs you nothing.
2. **It works out what was asked.** A percentage off, a target price on
   particular lines, a request for your best price, a delivery or payment
   request, or a question.
3. **It checks that against your limits.** This step is ordinary code, not AI.
   If the ask is outside your caps, it escalates *here* — before spending money
   on the expensive part, and it escalates even if your AI provider is down.
4. **It decides the number.** Inside your band, the AI chooses what to offer. It
   does not have to give the maximum, and usually should not. It answers at the
   level the customer asked at: per line if they named line prices, or across the
   quote if they asked for a percentage.
5. **It updates the quote, then checks its own work.** After saving, it re-reads
   the quote from the database and compares it against what was authorised. If
   the two disagree, the quote goes to a person with a note about what the
   database actually says.
6. **It writes the reply.** The facts — the reduction, the new total, the validity
   date — are fixed before the AI words them. If the wording changes any number,
   the plain version is sent instead. The customer then sees a normal quote
   offer.

Repeat visits are handled: it can see its own earlier offers on a quote and keeps
negotiating within the same caps, which are always measured against the *current*
prices, so concessions never quietly compound.

### It may look at the customer's history

While deciding, the agent can ask for that customer's own past quotes, their
order totals, or what they previously paid for a product on this quote. It uses
this only to choose where inside your band to land.

Two guarantees: **history never raises your cap**, and the agent is instructed
never to quote it back to the customer or confirm what it knows. If a customer
asks what you have on file, it says a colleague can go through their records with
them. History is always limited to that customer's own account.

---

## What always goes to a person

This is the part worth knowing before you promise anything internally. The agent
deliberately refuses to answer these itself:

| The customer asks for | What happens |
| --- | --- |
| A discount above your cap (and above the counter ceiling) | Escalated |
| Anything on a quote above your value ceiling | Escalated |
| Free shipping, express delivery, payment terms, deposits | Escalated. The quote cannot even record these, so answering the price half and dropping the rest would be worse than saying a person will take it. |
| Adding, removing, or re-quantifying products | Escalated. Changing *what* is being sold is outside a price mandate. |
| Volume or bulk pricing with no specific price named | Escalated |
| To speak to a human | Escalated |
| Something ambiguous | **Not escalated the first time.** The agent asks the customer a short clarifying question, in their language, and waits. If the answer is still unclear, then a person takes it. |
| A second round of *per-line* price cuts | Escalated, so a second concession cannot be measured against the first one's already reduced prices. Quote-wide rounds continue normally. |

And if anything goes wrong — the AI is unreachable, it proposes something outside
your rules, or the saved quote does not match what was approved — the quote goes
to a person. There is no mode where it guesses.

When it escalates, the customer sees one neutral message:

> A member of our team will review this quote personally and get back to you.

Your team finds out two ways: a notification in the administration, and a Flow
Builder trigger you can wire to email, Slack, a task, or a tag — whatever your
team already uses. Nothing is emailed by default, because that would mean us
choosing one channel and one recipient for every shop.

---

## What you get

### The dashboard

**Orders → Quote Agent Dashboard.** It opens filtered to **Needs review**, so
the first thing you see is the queue that wants a human. Pick a period — last 7,
30, or 90 days — and four figures sit at the top.

- **Auto-execution rate** — how much of the work it handled without you, with
  "*n* of *m* needed a human" beside it and a trend against the previous period.
- **Escalation resolution time** — how long your team takes to answer an
  escalation. Set the SLA field and it becomes "*n* of *m* within the SLA".
- **Discount granted** — what the agent gave, next to what was given on
  comparable deals it never touched, matched to similar deal sizes.
- **Deal cycle time** — how long from request to order, agent versus comparable
  deals.

Two honest notes. **"Discount granted" is not margin.** It compares the original
price against the price sold; neither this plugin nor a normal B2B catalogue
knows your cost of goods, so no margin figure is possible. And **a figure with
nothing to measure says so** — "Unavailable", "no comparable deals in this
period", "*n* still open" — rather than showing a confident zero. If a tile says
it needs permission to read quotes or orders, that is a role setting, not a bug.

Escalation resolution time only covers escalations resolved after this measure
shipped. Older ones are reported as still open rather than quietly dropped from
the average.

### A record of every quote

The list shows each quote it touched: what the customer asked, what was granted,
and the outcome — *Offer sent*, *Counter sent*, *Question asked*, *Needs review*,
or *No action needed*.

Open one and you get the whole negotiation in order: what the customer asked,
what the agent did, what changed on the quote, and why. Including the exact reply
that was sent, and, when it escalated, the reason in plain words — *Discount
above the cap*, *Quote value above the ceiling*, *Customer asked for a human*,
*Agent not configured*, *Model unavailable*, and so on.

This record is written by the plugin and cannot be edited afterwards, including
by your own staff through the API. It can be deleted by someone you give the
delete role to.

### Who can see what

Two roles, under **Permissions → merchant_quote_agent**:

- **viewer** — read the dashboard and the records. Also needs read access to
  quotes and orders, or two of the four tiles cannot be calculated.
- **deleter** — additionally remove audit rows.

There is also an **Agent access** page under Settings, but only if you run the
Agentic Commerce extension — it controls which customer assistants may talk to
your shop, and it edits that extension's own data, so it is gated by that
extension's permissions rather than the two above. Without the extension the
page is not in Settings at all.

---

## Costs and data

**You pay for the AI, directly.** The key is yours, so there is no per-quote fee
from us and no markup. Budget roughly up to three AI calls per customer message:
one to read the request, one to decide, one to word the reply. A repeat trigger
with nothing new on the quote makes none. An ask that is outside your caps makes
one, not three.

**What is sent to your AI provider.** To do its job the agent sends that
provider the quote's line items with quantities and prices, the customer's
message, and its own earlier replies. If it looks at history, that adds figures
about the account — how many past quotes, how many became orders, lifetime order
value, past prices for a product on this quote.

It never sends fields from the customer's profile: no name, no company name, no
address, no contact details, no payment data. Comment authors are handled as
internal IDs, which are not sent either. The one thing outside your control is
the message itself — whatever the customer typed is sent as they wrote it, so if
they sign it or include a phone number, that text goes with it.

Choose your provider and base URL accordingly. That setting exists so you can
point at a European endpoint, your own gateway, or a model you host yourself, if
that is what your privacy commitments require.

**Everything else stays in your shop.** Quotes, prices, the audit record and the
API key all live in your own Shopware installation. Nothing goes to us.

**One thing to be aware of:** the API key is stored in your shop's configuration.
The field hides it on screen, but it is not encrypted at rest — the same as every
other secret a Shopware extension holds. Treat database access accordingly.

If you can set environment variables on your shop, set `MQA_LLM_API_KEY`
instead. The agent prefers it over this field, and the key then never reaches
the database at all — neither a database dump nor an admin API token with
`system_config:read` can reveal it.

---

## Day to day

**Your routine is the Needs review queue.** Open the dashboard, work the
escalations, and the agent handles the rest. When you answer an escalated quote —
by sending a revised offer, or however you normally close it — the plugin notices
and stops counting it as open.

**Raising the cap.** Start with a low maximum on one sales channel, watch the
auto-execution rate and the discount figure for a couple of weeks, then widen.
Changes take effect on the next customer message; nothing is retroactive.

**If the agent seems to do nothing**, check these in order:

1. Is **Enable the quote agent** ticked for *that* sales channel?
2. Is **Maximum discount** still `0`? Then everything escalating is correct
   behaviour.
3. Is the background worker running? Ask your host. This is the most common
   cause, and the symptom is exactly this: requests pile up and nothing happens.
4. Does the customer have the B2B quote feature enabled on their account? Without
   it they cannot have a quote at all.

**If a customer gets two replies to one message**, tell your developer the admin
worker and the background worker are both running. It is a known configuration
clash with a known fix, and it does not double the discount — offers are written
as final values, not added on top of each other.

---

## Limits worth knowing

Stated plainly, so nothing here is a surprise later:

- It negotiates **price and offer validity**. Nothing else.
- There is **no rules-only mode**. Reading a customer's free-text request needs
  the AI, so an agent without model access does not negotiate more
  conservatively — it escalates.
- **Invalid settings take the channel out of service** rather than applying half
  a policy. A cap above 100, for instance, escalates everything and logs why.
- It **never places an order** and never touches a quote that is already
  accepted, declined, expired or cancelled.
- The customer's own words are **not** stored in the audit record. What they
  asked for is stored in structured form; the conversation itself stays on the
  quote where it always was.

---

## Where to go next

- Your developer's reference: [`end-to-end.md`](end-to-end.md)
- Why the extension is built the way it is:
  [`adr/0001-runtime-plugin-dependencies.md`](adr/0001-runtime-plugin-dependencies.md)
