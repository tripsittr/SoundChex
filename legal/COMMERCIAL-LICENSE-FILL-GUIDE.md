# Filling in the Commercial License Agreement — a guide

> **Not legal advice.** This walks through every blank in
> `COMMERCIAL-LICENSE-AGREEMENT.md` with a plain-English prompt, the options, a
> suggested default for a small/solo open-source vendor (Tripsittr LLC), and the
> trade-off. Answer each, then paste the value into the agreement. Take both
> documents to counsel.
>
> Two kinds of blank:
> - **Deal terms** (fees, scope) — set *per customer*, in Exhibits A/B.
> - **Template defaults** (indemnity, cap, warranty) — set *once* for your
>   standard agreement; counsel confirms them.

---

## Part 1 — Set once (your standard template)

These don't change per customer. Decide them once with counsel; they become your
house terms.

### 1. Effective date

> **Prompt:** *"What date does this specific deal start?"*

Per-deal. Leave `[EFFECTIVE DATE]` blank in the template; fill it when you sign.

### 2. Your street address

> **Prompt:** *"What is Tripsittr LLC's registered business street address in
> Phoenix?"*

Fill `[STREET ADDRESS]` (Section for Licensor). This is your LLC's address on
file with the Arizona Corporation Commission. If you use a registered-agent or
PO-box address for public docs, use that.

### 3. Exclusive or non-exclusive license? (§2.1)

> **Prompt:** *"Do you want to be able to sell this same commercial license to
> other companies too, or is this buyer getting an exclusive?"*

- **Non-exclusive** *(recommended default)* — you can license the same code to
  as many customers as you like. This is the normal commercial-OSS model and how
  you build recurring revenue.
- **Exclusive** — only this buyer gets commercial rights (often for a field/
  industry). Charge a **lot** more; you're giving up all other sales in that area.

**Default:** `non-exclusive`. Offer exclusivity only for a large, specific,
well-paid deal.

### 4. Transferable? (§2.1)

> **Prompt:** *"If the buyer sells their company, should the license
> automatically go to the new owner?"*

- **Non-transferable** *(recommended)* — the license doesn't move without your
  consent; §14.3 already allows assignment to a genuine successor-in-merger. Keeps
  you in control of who holds a license.

**Default:** `non-transferable`.

### 5. Sublicensable? (§2.1, Exhibit A)

> **Prompt:** *"Can the buyer pass license rights on to THEIR customers?"*

- **`non-sublicensable`** — the buyer uses it themselves only.
- **`sublicensable to end users of the Licensee Product only`** *(usually needed)*
  — if the buyer ships SoundChex *inside* their product to their customers, those
  end users need the right to run it. This is normal for "embed in a product."

**Default:** allow sublicensing **to end users of the Licensee Product only**
(not general resale). §3(d) already blocks standalone resale.

### 6. Updates included? (§4.2)

> **Prompt:** *"Does buying the license include future versions/patches, or just
> the version they bought?"*

- **Included** — simpler for a subscription; buyer always has the latest.
- **Not included** *(pairs with a perpetual license)* — they license one version;
  new major versions cost more, or need a maintenance plan.

**Default:** if you sell a **subscription**, updates **included**. If you sell a
**perpetual** license to a version, updates **not included** (sell maintenance
separately). Pick to match your fee model (§9 below).

### 7. Support included? (§4.3, Exhibit B)

> **Prompt:** *"Are you willing to answer support emails as part of the license,
> or is it 'as-is, figure it out via the open-source community'?"*

- **No support** *(recommended to start — you're solo)* — "provided as-is."
  Keeps your obligations low. Buyers of a self-hostable OSS product expect this.
- **Support per Exhibit B** — only if you want to offer (and price) it. Then fill
  Exhibit B: level (e.g. "email, 2 business-day response"), hours/timezone, and
  what's excluded (custom dev, third-party issues, modified builds).

**Default:** **No support included** at first. Add a paid support tier later.

### 8. Warranty — conformance or as-is? (§11.2)

> **Prompt:** *"Do you want to promise the software works as documented for a
> short window, or disclaim all warranties?"*

- **As-is / no conformance warranty** *(recommended for a solo vendor)* — choose
  the `[No conformance warranty — see 11.3.]` option. §11.3 disclaims everything.
  Lowest risk. Reasonable given the free AGPL version exists.
- **90-day limited warranty** — a "will materially conform to the docs for 90
  days, else we'll fix or refund" promise. More buyer-friendly, more obligation
  on you.

**Default:** **as-is** (select `[No conformance warranty]`).

### 9. IP indemnity — offer it or not? (§12.1)

> **Prompt:** *"If someone sues the BUYER claiming SoundChex infringes a patent/
> copyright, will you (Tripsittr) defend and pay — or is that the buyer's risk?"*

This is the most consequential template decision.

- **No indemnity** *(common for small OSS vendors)* — replace §12.1 with "Licensor
  provides no indemnification; the Software is provided as-is." You take on no
  litigation risk. Justified because the same code is available free under AGPL.
- **Limited IP indemnity** — you defend the *unmodified* software against
  copyright (and maybe patent) claims, with carve-outs for modifications,
  combinations, and third-party components. **Big-company buyers will ask for
  this**; it can be a deal-maker, but it's real exposure for a solo LLC.

**Default:** start with **no indemnity**; be willing to add a **copyright-only,
capped** indemnity for a large deal — and only after counsel sizes the risk.
**Discuss this one specifically with your lawyer.**

### 10. Liability cap (§13.2)

> **Prompt:** *"If something goes wrong and you're found liable, what's the most
> you could owe?"*

- **`the Fees paid in the 12 months before the claim`** *(recommended)* — caps
  your exposure to roughly one year of that customer's fees. Standard and
  protective.
- `the total Fees paid` — a bit more generous to the buyer.

**Default:** **12-month fees cap**. Keep §13.1 (no indirect/consequential
damages) and §13.3 exceptions as written.

### 11. Cure period & payment window (§5.2, §6.2)

> **Prompt:** *"How long to fix a breach before the other side can terminate, and
> how many days to pay an invoice?"*

- **`[30]` days** for both is standard. **Default: 30/30.**

### 12. Arbitration or courts? (§14.1)

> **Prompt:** *"If there's a dispute, do you want it fought in Arizona courts, or
> in private arbitration?"*

- **Courts (Maricopa County)** *(already the default in the draft)* — public,
  no extra clause needed.
- **Arbitration** — private, often faster, but you pay arbitrator fees. If you
  want it, keep the optional AAA-arbitration sentence; else delete it.

**Default:** **Arizona courts** (delete the optional arbitration sentence) unless
counsel prefers arbitration.

### 13. Third-party components link (§8.2)

> **Prompt:** *"Where's the public list of dependencies + their licenses?"*

Fill `[link to the credits page / THIRD-PARTY-LICENSES]` with your credits page
URL (e.g. `https://<site>/docs/credits`) once the site has a real domain, or the
repo's `Documentation & Planning/LicenseAudit.md` for now.

---

## Part 2 — Set per customer (Exhibit A / B)

These change every deal. You fill them when you quote a specific buyer.

### 14. Licensee details

> **Prompt:** *"Who is the buyer — exact legal name, entity type, home state,
> address, and signer's name/title?"*

Fill `[LICENSEE LEGAL NAME]`, `[ENTITY TYPE]`, `[JURISDICTION]`, `[ADDRESS]`, and
the signature block `[NAME]`/`[TITLE]`. Get these from the buyer; use their
**exact registered** legal name.

### 15. Licensed Version (Exhibit A)

> **Prompt:** *"Which version(s) of SoundChex does this license cover — a specific
> release, or everything during the term?"*

- A pinned version (e.g. "SoundChex v1.x") for a **perpetual** deal, or
- "all versions during the Term" for a **subscription**.

### 16. Licensee Product & permitted use (Exhibit A)

> **Prompt:** *"What is the buyer building with SoundChex, and are they embedding
> it, hosting it, or redistributing it?"*

Describe their product in a sentence, then pick the use: `embed in proprietary
product` / `operate as a hosted service` / `redistribute`. This defines what
they're actually paying to be allowed to do.

### 17. Scope / metrics (Exhibit A)

> **Prompt:** *"How do you meter this — per install, per seat, per site, by their
> revenue, or unlimited?"*

Pick the unit that matches how they'll use it: `instances`, `seats`, `sites`, a
`revenue tier`, or `unlimited`. This is what a bigger deal scales on.

### 18. Fees (Exhibit A, §5.1) — the money

> **Prompt:** *"What are you charging this buyer — a one-time fee, an annual
> subscription, or both, and how much?"*

Fill `[AMOUNT]`. Two common shapes:
- **One-time** perpetual-version fee: `$[AMOUNT]` once (updates sold separately).
- **Annual subscription**: `$[AMOUNT] per year` (updates + optional support
  included).

No public price is required — you quote per deal. **Tip:** price the commercial
license well above what the customer would spend to comply with the AGPL; the
value you're selling is *relief from the copyleft*, which is worth more to a
company that can't open-source their product.

### 19. Term (§6.1, Exhibit A)

> **Prompt:** *"Perpetual license to a version, or a renewing subscription?"*

- **Perpetual** — pairs with a one-time fee and "updates not included."
- **`[N]`-year subscription, auto-renewing** with `[60]`-day non-renewal notice —
  pairs with recurring fees and included updates.

Pick to match the fee shape you chose in #18.

### 20. Invoicing timing (§5.2)

> **Prompt:** *"Bill once up front, or annually in advance?"*

`on the Effective Date` (one-time) or `annually in advance` (subscription).

---

## Suggested "house default" package (a solo-vendor starting point)

If you want a sensible, low-risk default set to discuss with counsel:

- Non-exclusive, non-transferable, sublicensable **to end users only**
- **Subscription** model: annual fee, **updates included**, **no support** (add
  paid support later)
- **As-is**, **no IP indemnity** to start (add a capped copyright indemnity only
  for a big deal)
- Liability capped at **12 months of fees**; 30/30 cure/payment
- **Arizona courts**, Maricopa County (no arbitration)

Then Exhibit A is the only thing you rewrite per customer.

---

## Questions to bring to counsel

1. Given the free AGPL version, do we need **any** IP indemnity — and if so,
   copyright-only, capped, unmodified-only?
2. Is the **12-month-fees liability cap** enforceable and sufficient in Arizona?
3. Any **Arizona-specific** consumer/warranty statutes that override our
   as-is/disclaimer language for certain buyers?
4. Do we want **arbitration** or Arizona courts?
5. Does the **CLA** (`CLA.md`) actually secure our right to relicense
   contributions, and should we require signed CLAs (not just PR-implied)?
6. Trademark: is "SoundChex" registered, and does §9 protect it adequately?

*Reminder: draft template, pending legal review — not legal advice, not binding
until a lawyer-reviewed version is signed.*
