# The Container With SwagCommercial Absent

Date: 2026-09-16

## Status

Approved. Written for issue #79.

### Where the user would have been asked

No user was reachable during this work. Five questions would have been asked;
each is recorded here with the assumption taken instead.

1. **Assert "the container compiles", or assert the invariant that a failed
   compile would express?** A real `ContainerBuilder::compile()` is not
   available here: the file references dozens of core and SDK ids that only a
   booted Shopware kernel provides, so compiling a hand-built container fails
   for reasons that say nothing about the gate. `UcpSurfaceConfigurationTest`
   already reached this conclusion and wrote it down. **Assumed: assert the
   invariant.** The check below is strictly sharper than a compile, because it
   isolates gate-crossing failures from unrelated missing-core-id noise — and a
   compile that failed for a missing `quote.repository` would have to be
   suppressed with an allow-list that rots.
2. **Simulate absence, or simulate presence?** The unit suite already runs
   with SwagCommercial genuinely absent — ADR 0001 keeps it out of
   `composer.json` entirely, so `class_exists` is false in this process with no
   help from anyone. The thing that has to be *simulated* is therefore
   presence, not absence. **Assumed: build the absent container first, then
   `class_alias` three placeholders and build the present one**, exactly as
   `OrderHistoryLocatorConfigurationTest` already does.
3. **Fix `CommercialAvailability::isAvailableByClass()` in this change?**
   **Assumed: no.** It is asking the wrong question (see "The probe" below),
   but ADR 0001's amendment already records that, deliberately, with a
   condition — "worth fixing the next time SwagCommercial's gate is touched".
   This change tests the gate; it does not touch it. The test is written so
   that it keeps working if and when the probe changes.
4. **Cover the route gate in `AgentFacingRoutes` too?** It carries a second
   `isAvailableByClass()` branch that is supposed to mirror `services.php`.
   **Assumed: no.** Exercising it means driving a real `RoutingConfigurator`
   through the attribute loader over real controller files, which is a booted
   kernel's job. Its failure mode is also different and milder — a 500 on one
   route, not a shop that cannot boot. Recorded under "Not covered".
5. **One test file or two?** **Assumed: one.** The reference-closure check
   ranges over both gates at once because the mechanism is identical; splitting
   it by gate would mean two copies of the same walker.

## The gap

`src/Resources/config/services.php` is 867 lines with three gate branches in
it. Two probes decide what a shop gets:

- `UcpAvailability::isRegistered($container)` — four `if` blocks and one early
  `return`.
- `CommercialAvailability::isAvailableByClass()` — one nested `if` and one
  early `return` at line 553, after which the whole file is gated.

Nothing in the suite compiles that file with SwagCommercial absent.
`GatewayWiringTest` asserts a *licensed* shop as its precondition, and every
shop the integration suite can reach has SwagCommercial installed. So a service
that moves across the commercial boundary, or an unconditional reference to
something only the gated block registers, cannot fail a test. It fails as a
shop that will not boot.

`UcpSurfaceConfigurationTest` already covers the *other* gate, both sides, from
the real `services.php`. What is missing is the commercial half — and, in both
halves, any check that the services left standing can still be *built*, as
opposed to merely being listed.

### The issue's own list is stale

#79 names `ProtocolHash`, `A2cnKeyStore`, `A2cnIdentityResolver`,
`SellerMandateFactory`, `MandateSigner` and `A2cnDiscoveryController` as
"unconditionally registered". They no longer are. ADR 0001's amendment moved
the entire A2CN evidence layer behind `UcpAvailability::isRegistered()`, where
it sits today (lines 441–543). They are unconditional **with respect to
SwagCommercial**, which is the property #79 is really about, and that is how
this spec states it: on a shop with the UCP SDK bundle and no SwagCommercial,
all six resolve.

## The probe: `isAvailableByClass()` versus `isRegistered()`

#79 suggests the `class_exists` keying is what forces a compiler-pass-level
test. Checking that premise first turned up something else.

The two helpers ask different questions:

| | question | answer source |
|---|---|---|
| `CommercialAvailability::isAvailableByClass()` | is this class on the autoloader's classpath? | `class_exists` ×3 |
| `UcpAvailability::isRegistered($container)` | is this bundle in the container being built? | `kernel.bundles` |

Only the second answers "is this plugin actually active here". The first is
known to be wrong for a vendored plugin, and this repo learned it the hard way
on the Agentic Commerce side: `scripts/shop-setup.sh` installs both plugins
with `composer require`, so their namespaces are in Composer's autoloader
whether the plugin is active or not, and `plugin:deactivate` rebuilds the
container inside a process that booted with the plugin active. Either way the
class outlives the bundle. ADR 0001's amendment records the outcome — the
deactivation died in `DecoratorServicePass` — and then says, explicitly:

> `CommercialAvailability` still uses `class_exists` and is left alone here:
> its service gate and its route gate read the same probe, so they agree with
> each other. It carries the same vendored-plugin blind spot, worth fixing the
> next time SwagCommercial's gate is touched.

So the answer to #79's premise is: **yes, `isAvailableByClass()` asks the wrong
question, and it is a real defect, but not a new one and not this issue's.** It
is a documented, deliberately deferred one with a named trigger. What it means
concretely: deactivating SwagCommercial on a shop that vendored it leaves
`isAvailableByClass()` true, so `services.php` keeps registering
`service('quote.repository')` — a plain, non-optional reference to a DAL
repository that left with the bundle — and that is a compile-time
`ServiceNotFoundException`, i.e. the shop does not come back up. The test built
here would not catch that, because it is not a gate-crossing bug: it is the
gate reading the wrong input. It is reported alongside, not fixed here.

**Consequence for the design:** the test must not depend on *how* the probe
answers. It drives the gate by making the probe's current input true or false,
and asserts over the resulting containers. If `isAvailableByClass()` later
becomes `isRegistered()`-shaped, one private helper in the test changes and
every assertion stands.

## Design

One new file, `tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php`,
the commercial-side sibling of `tests/Unit/Ucp/UcpSurfaceConfigurationTest.php`
and built the same way: a bare `ContainerBuilder`, `kernel.environment` and
`kernel.bundles` set by hand, `MerchantQuoteAgentPlugin::build()` called on it,
definitions inspected. No kernel, no database, no SwagCommercial.

### Building the four shops

Both gates are booleans, so there are four shops:

| | SwagCommercial | UCP SDK bundle |
|---|---|---|
| A | no | no |
| B | no | yes |
| C | yes | yes |
| D | yes | no |

`kernel.bundles` is set per build. SwagCommercial presence is faked by
`class_alias`ing one anonymous placeholder onto the three names
`isAvailableByClass()` probes — the same trick
`OrderHistoryLocatorConfigurationTest` uses, and for the same reason: the gate
only asks whether the name exists, never instantiates anything behind it.

`class_alias` cannot be undone, so **A and B are built before it runs and C and
D after**, in one helper that returns all four. The class carries
`#[RunTestsInSeparateProcesses]` and `#[PreserveGlobalState(false)]` so the
three fake class names cannot leak into `CommercialAvailabilityTest`, which
asserts the opposite in the same suite.

### The three assertions

**1. The gated block is absent, not broken (A and B).** `SellerActEmitter`,
`QuoteTerminalStateReader`, `A2cnRecordsController` and the merchant/buyer
gateway ids are not defined; the same ids are defined in C. Absence and
presence are both asserted so the test cannot pass by asserting nothing.

**2. The ungated block still builds (B).** `ProtocolHash`, `A2cnKeyStore`,
`A2cnIdentityResolver`, `SellerMandateFactory`, `MandateSigner`,
`A2cnDiscoveryController` — #79's list, re-stated against the UCP gate they now
sit behind — plus the servicing and configuration services that must survive on
any shop.

**3. Nothing left standing depends on something its gate removed.** This is
the one that bites, and it is what replaces "the container compiles".

Let *U* be the union of every service id `services.php` registers across all
four shops. For each shop *S*, the ids missing from *S* are `U − ids(S)` — the
services *S*'s gates removed, derived from the file itself rather than from a
list somebody maintains. An id referenced by *S* that is in neither `ids(S)`
nor *U* is a core or SDK id, which is not this gate's business and is ignored.
The assertion is then: **no service registered in *S* has a mandatory
dependency on an id in `U − ids(S)`.**

Two kinds of dependency count, and the second is the one that makes this worth
landing:

- **Explicit** — a `service(...)` argument, property, method call or factory,
  walked recursively through arrays and `ArgumentInterface` values. References
  marked `ignoreOnInvalid()` / `nullOnInvalid()` are skipped: degrading to null
  is exactly what they are for, and `services.php` uses them deliberately
  throughout.
- **Autowired** — `$services->defaults()->autowire()` means most services here
  carry *no* arguments at load time, so an explicit-only walk would inspect a
  minority of the graph and miss most of a #76-shaped move. The check therefore
  reflects each autowired definition's constructor and resolves each parameter
  the way `AutowirePass` would: skip parameters an explicit argument already
  fills, skip optional ones (a default or a nullable type means autowiring
  falls back rather than failing), and flag any remaining class-typed parameter
  whose type is a removed id, or is not loadable at all.

The "not loadable at all" arm is the second failure mode #79 names — an
unconditional reference to a class that only exists behind the gate. It is free
here: the same reflection loop already has the type name in hand.

### Why not the alternatives

- **A real `compile()`.** Rejected above and already rejected in-repo. It fails
  on core ids that the gate has nothing to do with, and suppressing that needs
  an allow-list of every core service the plugin touches — a list that goes
  stale on the next unrelated edit, which is the exact failure this issue is
  about.
- **A container-dump comparison.** A dump is a compiled container, so it
  inherits the problem above; and a golden dump checked into the repo has to be
  regenerated on every legitimate edit, which trains people to regenerate it on
  illegitimate ones.
- **An autoloader that hides the namespace.** Nothing to hide: the namespace is
  genuinely not there in this suite. Adding a hiding autoloader would be
  machinery to reproduce the state the process is already in.
- **A hand-maintained list of which services belong on which side.** This is
  the tempting one, and it is the thing that stops working. `U − ids(S)` is
  computed from the file on every run, so a service added to either side of
  either gate is classified correctly without anyone updating the test.

## Evidence it bites

The deliverable is not the file. Before this is reported done, each of these is
applied to `services.php` on a scratch commit, the suite is run, the named test
is confirmed red, and the change is reverted:

1. Move an autowired gated service above the `isAvailableByClass()` return
   (the #76 shape, autowired arm).
2. Give an ungated service an explicit `service(...)` argument pointing into
   the gated block (the #76 shape, explicit arm).
3. Move a service that takes a SwagCommercial-typed constructor parameter above
   the gate (the not-loadable arm).

A mutation that does not turn the test red is a hole in the test, not a
successful experiment.

## Not covered

Stated plainly, because a test's blind spots are what the next person needs:

- **The route gate.** `AgentFacingRoutes::import()` has its own
  `isAvailableByClass()` branch that is meant to mirror `services.php`. Drift
  between the two is not detected here. Milder failure — a 500 on one route,
  not a dead shop.
- **The probe itself.** A shop where SwagCommercial's classes are loadable but
  its bundle is gone still gets the bridge registered. That is the defect
  described under "The probe", and it is upstream of everything this test sees.
- **Anything that only a real compile catches.** Circular references, tag
  handling, decoration order, `ServiceLocator` contents, compiler passes from
  core or other bundles. If a service resolves in `ids(S)` this test believes
  it; it does not build it.
- **Runtime behaviour.** Whether the degraded shop *does the right thing* is
  covered elsewhere (`CommercialAvailabilityTest`, `BuyerQuoteNoA2cnTest`,
  and the integration suite's skip counts).
- **SwagCommercial's own service ids.** `GatewayWiringTest` owns those, against
  a live licensed shop; nothing here can see them.

## Gates

`composer run test`, `composer run quality`, `composer run test:integration`.
No production code changes, so the integration suite and
`CoreFloorCompatibilityTest` are regression checks rather than targets.
