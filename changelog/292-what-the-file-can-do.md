# What the file can do

Part of #511. The capability badges a streaming detail page shows — **HD**,
**Dolby Vision**, **5.1** — answering *"will this look and sound good on my
setup"*, which is a different question from the numbers in the facts table and
is answered at a glance rather than read.

Derived from the probe rather than stored, so a re-probe corrects them and
nothing can drift out of step with the file.

## What earns a badge, and what deliberately does not

**4K**, not "2160p" — what the box said, and what somebody is looking for. 1080p
reads as **HD**; anything lower keeps its number, because "720p" means something
precise and calling it "SD" would flatten three tiers into one.

**Dolby Vision**, **HDR10+**, **HDR10**, **HLG** each get their own badge: they
are not interchangeable, and a player that does one may not do another.

**SDR earns nothing.** `none` is a real stored value meaning *"measured, and it
is SDR"*, as distinct from null meaning *"never probed"* — but neither is worth
a badge. Every file was SDR once, so saying so is noise. **Stereo** earns
nothing for the same reason: it is the floor, and marking the floor says
nothing.

For audio the **best** track wins, not the first. A file with a 5.1 track and a
stereo fallback is a 5.1 file; showing both would describe the packaging rather
than the capability.

## An unprobed file claims nothing

Empty badges mean *"not measured"*. Inventing "HD" from a filename or a
container would be a guess presented as a fact, on a page whose whole purpose is
telling you what you actually have.

## Verified, and what is not

- **72 tests pass** across the capability, details, probe and quality suites;
  15 new, covering every resolution tier, all four HDR flavours, the
  best-track-wins rule, and the absent cases.
- The badge order is pinned by a test — picture, dynamic range, sound — because
  a row read in a different order each time is a row nobody reads.

**Not verified against a real file.** `media_probes` has **zero rows** on this
machine: no media in this library is readable from here, so `library:probe` has
never run. The field shapes come from reading `MediaProber::hdrFormat()` and
`audioStreams()`, not from observing their output. A real 4K Dolby Vision file
is what would confirm them.
