# The server release could never publish, and the reason was one matrix row

S-419.

## Twenty-four hours, then cancelled

Every run of `build-server.yml` since it was written ended the same way:
cancelled at 24h0m02s. Not failed — *cancelled*, which reads like someone
stopped it rather than something being broken, and is why it sat unexplained
for as long as it did.

The runs were not doing anything for those twenty-four hours. Four of the five
platforms finished in under twenty-five minutes:

    windows-x86_64   21m07s   ok
    linux-x86_64      9m37s   ok
    macos-aarch64     8m20s   ok
    linux-aarch64     6m39s   ok
    macos-x86_64     24h00m   "exceeded the maximum execution time while
                               awaiting a runner"

`macos-13` was the last GitHub-hosted Intel macOS image and it has been
retired. The job was not slow; it was queued for a machine that does not
exist. The release job `needs:` the whole matrix, so it never started — and
**no server release has ever published**, for any version.

Checked the history before removing it: that entry has never once succeeded.
Intel macOS has been dead in this workflow from the day it was written, so
nothing is being given up that ever worked.

## What replaces it

Nothing, for now. Apple Silicon has been the only Mac Apple sells since 2023,
and an Intel Mac runs the aarch64 build under Rosetta 2. If a native Intel
build is wanted later it needs a self-hosted runner — there is no hosted
option left to switch to.

## The part that should have caught this sooner

Every job now has `timeout-minutes: 60`. A missing runner should fail in an
hour, visibly, not consume a day and then report itself as cancelled. The
absence of a timeout is what let a queue problem look like a scheduling
accident for weeks.

The same timeout went on `build-client.yml`, which has no Intel entry and is
otherwise unaffected.
